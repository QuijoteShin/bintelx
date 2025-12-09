<?php # custom/ws/unsubscribe.endpoint.php
namespace ws;

use bX\Router;
use bX\Response;
use bX\Log;

/**
 * @endpoint   /ws/unsubscribe
 * @method     WS
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Unsubscribes from a channel
 * @body       (JSON) {"channel": "..."}
 * @tag        WebSocket
 */
Router::register(['POST'], 'unsubscribe', function(...$params) {
    $server = $_SERVER['WS_SERVER'];
    $fd = $_SERVER['WS_FD'];
    $channelsTable = $_SERVER['WS_CHANNELS_TABLE'];

    $channel = $_POST['channel'] ?? null;

    if (!$channel) {
        return Response::json([
            'type' => 'error',
            'message' => 'Missing channel name',
            'timestamp' => time()
        ]);
        return;
    }

    # Remove from Swoole\Table
    $channelsTable->del($channel . ':' . $fd);

    return Response::json([
        'type' => 'unsubscribe',
        'success' => true,
        'channel' => $channel,
        'timestamp' => time()
    ]);

    Log::logInfo("WebSocket: Unsubscribed from channel", ['fd' => $fd, 'channel' => $channel]);
}, ROUTER_SCOPE_PUBLIC);
