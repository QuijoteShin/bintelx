<?php
# bintelx/kernel/Cache/CacheBackend.php
namespace bX\Cache;

interface CacheBackend {
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 0): void;
    public function has(string $key): bool;
    public function delete(string $key): void;
    public function flush(string $prefix): void;
}
