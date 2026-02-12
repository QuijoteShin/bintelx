<?php
# bintelx/kernel/Cache/SwooleTableBackend.php
namespace bX\Cache;

# Cache compartido entre todos los workers Swoole via Swoole\Table (shared memory)
class SwooleTableBackend implements CacheBackend {
    private \Swoole\Table $table;

    public function __construct(\Swoole\Table $table) {
        $this->table = $table;
    }

    public function get(string $key): mixed {
        $row = $this->table->get($key);
        if ($row === false) {
            return null;
        }

        # TTL check
        if ($row['expires_at'] > 0 && $row['expires_at'] < time()) {
            $this->table->del($key);
            return null;
        }

        return json_decode($row['data'], true);
    }

    public function set(string $key, mixed $value, int $ttl = 0): void {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

        # Swoole\Table STRING column has a size limit; truncate if too large
        if (strlen($encoded) > 8192) {
            \bX\Log::logWarning("Cache: Value too large for key '{$key}' (" . strlen($encoded) . " bytes), skipping.");
            return;
        }

        $result = $this->table->set($key, [
            'data' => $encoded,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
        ]);

        if ($result === false) {
            # Tabla llena — evictar entries expiradas e intentar de nuevo
            $this->evictExpired();
            $retry = $this->table->set($key, [
                'data' => $encoded,
                'expires_at' => $ttl > 0 ? time() + $ttl : 0,
            ]);
            if ($retry === false) {
                \bX\Log::logWarning("Cache: Table full, cannot store key '{$key}' (count={$this->table->count()}).");
            }
        }
    }

    # Limpia entries expiradas para liberar espacio
    private function evictExpired(): void {
        $now = time();
        foreach ($this->table as $key => $row) {
            if ($row['expires_at'] > 0 && $row['expires_at'] < $now) {
                $this->table->del($key);
            }
        }
    }

    # Verifica existencia independiente de get() — distingue miss de valor null almacenado
    public function has(string $key): bool {
        $row = $this->table->get($key);
        if ($row === false) return false;
        if ($row['expires_at'] > 0 && $row['expires_at'] < time()) {
            $this->table->del($key);
            return false;
        }
        return true;
    }

    public function delete(string $key): void {
        $this->table->del($key);
    }

    public function flush(string $prefix): void {
        foreach ($this->table as $key => $row) {
            if (str_starts_with($key, $prefix)) {
                $this->table->del($key);
            }
        }
    }
}
