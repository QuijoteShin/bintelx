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
 *   stdbuf -oL php app/channel.server.php > /tmp/channel-server.log 2>&1 &
 *
 * IMPORTANTE: stdbuf -oL fuerza line-buffering en stdout. Sin esto, los workers
 * Swoole (procesos forkeados) bufferean su output y los logs no aparecen en el
 * archivo hasta que el buffer se llena (~4KB). Con -oL cada echo/print aparece
 * inmediatamente en el log.
 *
 * Opciones:
 *   --host=0.0.0.0    (default: 127.0.0.1)
 *   --port=9501        (default: 8000)
 */

# Xdebug + Swoole coroutines = SIGSEGV (github.com/swoole/swoole-src/issues/5802)
if (extension_loaded('xdebug')) {
    fwrite(STDERR, "ERROR: Xdebug loaded. Channel server refuses to start.\n");
    fwrite(STDERR, "Fix: sudo mv /etc/php/8.4/cli/conf.d/20-xdebug.ini{,.disabled}\n");
    exit(1);
}

# Habilitar hooks ANTES de cualquier otra cosa (PDO, file_get_contents, sleep → non-blocking)
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
    private ?\Swoole\Table $rateLimitTable = null; # Token bucket per FD
    # Per-worker arrays — crecen con conexiones, se limpian en onClose.
    # heartbeat_idle_time (65s) fuerza onClose en conexiones muertas sin FIN.
    private array $authenticatedConnections = [];
    private array $fdChannels = []; # fd → [channel1, channel2, ...] índice inverso O(1) cleanup
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
        $this->authTable = new \Swoole\Table(65536); # 64k conexiones
        $this->authTable->column('account_id', \Swoole\Table::TYPE_INT);
        $this->authTable->column('profile_id', \Swoole\Table::TYPE_INT);
        $this->authTable->column('token', \Swoole\Table::TYPE_STRING, 512);
        $this->authTable->column('device_hash', \Swoole\Table::TYPE_STRING, 32); # xxh128 = 32 hex chars
        $this->authTable->column('scope_entity_id', \Swoole\Table::TYPE_INT);
        $this->authTable->create();

        # Rate limiting: token bucket por FD (shared entre workers)
        $this->rateLimitTable = new \Swoole\Table(65536);
        $this->rateLimitTable->column('tokens', \Swoole\Table::TYPE_FLOAT);
        $this->rateLimitTable->column('last_ts', \Swoole\Table::TYPE_FLOAT);
        $this->rateLimitTable->create();

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
    # SYNC: lógica de parseo URI/query/auth paralela a executeApiRoute (WS)
    #        cambios aquí deben reflejarse en executeApiRoute y viceversa
    private function executeHttpRoute(
        Swoole\Http\Request $request,
        Swoole\Http\Response $response,
        string $uri,
        string $method
    ): void {
        $snapshot = SuperGlobalHydrator::snapshot();
        try {
            Profile::resetStaticProfileData();

            $rawContent = $request->rawContent() ?: '';
            $body = json_decode($rawContent, true) ?: [];
            $query = $request->get ?? [];

            # Raw body para endpoints que leen binario (ej: file upload chunks)
            # En FPM usan php://input; en Swoole ese stream está vacío
            $_SERVER['SWOOLE_RAW_CONTENT'] = $rawContent;
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
                    Profile::ctx()->scopeEntityId = (int)($userPayload['scope_entity_id'] ?? 0);
                }
            }

            ob_start();
            $route = new Router($uri, '/api');
            Router::$currentTransport = 'http';
            Router::dispatch($method, $uri);
            $output = ob_get_clean();

            # Enviar respuesta HTTP
            $response->header('Content-Type', 'application/json; charset=utf-8');
            $response->end($output);

        } catch (\Exception $e) {
            if (ob_get_level() > 0) ob_end_clean();
            $response->status(500);
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode(['error' => 'Internal server error. Check logs for details.']));
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
        $origin = $request->header['origin'] ?? 'none';
        $this->info("New connection: fd={$fd}, origin={$origin}");

        # Origin check: rechazar conexiones de origins no permitidos
        $allowedOrigins = array_filter(array_map('trim', explode(',', Config::get('CHANNEL_ALLOWED_ORIGINS', 'https://dev.local'))));
        if ($origin !== 'none' && !in_array($origin, $allowedOrigins, true)) {
            $this->info("Rejected fd={$fd}: origin '{$origin}' not allowed");
            $server->close($fd);
            return;
        }

        $server->push($fd, json_encode([
            'type' => 'system',
            'event' => 'connected',
            'message' => 'Connected to Bintelx Channel Server',
            'fd' => $fd,
            'timestamp' => time()
        ]));

        # Auth timeout: cerrar si no se autentica en N segundos (previene FD exhaustion)
        $authTimeout = (int)Config::get('CHANNEL_AUTH_TIMEOUT', 10);
        Swoole\Timer::after($authTimeout * 1000, function () use ($server, $fd) {
            if (!isset($this->authenticatedConnections[$fd]) && $server->isEstablished($fd)) {
                $this->sendError($server, $fd, 'Authentication timeout', null, 401);
                $server->close($fd);
            }
        });
    }

    public function onMessage(Swoole\WebSocket\Server $server, Swoole\WebSocket\Frame $frame): void
    {
        $fd = $frame->fd;

        # Rate limiting: token bucket per FD
        if (!$this->checkRateLimit($fd)) {
            $this->sendError($server, $fd, 'Rate limit exceeded', null, 429);
            return;
        }

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

            # Verbose logging: mensaje entrante
            $logCtx = match($type) {
                'api' => ($data['method'] ?? 'POST') . ' ' . ($data['route'] ?? $data['uri'] ?? '?'),
                'auth' => 'auth (token ' . (isset($data['token']) ? substr($data['token'], -8) : 'none') . ')',
                'subscribe', 'unsubscribe' => $type . ' ' . ($data['channel'] ?? '?'),
                'ping' => 'ping',
                default => $type,
            };
            $this->info("→ fd={$fd} IN: {$logCtx}");

            # Dispatch por tipo de mensaje
            switch ($type) {
                case 'auth':
                    # Re-auth sin reconectar (JWT refresh, scope switch)
                    $token = $data['token'] ?? null;
                    if (!$token) {
                        $this->sendError($server, $fd, 'Token required', null, 400);
                        return;
                    }
                    Profile::resetStaticProfileData();
                    $this->loadProfile($token, $fd);
                    if (isset($this->authenticatedConnections[$fd])) {
                        $server->push($fd, json_encode([
                            'type' => 'authenticated',
                            'profile_id' => $this->authenticatedConnections[$fd]['profile_id'],
                            'scope_entity_id' => $this->authenticatedConnections[$fd]['scope_entity_id'],
                            'timestamp' => time()
                        ]));
                    } else {
                        $this->sendError($server, $fd, 'Authentication failed', null, 401);
                    }
                    Profile::resetStaticProfileData();
                    return;

                case 'subscribe':
                    $channel = $data['channel'] ?? null;
                    if (!$channel || strlen($channel) > 128 || str_contains($channel, "\x00")) {
                        $this->sendError($server, $fd, 'Invalid channel name', null, 400);
                        return;
                    }
                    # Auth: array local primero, luego authTable (resiliente a worker reload)
                    if (!isset($this->authenticatedConnections[$fd]) && $this->authTable->exists((string)$fd)) {
                        $this->authenticatedConnections[$fd] = $this->authTable->get((string)$fd);
                    }
                    if (!isset($this->authenticatedConnections[$fd])) {
                        $this->sendError($server, $fd, 'Authentication required', null, 401);
                        return;
                    }
                    # TODO: ACL de canales — validar permisos por prefijo/scope
                    $key = $this->channelKey($channel, $fd);
                    if (!$this->channelsTable->set($key, ['subscribed' => 1])) {
                        $this->sendError($server, $fd, 'Channel table full', null, 503);
                        return;
                    }
                    $this->fdChannels[$fd][] = $channel;
                    $server->push($fd, json_encode([
                        'type' => 'subscribed',
                        'channel' => $channel,
                        'timestamp' => time()
                    ]));
                    return;

                case 'unsubscribe':
                    $channel = $data['channel'] ?? null;
                    if ($channel) {
                        $this->channelsTable->del($this->channelKey($channel, $fd));
                        if (isset($this->fdChannels[$fd])) {
                            $this->fdChannels[$fd] = array_values(array_filter(
                                $this->fdChannels[$fd],
                                fn($c) => $c !== $channel
                            ));
                        }
                        $server->push($fd, json_encode([
                            'type' => 'unsubscribed',
                            'channel' => $channel,
                            'timestamp' => time()
                        ]));
                    }
                    return;

                case 'ping':
                    $server->push($fd, json_encode([
                        'type' => 'pong',
                        'ts' => $data['ts'] ?? time(),
                        'timestamp' => time()
                    ]));
                    return;

                default:
                    # API calls y otros → executeApiRoute
                    $this->executeApiRoute($server, $fd, $data);
            }

        } catch (\Exception $e) {
            $this->error("Error processing message from fd={$fd}: " . $e->getMessage());
            $this->sendError($server, $fd, 'Internal server error');
            Log::logError("ChannelServer: " . $e->getMessage());
        }
    }

    # Ejecuta CUALQUIER endpoint (WS o API REST) vía Router Unificado
    # SYNC: lógica de parseo URI/query/auth paralela a executeHttpRoute (HTTP)
    #        cambios aquí deben reflejarse en executeHttpRoute y viceversa
    private function executeApiRoute(Swoole\WebSocket\Server $server, int $fd, array $data): void
    {
        # Support both 'route' and 'uri' keys (backward compatibility)
        $rawUri = $data['route'] ?? $data['uri'] ?? null;
        $method = strtoupper($data['method'] ?? 'POST');
        $body = $data['body'] ?? [];
        $query = $data['query'] ?? [];

        # Separar query string de la URI (front puede enviar /api/units/list.json?page=1&limit=50)
        $uri = $rawUri;
        if ($rawUri && str_contains($rawUri, '?')) {
            [$uri, $qs] = explode('?', $rawUri, 2);
            parse_str($qs, $qsParams);
            $query = array_merge($qsParams, $query); # query explícito tiene prioridad
        }
        $correlationId = $data['correlation_id'] ?? uniqid('api_', true);

        if (!$uri) {
            $this->sendError($server, $fd, 'API calls require a "route" or "uri" field in message');
            Log::logWarning("WebSocket message missing 'route' field", ['data' => $data, 'fd' => $fd]);
            return;
        }

        $t0 = microtime(true);

        # 1. SNAPSHOT de superglobales (aislamiento entre requests)
        $snapshot = SuperGlobalHydrator::snapshot();

        try {
            # 2. RESET de estado
            Profile::resetStaticProfileData();

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

            # Verificar fingerprint del dispositivo contra JWT device_hash
            $this->verifyDeviceFingerprint($fd, $data);

            # 6. EJECUTAR Router
            ob_start();

            $route = new Router($uri, '/api');

            # FD per-coroutine (aislado entre requests concurrentes del mismo worker)
            $ctx = \Swoole\Coroutine::getContext();
            $ctx->ws_fd = $fd;
            $ctx->http_status = 200;

            Router::$currentTransport = 'ws';
            Router::dispatch($method, $uri);

            $output = ob_get_clean();

            # Status code coroutine-safe (evita race condition con http_response_code() global)
            $statusCode = $ctx->http_status ?? (http_response_code() ?: 200);

            # Parse JSON response
            $responseData = json_decode($output, true) ?? ['raw' => $output];

            # Enviar respuesta con status_code para compatibilidad HTTP
            $elapsed = round((microtime(true) - $t0) * 1000, 1);
            $responsePayload = [
                'type' => 'api_response',
                'correlation_id' => $correlationId,
                'status' => $statusCode >= 200 && $statusCode < 400 ? 'success' : 'error',
                'status_code' => $statusCode,
                'data' => $responseData,
                '_l' => "{$statusCode} {$method} {$uri}",
                'timestamp' => time()
            ];
            $server->push($fd, json_encode($responsePayload));

            # Verbose logging: response out
            $outSize = strlen(json_encode($responseData));
            $this->info("← fd={$fd} OUT: {$statusCode} {$method} {$uri} ({$elapsed}ms, {$outSize}B)");

        } catch (\Exception $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            $elapsed = round((microtime(true) - $t0) * 1000, 1);
            $server->push($fd, json_encode([
                'type' => 'api_error',
                'correlation_id' => $correlationId,
                'status' => 'error',
                'status_code' => 500,
                'message' => 'Request failed. Check server logs for details.',
                '_l' => "500 {$method} {$uri}",
                'timestamp' => time()
            ]));

            $this->info("← fd={$fd} ERR: 500 {$method} {$uri} ({$elapsed}ms) {$e->getMessage()}");
            Log::logError("API via WS failed", ['uri' => $uri, 'error' => $e->getMessage()]);
        } finally {
            # 7. RESTORE superglobales
            SuperGlobalHydrator::restore($snapshot);

            # 8. CLEANUP estado
            Profile::resetStaticProfileData();
        }
    }

    # Ejecuta endpoints WS nativos

    # Autentica y carga Profile — limpia auth previa si JWT inválido/expirado
    private function loadProfile(string $token, int $fd): void
    {
        try {
            $jwtSecret = Config::required('JWT_SECRET');
            $jwtXorKey = Config::required('JWT_XOR_KEY');

            $account = new \bX\Account($jwtSecret, $jwtXorKey);
            $accountId = $account->verifyToken($token, $_SERVER['REMOTE_ADDR']);

            if (!$accountId) {
                # Token inválido/expirado — limpiar auth previa para que no siga recibiendo pushes
                $this->clearAuth($fd);
                return;
            }

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

            # Validate scope from JWT against ACL
            if ($scopeEntityId > 0 && !Profile::canAccessScope($scopeEntityId)) {
                Log::logError('SECURITY: JWT_SCOPE_MISMATCH', [
                    'account_id' => $accountId,
                    'profile_id' => Profile::ctx()->profileId,
                    'jwt_scope' => $scopeEntityId,
                    'device_hash' => $deviceHash,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                $scopeEntityId = Profile::ctx()->entityId;
            }
            Profile::ctx()->scopeEntityId = $scopeEntityId;

            # Guardar sesión WS (array + Swoole\Table)
            $this->authenticatedConnections[$fd] = [
                'token' => $token,
                'account_id' => $accountId,
                'profile_id' => Profile::ctx()->profileId,
                'scope_entity_id' => $scopeEntityId,
                'device_hash' => $deviceHash
            ];

            if ($this->authTable) {
                $ok = $this->authTable->set((string)$fd, [
                    'token' => $token,
                    'account_id' => (int)$accountId,
                    'profile_id' => Profile::ctx()->profileId,
                    'device_hash' => $deviceHash,
                    'scope_entity_id' => (int)$scopeEntityId
                ]);
                if (!$ok) {
                    Log::logError('authTable FULL — cannot store auth', ['fd' => $fd, 'profile_id' => Profile::ctx()->profileId]);
                }
            }

            # Set permissions

        } catch (\Exception $e) {
            $this->clearAuth($fd);
            Log::logWarning("Profile load failed", ['fd' => $fd, 'error' => $e->getMessage()]);
        }
    }

    # Compara device_hash del JWT (source of truth) contra meta.fingerprint del mensaje
    # Configurable via DEVICE_FINGERPRINT_MODE: off | log | strict
    private function verifyDeviceFingerprint(int $fd, array $data): void
    {
        $mode = Config::get('DEVICE_FINGERPRINT_MODE', 'log');
        if ($mode === 'off') return;

        # device_hash del JWT almacenado en auth
        $storedHash = '';
        if (isset($this->authenticatedConnections[$fd]['device_hash'])) {
            $storedHash = $this->authenticatedConnections[$fd]['device_hash'];
        } elseif ($this->authTable && $this->authTable->exists((string)$fd)) {
            $storedHash = $this->authTable->get((string)$fd)['device_hash'] ?? '';
        }

        # Token legacy sin device_hash → skip
        if (empty($storedHash)) return;

        $clientFingerprint = $data['meta']['fingerprint'] ?? '';
        if (empty($clientFingerprint)) return;

        if ($storedHash !== $clientFingerprint) {
            Log::logWarning('Device fingerprint mismatch', [
                'fd' => $fd,
                'stored' => $storedHash,
                'received' => $clientFingerprint,
                'account_id' => $this->authenticatedConnections[$fd]['account_id'] ?? 0,
                'mode' => $mode
            ]);

            if ($mode === 'strict') {
                $this->server->push($fd, json_encode([
                    'type' => 'error',
                    'event' => 'device_mismatch',
                    'message' => 'Device fingerprint mismatch',
                    'timestamp' => time()
                ]));
                $this->server->close($fd);
            }
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
        # O(1) cleanup via índice inverso (no itera toda channelsTable)
        if (isset($this->fdChannels[$fd])) {
            foreach ($this->fdChannels[$fd] as $channel) {
                $this->channelsTable->del($this->channelKey($channel, $fd));
            }
            unset($this->fdChannels[$fd]);
        }

        # Limpiar autenticación
        if ($this->authTable->exists((string)$fd)) {
            $user = $this->authTable->get((string)$fd);
            $this->info("User disconnected: fd={$fd}, account_id={$user['account_id']}, profile_id={$user['profile_id']}");
            $this->authTable->del((string)$fd);
        } else {
            $this->info("Connection closed: fd={$fd}");
        }

        # Cleanup per-worker state
        unset($this->authenticatedConnections[$fd]);
        $this->rateLimitTable->del((string)$fd);
    }

    private function sendError(Swoole\WebSocket\Server $server, int $fd, string $message, ?string $correlationId = null, int $statusCode = 400): void
    {
        $server->push($fd, json_encode([
            'type' => 'error',
            'correlation_id' => $correlationId,
            'status' => 'error',
            'status_code' => $statusCode,
            'message' => $message,
            'timestamp' => time()
        ]));
    }

    # Channel key: separador \x00 evita colisiones cuando channel name contiene ":"
    private function channelKey(string $channel, int $fd): string
    {
        return $channel . "\x00" . $fd;
    }

    # Token bucket rate limiter per FD
    private function checkRateLimit(int $fd): bool
    {
        $maxPerSec = (float)Config::get('CHANNEL_RATE_LIMIT_PER_SEC', 20);
        $burst = (float)Config::get('CHANNEL_RATE_LIMIT_BURST', 30);
        $now = microtime(true);
        $key = (string)$fd;

        if (!$this->rateLimitTable->exists($key)) {
            $this->rateLimitTable->set($key, ['tokens' => $burst - 1, 'last_ts' => $now]);
            return true;
        }

        $row = $this->rateLimitTable->get($key);
        $elapsed = $now - $row['last_ts'];
        $tokens = min($burst, $row['tokens'] + $elapsed * $maxPerSec);

        if ($tokens < 1.0) {
            return false;
        }

        $this->rateLimitTable->set($key, ['tokens' => $tokens - 1, 'last_ts' => $now]);
        return true;
    }

    # Limpia auth de un FD (JWT expirado, error, desconexión)
    private function clearAuth(int $fd): void
    {
        unset($this->authenticatedConnections[$fd]);
        if ($this->authTable) {
            $this->authTable->del((string)$fd);
        }
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

# Protección contra doble inicio en el mismo puerto
$checkSocket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 1);
if ($checkSocket) {
    fclose($checkSocket);
    $existingPid = trim(shell_exec("lsof -ti tcp:{$port} 2>/dev/null | head -1") ?? '');
    fwrite(STDERR, "[ERROR] Puerto {$host}:{$port} ya está en uso (PID: {$existingPid})\n");
    fwrite(STDERR, "[ERROR] Cierre el proceso existente: kill {$existingPid}\n");
    exit(1);
}

$server = new ChannelServer($host, $port);
$server->start();
