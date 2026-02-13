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
 * - Conexiones DB no-bloqueantes (coroutine-aware)
 *
 * Uso:
 *   php app/channel.server.php [--port=9501] [--host=0.0.0.0]
 */

# CRÍTICO: Habilitar hooks ANTES de cualquier otra cosa
# Esto hace que PDO, file_get_contents, sleep, etc. sean non-blocking
# Cada coroutine tendrá su propia conexión DB aislada (ver CONN::getCoroutineConnection)
\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

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
use bX\Cache;
use bX\Cache\SwooleTableBackend;
use bX\Async\TaskRouter;
use bX\Async\SwooleResponseBus;
use bX\Async\SwooleAsyncBusAdapter;
use bX\ChannelContext;

class ChannelServer
{
    private $server;
    private ?\Swoole\Table $channelsTable = null; # Memoria compartida entre workers
    private ?\Swoole\Table $authTable = null; # Autenticaciones compartidas
    # Per-worker array — crece con conexiones, se limpia en onClose.
    # heartbeat_idle_time (65s) fuerza onClose en conexiones muertas sin FIN.
    private array $authenticatedConnections = [];
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
        $this->authTable->column('device_hash', \Swoole\Table::TYPE_STRING, 32); # xxh128 = 32 hex chars
        $this->authTable->create();

        # Cache compartido entre workers (GeoService, FeePolicyRepository, EDC, HolidayProvider)
        $cacheTable = new \Swoole\Table(65536); # 64k entries
        $cacheTable->column('data', \Swoole\Table::TYPE_STRING, 8192);
        $cacheTable->column('expires_at', \Swoole\Table::TYPE_INT);
        $cacheTable->create();
        Cache::init(new SwooleTableBackend($cacheTable));

        $workerNum = Config::getInt('CHANNEL_WORKER_NUM', swoole_cpu_num() * 2);
        $taskWorkerNum = Config::getInt('CHANNEL_TASK_WORKER_NUM', swoole_cpu_num());

        $this->server->set([
            'worker_num' => $workerNum,
            'task_worker_num' => $taskWorkerNum,
            'daemonize' => false,
            'log_level' => SWOOLE_LOG_INFO,
            'heartbeat_check_interval' => 30,
            'heartbeat_idle_time' => 65,
            # Límite de payload WebSocket: rechaza frames > 1MB antes de json_decode
            'package_max_length' => 1 * 1024 * 1024,
            # Buffer de salida por worker (respuestas grandes)
            'buffer_output_size' => 2 * 1024 * 1024,
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
        $this->server->on('request', [$this, 'onRequest']);
        $this->server->on('task', [$this, 'onTask']);
        $this->server->on('finish', [$this, 'onFinish']);
    }

    public function onStart(Swoole\WebSocket\Server $server): void
    {
        cli_set_process_title('bintelx-channel-master');
        # SIGUSR1: recarga workers (código nuevo), master mantiene puerto — sin "address already in use"
        # Swoole\Table (cache) persiste en memoria compartida, conexiones WS persisten
        Swoole\Process::signal(SIGUSR1, function() use ($server) {
            $server->reload();
            $this->info("Channel workers reloaded via SIGUSR1");
        });

        $this->success("Channel Server started successfully");
        $this->info("Listening on ws://{$this->host}:{$this->port}");
        $this->info("Workers: {$server->setting['worker_num']}, Task Workers: {$server->setting['task_worker_num']}");
        $this->info("Hot reload: kill -USR1 \$(pgrep -f 'bintelx-channel-master')");
    }

    # HTTP gateway: todo pasa por Router (mismos endpoints que WS)
    # _internal/* protegido por ROUTER_SCOPE_SYSTEM (X-System-Key o localhost)
    public function onRequest(Swoole\Http\Request $request, Swoole\Http\Response $response): void
    {
        $uri = $request->server['request_uri'] ?? '';
        $method = $request->server['request_method'] ?? 'GET';

        # CORS headers — misma config que api.php via .env
        $corsOrigin = Config::get('CORS_ALLOWED_ORIGINS', 'https://dev.local');
        $corsMethods = Config::get('CORS_ALLOWED_METHODS', 'GET,POST,PATCH,DELETE,OPTIONS');
        $corsHeaders = Config::get('CORS_ALLOWED_HEADERS', 'Origin,X-Auth-Token,X-Requested-With,Content-Type,Accept,Authorization');

        $response->header('Access-Control-Allow-Origin', $corsOrigin);
        $response->header('Access-Control-Allow-Methods', $corsMethods);
        $response->header('Access-Control-Allow-Headers', $corsHeaders);
        $response->header('Access-Control-Allow-Credentials', 'true');
        $response->header('Access-Control-Max-Age', '3600');

        # Preflight — responder y cortar
        if ($method === 'OPTIONS') {
            $reqHeaders = $request->header['access-control-request-headers'] ?? '';
            if ($reqHeaders) {
                $response->header('Access-Control-Allow-Headers', $reqHeaders);
            }
            $response->status(204);
            $response->end();
            return;
        }

        $this->executeHttpRoute($request, $response, $uri, $method);
    }

    # Despacha requests HTTP al Router unificado (mismos endpoints que WS)
    private function executeHttpRoute(
        Swoole\Http\Request $request,
        Swoole\Http\Response $response,
        string $uri,
        string $method
    ): void {
        $snapshot = SuperGlobalHydrator::snapshot();
        try {
            Profile::resetStaticProfileData();
            Router::$currentUserPermissions = [];

            $body = json_decode($request->rawContent() ?: '{}', true) ?: [];
            $query = $request->get ?? [];
            $headers = [];

            # JWT desde header Authorization o cookie bnxt
            $authHeader = $request->header['authorization'] ?? '';
            if ($authHeader) {
                $headers['Authorization'] = $authHeader;
            } elseif (isset($request->cookie['bnxt'])) {
                $headers['Authorization'] = 'Bearer ' . $request->cookie['bnxt'];
            }

            # X-System-Key para ROUTER_SCOPE_SYSTEM (swoole headers son lowercase)
            $systemKey = $request->header['x-system-key'] ?? '';
            if ($systemKey) {
                $headers['X-System-Key'] = $systemKey;
            }

            SuperGlobalHydrator::hydrate([
                'method' => $method,
                'uri' => $uri,
                'headers' => $headers,
                'body' => $body,
                'query' => $query,
                'remote_addr' => $request->server['remote_addr'] ?? '127.0.0.1'
            ]);
            SuperGlobalHydrator::hydrateArgs($method, $body, $query);

            # Auth
            $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
            if ($token) {
                $jwtSecret = Config::get('JWT_SECRET');
                $jwtXorKey = Config::get('JWT_XOR_KEY', '');
                $account = new \bX\Account($jwtSecret, $jwtXorKey);
                $accountId = $account->verifyToken($token, $request->server['remote_addr'] ?? '');
                if ($accountId) {
                    $jwt = new JWT($jwtSecret, $token);
                    $payload = $jwt->getPayload();
                    $userPayload = $payload[1] ?? [];
                    $profile = new Profile();
                    $profile->load(['account_id' => $accountId]);
                    Profile::$scope_entity_id = (int)($userPayload['scope_entity_id'] ?? 0);
                    Router::$currentUserPermissions = Profile::getRoutePermissions();
                }
            }

            ob_start();
            $route = new Router($uri, '/api');
            Router::dispatch($method, $uri);
            $output = ob_get_clean();

            # Enviar respuesta HTTP
            $response->header('Content-Type', 'application/json; charset=utf-8');
            $response->end($output);

        } catch (\Exception $e) {
            if (ob_get_level() > 0) ob_end_clean();
            $response->status(500);
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode(['error' => $e->getMessage()]));
            Log::logError("Channel HTTP route error", ['uri' => $uri, 'error' => $e->getMessage()]);
        } finally {
            SuperGlobalHydrator::restore($snapshot);
            Profile::resetStaticProfileData();
        }
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

            // Load all API routes CASCADE: package (system) → custom (override via CUSTOM_PATH)
            Router::load([
                'find_str' => [
                    'package' => \bX\WarmUp::$BINTELX_HOME . '../package/',
                    'custom' => \bX\WarmUp::getCustomPath()
                ],
                'pattern' => '{*/,}*.endpoint.php'
            ]);

            # ChannelContext: estado de worker disponible para todos los endpoints (WS + HTTP)
            ChannelContext::$server = $server;
            ChannelContext::$channelsTable = $this->channelsTable;
            ChannelContext::$authTable = $this->authTable;

            $this->info("Worker #{$workerId} started with AsyncBus and Routes loaded (package + custom cascade)");
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
        # Support both 'route' and 'uri' keys (backward compatibility)
        $uri = $data['route'] ?? $data['uri'] ?? null;
        $method = strtoupper($data['method'] ?? 'POST');
        $body = $data['body'] ?? [];
        $query = $data['query'] ?? [];
        $correlationId = $data['correlation_id'] ?? uniqid('api_', true);

        if (!$uri) {
            $this->sendError($server, $fd, 'API calls require a "route" or "uri" field in message');
            Log::logWarning("WebSocket message missing 'route' field", ['data' => $data, 'fd' => $fd]);
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
            $route = new Router($uri, '/api');  // Set apiBasePath

            # FD es per-request (cada mensaje WS tiene su propia conexión)
            # Server + tables viven en ChannelContext (set en onWorkerStart)
            $_SERVER['WS_FD'] = $fd;

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

            unset($_SERVER['WS_FD']);

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
                # Extraer claims del JWT (scope, device_hash)
                $scopeEntityId = 0;
                $deviceHash = '';
                try {
                    $jwt = new JWT($jwtSecret, $token);
                    $payload = $jwt->getPayload(); # [METADATA, {id, profile_id, scope_entity_id, device_hash}]
                    $userPayload = $payload[1] ?? [];
                    $scopeEntityId = (int)($userPayload['scope_entity_id'] ?? 0);
                    $deviceHash = $userPayload['device_hash'] ?? '';
                } catch (\Exception $e) {
                    Log::logWarning("Channel: Failed to extract JWT claims: " . $e->getMessage());
                }

                $profile = new Profile();
                $profile->load(['account_id' => $accountId]);

                # Set scope from JWT (signed, trusted)
                Profile::$scope_entity_id = $scopeEntityId;

                # Guardar sesión WS (array + Swoole\Table)
                $this->authenticatedConnections[$fd]['token'] = $token;
                $this->authenticatedConnections[$fd]['account_id'] = $accountId;
                $this->authenticatedConnections[$fd]['profile_id'] = Profile::$profile_id;
                $this->authenticatedConnections[$fd]['scope_entity_id'] = $scopeEntityId;
                $this->authenticatedConnections[$fd]['device_hash'] = $deviceHash;

                if ($this->authTable) {
                    $this->authTable->set((string)$fd, [
                        'token' => $token,
                        'account_id' => (int)$accountId,
                        'profile_id' => Profile::$profile_id,
                        'device_hash' => $deviceHash
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
