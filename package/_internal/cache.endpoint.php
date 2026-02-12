<?php # package/_internal/cache.endpoint.php
namespace _internal;

use bX\Router;
use bX\Response;
use bX\Cache;
use bX\Log;
use bX\Args;

# Flush de cache namespace — solo server-to-server (FPM → Channel)
Router::register(['POST'], 'flush', function() {
    $ns = Args::$OPT['namespace'] ?? '';
    if ($ns) {
        Cache::flush($ns);
        Log::logInfo("Cache namespace '{$ns}' flushed via system endpoint");
    }
    return Response::json(['flushed' => $ns]);
}, ROUTER_SCOPE_SYSTEM);
