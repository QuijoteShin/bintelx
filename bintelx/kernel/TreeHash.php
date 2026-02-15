<?php # bintelx/kernel/TreeHash.php
namespace bX;

# Hashing estructural para árboles PHP
# - hashTree(): ksort + json_encode + xxh128 (C-level, rápido para dedup)
# - hash/equals/diff: fingerprints bottom-up (O(n), eficiente para comparación)
# Requiere PHP 8.1+ (array_is_list, hash xxh128)

class TreeHash
{
    # Hash un valor PHP arbitrario → string hex 32 chars (xxh128)
    public static function hash(mixed $data): string
    {
        if ($data instanceof \stdClass) {
            $data = (array)$data;
        }
        $fp = self::fingerprint($data);
        return strlen($fp) === 32 && ctype_xdigit($fp) ? $fp : hash('xxh128', $fp);
    }

    # Hash un árbol completo con registro de frecuencias para dedup
    # Usa json_encode (C-level) + xxh128 para velocidad
    # normalize=true: ksort keys para dedup cross-source (correcto, más lento)
    # normalize=false: usa key order de PHP (rápido, válido cuando data viene de misma fuente)
    public static function hashTree(array $data, string $basePath = '', int $minKeys = 2, bool $normalize = true): array
    {
        if ($normalize) {
            self::recursiveKsort($data);
        }

        $index = [];
        self::collectHashesHybrid($data, $basePath, $index, $minKeys);

        $rootHash = hash('xxh128', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return ['root_hash' => $rootHash, 'index' => $index];
    }

    # Comparar dos estructuras: ¿son idénticas?
    public static function equals(mixed $a, mixed $b): bool
    {
        return self::fingerprint($a) === self::fingerprint($b);
    }

    # Diff estructural: retorna paths donde difieren
    # Poda ramas idénticas por hash → O(cambios)
    public static function diff(array $a, array $b, string $path = ''): array
    {
        if (self::fingerprint($a) === self::fingerprint($b)) {
            return [];
        }

        $diffs = [];
        $allKeys = array_unique(array_merge(array_keys($a), array_keys($b)));

        foreach ($allKeys as $key) {
            $currentPath = $path === '' ? (string)$key : "$path.$key";

            if (!array_key_exists($key, $a)) {
                $diffs[] = ['path' => $currentPath, 'type' => 'added', 'value' => $b[$key]];
                continue;
            }
            if (!array_key_exists($key, $b)) {
                $diffs[] = ['path' => $currentPath, 'type' => 'removed', 'value' => $a[$key]];
                continue;
            }

            $valA = $a[$key];
            $valB = $b[$key];

            if (self::fingerprint($valA) === self::fingerprint($valB)) {
                continue;
            }

            if (is_array($valA) && is_array($valB) && !array_is_list($valA) && !array_is_list($valB)) {
                $diffs = array_merge($diffs, self::diff($valA, $valB, $currentPath));
            } else {
                $diffs[] = ['path' => $currentPath, 'type' => 'changed', 'old' => $valA, 'new' => $valB];
            }
        }

        return $diffs;
    }

    # --- hashTree internals (hybrid: C-level json_encode + xxh128) ---

    private static function recursiveKsort(array &$data): void
    {
        if (!array_is_list($data)) {
            ksort($data, SORT_STRING);
        }
        foreach ($data as &$val) {
            if (is_array($val)) {
                self::recursiveKsort($val);
            }
        }
    }

    private static function collectHashesHybrid(array $data, string $path, array &$index, int $minKeys): void
    {
        foreach ($data as $key => $val) {
            if (is_array($val) && !empty($val) && !array_is_list($val)) {
                $childPath = $path === '' ? (string)$key : "$path.$key";

                if (count($val) >= $minKeys) {
                    $hash = hash('xxh128', json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    if (isset($index[$hash])) {
                        $index[$hash]['count']++;
                    } else {
                        $index[$hash] = ['count' => 1, 'path' => $childPath, 'data' => $val];
                    }
                }

                self::collectHashesHybrid($val, $childPath, $index, $minKeys);
            }
        }
    }

    # --- fingerprint internals (bottom-up, para hash/equals/diff) ---
    # Primitivos: type-tagged binary string (sin hash call)
    # Arrays: xxh128 hex digest (32 chars)

    private static function fingerprint(mixed $data): string
    {
        if ($data instanceof \stdClass) {
            $data = (array)$data;
        }
        if (!is_array($data)) {
            return self::primitiveFP($data);
        }
        if (empty($data)) {
            return hash('xxh128', 'A:0');
        }
        if (array_is_list($data)) {
            return self::seqFP($data);
        }
        return self::mapFP($data);
    }

    private static function primitiveFP(mixed $val): string
    {
        if ($val === null) return "\x00";
        if ($val === true) return "\x01\x01";
        if ($val === false) return "\x01\x00";
        if (is_int($val)) return "\x02" . pack('q', $val);
        if (is_float($val)) return "\x03" . pack('E', $val);
        if (is_string($val)) return "\x04" . $val;
        return "\x04" . (string)$val;
    }

    private static function seqFP(array $arr): string
    {
        $buf = 'A:' . count($arr);
        foreach ($arr as $item) {
            $buf .= '|' . self::fingerprint($item);
        }
        return hash('xxh128', $buf);
    }

    private static function mapFP(array $arr): string
    {
        ksort($arr, SORT_STRING);
        $buf = 'O:' . count($arr);
        foreach ($arr as $key => $val) {
            $buf .= '|' . $key . ':' . self::fingerprint($val);
        }
        return hash('xxh128', $buf);
    }
}
