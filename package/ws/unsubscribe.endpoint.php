<?php # package/ws/unsubscribe.endpoint.php
namespace ws;

use bX\Router;
use bX\Response;
use bX\Log;
use bX\ChannelContext;

/**
 * @endpoint   /ws/unsubscribe
 * @method     WS
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Unsubscribes from a channel
 * @body       (JSON) {"channel": "..."}
 * @tag        WebSocket
 */
Router::register(['POST'], 'unsubscribe', function(...$params) {
    $server = ChannelContext::$server;
    $fd = ChannelContext::getWsFd();
    $channelsTable = ChannelContext::$channelsTable;

    $channel = $_POST['channel'] ?? null;

    if (!$channel) {
        return Response::json([
            'type' => 'error',
            'message' => 'Missing channel name',
            'timestamp' => time()
        ]);
        return;
    }

    # Remove from Swoole\Table — key format: "{channel}\x00{fd}"
    $channelsTable->del($channel . "\x00" . $fd);

    return Response::json([
        'type' => 'unsubscribe',
        'success' => true,
        'channel' => $channel,
        'timestamp' => time()
    ]);

    Log::logInfo("WebSocket: Unsubscribed from channel", ['fd' => $fd, 'channel' => $channel]);
}, ROUTER_SCOPE_PUBLIC);
