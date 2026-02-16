<?php # package/_internal/connections.endpoint.php
namespace _internal;

use bX\Router;
use bX\Response;
use bX\ChannelContext;

# Conexiones autenticadas activas — lista desde authTable (Swoole\Table compartida)
Router::register(['GET'], 'connections', function() {
    $authTable = ChannelContext::$authTable;
    $server = ChannelContext::$server;
    $connections = [];

    if ($authTable) {
        foreach ($authTable as $fd => $row) {
            $info = $server ? $server->getClientInfo((int)$fd) : [];
            $connections[] = [
                'fd' => (int)$fd,
                'account_id' => $row['account_id'],
                'profile_id' => $row['profile_id'],
                'scope_entity_id' => $row['scope_entity_id'],
                'device_hash' => $row['device_hash'],
                'remote_ip' => $info['remote_ip'] ?? 'unknown',
                'connect_time' => $info['connect_time'] ?? null,
            ];
        }
    }

    return Response::json([
        'count' => count($connections),
        'connections' => $connections,
    ]);
}, ROUTER_SCOPE_SYSTEM);
