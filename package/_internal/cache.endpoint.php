<?php # package/_internal/cache.endpoint.php
namespace _internal;

use bX\Router;
use bX\Response;
use bX\Cache;
use bX\Log;
use bX\Args;

# Invalidación de cache — solo server-to-server (FPM → Channel)
# action=flush: flush namespace con scope (construye prefix correcto)
# action=delete: delete entry específica por fullKey
Router::register(['POST'], 'flush', function() {
    $action = Args::ctx()->opt['action'] ?? 'flush';

    if ($action === 'delete') {
        $key = Args::ctx()->opt['key'] ?? '';
        if ($key) {
            Cache::delete_raw($key);
            Log::logInfo("Cache key '{$key}' deleted via system endpoint");
        }
        return Response::json(['deleted' => $key]);
    }

    # action=flush — construir prefix con scope recibido
    $ns = Args::ctx()->opt['namespace'] ?? '';
    $scope = (int)(Args::ctx()->opt['scope'] ?? 0);
    if ($ns) {
        if (str_starts_with($ns, 'global:')) {
            Cache::flushPrefix("{$ns}:");
        } else {
            $prefix = $scope > 0 ? "{$scope}:{$ns}:" : "{$ns}:";
            Cache::flushPrefix($prefix);
        }
        Log::logInfo("Cache namespace '{$ns}' flushed via system endpoint (scope={$scope})");
    }
    return Response::json(['flushed' => $ns, 'scope' => $scope]);
}, ROUTER_SCOPE_SYSTEM);

# Cache GET — retorna valor + flag exists (1 solo round-trip para has()+get())
Router::register(['POST'], 'cache/get', function() {
    $key = Args::ctx()->opt['key'] ?? '';
    if (!$key) return Response::json(['exists' => false]);
    $exists = Cache::has_raw($key);
    return Response::json([
        'exists' => $exists,
        'value' => $exists ? Cache::get_raw($key) : null
    ]);
}, ROUTER_SCOPE_SYSTEM);

# Cache SET — almacena valor con TTL
Router::register(['POST'], 'cache/set', function() {
    $key = Args::ctx()->opt['key'] ?? '';
    $value = Args::ctx()->opt['value'] ?? null;
    $ttl = (int)(Args::ctx()->opt['ttl'] ?? 0);
    if ($key) {
        Cache::set_raw($key, $value, $ttl);
    }
    return Response::json(['ok' => true]);
}, ROUTER_SCOPE_SYSTEM);

# Cache DELETE — elimina key individual (usado por EDC)
Router::register(['POST'], 'cache/delete', function() {
    $key = Args::ctx()->opt['key'] ?? '';
    if ($key) {
        Cache::delete_raw($key);
    }
    return Response::json(['ok' => true]);
}, ROUTER_SCOPE_SYSTEM);
