<?php
# bintelx/kernel/Cache/ArrayBackend.php
namespace bX\Cache;

# Fallback para FPM/CLI: cache en memoria estática (muere con el request en FPM)
class ArrayBackend implements CacheBackend {
    private array $store = [];

    public function get(string $key): mixed {
        if (!isset($this->store[$key])) {
            return null;
        }

        $entry = $this->store[$key];

        # TTL check
        if ($entry['expires_at'] > 0 && $entry['expires_at'] < time()) {
            unset($this->store[$key]);
            return null;
        }

        return $entry['data'];
    }

    public function set(string $key, mixed $value, int $ttl = 0): void {
        $this->store[$key] = [
            'data' => $value,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
        ];
    }

    # Verifica existencia independiente de get() — distingue miss de valor null almacenado
    public function has(string $key): bool {
        if (!isset($this->store[$key])) return false;
        $entry = $this->store[$key];
        if ($entry['expires_at'] > 0 && $entry['expires_at'] < time()) {
            unset($this->store[$key]);
            return false;
        }
        return true;
    }

    public function delete(string $key): void {
        unset($this->store[$key]);
    }

    public function flush(string $prefix): void {
        foreach (array_keys($this->store) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->store[$key]);
            }
        }
    }
}
