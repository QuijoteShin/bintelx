<?php

namespace App\Workers;

use Swoole\Server;
use bX\Log;
use bX\CONN;

/**
 * RouterWorker - Analyzes message intent and routes to appropriate action
 *
 * Example job handler for 'job.analyze.intent' subject
 */
class RouterWorker
{
    protected Server $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /**
     * Handles intent analysis job
     *
     * @param array $payload Expected: ['msg_id' => int, 'text' => string, 'trace_id' => string]
     * @return void
     */
    public function handle(array $payload): void
    {
        $msgId = $payload['msg_id'] ?? null;
        $text = $payload['text'] ?? '';
        $traceId = $payload['trace_id'] ?? 'unknown';

        Log::logInfo("RouterWorker: Analyzing intent", [
            'msg_id' => $msgId,
            'trace_id' => $traceId,
            'text_length' => strlen($text)
        ]);

        // Example: Simple keyword-based intent detection
        $intent = $this->detectIntent($text);

        // Store result in database
        if ($msgId) {
            $this->storeIntentResult($msgId, $intent);
        }

        Log::logInfo("RouterWorker: Intent detected", [
            'msg_id' => $msgId,
            'intent' => $intent,
            'trace_id' => $traceId
        ]);

        // Example: If intent requires vectorization, publish another job
        if ($intent === 'needs_vectorization') {
            // This would publish to 'job.vectorize'
            // $this->server->task(['subject' => 'job.vectorize', 'payload' => ...]);
        }
    }

    /**
     * Simple intent detection (replace with real ML/AI)
     */
    protected function detectIntent(string $text): string
    {
        $text = strtolower($text);

        if (str_contains($text, 'help') || str_contains($text, 'ayuda')) {
            return 'help_request';
        }

        if (str_contains($text, 'cancel') || str_contains($text, 'cancelar')) {
            return 'cancel_request';
        }

        if (strlen($text) > 200) {
            return 'needs_vectorization';
        }

        return 'general_message';
    }

    /**
     * Stores intent analysis result
     */
    protected function storeIntentResult(int $msgId, string $intent): void
    {
        $sql = "UPDATE messages SET intent = :intent, analyzed_at = NOW() WHERE id = :id";

        try {
            CONN::nodml($sql, [
                ':id' => $msgId,
                ':intent' => $intent
            ]);
        } catch (\Exception $e) {
            Log::logError("RouterWorker: Failed to store intent", [
                'msg_id' => $msgId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
