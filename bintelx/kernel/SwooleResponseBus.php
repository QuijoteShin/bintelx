<?php

namespace bX\Core\Async;

use Swoole\WebSocket\Server;

/**
 * Swoole implementation of ResponseBusInterface.
 * Sends responses back to WebSocket clients.
 */
class SwooleResponseBus implements ResponseBusInterface
{
    protected Server $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /**
     * {@inheritDoc}
     */
    public function sendSuccess(string $fd, mixed $data, string $msg = 'OK'): void
    {
        $response = [
            'type' => 'response',
            'status' => 'success',
            'message' => $msg,
            'data' => $data,
            'timestamp' => microtime(true)
        ];

        $this->push($fd, $response);
    }

    /**
     * {@inheritDoc}
     */
    public function sendError(string $fd, string $error, int $code = 500): void
    {
        $response = [
            'type' => 'response',
            'status' => 'error',
            'code' => $code,
            'error' => $error,
            'timestamp' => microtime(true)
        ];

        $this->push($fd, $response);
    }

    /**
     * {@inheritDoc}
     */
    public function sendProgress(string $fd, int $percentage, string $status): void
    {
        $response = [
            'type' => 'progress',
            'percentage' => max(0, min(100, $percentage)),
            'status' => $status,
            'timestamp' => microtime(true)
        ];

        $this->push($fd, $response);
    }

    /**
     * Internal push method with connection validation
     */
    protected function push(string $fd, array $data): void
    {
        $fdInt = (int)$fd;

        if (!$this->server->isEstablished($fdInt)) {
            error_log("[SwooleResponseBus] Connection not established: fd={$fdInt}");
            return;
        }

        $json = json_encode($data);

        if ($json === false) {
            error_log("[SwooleResponseBus] JSON encode failed for fd={$fdInt}");
            return;
        }

        $result = $this->server->push($fdInt, $json);

        if (!$result) {
            error_log("[SwooleResponseBus] Failed to push message to fd={$fdInt}");
        }
    }
}
