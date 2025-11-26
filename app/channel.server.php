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
use bX\Async\TaskRouter;
use bX\Async\SwooleResponseBus;
use bX\Async\SwooleAsyncBusAdapter;

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

            // Load WebSocket routes
            Router::load([
                'find_str' => __DIR__ . '/../custom/ws',
                'pattern' => '*.endpoint.php'
            ]);

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

            if (!$type) {
                $this->sendError($server, $fd, 'Missing message type');
                return;
            }

            # Map WebSocket message type to Router endpoint
            # type: 'auth' -> WS /ws/auth
            # type: 'subscribe' -> WS /ws/subscribe
            $uri = "/ws/{$type}";

            # Setup context for the endpoint
            $_SERVER['REQUEST_METHOD'] = 'WS';
            $_SERVER['REQUEST_URI'] = $uri;
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            $_POST = $data;

            # Inject WebSocket-specific context
            $_SERVER['WS_SERVER'] = $server;
            $_SERVER['WS_FD'] = $fd;
            $_SERVER['WS_AUTHENTICATED_CONNECTIONS'] = &$this->authenticatedConnections;
            $_SERVER['WS_CHANNELS'] = &$this->channels;

            # Initialize Args
            new \bX\Args();

            # Initialize Router (required before dispatch)
            $route = new Router($uri, '/ws');

            # Capture output
            ob_start();

            try {
                # Execute Router
                Router::dispatch('WS', $uri);

                $output = ob_get_clean();

                # Send response to client
                if (!empty($output)) {
                    $server->push($fd, $output);
                }

            } catch (\Exception $e) {
                ob_end_clean();
                $this->sendError($server, $fd, $e->getMessage());
                Log::logError("ChannelServer Router: " . $e->getMessage(), ['type' => $type, 'fd' => $fd]);
            }

        } catch (\Exception $e) {
            $this->error("Error processing message from fd={$fd}: " . $e->getMessage());
            $this->sendError($server, $fd, 'Internal server error');
            Log::logError("ChannelServer: " . $e->getMessage());
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
