<?php

namespace App\Workers;

use Swoole\Server;
use bX\Log;
use bX\CONN;

/**
 * VectorWorker - Handles vectorization jobs
 *
 * Sends data to Python Grid for vectorization via WebSocket
 * Does NOT wait for response (async pattern)
 */
class VectorWorker
{
    protected Server $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /**
     * Handles vectorization job
     *
     * @param array $payload Expected: ['doc_id' => int, 'text' => string, 'correlation_id' => string]
     * @return void
     */
    public function handle(array $payload): void
    {
        $docId = $payload['doc_id'] ?? null;
        $text = $payload['text'] ?? '';
        $correlationId = $payload['correlation_id'] ?? uniqid('vec_', true);

        Log::logInfo("VectorWorker: Processing vectorization", [
            'doc_id' => $docId,
            'correlation_id' => $correlationId,
            'text_length' => strlen($text)
        ]);

        // Prepare message for Python Grid
        $gridMessage = [
            'action' => 'vectorize',
            'correlation_id' => $correlationId,
            'payload' => [
                'text' => $text,
                'model' => 'all-MiniLM-L6-v2',
                'doc_id' => $docId
            ]
        ];

        // Send to Python Grid via WebSocket (connection to Grid server)
        // In production, this would be a persistent connection to Python worker
        // For now, we log it as example
        $this->sendToGrid($gridMessage);

        Log::logInfo("VectorWorker: Sent to Grid", [
            'correlation_id' => $correlationId,
            'doc_id' => $docId
        ]);

        // CRITICAL: Worker terminates here. Does NOT wait for response.
        // Response will arrive later via 'grid.response' message type
    }

    /**
     * Sends message to Python Grid
     * In production, this connects to Python WebSocket server
     */
    protected function sendToGrid(array $message): void
    {
        // Example implementation: Connect to Python Grid WebSocket
        // In real implementation, maintain persistent connection pool

        $gridHost = \bX\Config::get('GRID_HOST', '127.0.0.1');
        $gridPort = \bX\Config::getInt('GRID_PORT', 9600);

        try {
            // For demo purposes, we just log
            Log::logDebug("VectorWorker: Would send to Grid", [
                'grid_host' => $gridHost,
                'grid_port' => $gridPort,
                'message' => $message
            ]);

            // Real implementation would use:
            // $client = new Swoole\Coroutine\Http\Client($gridHost, $gridPort);
            // $client->upgrade('/');
            // $client->push(json_encode($message));
            // $client->close();

        } catch (\Exception $e) {
            Log::logError("VectorWorker: Failed to send to Grid", [
                'error' => $e->getMessage(),
                'correlation_id' => $message['correlation_id']
            ]);
        }
    }
}
