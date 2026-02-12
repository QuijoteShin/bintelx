<?php # package/_internal/metrics.endpoint.php
namespace _internal;

use bX\Router;
use bX\Response;
use bX\ChannelContext;

# Métricas del Channel Server — stats de Swoole, conexiones auth, canales, memoria
Router::register(['GET'], 'metrics', function() {
    $server = ChannelContext::$server;
    $authTable = ChannelContext::$authTable;
    $channelsTable = ChannelContext::$channelsTable;

    $stats = $server ? $server->stats() : [];

    $authCount = 0;
    if ($authTable) {
        foreach ($authTable as $row) $authCount++;
    }

    $channels = [];
    if ($channelsTable) {
        foreach ($channelsTable as $key => $row) {
            $channel = explode(':', $key)[0] ?? '';
            $channels[$channel] = ($channels[$channel] ?? 0) + 1;
        }
    }

    return Response::json([
        'swoole' => $stats,
        'auth_connections' => $authCount,
        'channels' => $channels,
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true),
    ]);
}, ROUTER_SCOPE_SYSTEM);
