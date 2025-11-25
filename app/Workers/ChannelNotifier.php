<?php

namespace App\Workers;

use Swoole\Server;
use bX\Log;

/**
 * ChannelNotifier - Broadcasts messages to WebSocket channels
 *
 * Example job handler for 'event.chat.reply' subject
 */
class ChannelNotifier
{
    protected Server $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /**
     * Handles channel notification job
     *
     * @param array $payload Expected: ['channel' => string, 'message' => mixed, 'exclude_fd' => int]
     * @return void
     */
    public function handle(array $payload): void
    {
        $channel = $payload['channel'] ?? null;
        $message = $payload['message'] ?? null;
        $excludeFd = $payload['exclude_fd'] ?? null;

        if (!$channel || !$message) {
            Log::logWarning("ChannelNotifier: Missing channel or message");
            return;
        }

        Log::logInfo("ChannelNotifier: Broadcasting to channel", [
            'channel' => $channel,
            'exclude_fd' => $excludeFd
        ]);

        // Get all subscribers to this channel
        // In real implementation, this would query a shared state (Redis, Table, etc.)
        $subscribers = $this->getChannelSubscribers($channel);

        $sent = 0;
        foreach ($subscribers as $fd) {
            // Skip sender if exclude_fd is set
            if ($excludeFd && $fd === $excludeFd) {
                continue;
            }

            // Check if connection is still valid
            if (!$this->server->isEstablished($fd)) {
                continue;
            }

            // Send message
            $envelope = [
                'type' => 'channel_message',
                'channel' => $channel,
                'message' => $message,
                'timestamp' => time()
            ];

            if ($this->server->push($fd, json_encode($envelope))) {
                $sent++;
            }
        }

        Log::logInfo("ChannelNotifier: Broadcast completed", [
            'channel' => $channel,
            'subscribers' => count($subscribers),
            'sent' => $sent
        ]);
    }

    /**
     * Gets subscribers for a channel
     * In production, this would query Redis or Swoole\Table
     */
    protected function getChannelSubscribers(string $channel): array
    {
        // Example: In production, use Redis or Swoole\Table
        // For now, return empty (would need access to ChannelServer->channels)

        // Real implementation:
        // $redis = new Redis();
        // $redis->connect('127.0.0.1', 6379);
        // return $redis->sMembers("channel:$channel:subscribers");

        return [];
    }
}
