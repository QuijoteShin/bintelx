<?php
# kernel/Toon/Encoder.php
namespace bX\Toon;

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

    private $indent;
    private $delimiter;

    public function __construct($options = []) {
        $this->indent = $options['indent'] ?? 2;
        $this->delimiter = $options['delimiter'] ?? self::COMMA;
    }

    public function encode($value) {
        $lines = [];
        foreach ($this->encodeValue($value, 0) as $line) {
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    private function encodeValue($value, $depth) {
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
            return;
        }

        if (is_object($value)) {
            yield from $this->encodeObject($value, $depth);
        }
    }

    private function encodeObject($obj, $depth) {
        $entries = is_array($obj) ? $obj : get_object_vars($obj);

        foreach ($entries as $key => $val) {
            if ($this->isPrimitive($val)) {
                $encodedKey = $this->encodeKey($key);
                $encodedValue = $this->encodePrimitive($val);
                yield $this->indentedLine($depth, "$encodedKey: $encodedValue");
            } elseif (is_array($val) && $this->isSequentialArray($val)) {
                yield from $this->encodeArray($key, $val, $depth);
            } elseif (is_array($val) || is_object($val)) {
                $encodedKey = $this->encodeKey($key);
                yield $this->indentedLine($depth, "$encodedKey:");
                if (!empty($val)) {
                    yield from $this->encodeObject($val, $depth + 1);
                }
            }
        }
    }

    private function encodeArray($key, $array, $depth) {
        $length = count($array);

        if ($length === 0) {
            $header = $this->formatHeader(0, $key);
            yield $this->indentedLine($depth, $header);
            return;
        }

        # Array de primitivos
        if ($this->isArrayOfPrimitives($array)) {
            $header = $this->formatHeader($length, $key);
            $values = $this->encodeAndJoinPrimitives($array);
            yield $this->indentedLine($depth, "$header $values");
            return;
        }

        # Array de arrays
        if ($this->isArrayOfArrays($array)) {
            $header = $this->formatHeader($length, $key);
            yield $this->indentedLine($depth, $header);

            foreach ($array as $subArray) {
                if ($this->isArrayOfPrimitives($subArray)) {
                    $subHeader = $this->formatHeader(count($subArray), null);
                    $values = $this->encodeAndJoinPrimitives($subArray);
                    yield $this->indentedListItem($depth + 1, "$subHeader $values");
                }
            }
            return;
        }

        # Array de objetos (formato tabular)
        if ($this->isArrayOfObjects($array)) {
            $header = $this->extractTabularHeader($array);
            if ($header !== null) {
                yield from $this->encodeTabularArray($key, $array, $header, $depth);
                return;
            }
        }

        # Array mixto
        yield from $this->encodeMixedArray($key, $array, $depth);
    }

    private function encodeTabularArray($key, $rows, $fields, $depth) {
        $length = count($rows);
        $header = $this->formatHeader($length, $key, $fields);
        yield $this->indentedLine($depth, $header);

        foreach ($rows as $row) {
            $values = [];
            foreach ($fields as $field) {
                $values[] = isset($row[$field]) ? $row[$field] : null;
            }
            $encoded = $this->encodeAndJoinPrimitives($values);
            yield $this->indentedLine($depth + 1, $encoded);
        }
    }

    private function encodeMixedArray($key, $items, $depth) {
        $header = $this->formatHeader(count($items), $key);
        yield $this->indentedLine($depth, $header);

        foreach ($items as $item) {
            if ($this->isPrimitive($item)) {
                yield $this->indentedListItem($depth + 1, $this->encodePrimitive($item));
            } elseif (is_array($item) && $this->isSequentialArray($item)) {
                if ($this->isArrayOfPrimitives($item)) {
                    $itemHeader = $this->formatHeader(count($item), null);
                    $values = $this->encodeAndJoinPrimitives($item);
                    yield $this->indentedListItem($depth + 1, "$itemHeader $values");
                }
            } elseif (is_array($item) || is_object($item)) {
                yield from $this->encodeObjectAsListItem($item, $depth + 1);
            }
        }
    }

    private function encodeObjectAsListItem($obj, $depth) {
        $entries = is_array($obj) ? $obj : get_object_vars($obj);

        if (empty($entries)) {
            yield $this->indentedLine($depth, self::LIST_ITEM_PREFIX);
            return;
        }

        $keys = array_keys($entries);
        $firstKey = $keys[0];
        $firstValue = $entries[$firstKey];
        $restEntries = array_slice($entries, 1, null, true);

        $encodedKey = $this->encodeKey($firstKey);

        if ($this->isPrimitive($firstValue)) {
            $encodedValue = $this->encodePrimitive($firstValue);
            yield $this->indentedListItem($depth, "$encodedKey: $encodedValue");
        } elseif (is_array($firstValue) && $this->isSequentialArray($firstValue)) {
            if (empty($firstValue)) {
                $header = $this->formatHeader(0, null);
                yield $this->indentedListItem($depth, "$encodedKey$header");
            } elseif ($this->isArrayOfPrimitives($firstValue)) {
                $header = $this->formatHeader(count($firstValue), null);
                $values = $this->encodeAndJoinPrimitives($firstValue);
                yield $this->indentedListItem($depth, "$encodedKey$header $values");
            }
        } elseif (is_array($firstValue) || is_object($firstValue)) {
            yield $this->indentedListItem($depth, "$encodedKey:");
            yield from $this->encodeObject($firstValue, $depth + 2);
        }

        if (!empty($restEntries)) {
            yield from $this->encodeObject($restEntries, $depth + 1);
        }
    }

    private function extractTabularHeader($array) {
        if (empty($array)) {
            return null;
        }

        $first = $array[0];
        if (!is_array($first) && !is_object($first)) {
            return null;
        }

        $firstKeys = is_array($first) ? array_keys($first) : array_keys(get_object_vars($first));

        if (empty($firstKeys)) {
            return null;
        }

        # Verificar que todos los objetos tengan los mismos campos y valores primitivos
        foreach ($array as $row) {
            $rowData = is_array($row) ? $row : get_object_vars($row);
            $rowKeys = array_keys($rowData);

            if (count($rowKeys) !== count($firstKeys)) {
                return null;
            }

            foreach ($firstKeys as $key) {
                if (!array_key_exists($key, $rowData)) {
                    return null;
                }
                if (!$this->isPrimitive($rowData[$key])) {
                    return null;
                }
            }
        }

        return $firstKeys;
    }

    private function formatHeader($length, $key = null, $fields = null) {
        $header = '';

        if ($key !== null) {
            $header .= $this->encodeKey($key);
        }

        $delimiterSuffix = ($this->delimiter !== self::COMMA) ? $this->delimiter : '';
        $header .= self::OPEN_BRACKET . $length . $delimiterSuffix . self::CLOSE_BRACKET;

        if ($fields !== null) {
            $quotedFields = array_map([$this, 'encodeKey'], $fields);
            $header .= self::OPEN_BRACE . implode($this->delimiter, $quotedFields) . self::CLOSE_BRACE;
        }

        $header .= self::COLON;

        return $header;
    }

    private function encodePrimitive($value) {
        if ($value === null) {
            return self::NULL_LITERAL;
        }

        if (is_bool($value)) {
            return $value ? self::TRUE_LITERAL : self::FALSE_LITERAL;
        }

        if (is_int($value) || is_float($value)) {
            # Normalizar -0 a 0
            if ($value === 0 && is_float($value) && 1 / $value === -INF) {
                return '0';
            }
            return strval($value);
        }

        if (is_string($value)) {
            return $this->encodeString($value);
        }

        return '';
    }

    private function encodeString($value) {
        if ($this->isSafeUnquoted($value)) {
            return $value;
        }

        return self::DOUBLE_QUOTE . $this->escapeString($value) . self::DOUBLE_QUOTE;
    }

    private function encodeKey($key) {
        if ($this->isValidUnquotedKey($key)) {
            return $key;
        }

        return self::DOUBLE_QUOTE . $this->escapeString($key) . self::DOUBLE_QUOTE;
    }

    private function escapeString($str) {
        $escaped = str_replace('\\', '\\\\', $str);
        $escaped = str_replace('"', '\\"', $escaped);
        $escaped = str_replace("\n", '\\n', $escaped);
        $escaped = str_replace("\r", '\\r', $escaped);
        $escaped = str_replace("\t", '\\t', $escaped);
        return $escaped;
    }

    private function isSafeUnquoted($value) {
        if ($value === '') {
            return false;
        }

        # Keywords reservados
        if (in_array($value, [self::TRUE_LITERAL, self::FALSE_LITERAL, self::NULL_LITERAL], true)) {
            return false;
        }

        # Números
        if (is_numeric($value)) {
            return false;
        }

        # Contiene delimiter
        if (strpos($value, $this->delimiter) !== false) {
            return false;
        }

        # Contiene caracteres especiales
        if (preg_match('/[\s:"\\\\]/', $value)) {
            return false;
        }

        return true;
    }

    private function isValidUnquotedKey($key) {
        if ($key === '') {
            return false;
        }

        # Contiene caracteres especiales
        if (preg_match('/[:\[\]{}"\\\\\s]/', $key)) {
            return false;
        }

        return true;
    }

    private function encodeAndJoinPrimitives($values) {
        $encoded = array_map([$this, 'encodePrimitive'], $values);
        return implode($this->delimiter, $encoded);
    }

    private function isPrimitive($value) {
        return is_null($value) || is_bool($value) || is_int($value) || is_float($value) || is_string($value);
    }

    private function isSequentialArray($array) {
        if (!is_array($array)) {
            return false;
        }

        if (empty($array)) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    private function isArrayOfPrimitives($array) {
        if (!is_array($array) || !$this->isSequentialArray($array)) {
            return false;
        }

        foreach ($array as $item) {
            if (!$this->isPrimitive($item)) {
                return false;
            }
        }

        return true;
    }

    private function isArrayOfArrays($array) {
        if (!is_array($array) || !$this->isSequentialArray($array)) {
            return false;
        }

        foreach ($array as $item) {
            if (!is_array($item) || !$this->isSequentialArray($item)) {
                return false;
            }
        }

        return true;
    }

    private function isArrayOfObjects($array) {
        if (!is_array($array) || !$this->isSequentialArray($array)) {
            return false;
        }

        foreach ($array as $item) {
            if (!is_array($item) && !is_object($item)) {
                return false;
            }
            if (is_array($item) && $this->isSequentialArray($item)) {
                return false;
            }
        }

        return true;
    }

    private function indentedLine($depth, $content) {
        return str_repeat(' ', $this->indent * $depth) . $content;
    }

    private function indentedListItem($depth, $content) {
        return $this->indentedLine($depth, self::LIST_ITEM_PREFIX . $content);
    }
}
