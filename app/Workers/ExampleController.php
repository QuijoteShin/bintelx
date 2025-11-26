<?php

namespace App\Controllers;

use bX\Async\AsyncBusInterface;
use bX\Log;

/**
 * Example Controller - Demonstrates AsyncBus usage
 *
 * Shows how to use AsyncBusInterface in business logic
 */
class ExampleController
{
    protected AsyncBusInterface $bus;

    public function __construct(AsyncBusInterface $bus)
    {
        $this->bus = $bus;
    }

    /**
     * Example: Sends a chat message and triggers async analysis
     */
    public function sendChatMessage(array $data): array
    {
        $text = $data['message'] ?? '';
        $userId = $data['user_id'] ?? 0;

        // 1. Immediate persistence (synchronous)
        $msgId = $this->saveMessage($userId, $text);

        // 2. Async job - Analyze intent (non-blocking)
        $this->bus->publish('job.analyze.intent', [
            'msg_id' => $msgId,
            'text' => $text,
            'user_id' => $userId
        ], "intent_$msgId");

        // 3. Async endpoint - Track analytics (non-blocking)
        $this->bus->executeEndpoint('/api/analytics/track', 'POST', [
            'event' => 'message_sent',
            'msg_id' => $msgId,
            'user_id' => $userId
        ]);

        Log::logInfo("ExampleController: Message queued for processing", [
            'msg_id' => $msgId,
            'user_id' => $userId
        ]);

        return [
            'success' => true,
            'msg_id' => $msgId,
            'status' => 'queued'
        ];
    }

    /**
     * Example: Triggers heavy PDF processing
     */
    public function processPDF(array $data): array
    {
        $pdfPath = $data['pdf_path'] ?? '';
        $docId = $data['doc_id'] ?? 0;

        // Publish vectorization job
        $correlationId = uniqid('pdf_', true);

        $this->bus->publish('job.vectorize', [
            'doc_id' => $docId,
            'pdf_path' => $pdfPath,
            'action' => 'extract_and_vectorize'
        ], $correlationId);

        return [
            'success' => true,
            'correlation_id' => $correlationId,
            'status' => 'processing'
        ];
    }

    /**
     * Example: Sends notification to channel
     */
    public function notifyChannel(string $channel, array $message): array
    {
        // Publish to channel notifier
        $this->bus->publish('event.chat.reply', [
            'channel' => $channel,
            'message' => $message,
            'timestamp' => time()
        ]);

        return [
            'success' => true,
            'status' => 'notification_queued'
        ];
    }

    /**
     * Simulated database save
     */
    protected function saveMessage(int $userId, string $text): int
    {
        // In real implementation, save to database
        // For demo, return random ID
        return rand(1000, 9999);
    }
}
