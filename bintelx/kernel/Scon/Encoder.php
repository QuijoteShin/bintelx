<?php
# kernel/Scon/Encoder.php
namespace bX\Scon;

class Encoder {

    const COMMA = ',';
    const TAB = "\t";
    const PIPE = '|';
    const DOUBLE_QUOTE = '"';
    const COLON = ':';
    const OPEN_BRACKET = '[';
    const CLOSE_BRACKET = ']';
    const OPEN_BRACE = '{';
    const CLOSE_BRACE = '}';
    const LIST_ITEM_PREFIX = '- ';
    const NULL_LITERAL = 'null';
    const TRUE_LITERAL = 'true';
    const FALSE_LITERAL = 'false';
    const SEMICOLON = ';';
    const HEADER = '#!scon/1.0';

    private int $indent;
    private string $delimiter;
    private string $mode; # warn (default), loose, strict
    private ?string $enforce;
    private SchemaRegistry $registry;
    private array $extractedSchemas; # auto-detected schemas for dedup
    private bool $autoExtract;

    public function __construct(array $options = []) {
        $this->indent = $options['indent'] ?? 2;
        $this->delimiter = $options['delimiter'] ?? self::COMMA;
        $this->mode = $options['mode'] ?? 'warn';
        $this->enforce = $options['enforce'] ?? null;
        $this->autoExtract = $options['autoExtract'] ?? false;
        $this->registry = new SchemaRegistry();
        $this->extractedSchemas = [];
    }

    public function getRegistry(): SchemaRegistry {
        return $this->registry;
    }

    # Encode PHP data to SCON string
    public function encode(mixed $data, array $schemas = [], array $responses = [], array $security = []): string {
        # Register explicit schemas
        foreach ($schemas as $name => $def) {
            $this->registry->register('s', $name, $def);
        }
        foreach ($responses as $name => $def) {
            $this->registry->register('r', $name, $def);
        }
        foreach ($security as $name => $def) {
            $this->registry->register('sec', $name, $def);
        }

        # Auto-extract repeated schemas if enabled
        if ($this->autoExtract && is_array($data)) {
            $this->detectRepeatedSchemas($data);
        }

        $lines = [];

        # Header
        $lines[] = self::HEADER;

        # Directives
        if ($this->mode !== 'warn') {
            $lines[] = "@@{$this->mode}";
        }
        if ($this->enforce !== null) {
            $lines[] = "@@enforce({$this->enforce})";
        }

        # Schema definitions
        $allSchemas = $this->registry->getAll('s');
        if (!empty($allSchemas)) {
            $lines[] = '';
            foreach ($allSchemas as $name => $def) {
                $lines[] = "s:$name " . $this->encodeInline($def);
            }
        }

        # Response group definitions
        $allResponses = $this->registry->getAll('r');
        if (!empty($allResponses)) {
            $lines[] = '';
            foreach ($allResponses as $name => $def) {
                $lines[] = "r:$name " . $this->encodeResponseGroup($def);
            }
        }

        # Security group definitions
        $allSecurity = $this->registry->getAll('sec');
        if (!empty($allSecurity)) {
            $lines[] = '';
            foreach ($allSecurity as $name => $def) {
                $lines[] = "sec:$name " . $this->encodeInline($def);
            }
        }

        # Body
        if (!empty($allSchemas) || !empty($allResponses) || !empty($allSecurity)) {
            $lines[] = '';
        }

        foreach ($this->encodeValue($data, 0) as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    # Encode a response group definition
    private function encodeResponseGroup(array $group): string {
        $parts = [];
        foreach ($group as $code => $def) {
            $desc = $def['description'] ?? '';
            $schemaRef = $def['schemaRef'] ?? null;
            $part = "$code:" . $this->encodeString($desc);
            if ($schemaRef !== null) {
                $part .= " @s:$schemaRef";
                if (!empty($def['overrides'])) {
                    $part .= ' ' . $this->encodeInline($def['overrides']);
                }
            }
            $parts[] = $part;
        }
        return self::OPEN_BRACE . implode(', ', $parts) . self::CLOSE_BRACE;
    }

    # Encode data as inline single-line notation
    private function encodeInline(mixed $data): string {
        if ($this->isPrimitive($data)) {
            return $this->encodePrimitive($data);
        }

        if (is_array($data) && $this->isSequentialArray($data)) {
            $items = array_map(fn($v) => $this->encodeInline($v), $data);
            return self::OPEN_BRACKET . implode(', ', $items) . self::CLOSE_BRACKET;
        }

        if (is_array($data)) {
            $parts = [];
            foreach ($data as $key => $val) {
                $encodedKey = $this->encodeKey($key);
                $encodedVal = $this->encodeInline($val);
                $parts[] = "$encodedKey:$encodedVal";
            }
            return self::OPEN_BRACE . implode(', ', $parts) . self::CLOSE_BRACE;
        }

        return '';
    }

    # Encode value with indentation (TOON-style body)
    private function encodeValue(mixed $value, int $depth): \Generator {
        if ($this->isPrimitive($value)) {
            $encoded = $this->encodePrimitive($value);
            if ($encoded !== '') {
                yield $encoded;
            }
            return;
        }

        if (is_array($value)) {
            if ($this->isSequentialArray($value)) {
                yield from $this->encodeArray(null, $value, $depth);
            } else {
                yield from $this->encodeObject($value, $depth);
            }
        }
    }

    private function encodeObject(array $obj, int $depth): \Generator {
        foreach ($obj as $key => $val) {
            if ($this->isPrimitive($val)) {
                $encodedKey = $this->encodeKey($key);
                $encodedVal = $this->encodePrimitive($val);
                yield $this->indentedLine($depth, "$encodedKey: $encodedVal");
            } elseif (is_array($val) && $this->isSequentialArray($val)) {
                yield from $this->encodeArray($key, $val, $depth);
            } elseif (is_array($val)) {
                $encodedKey = $this->encodeKey($key);
                # Check if value matches a registered schema
                $schemaRef = $this->findMatchingSchema($val);
                if ($schemaRef !== null) {
                    yield $this->indentedLine($depth, "$encodedKey: @s:$schemaRef");
                } else {
                    yield $this->indentedLine($depth, "$encodedKey:");
                    if (!empty($val)) {
                        yield from $this->encodeObject($val, $depth + 1);
                    }
                }
            }
        }
    }

    private function encodeArray(?string $key, array $array, int $depth): \Generator {
        $length = count($array);

        if ($length === 0) {
            if ($key !== null) {
                yield $this->indentedLine($depth, $this->encodeKey($key) . ': []');
            } else {
                yield $this->indentedLine($depth, '[]');
            }
            return;
        }

        # Array de primitivos
        if ($this->isArrayOfPrimitives($array)) {
            $header = $this->formatHeader($length, $key);
            $values = $this->encodeAndJoinPrimitives($array);
            yield $this->indentedLine($depth, "$header $values");
            return;
        }

        # Array de objetos (tabular)
        if ($this->isArrayOfObjects($array)) {
            $fields = $this->extractTabularHeader($array);
            if ($fields !== null) {
                yield from $this->encodeTabularArray($key, $array, $fields, $depth);
                return;
            }
        }

        # Array mixto / expandido
        yield from $this->encodeMixedArray($key, $array, $depth);
    }

    private function encodeTabularArray(?string $key, array $rows, array $fields, int $depth): \Generator {
        $length = count($rows);
        $header = $this->formatHeader($length, $key, $fields);
        yield $this->indentedLine($depth, $header);

        foreach ($rows as $row) {
            $values = [];
            foreach ($fields as $field) {
                $values[] = $row[$field] ?? null;
            }
            yield $this->indentedLine($depth + 1, $this->encodeAndJoinPrimitives($values));
        }
    }

    private function encodeMixedArray(?string $key, array $items, int $depth): \Generator {
        $header = $this->formatHeader(count($items), $key);
        yield $this->indentedLine($depth, $header);

        foreach ($items as $item) {
            if ($this->isPrimitive($item)) {
                yield $this->indentedListItem($depth + 1, $this->encodePrimitive($item));
            } elseif (is_array($item) && !$this->isSequentialArray($item)) {
                yield from $this->encodeObjectAsListItem($item, $depth + 1);
            } elseif (is_array($item) && $this->isSequentialArray($item)) {
                if ($this->isArrayOfPrimitives($item)) {
                    $subHeader = $this->formatHeader(count($item), null);
                    $values = $this->encodeAndJoinPrimitives($item);
                    yield $this->indentedListItem($depth + 1, "$subHeader $values");
                }
            }
        }
    }

    private function encodeObjectAsListItem(array $obj, int $depth): \Generator {
        if (empty($obj)) {
            yield $this->indentedLine($depth, self::LIST_ITEM_PREFIX);
            return;
        }

        $keys = array_keys($obj);
        $firstKey = $keys[0];
        $firstVal = $obj[$firstKey];
        $rest = array_slice($obj, 1, null, true);

        $encodedKey = $this->encodeKey($firstKey);

        if ($this->isPrimitive($firstVal)) {
            yield $this->indentedListItem($depth, "$encodedKey: " . $this->encodePrimitive($firstVal));
        } elseif (is_array($firstVal) && $this->isSequentialArray($firstVal) && empty($firstVal)) {
            yield $this->indentedListItem($depth, "$encodedKey: []");
        } elseif (is_array($firstVal) && $this->isSequentialArray($firstVal) && $this->isArrayOfPrimitives($firstVal)) {
            $hdr = $this->formatHeader(count($firstVal), null);
            $vals = $this->encodeAndJoinPrimitives($firstVal);
            yield $this->indentedListItem($depth, "$encodedKey$hdr $vals");
        } elseif (is_array($firstVal)) {
            yield $this->indentedListItem($depth, "$encodedKey:");
            yield from $this->encodeObject($firstVal, $depth + 2);
        }

        if (!empty($rest)) {
            yield from $this->encodeObject($rest, $depth + 1);
        }
    }

    # Find a registered schema that matches this data exactly
    private function findMatchingSchema(array $data): ?string {
        foreach ($this->registry->getAll('s') as $name => $def) {
            if ($data === $def) {
                return $name;
            }
        }
        return null;
    }

    # Detect repeated sub-structures and extract as schemas
    private function detectRepeatedSchemas(array $data, string $path = ''): void {
        $hashes = [];
        $this->collectSubStructures($data, $hashes, $path);

        # Find structures that appear 2+ times
        $counts = array_count_values(array_map(fn($h) => $h['hash'], $hashes));
        $extracted = [];

        foreach ($hashes as $entry) {
            if ($counts[$entry['hash']] >= 2 && !isset($extracted[$entry['hash']])) {
                $name = $this->generateSchemaName($entry['path']);
                $this->registry->register('s', $name, $entry['data']);
                $extracted[$entry['hash']] = $name;
            }
        }
    }

    private function collectSubStructures(array $data, array &$hashes, string $path): void {
        foreach ($data as $key => $val) {
            if (is_array($val) && !$this->isSequentialArray($val) && count($val) >= 2) {
                $hash = md5(json_encode($val, JSON_UNESCAPED_SLASHES));
                $hashes[] = ['hash' => $hash, 'path' => "$path.$key", 'data' => $val];
                $this->collectSubStructures($val, $hashes, "$path.$key");
            }
        }
    }

    private function generateSchemaName(string $path): string {
        $parts = explode('.', trim($path, '.'));
        $last = end($parts);
        # Clean up common path segments
        $last = str_replace(['properties', 'content', 'application/json', 'schema'], '', $last);
        $last = trim($last, '.');
        return $last ?: 'auto_' . substr(md5($path), 0, 6);
    }

    # --- Formatting helpers ---

    private function formatHeader(int $length, ?string $key = null, ?array $fields = null): string {
        $header = '';
        if ($key !== null) {
            $header .= $this->encodeKey($key);
        }

        $delimSuffix = ($this->delimiter !== self::COMMA) ? $this->delimiter : '';
        $header .= self::OPEN_BRACKET . $length . $delimSuffix . self::CLOSE_BRACKET;

        if ($fields !== null) {
            $qFields = array_map([$this, 'encodeKey'], $fields);
            $header .= self::OPEN_BRACE . implode($this->delimiter, $qFields) . self::CLOSE_BRACE;
        }

        $header .= self::COLON;
        return $header;
    }

    private function extractTabularHeader(array $array): ?array {
        if (empty($array)) return null;

        $first = $array[0];
        if (!is_array($first) || $this->isSequentialArray($first)) return null;

        $firstKeys = array_keys($first);
        if (empty($firstKeys)) return null;

        foreach ($array as $row) {
            if (!is_array($row)) return null;
            $rowKeys = array_keys($row);
            if (count($rowKeys) !== count($firstKeys)) return null;
            foreach ($firstKeys as $fk) {
                if (!array_key_exists($fk, $row)) return null;
                if (!$this->isPrimitive($row[$fk])) return null;
            }
        }

        return $firstKeys;
    }

    # --- Primitive encoding (same as TOON) ---

    private function encodePrimitive(mixed $value): string {
        if ($value === null) return self::NULL_LITERAL;
        if (is_bool($value)) return $value ? self::TRUE_LITERAL : self::FALSE_LITERAL;
        if (is_int($value) || is_float($value)) {
            if ($value === 0 && is_float($value) && 1 / $value === -INF) return '0';
            return strval($value);
        }
        if (is_string($value)) return $this->encodeString($value);
        return '';
    }

    private function encodeString(string $value): string {
        if ($this->isSafeUnquoted($value)) return $value;
        return self::DOUBLE_QUOTE . $this->escapeString($value) . self::DOUBLE_QUOTE;
    }

    private function encodeKey(string $key): string {
        if ($this->isValidUnquotedKey($key)) return $key;
        return self::DOUBLE_QUOTE . $this->escapeString($key) . self::DOUBLE_QUOTE;
    }

    private function escapeString(string $str): string {
        $escaped = str_replace('\\', '\\\\', $str);
        $escaped = str_replace('"', '\\"', $escaped);
        $escaped = str_replace("\n", '\\n', $escaped);
        $escaped = str_replace("\r", '\\r', $escaped);
        $escaped = str_replace("\t", '\\t', $escaped);
        $escaped = str_replace(';', '\\;', $escaped);
        return $escaped;
    }

    private function isSafeUnquoted(string $value): bool {
        if ($value === '') return false;
        if (in_array($value, [self::TRUE_LITERAL, self::FALSE_LITERAL, self::NULL_LITERAL], true)) return false;
        if (is_numeric($value)) return false;
        if (strpos($value, $this->delimiter) !== false) return false;
        if (preg_match('/[\s:"\\\\;@#]/', $value)) return false;
        return true;
    }

    private function isValidUnquotedKey(string $key): bool {
        if ($key === '') return false;
        if (preg_match('/[:\[\]{}"\\\\\s;@]/', $key)) return false;
        return true;
    }

    private function encodeAndJoinPrimitives(array $values): string {
        return implode($this->delimiter . ' ', array_map([$this, 'encodePrimitive'], $values));
    }

    private function isPrimitive(mixed $value): bool {
        return is_null($value) || is_bool($value) || is_int($value) || is_float($value) || is_string($value);
    }

    private function isSequentialArray(mixed $arr): bool {
        if (!is_array($arr) || empty($arr)) return is_array($arr);
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private function isArrayOfPrimitives(array $arr): bool {
        if (!$this->isSequentialArray($arr)) return false;
        foreach ($arr as $item) {
            if (!$this->isPrimitive($item)) return false;
        }
        return true;
    }

    private function isArrayOfObjects(array $arr): bool {
        if (!$this->isSequentialArray($arr)) return false;
        foreach ($arr as $item) {
            if (!is_array($item) || $this->isSequentialArray($item)) return false;
        }
        return true;
    }

    private function indentedLine(int $depth, string $content): string {
        return str_repeat(' ', $this->indent * $depth) . $content;
    }

    private function indentedListItem(int $depth, string $content): string {
        return $this->indentedLine($depth, self::LIST_ITEM_PREFIX . $content);
    }
}
