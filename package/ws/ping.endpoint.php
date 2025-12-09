<?php # custom/ws/ping.endpoint.php
namespace ws;

use bX\Router;
use bX\Response;

/**
 * @endpoint   /ws/ping
 * @method     WS
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Simple heartbeat endpoint to keep connection alive
 * @tag        WebSocket
 */
Router::register(['GET'], 'ping', function(...$params) {
    return Response::json([
        'type' => 'pong',
        'timestamp' => time()
    ]);
}, ROUTER_SCOPE_PUBLIC);
