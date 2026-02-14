<?php
# bintelx/kernel/Cache.php
namespace bX;

use bX\Cache\CacheBackend;
use bX\Cache\ArrayBackend;

# Cache transparente: Swoole\Table en channel server, static array en FPM/CLI
# Uso: Cache::get('geo:rates', $cacheKey) / Cache::set('geo:rates', $cacheKey, $data, 3600)
class Cache {
    private static ?CacheBackend $backend = null;

    # Inicializar con un backend específico (llamar desde channel.server.php con SwooleTableBackend)
    public static function init(CacheBackend $backend): void {
        self::$backend = $backend;
    }

    # Auto-init: Swoole ya llamó init() con SwooleTableBackend
    # FPM/CLI usa ChannelCacheBackend (circuit breaker maneja indisponibilidad)
    private static function ensureBackend(): CacheBackend {
        if (self::$backend === null) {
            self::$backend = new \bX\Cache\ChannelCacheBackend();
        }
        return self::$backend;
    }

    # Construye key interno: "{ns}:{key}"
    private static function makeKey(string $ns, string $key): string {
        return "{$ns}:{$key}";
    }

    public static function get(string $ns, string $key): mixed {
        return self::ensureBackend()->get(self::makeKey($ns, $key));
    }

    public static function set(string $ns, string $key, mixed $value, int $ttl = 0): void {
        self::ensureBackend()->set(self::makeKey($ns, $key), $value, $ttl);
    }

    public static function has(string $ns, string $key): bool {
        return self::ensureBackend()->has(self::makeKey($ns, $key));
    }

    # Get from cache or compute+store via loader
    # Resuelve null ambiguity: stored null se distingue de cache miss via has()
    # Si set() falla (payload > max o tabla llena), retorna el valor del loader igualmente
    # Sin singleflight: N misses concurrentes ejecutan N loaders en paralelo
    # Aceptable para queries ligeras (geo, edc). Para loaders pesados, usar locking externo.
    public static function getOrSet(string $ns, string $key, int $ttl, callable $loader): mixed {
        $fullKey = self::makeKey($ns, $key);
        $backend = self::ensureBackend();
        if ($backend->has($fullKey)) {
            return $backend->get($fullKey);
        }
        $value = $loader();
        $backend->set($fullKey, $value, $ttl);
        return $value;
    }

    public static function delete(string $ns, string $key): void {
        self::ensureBackend()->delete(self::makeKey($ns, $key));
    }

    # Flush all entries in a namespace
    public static function flush(string $ns): void {
        self::ensureBackend()->flush("{$ns}:");
    }

    # Notifica al Channel Server para invalidar cache desde FPM/CLI
    # Fire-and-forget: si el Channel no está corriendo, falla silenciosamente
    # Usa ROUTER_SCOPE_SYSTEM: auth vía X-System-Key header
    public static function notifyChannel(string $ns): void {
        if (self::$backend instanceof \bX\Cache\SwooleTableBackend) return;

        $channelHost = getenv('CHANNEL_HOST') ?: '127.0.0.1';
        $channelPort = getenv('CHANNEL_PORT') ?: '8000';
        $url = "http://{$channelHost}:{$channelPort}/api/_internal/flush";
        $payload = json_encode(['namespace' => $ns]);

        $headers = "Content-Type: application/json\r\n";
        $systemSecret = Config::get('SYSTEM_SECRET', '');
        if ($systemSecret) {
            $headers .= "X-System-Key: {$systemSecret}\r\n";
        }

        @file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $payload,
                'timeout' => 1,
            ]
        ]));
    }

    # Acceso directo al backend con key completa (para endpoints _internal)
    # Bypass makeKey() — la key ya viene con namespace prefix
    public static function get_raw(string $fullKey): mixed {
        return self::ensureBackend()->get($fullKey);
    }

    public static function set_raw(string $fullKey, mixed $value, int $ttl = 0): void {
        self::ensureBackend()->set($fullKey, $value, $ttl);
    }

    public static function has_raw(string $fullKey): bool {
        return self::ensureBackend()->has($fullKey);
    }

    public static function delete_raw(string $fullKey): void {
        self::ensureBackend()->delete($fullKey);
    }

    # Stats de la tabla (solo SwooleTableBackend expone count/memory)
    public static function stats(): array {
        $backend = self::ensureBackend();
        if ($backend instanceof \bX\Cache\SwooleTableBackend) {
            return $backend->stats();
        }
        return ['backend' => get_class($backend), 'note' => 'stats only available on SwooleTableBackend'];
    }

    # Reset backend (testing / shutdown)
    public static function reset(): void {
        self::$backend = null;
    }
}
