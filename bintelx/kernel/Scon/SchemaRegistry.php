<?php
# kernel/Scon/SchemaRegistry.php
namespace bX\Scon;

use bX\Exception;

class SchemaRegistry {

    private array $schemas = [];
    private array $responses = [];
    private array $security = [];
    private array $resolving = []; # cycle detection

    # Register a definition
    public function register(string $type, string $name, array $definition): void {
        match ($type) {
            's' => $this->schemas[$name] = $definition,
            'r' => $this->responses[$name] = $definition,
            'sec' => $this->security[$name] = $definition,
            default => throw new Exception("Unknown definition type: $type")
        };
    }

    # Resolve a reference by type and name
    public function resolve(string $type, string $name): array {
        $store = match ($type) {
            's' => $this->schemas,
            'r' => $this->responses,
            'sec' => $this->security,
            default => throw new Exception("Unknown ref type: $type")
        };

        if (!isset($store[$name])) {
            throw new Exception("Undefined reference: @$type:$name");
        }

        $refKey = "$type:$name";
        if (isset($this->resolving[$refKey])) {
            # Circular reference - return marker for lazy resolution
            return ['$ref' => "#/definitions/$name"];
        }

        $this->resolving[$refKey] = true;
        $resolved = $this->deepResolveRefs($store[$name]);
        unset($this->resolving[$refKey]);

        return $resolved;
    }

    # Resolve with override (deep merge + dot-notation + field removal)
    public function resolveWithOverride(string $type, string $name, array $overrides): array {
        $base = $this->resolve($type, $name);

        # Process field removals first
        $removals = [];
        $merges = [];
        foreach ($overrides as $key => $val) {
            if (str_starts_with($key, '-')) {
                $removals[] = substr($key, 1);
            } else {
                $merges[$key] = $val;
            }
        }

        # Apply removals
        foreach ($removals as $field) {
            if (str_contains($field, '.')) {
                $this->unsetDotPath($base, $field);
            } else {
                unset($base[$field]);
            }
        }

        # Apply deep merges with dot-notation support
        foreach ($merges as $key => $val) {
            if (str_contains($key, '.')) {
                $this->setDotPath($base, $key, $val);
            } else {
                if (is_array($val) && isset($base[$key]) && is_array($base[$key])
                    && !$this->isSequential($val)) {
                    $base[$key] = $this->deepMerge($base[$key], $val);
                } else {
                    $base[$key] = $val;
                }
            }
        }

        return $base;
    }

    # Resolve polymorphic references (oneOf with pipe)
    public function resolvePolymorphic(array $refs): array {
        $schemas = [];
        foreach ($refs as $ref) {
            $schemas[] = $this->resolve($ref['type'], $ref['name']);
        }
        return ['oneOf' => $schemas];
    }

    # Check if a definition exists
    public function has(string $type, string $name): bool {
        return match ($type) {
            's' => isset($this->schemas[$name]),
            'r' => isset($this->responses[$name]),
            'sec' => isset($this->security[$name]),
            default => false
        };
    }

    # Get all definitions of a type (for encoding)
    public function getAll(string $type): array {
        return match ($type) {
            's' => $this->schemas,
            'r' => $this->responses,
            'sec' => $this->security,
            default => []
        };
    }

    # Reset all definitions
    public function reset(): void {
        $this->schemas = [];
        $this->responses = [];
        $this->security = [];
        $this->resolving = [];
    }

    # Deep-resolve any @ref markers within a definition
    private function deepResolveRefs(array $data): array {
        $result = [];
        foreach ($data as $key => $val) {
            if (is_array($val)) {
                if (isset($val['@ref'])) {
                    $ref = $val['@ref'];
                    if (isset($val['@overrides'])) {
                        $result[$key] = $this->resolveWithOverride($ref['type'], $ref['name'], $val['@overrides']);
                    } else {
                        $result[$key] = $this->resolve($ref['type'], $ref['name']);
                    }
                } elseif (isset($val['@polymorphic'])) {
                    $result[$key] = $this->resolvePolymorphic($val['@polymorphic']);
                } else {
                    $result[$key] = $this->deepResolveRefs($val);
                }
            } else {
                $result[$key] = $val;
            }
        }
        return $result;
    }

    # Set a value using dot-notation path (a.b.c = val)
    private function setDotPath(array &$arr, string $path, mixed $val): void {
        $keys = explode('.', $path);
        $ref = &$arr;
        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $ref[$key] = $val;
            } else {
                if (!isset($ref[$key]) || !is_array($ref[$key])) {
                    $ref[$key] = [];
                }
                $ref = &$ref[$key];
            }
        }
    }

    # Unset a value using dot-notation path
    private function unsetDotPath(array &$arr, string $path): void {
        $keys = explode('.', $path);
        $ref = &$arr;
        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                unset($ref[$key]);
            } else {
                if (!isset($ref[$key]) || !is_array($ref[$key])) {
                    return;
                }
                $ref = &$ref[$key];
            }
        }
    }

    # Deep merge: objects merge recursively, arrays replace
    private function deepMerge(array $base, array $override): array {
        foreach ($override as $key => $val) {
            if (is_array($val) && isset($base[$key]) && is_array($base[$key])
                && !$this->isSequential($val) && !$this->isSequential($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $val);
            } else {
                $base[$key] = $val;
            }
        }
        return $base;
    }

    private function isSequential(array $arr): bool {
        if (empty($arr)) return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
