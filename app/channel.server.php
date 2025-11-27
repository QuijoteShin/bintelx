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

 \bX\Log::$logLevel = 'DEBUG';

use bX\Config;
use bX\JWT;
use bX\Log;
use bX\Router;
use bX\Profile;
use bX\CONN;
use bX\Async\TaskRouter;
use bX\Async\SwooleResponseBus;
use bX\Async\SwooleAsyncBusAdapter;

class ChannelServer
{
    private $server;
    private array $channels = [];
    private array $authenticatedConnections = [];
    private array $pendingAcks = []; # Mensajes esperando confirmación
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

        # Context Switching: Simular entorno HTTP
        Profile::resetStaticProfileData();
        Router::$currentUserPermissions = [];

        $_POST = ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') ? $body : [];
        $_GET = ($method === 'GET') ? $query : [];
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REMOTE_ADDR'] = $server->getClientInfo($fd)['remote_ip'] ?? '127.0.0.1';
        $_SERVER['HTTP_X_USER_TIMEZONE'] = Config::get('DEFAULT_TIMEZONE', 'America/Santiago');

        # Hidratación manual de Args (evitar lógica CLI)
        \bX\Args::$OPT = [];
        \bX\Args::$input = [];

        # Poblar directamente según el método HTTP
        $inputData = ($method === 'GET') ? $_GET : $_POST;
        \bX\Args::$OPT = $inputData;
        \bX\Args::$input = $inputData;

        # Autenticación: Usar token de sesión WS si existe
        if (isset($this->authenticatedConnections[$fd]['token'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->authenticatedConnections[$fd]['token'];
            $this->loadProfile($this->authenticatedConnections[$fd]['token'], $fd);
        } elseif (!empty($data['token'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $data['token'];
            $this->loadProfile($data['token'], $fd);
        }

        # Ejecutar Router sin base path (detecta automáticamente de la URI)
        ob_start();

        try {
            # No usar base path - dejar que Router extraiga módulo de la URI completa
            $route = new Router($uri);
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
            ob_end_clean();

            $server->push($fd, json_encode([
                'type' => 'api_error',
                'correlation_id' => $correlationId,
                'message' => $e->getMessage(),
                'timestamp' => time()
            ]));

            Log::logError("API via WS failed", ['uri' => $uri, 'error' => $e->getMessage()]);
        }

        # Cleanup
        Profile::resetStaticProfileData();
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

                # Guardar token en sesión WS
                $this->authenticatedConnections[$fd]['token'] = $token;
                $this->authenticatedConnections[$fd]['account_id'] = $accountId;
                $this->authenticatedConnections[$fd]['profile_id'] = Profile::$profile_id;

                # Set permissions
                if ($accountId == 1) {
                    Router::$currentUserPermissions['*'] = ROUTER_SCOPE_WRITE;
                } else {
                    Router::$currentUserPermissions['*'] = ROUTER_SCOPE_PRIVATE;
                }
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
        foreach ($this->channels as $channel => $subscribers) {
            $this->channels[$channel] = array_filter($subscribers, fn($connFd) => $connFd !== $fd);

            if (empty($this->channels[$channel])) {
                unset($this->channels[$channel]);
            }
        }

        if (isset($this->authenticatedConnections[$fd])) {
            $user = $this->authenticatedConnections[$fd];
            $this->info("User disconnected: fd={$fd}, account_id={$user['account_id']}, profile_id={$user['profile_id']}");
            unset($this->authenticatedConnections[$fd]);
        } else {
            $this->info("Connection closed: fd={$fd}");
        }
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

    # Buffer de notificaciones para usuarios offline
    protected function bufferNotification(int $userId, string $channel, array $message, string $priority = 'normal'): void
    {
        $preview = json_encode([
            'text' => $message['text'] ?? $message['message'] ?? '',
            'from' => $message['from'] ?? null,
            'ts' => time()
        ]);

        $sql = "INSERT INTO sys_notification_buffer (user_id, channel, count, payload_preview, priority)
                VALUES (:user, :ch, 1, JSON_ARRAY(:preview), :prio)
                ON DUPLICATE KEY UPDATE
                  count = count + 1,
                  payload_preview = JSON_ARRAY_APPEND(payload_preview, '\$', :preview),
                  updated_at = NOW(6)";

        CONN::nodml($sql, [
            ':user' => $userId,
            ':ch' => $channel,
            ':preview' => $preview,
            ':prio' => $priority
        ]);

        Log::logInfo("Notification buffered for offline user", [
            'user_id' => $userId,
            'channel' => $channel
        ]);
    }

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
