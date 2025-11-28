<?php # bintelx/kernel/Async/TaskRouter.php

namespace bX\Async;

use Swoole\Server;
use Swoole\ExitException;
use bX\Router;
use bX\CONN;
use bX\Profile;
use bX\Log;
use bX\SuperGlobalHydrator;

# The Brain of the Async Grid
# Manages task routing and memory isolation (Sandbox) to prevent state pollution in workers
class TaskRouter
{
    protected Server $server;
    protected array $handlers = [];
    protected ?ResponseBusInterface $responseBus = null;

    public function __construct(Server $server, ?ResponseBusInterface $responseBus = null)
    {
        $this->server = $server;
        $this->responseBus = $responseBus;
        $this->registerHandlers();
    }

    /**
     * Worker Registry.
     * Maps subjects to handler classes
     */
    protected function registerHandlers(): void
    {
        $this->handlers = [
            'job.analyze.intent' => \App\Workers\RouterWorker::class,
            'job.vectorize' => \App\Workers\VectorWorker::class,
            'event.chat.reply' => \App\Workers\ChannelNotifier::class,
        ];
    }

    /**
     * Main method executed on the 'onTask' event.
     */
    public function route(Server $server, int $taskId, int $srcWorkerId, mixed $data): void
    {
        # 1. SNAPSHOT (Backup Superglobals to prevent contamination between tasks)
        $snapshot = SuperGlobalHydrator::snapshot();

        $initialObLevel = ob_get_level();
        $correlationId = $data['meta']['correlation_id'] ?? null;
        $jobId = null;

        try {
            if (!is_array($data) || !isset($data['type'])) {
                throw new \InvalidArgumentException("Invalid task format. 'type' required.");
            }

            $type = $data['type'];

            // Persist job to sys_jobs if it's a job type
            if ($type === 'job' && $correlationId) {
                $jobId = $this->persistJob($correlationId, $data['subject'], $data['payload']);
                $this->updateJobStatus($jobId, 'PROCESSING');
            }

            switch ($type) {
                case 'endpoint':
                    $this->handleEndpointExecution($data['request'], $data['meta']);
                    break;

                case 'job':
                    $this->handleJobDispatch($data['subject'], $data['payload'], $data['meta']);
                    break;

                case 'grid.response':
                    $this->handleGridResponse($data['payload'], $data['meta']);
                    break;

                default:
                    error_log("[TaskRouter] Unknown task type: {$type}");
            }

            // Mark job as completed
            if ($jobId) {
                $this->updateJobStatus($jobId, 'COMPLETED');
            }

        } catch (ExitException $e) {
            // CRITICAL: Catch exit() or die() in legacy code to prevent killing the Worker process
            error_log("[TaskRouter] Script attempted exit(): " . $e->getMessage());
            if ($jobId) {
                $this->updateJobStatus($jobId, 'FAILED', ['error' => 'Script used exit/die']);
            }
        } catch (\Throwable $e) {
            error_log("[TaskRouter] Exception: " . $e->getMessage());
            Log::logError("TaskRouter Exception", [
                'message' => $e->getMessage(),
                'correlation_id' => $correlationId,
                'trace' => $e->getTraceAsString()
            ]);
            if ($jobId) {
                $this->updateJobStatus($jobId, 'FAILED', ['error' => $e->getMessage()]);
            }
        } finally {
            # 2. RESTORE (Forensic Cleanup)

            # A. Restore Superglobals
            SuperGlobalHydrator::restore($snapshot);

            # B. Clean residual Output Buffers
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }

            # C. Reset static Profile data (CRITICAL for security)
            Profile::resetStaticProfileData();
        }
    }

    protected function handleEndpointExecution(array $request, array $meta): void
    {
        $correlationId = $meta['correlation_id'] ?? 'unknown';
        $method = $request['method'];
        $uri = $request['uri'];
        $data = $request['data'] ?? [];
        $headers = $request['headers'] ?? [];

        # Hidratación de superglobales
        SuperGlobalHydrator::hydrate([
            'method' => $method,
            'uri' => $uri,
            'headers' => $headers,
            'body' => in_array($method, ['POST', 'PUT', 'PATCH']) ? $data : [],
            'query' => $method === 'GET' ? $data : [],
            'remote_addr' => '127.0.0.1' # Internal call
        ]);

        # Hidratación de Args
        SuperGlobalHydrator::hydrateArgs($method, $data, $data);

        // Set timezone for DB
        CONN::nodml("SET time_zone = '" . $_SERVER["HTTP_X_USER_TIMEZONE"] . "'");

        // JWT Authentication (como api.php)
        $token = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (!empty($token)) {
            $jwtSecret = \bX\Config::get('JWT_SECRET');
            $jwtXorKey = \bX\Config::get('JWT_XOR_KEY', '');

            if ($jwtSecret) {
                $account = new \bX\Account($jwtSecret, $jwtXorKey);
                $accountId = $account->verifyToken($token, $_SERVER["REMOTE_ADDR"]);

                if ($accountId) {
                    $profile = new \bX\Profile();
                    if ($profile->load(['account_id' => $accountId])) {
                        Router::$currentUserPermissions = \bX\Profile::getRoutePermissions();
                    }
                }
            }
        }

        // Extract module from URI
        $module = explode('/', $uri)[2] ?? 'default';

        // Load routes for this module (como api.php)
        Router::load([
            'find_str' => \bX\WarmUp::$BINTELX_HOME . '../custom/',
            'pattern' => '{*/,}*{endpoint,controller}.php'
        ], function($routeFileContext) use ($module) {
            if (is_file($routeFileContext['real']) && strpos($routeFileContext['real'], "/$module/") > 1) {
                require_once $routeFileContext['real'];
            }
        });

        // Initialize Router (como api.php)
        $route = new Router($uri, '/api');

        // Capture Router output
        ob_start();

        try {
            // Execute Bintelx Router
            Router::dispatch($method, $uri);

            $output = ob_get_clean();

            // Extract JSON from output (skip any echo statements)
            $jsonStart = strpos($output, '{');
            if ($jsonStart !== false) {
                $output = substr($output, $jsonStart);
            }

            $result = json_decode($output, true) ?? ['raw' => $output];

            // Send response if ResponseBus available
            if ($this->responseBus && isset($meta['client_fd'])) {
                $this->responseBus->sendSuccess($meta['client_fd'], $result);
            }

            Log::logInfo("TaskRouter: Endpoint executed successfully", [
                'correlation_id' => $correlationId,
                'uri' => $uri,
                'method' => $method
            ]);

        } catch (\Exception $e) {
            ob_end_clean();

            $error = $e->getMessage();
            Log::logError("TaskRouter: Endpoint execution failed", [
                'correlation_id' => $correlationId,
                'uri' => $uri,
                'error' => $error
            ]);

            if ($this->responseBus && isset($meta['client_fd'])) {
                $this->responseBus->sendError($meta['client_fd'], $error);
            }
        }
    }

    protected function handleJobDispatch(string $subject, mixed $payload, array $meta): void
    {
        $correlationId = $meta['correlation_id'] ?? 'unknown';

        if (isset($this->handlers[$subject])) {
            $handlerClass = $this->handlers[$subject];
            $worker = new $handlerClass($this->server);
            $worker->handle($payload);

            Log::logInfo("TaskRouter: Job dispatched", [
                'correlation_id' => $correlationId,
                'subject' => $subject
            ]);
        } else {
            Log::logWarning("TaskRouter: No handler registered", [
                'correlation_id' => $correlationId,
                'subject' => $subject
            ]);
        }
    }

    protected function handleGridResponse(mixed $payload, array $meta): void
    {
        $correlationId = $meta['correlation_id'] ?? null;

        if (!$correlationId) {
            Log::logError("TaskRouter: Grid response missing correlation_id");
            return;
        }

        // Load original job from sys_jobs
        $job = $this->loadJob($correlationId);

        if (!$job) {
            Log::logError("TaskRouter: Job not found for grid response", [
                'correlation_id' => $correlationId
            ]);
            return;
        }

        // Update job with result
        $this->updateJobStatus($job['id'], 'COMPLETED', $payload);

        // Continue job flow (example: next step in pipeline)
        Log::logInfo("TaskRouter: Grid response processed", [
            'correlation_id' => $correlationId,
            'job_id' => $job['id']
        ]);
    }

    /**
     * Persists job to sys_jobs table
     */
    protected function persistJob(string $correlationId, string $subject, mixed $payload): int
    {
        $sql = "INSERT INTO sys_jobs (correlation_id, subject, status, payload, attempts)
                VALUES (:correlation_id, :subject, 'PENDING', :payload, 0)";

        $params = [
            ':correlation_id' => $correlationId,
            ':subject' => $subject,
            ':payload' => json_encode($payload)
        ];

        $result = CONN::nodml($sql, $params);

        if (!$result['success']) {
            Log::logError("TaskRouter: Failed to persist job", [
                'correlation_id' => $correlationId,
                'error' => $result['error'] ?? 'Unknown'
            ]);
            return 0;
        }

        return (int)$result['last_id'];
    }

    /**
     * Updates job status in sys_jobs
     */
    protected function updateJobStatus(int $jobId, string $status, mixed $result = null): void
    {
        if ($jobId === 0) return;

        $sql = "UPDATE sys_jobs
                SET status = :status,
                    result = :result,
                    attempts = attempts + 1,
                    updated_at = NOW(6)
                WHERE id = :id";

        $params = [
            ':id' => $jobId,
            ':status' => $status,
            ':result' => $result ? json_encode($result) : null
        ];

        CONN::nodml($sql, $params);
    }

    /**
     * Loads job from sys_jobs by correlation_id
     */
    protected function loadJob(string $correlationId): ?array
    {
        $sql = "SELECT * FROM sys_jobs WHERE correlation_id = :correlation_id LIMIT 1";

        $job = null;
        CONN::dml($sql, [':correlation_id' => $correlationId], function($row) use (&$job) {
            $job = $row;
            return false;
        });

        return $job;
    }
}
