<?php # package/ws/subscribe.endpoint.php
namespace ws;

use bX\Router;
use bX\Response;
use bX\Log;
use bX\Channel\MessagePersistence;
use bX\ChannelContext;

/**
 * @endpoint   /ws/subscribe
 * @method     WS
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Subscribes to a channel for real-time updates
 * @body       (JSON) {"channel": "chat.room.123"}
 * @tag        WebSocket
 */
Router::register(['POST'], 'subscribe', function(...$params) {
    $server = ChannelContext::$server;
    $fd = $_SERVER['WS_FD'];
    $channelsTable = ChannelContext::$channelsTable;
    $authTable = ChannelContext::$authTable;

    # Require authentication
    if (!$authTable->exists((string)$fd)) {
        return Response::error('Authentication required', 401);
    }

    $channel = $_POST['channel'] ?? null;

    if (!$channel) {
        return Response::error('Missing channel name', 400);
    }

    # Add to Swoole\Table (memoria compartida entre workers)
    $key = $channel . ':' . $fd;
    $channelsTable->set($key, ['subscribed' => 1]);

    # Contar suscriptores actuales de este canal
    $subscribers = 0;
    $subscribers = 0;
    $channelLen = strlen($channel) + 1;
    foreach ($channelsTable as $k => $v) {
        if (strncmp($k, $channel . ':', $channelLen) === 0) {
            $subscribers++;
        }
    }

    # Persistir suscripción en DB
    $user = $authTable->get((string)$fd);
    MessagePersistence::subscribe(
        $channel,
        $user['profile_id'],
        $user['account_id'],
        'persistent'
    );

    # Ver mensajes pendientes
    $pending = MessagePersistence::getPendingMessages($user['profile_id'], $channel);

    Log::logInfo("WebSocket: Subscribed to channel", [
        'fd' => $fd,
        'channel' => $channel,
        'subscribers' => $subscribers,
        'pending_count' => count($pending)
    ]);

    return Response::json([
        'type' => 'subscribe',
        'channel' => $channel,
        'subscribers' => $subscribers,
        'pending_count' => count($pending),
        'pending_messages' => $pending
    ]);
}, ROUTER_SCOPE_PUBLIC);
