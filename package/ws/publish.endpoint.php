<?php # custom/ws/publish.endpoint.php
namespace ws;

use bX\Router;
use bX\Response;
use bX\Log;
use bX\CONN;
use bX\Channel\MessagePersistence;

/**
 * @endpoint   /ws/publish
 * @method     WS
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Publishes a message to all subscribers of a channel
 * @body       (JSON) {"channel": "...", "message": {...}}
 * @tag        WebSocket
 */
Router::register(['POST'], 'publish', function(...$params) {
    $server = $_SERVER['WS_SERVER'];
    $fd = $_SERVER['WS_FD'];
    $channelsTable = $_SERVER['WS_CHANNELS_TABLE'];
    $authTable = $_SERVER['WS_AUTH_TABLE'];

    # Require authentication
    if (!$authTable->exists((string)$fd)) {
        return Response::error('Authentication required', 401);
    }

    $channel = $_POST['channel'] ?? null;
    $message = $_POST['message'] ?? null;

    if (!$channel || !$message) {
        return Response::error('Missing channel or message', 400);
    }

    $user = $authTable->get((string)$fd);
    $messageId = MessagePersistence::generateMessageId();

    $messageData = [
        'type' => 'message',
        'message_id' => $messageId,
        'channel' => $channel,
        'message' => $message,
        'from' => [
            'account_id' => $user['account_id'],
            'profile_id' => $user['profile_id']
        ],
        'timestamp' => time()
    ];

    $payload = json_encode($messageData);

    # Broadcast a TODOS los suscriptores del canal (Swoole\Table compartida)
    $sent = 0;
    $prefix = $channel . ':';
    $channelLen = strlen($prefix);
    foreach ($channelsTable as $key => $row) {
        if (strncmp($key, $prefix, $channelLen) === 0) {
            $recipientFd = (int)substr($key, $channelLen);
            if ($server->isEstablished($recipientFd)) {
                $server->push($recipientFd, $payload);
                $sent++;
            }
        }
    }

    # Encolar persistencia asíncrona
    $server->task([
        'type' => 'channel.persist',
        'payload' => [
            'message_id' => $messageId,
            'channel' => $channel,
            'message' => $message,
            'profile_id' => $user['profile_id'] ?? null,
            'account_id' => $user['account_id'] ?? null,
            'message_type' => 'text',
            'priority' => 'normal'
        ]
    ]);

    Log::logInfo("WebSocket: Message published", [
        'fd' => $fd,
        'channel' => $channel,
        'message_id' => $messageId,
        'sent_online' => $sent
    ]);

    return Response::json([
        'type' => 'publish',
        'success' => true,
        'message_id' => $messageId,
        'channel' => $channel,
        'sent_to' => $sent,
        'persist_queued' => true,
        'data' => [
            'message_id' => $messageId
        ],
        'timestamp' => time()
    ]);
}, ROUTER_SCOPE_PUBLIC);
