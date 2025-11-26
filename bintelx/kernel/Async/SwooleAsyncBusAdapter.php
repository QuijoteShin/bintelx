<?php # bintelx/kernel/Async/SwooleAsyncBusAdapter.php

namespace bX\Async;

use Swoole\Server;

# Bus implementation using Swoole Task Workers
# Provides high-speed in-memory Inter-Process Communication (IPC)
class SwooleAsyncBusAdapter implements AsyncBusInterface
{
    protected Server $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /**
     * {@inheritDoc}
     */
    public function publish(string $subject, array|object $payload, ?string $correlationId = null): mixed
    {
        // Generate Trace ID if missing to ensure observability
        $correlationId = $correlationId ?? uniqid('job_', true);
        
        // Standardized Envelope Structure
        $taskData = [
            'type' => 'job',
            'subject' => $subject,
            'payload' => $payload,
            'meta' => [
                'correlation_id' => $correlationId,
                'timestamp' => microtime(true),
                'origin' => 'swoole.bus'
            ]
        ];

        // Delegate to the TaskWorker pool. Returns the TaskID (int) or false.
        $taskId = $this->server->task($taskData);
        
        if ($taskId === false) {
            // Basic error handling if the pool is exhausted
            error_log("[SwooleAsyncBus] Failed to dispatch task: Pool exhausted or server busy.");
        }

        return $taskId;
    }

    /**
     * {@inheritDoc}
     */
    public function executeEndpoint(string $uri, string $method = 'POST', array $data = [], array $headers = []): mixed
    {
        $correlationId = $headers['X-Trace-ID'] ?? uniqid('req_', true);
        $clientFd = $headers['X-Client-FD'] ?? null;

        $taskData = [
            'type' => 'endpoint',
            'request' => [
                'uri' => $uri,
                'method' => strtoupper($method),
                'data' => $data,
                'headers' => $headers
            ],
            'meta' => [
                'correlation_id' => $correlationId,
                'client_fd' => $clientFd,
                'timestamp' => microtime(true)
            ]
        ];

        return $this->server->task($taskData);
    }
}
