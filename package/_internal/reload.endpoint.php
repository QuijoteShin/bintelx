<?php # package/_internal/reload.endpoint.php
namespace _internal;

use bX\Router;
use bX\Response;
use bX\Log;
use bX\ChannelContext;

# Hot reload de workers — envía SIGUSR1 al master, recarga código sin perder conexiones
Router::register(['POST'], 'reload', function() {
    $server = ChannelContext::$server;
    if (!$server) {
        return Response::json(['error' => 'No server context'], 500);
    }

    $masterPid = $server->master_pid;
    \Swoole\Process::kill($masterPid, SIGUSR1);
    Log::logInfo("Hot reload triggered via system endpoint (master PID: $masterPid)");

    return Response::json([
        'reloaded' => true,
        'master_pid' => $masterPid,
        'timestamp' => time(),
    ]);
}, ROUTER_SCOPE_SYSTEM);
