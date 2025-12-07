<?php # app/channel.server.php
/**
 * Bintelx Channel Server - Swoole
 * Servidor de canales para pub/sub, chat, notificaciones en tiempo real
 *
 * Características:
 * - WebSocket server para conexiones persistentes
 * - Pub/Sub para broadcasting de mensajes
 * - Autenticación JWT integrada
 * - Canales privados y públicos
 *
 * Uso:
 *   php app/channel.server.php [--port=9501] [--host=0.0.0.0]
 */

require_once __DIR__ . '/../bintelx/WarmUp.php';

# Log level se carga automáticamente desde .env via Log::init()
# En producción LOG_LEVEL=ERROR, en desarrollo LOG_LEVEL=DEBUG

use bX\Config;
use bX\JWT;
use bX\Log;
use bX\Router;
use bX\Profile;
use bX\CONN;
use bX\SuperGlobalHydrator;
use bX\Async\TaskRouter;
use bX\Async\SwooleResponseBus;
use bX\Async\SwooleAsyncBusAdapter;

class ChannelServer
{
    private $server;
    private ?\Swoole\Table $channelsTable = null; # Memoria compartida entre workers
    private ?\Swoole\Table $authTable = null; # Autenticaciones compartidas
    private array $authenticatedConnections = []; # Compatibilidad para headers/token
    private ?TaskRouter $taskRouter = null;
    private ?SwooleResponseBus $responseBus = null;
    private ?SwooleAsyncBusAdapter $asyncBus = null;

    private string $host;
    private int $port;

    public function __construct(string $host = '127.0.0.1', int $port = 8000)
    {
        $this->host = $host;
        $this->port = $port;

        if (!extension_loaded('swoole')) {
            $this->error('Swoole extension is not loaded. Install with: pecl install swoole');
            exit(1);
        }

        $this->info("Initializing Bintelx Channel Server...");
        $this->info("Host: {$host}, Port: {$port}");
        $this->info("SSL/TLS: Disabled (Nginx handles SSL termination)");

        # Plain WebSocket server (no SSL) - Nginx will handle SSL termination
        $this->server = new Swoole\WebSocket\Server($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);

        # Crear Swoole\Table para channels compartidos entre workers
        # Formato: key="channel:fd" value=1 (set-like structure)
        $this->channelsTable = new \Swoole\Table(10240); # 10k subscripciones
        $this->channelsTable->column('subscribed', \Swoole\Table::TYPE_INT, 1);
        $this->channelsTable->create();

        # Tabla para autenticaciones compartidas
        $this->authTable = new \Swoole\Table(2048); # 2k conexiones
        $this->authTable->column('account_id', \Swoole\Table::TYPE_INT);
        $this->authTable->column('profile_id', \Swoole\Table::TYPE_INT);
        $this->authTable->column('token', \Swoole\Table::TYPE_STRING, 512);
        $this->authTable->create();

        $workerNum = Config::getInt('CHANNEL_WORKER_NUM', swoole_cpu_num() * 2);
        $taskWorkerNum = Config::getInt('CHANNEL_TASK_WORKER_NUM', swoole_cpu_num());

        $this->server->set([
            'worker_num' => $workerNum,
            'task_worker_num' => $taskWorkerNum,
            'daemonize' => false,
            'log_level' => SWOOLE_LOG_INFO,
            'heartbeat_check_interval' => 30,
            'heartbeat_idle_time' => 65,
        ]);

        $this->registerHandlers();
    }

    private function registerHandlers(): void
    {
        $this->server->on('start', [$this, 'onStart']);
        $this->server->on('workerStart', [$this, 'onWorkerStart']);
        $this->server->on('open', [$this, 'onOpen']);
        $this->server->on('message', [$this, 'onMessage']);
        $this->server->on('close', [$this, 'onClose']);
        $this->server->on('task', [$this, 'onTask']);
        $this->server->on('finish', [$this, 'onFinish']);
    }

    public function onStart(Swoole\WebSocket\Server $server): void
    {
        $this->success("Channel Server started successfully");
        $this->info("Listening on ws://{$this->host}:{$this->port}");
        $this->info("Workers: {$server->setting['worker_num']}, Task Workers: {$server->setting['task_worker_num']}");
        $this->info("Connect via: ws://{$this->host}:{$this->port} (local) or wss://your-domain.com/ws (via Nginx)");
    }

    public function onWorkerStart(Swoole\WebSocket\Server $server, int $workerId): void
    {
        // Initialize in Task Workers only
        if ($server->taskworker) {
            $this->responseBus = new SwooleResponseBus($server);
            $this->taskRouter = new TaskRouter($server, $this->responseBus);
            $this->info("Task Worker #{$workerId} started with TaskRouter");
        } else {
            // Regular workers - initialize AsyncBus for controllers
            $this->asyncBus = new SwooleAsyncBusAdapter($server);

            // Load all API routes
            Router::load([
                'find_str' => \bX\WarmUp::$BINTELX_HOME . '../custom/',
                'pattern' => '{*/,}*.endpoint.php'
            ]);

            $this->info("Worker #{$workerId} started with AsyncBus and Routes loaded");
        }
    }

    public function onOpen(Swoole\WebSocket\Server $server, Swoole\Http\Request $request): void
    {
        $fd = $request->fd;
        $this->info("New connection: fd={$fd}, remote={$request->server['remote_addr']}");

        $server->push($fd, json_encode([
            'type' => 'system',
            'event' => 'connected',
            'message' => 'Connected to Bintelx Channel Server',
            'fd' => $fd,
            'timestamp' => time()
        ]));
    }

    public function onMessage(Swoole\WebSocket\Server $server, Swoole\WebSocket\Frame $frame): void
    {
        $fd = $frame->fd;
        try {
            $data = json_decode($frame->data, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->sendError($server, $fd, 'Invalid JSON format');
                return;
            }

            $type = $data['type'] ?? null;

            # Inferir type si viene 'route' (backward compat)
            if (!$type && isset($data['route'])) {
                $type = 'api';
            }

            if (!$type) {
                $this->sendError($server, $fd, 'Missing message type');
                return;
            }

            # Todos los mensajes se ejecutan como endpoints
            $this->executeApiRoute($server, $fd, $data);

        } catch (\Exception $e) {
            $this->error("Error processing message from fd={$fd}: " . $e->getMessage());
            $this->sendError($server, $fd, 'Internal server error');
            Log::logError("ChannelServer: " . $e->getMessage());
        }
    }

    # Ejecuta CUALQUIER endpoint (WS o API REST) vía Router Unificado
    private function executeApiRoute(Swoole\WebSocket\Server $server, int $fd, array $data): void
    {
        $uri = $data['route'];
        $method = strtoupper($data['method'] ?? 'POST');
        $body = $data['body'] ?? [];
        $query = $data['query'] ?? [];
        $correlationId = $data['correlation_id'] ?? uniqid('api_', true);

        if (!$uri) {
            $this->sendError($server, $fd, 'API calls require a "route" or "uri"');
            return;
        }

        # 1. SNAPSHOT de superglobales (aislamiento entre requests)
        $snapshot = SuperGlobalHydrator::snapshot();

        try {
            # 2. RESET de estado
            Profile::resetStaticProfileData();
            Router::$currentUserPermissions = [];

            # 3. HIDRATACIÓN de superglobales
            $clientInfo = $server->getClientInfo($fd);
            $remoteAddr = $clientInfo['remote_ip'] ?? '127.0.0.1';

            $headers = [];
            if (isset($this->authenticatedConnections[$fd]['token'])) {
                $headers['Authorization'] = 'Bearer ' . $this->authenticatedConnections[$fd]['token'];
            } elseif (!empty($data['token'])) {
                $headers['Authorization'] = 'Bearer ' . $data['token'];
            } elseif ($this->authTable && $this->authTable->exists((string)$fd)) {
                $entry = $this->authTable->get((string)$fd);
                if (!empty($entry['token'])) {
                    $headers['Authorization'] = 'Bearer ' . $entry['token'];
                }
            }

            SuperGlobalHydrator::hydrate([
                'method' => $method,
                'uri' => $uri,
                'headers' => $headers,
                'body' => $body,
                'query' => $query,
                'remote_addr' => $remoteAddr
            ]);

            # 4. HIDRATACIÓN de Args
            SuperGlobalHydrator::hydrateArgs($method, $body, $query);

            # 5. AUTENTICACIÓN
            $token = null;
            if (isset($this->authenticatedConnections[$fd]['token'])) {
                $token = $this->authenticatedConnections[$fd]['token'];
            } elseif (!empty($data['token'])) {
                $token = $data['token'];
            } elseif ($this->authTable && $this->authTable->exists((string)$fd)) {
                $entry = $this->authTable->get((string)$fd);
                $token = $entry['token'] ?? null;
            }

            if ($token) {
                $this->loadProfile($token, $fd);
            }

            # 6. EJECUTAR Router
            ob_start();

            # No usar base path - dejar que Router extraiga módulo de la URI completa
            $route = new Router($uri);

            # Inyectar contexto WS (tablas compartidas entre workers)
            $_SERVER['WS_SERVER'] = $server;
            $_SERVER['WS_FD'] = $fd;
            $_SERVER['WS_CHANNELS_TABLE'] = $this->channelsTable;
            $_SERVER['WS_AUTH_TABLE'] = $this->authTable;

            Router::dispatch($method, $uri);

            $output = ob_get_clean();

            # Parse JSON response
            $responseData = json_decode($output, true) ?? ['raw' => $output];

            # Enviar respuesta estructurada
            $server->push($fd, json_encode([
                'type' => 'api_response',
                'correlation_id' => $correlationId,
                'status' => 'success',
                'data' => $responseData,
                'timestamp' => time()
            ]));

        } catch (\Exception $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            $server->push($fd, json_encode([
                'type' => 'api_error',
                'correlation_id' => $correlationId,
                'message' => $e->getMessage(),
                'timestamp' => time()
            ]));

            Log::logError("API via WS failed", ['uri' => $uri, 'error' => $e->getMessage()]);
        } finally {
            # 7. RESTORE superglobales
            SuperGlobalHydrator::restore($snapshot);

            unset($_SERVER['WS_SERVER'], $_SERVER['WS_FD'], $_SERVER['WS_CHANNELS_TABLE'], $_SERVER['WS_AUTH_TABLE']);

            # 8. CLEANUP estado
            Profile::resetStaticProfileData();
        }
    }

    # Ejecuta endpoints WS nativos

    # Autentica y carga Profile
    private function loadProfile(string $token, int $fd): void
    {
        try {
            $jwtSecret = Config::get('JWT_SECRET');
            $jwtXorKey = Config::get('JWT_XOR_KEY', '');

            $account = new \bX\Account($jwtSecret, $jwtXorKey);
            $accountId = $account->verifyToken($token, $_SERVER['REMOTE_ADDR']);

            if ($accountId) {
                $profile = new Profile();
                $profile->load(['account_id' => $accountId]);

                # Guardar token en sesión WS (array + Swoole\Table)
                $this->authenticatedConnections[$fd]['token'] = $token;
                $this->authenticatedConnections[$fd]['account_id'] = $accountId;
                $this->authenticatedConnections[$fd]['profile_id'] = Profile::$profile_id;

                if ($this->authTable) {
                    $this->authTable->set((string)$fd, [
                        'token' => $token,
                        'account_id' => $accountId,
                        'profile_id' => Profile::$profile_id
                    ]);
                }

                # Set permissions
                Router::$currentUserPermissions = Profile::getRoutePermissions();
            }
        } catch (\Exception $e) {
            Log::logWarning("Profile load failed", ['error' => $e->getMessage()]);
        }
    }

    public function onTask(Swoole\WebSocket\Server $server, int $taskId, int $srcWorkerId, mixed $data): void
    {
        if (!$this->taskRouter) {
            error_log("[ChannelServer] TaskRouter not initialized in task worker");
            return;
        }

        $this->taskRouter->route($server, $taskId, $srcWorkerId, $data);
    }

    public function onFinish(Swoole\WebSocket\Server $server, int $taskId, mixed $data): void
    {
        $this->info("Task #{$taskId} finished");
    }

    public function onClose(Swoole\WebSocket\Server $server, int $fd): void
    {
        # Limpiar de Swoole\Table (compartida entre workers)
        foreach ($this->channelsTable as $key => $row) {
            if (str_ends_with($key, ':' . $fd)) {
                $this->channelsTable->del($key);
            }
        }

        # Limpiar autenticación
        if ($this->authTable->exists((string)$fd)) {
            $user = $this->authTable->get((string)$fd);
            $this->info("User disconnected: fd={$fd}, account_id={$user['account_id']}, profile_id={$user['profile_id']}");
            $this->authTable->del((string)$fd);
        } else {
            $this->info("Connection closed: fd={$fd}");
        }

        unset($this->authenticatedConnections[$fd]);
    }

    private function sendError(Swoole\WebSocket\Server $server, int $fd, string $message): void
    {
        $server->push($fd, json_encode([
            'type' => 'error',
            'message' => $message,
            'timestamp' => time()
        ]));
    }

    private function info(string $message): void
    {
        echo "[INFO] " . date('Y-m-d H:i:s') . " - {$message}\n";
    }

    private function success(string $message): void
    {
        echo "\033[0;32m[SUCCESS]\033[0m " . date('Y-m-d H:i:s') . " - {$message}\n";
    }

    private function warning(string $message): void
    {
        echo "\033[1;33m[WARNING]\033[0m " . date('Y-m-d H:i:s') . " - {$message}\n";
    }

    private function error(string $message): void
    {
        echo "\033[0;31m[ERROR]\033[0m " . date('Y-m-d H:i:s') . " - {$message}\n";
    }

    # Mensajes offline ahora se manejan automáticamente vía MessagePersistence::persistMessage()
    # Ya no necesitamos buffer separado

    public function start(): void
    {
        $this->server->start();
    }
}

$host = Config::get('CHANNEL_HOST', '127.0.0.1');
$port = Config::getInt('CHANNEL_PORT', 8000);

foreach ($argv as $arg) {
    if (strpos($arg, '--host=') === 0) {
        $host = substr($arg, 7);
    }
    if (strpos($arg, '--port=') === 0) {
        $port = (int)substr($arg, 7);
    }
}

$server = new ChannelServer($host, $port);
$server->start();
