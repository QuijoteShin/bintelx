<?php
# bintelx/kernel/Cache/ChannelCacheBackend.php
namespace bX\Cache;

use bX\Config;
use bX\Log;

# Cache bridge FPM → Channel Server via HTTP
# Usa SwooleTableBackend del Channel como almacenamiento compartido
# Memoize L1 puentea has()+get() en 1 solo round-trip
# Circuit breaker timestamp: si Channel cae, fallback silencioso a DB por 5s
class ChannelCacheBackend implements CacheBackend {
    private string $baseUrl;
    private string $systemKey;
    private array $memoize = [];
    private int $downSince = 0;

    private const DOWN_COOLDOWN = 5; # segundos antes de reintentar
    private const CONNECT_TIMEOUT_MS = 50;
    private const TIMEOUT_MS = 200;

    public function __construct() {
        $host = Config::get('CHANNEL_HOST', '127.0.0.1');
        $port = Config::get('CHANNEL_PORT', '8000');
        $this->baseUrl = "http://{$host}:{$port}/api/_internal/cache";
        $this->systemKey = Config::get('SYSTEM_SECRET', '');
    }

    private function isDown(): bool {
        return $this->downSince > 0 && (time() - $this->downSince) < self::DOWN_COOLDOWN;
    }

    private function markDown(): void {
        $this->downSince = time();
    }

    # has() hace el HTTP real y memoiza el valor para get()
    public function has(string $key): bool {
        if (array_key_exists($key, $this->memoize)) return true;
        if ($this->isDown()) return false;

        $result = $this->httpPost('/get', ['key' => $key]);
        if ($result === null) {
            $this->markDown();
            return false;
        }

        if ($result['exists'] ?? false) {
            $this->memoize[$key] = $result['value'];
            return true;
        }

        return false;
    }

    # get() consume de memoize (0 HTTP si has() ya lo trajo)
    public function get(string $key): mixed {
        if (array_key_exists($key, $this->memoize)) {
            $val = $this->memoize[$key];
            unset($this->memoize[$key]);
            return $val;
        }

        if ($this->isDown()) return null;

        $result = $this->httpPost('/get', ['key' => $key]);
        if ($result === null) {
            $this->markDown();
            return null;
        }

        return $result['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void {
        if ($this->isDown()) return;

        $result = $this->httpPost('/set', ['key' => $key, 'value' => $value, 'ttl' => $ttl]);
        if ($result === null) {
            $this->markDown();
        }
    }

    public function delete(string $key): void {
        if ($this->isDown()) return;

        $result = $this->httpPost('/delete', ['key' => $key]);
        if ($result === null) {
            $this->markDown();
        }
    }

    # flush se maneja via Cache::notifyChannel() existente
    public function flush(string $prefix): void {
        # no-op: el caller ya usa Cache::notifyChannel() para invalidación
    }

    # HTTP POST con curl, timeout agresivo
    # Retorna array decodificado o null si falla
    private function httpPost(string $path, array $data): ?array {
        $ch = curl_init($this->baseUrl . $path);

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type: application/json'];
        if ($this->systemKey) {
            $headers[] = 'X-System-Key: ' . $this->systemKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_TCP_NODELAY => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            if ($error) {
                Log::logWarning("ChannelCacheBackend: {$path} failed - {$error}");
            }
            return null;
        }

        return json_decode($response, true) ?: [];
    }
}
