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

use bX\Config;
use bX\JWT;
use bX\Log;
use bX\Router;
use bX\Core\Async\TaskRouter;
use bX\Core\Async\SwooleResponseBus;
use bX\Core\Async\SwooleAsyncBusAdapter;

class ChannelServer
{
    private $server;
    private array $channels = [];
    private array $authenticatedConnections = [];
    private ?TaskRouter $taskRouter = null;
    private ?SwooleResponseBus $responseBus = null;
    private ?SwooleAsyncBusAdapter $asyncBus = null;

    private string $host;
    private int $port;

    public function __construct(string $host = '0.0.0.0', int $port = 9501)
    {
        $this->host = $host;
        $this->port = $port;

        if (!extension_loaded('swoole')) {
            $this->error('Swoole extension is not loaded. Install with: pecl install swoole');
            exit(1);
        }

        $this->info("Initializing Bintelx Channel Server...");
        $this->info("Host: {$host}, Port: {$port}");

        $this->server = new Swoole\WebSocket\Server($host, $port);

        $this->server->set([
            'worker_num' => swoole_cpu_num() * 2,
            'task_worker_num' => swoole_cpu_num(),
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
        $this->info("Workers: {$server->setting['worker_num']}");
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
                'find_str' => __DIR__ . '/../custom',
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

            switch ($type) {
                case 'auth':
                    $this->handleAuth($server, $fd, $data);
                    break;

                case 'subscribe':
                    $this->handleSubscribe($server, $fd, $data);
                    break;

                case 'unsubscribe':
                    $this->handleUnsubscribe($server, $fd, $data);
                    break;

                case 'publish':
                    $this->handlePublish($server, $fd, $data);
                    break;

                case 'ping':
                    $server->push($fd, json_encode(['type' => 'pong', 'timestamp' => time()]));
                    break;

                case 'endpoint':
                    $this->handleEndpointRequest($server, $fd, $data);
                    break;

                default:
                    $this->sendError($server, $fd, "Unknown message type: {$type}");
            }

        } catch (\Exception $e) {
            $this->error("Error processing message from fd={$fd}: " . $e->getMessage());
            $this->sendError($server, $fd, 'Internal server error');
            Log::logError("ChannelServer: " . $e->getMessage());
        }
    }

    private function handleAuth(Swoole\WebSocket\Server $server, int $fd, array $data): void
    {
        $token = $data['token'] ?? null;

        if (!$token) {
            $this->sendError($server, $fd, 'Missing authentication token');
            return;
        }

        try {
            $payload = JWT::decode($token);

            $this->authenticatedConnections[$fd] = [
                'user_id' => $payload['user_id'] ?? null,
                'username' => $payload['username'] ?? null,
                'roles' => $payload['roles'] ?? [],
                'authenticated_at' => time()
            ];

            $server->push($fd, json_encode([
                'type' => 'auth',
                'success' => true,
                'user' => [
                    'id' => $payload['user_id'] ?? null,
                    'username' => $payload['username'] ?? null
                ],
                'timestamp' => time()
            ]));

            $this->success("User authenticated: fd={$fd}, user_id={$payload['user_id']}");

        } catch (\Exception $e) {
            $this->sendError($server, $fd, 'Invalid or expired token');
            $this->warning("Authentication failed for fd={$fd}: " . $e->getMessage());
        }
    }

    private function handleSubscribe(Swoole\WebSocket\Server $server, int $fd, array $data): void
    {
        if (!$this->isAuthenticated($fd)) {
            $this->sendError($server, $fd, 'Authentication required');
            return;
        }

        $channel = $data['channel'] ?? null;

        if (!$channel) {
            $this->sendError($server, $fd, 'Missing channel name');
            return;
        }

        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = [];
        }

        if (!in_array($fd, $this->channels[$channel])) {
            $this->channels[$channel][] = $fd;
        }

        $server->push($fd, json_encode([
            'type' => 'subscribe',
            'success' => true,
            'channel' => $channel,
            'subscribers' => count($this->channels[$channel]),
            'timestamp' => time()
        ]));

        $this->info("fd={$fd} subscribed to channel: {$channel}");
    }

    private function handleUnsubscribe(Swoole\WebSocket\Server $server, int $fd, array $data): void
    {
        $channel = $data['channel'] ?? null;

        if (!$channel) {
            $this->sendError($server, $fd, 'Missing channel name');
            return;
        }

        if (isset($this->channels[$channel])) {
            $this->channels[$channel] = array_filter(
                $this->channels[$channel],
                fn($connFd) => $connFd !== $fd
            );

            if (empty($this->channels[$channel])) {
                unset($this->channels[$channel]);
            }
        }

        $server->push($fd, json_encode([
            'type' => 'unsubscribe',
            'success' => true,
            'channel' => $channel,
            'timestamp' => time()
        ]));

        $this->info("fd={$fd} unsubscribed from channel: {$channel}");
    }

    private function handlePublish(Swoole\WebSocket\Server $server, int $fd, array $data): void
    {
        if (!$this->isAuthenticated($fd)) {
            $this->sendError($server, $fd, 'Authentication required');
            return;
        }

        $channel = $data['channel'] ?? null;
        $message = $data['message'] ?? null;

        if (!$channel || !$message) {
            $this->sendError($server, $fd, 'Missing channel or message');
            return;
        }

        if (!isset($this->channels[$channel])) {
            $this->sendError($server, $fd, "Channel not found: {$channel}");
            return;
        }

        $user = $this->authenticatedConnections[$fd];
        $payload = json_encode([
            'type' => 'message',
            'channel' => $channel,
            'message' => $message,
            'from' => [
                'user_id' => $user['user_id'],
                'username' => $user['username']
            ],
            'timestamp' => time()
        ]);

        $sent = 0;
        foreach ($this->channels[$channel] as $subscriberFd) {
            if ($server->exist($subscriberFd)) {
                $server->push($subscriberFd, $payload);
                $sent++;
            }
        }

        $server->push($fd, json_encode([
            'type' => 'publish',
            'success' => true,
            'channel' => $channel,
            'sent_to' => $sent,
            'timestamp' => time()
        ]));

        $this->info("Message published to channel '{$channel}': {$sent} subscribers");
    }

    private function handleEndpointRequest(Swoole\WebSocket\Server $server, int $fd, array $data): void
    {
        if (!$this->asyncBus) {
            $this->sendError($server, $fd, 'AsyncBus not available in this worker');
            return;
        }

        $method = strtoupper($data['method'] ?? 'GET');
        $uri = $data['uri'] ?? null;
        $body = $data['body'] ?? [];
        $headers = $data['headers'] ?? [];

        if (!$uri) {
            $this->sendError($server, $fd, 'Missing uri parameter');
            return;
        }

        // Add client_fd to meta for response routing
        $correlationId = $data['correlation_id'] ?? uniqid('req_', true);
        $headers['X-Trace-ID'] = $correlationId;
        $headers['X-Client-FD'] = (string)$fd;

        // Dispatch to Task Worker
        $taskId = $this->asyncBus->executeEndpoint($uri, $method, $body, $headers);

        // Send acknowledgment
        $server->push($fd, json_encode([
            'type' => 'endpoint_queued',
            'correlation_id' => $correlationId,
            'task_id' => $taskId,
            'timestamp' => time()
        ]));

        $this->info("fd={$fd} endpoint queued: {$method} {$uri} (correlation_id: {$correlationId})");
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
            $this->info("User disconnected: fd={$fd}, user_id={$user['user_id']}");
            unset($this->authenticatedConnections[$fd]);
        } else {
            $this->info("Connection closed: fd={$fd}");
        }
    }

    private function isAuthenticated(int $fd): bool
    {
        return isset($this->authenticatedConnections[$fd]);
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

    public function start(): void
    {
        $this->server->start();
    }
}

$host = Config::get('CHANNEL_HOST', '0.0.0.0');
$port = Config::getInt('CHANNEL_PORT', 9501);

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
