<?php # package/_internal/health.endpoint.php
namespace _internal;

use bX\Router;
use bX\Response;
use bX\ChannelContext;

# Health check del Channel Server — liveness probe con stats básicos
Router::register(['GET'], 'status', function() {
    $server = ChannelContext::$server;
    $stats = $server ? $server->stats() : [];

    return Response::json([
        'status' => 'ok',
        'timestamp' => time(),
        'uptime' => isset($stats['start_time']) ? time() - $stats['start_time'] : null,
        'connections' => $stats['connection_num'] ?? 0,
        'workers' => $stats['worker_num'] ?? 0,
    ]);
}, ROUTER_SCOPE_SYSTEM);
