<?php
# kernel/Toon/Decoder.php
namespace bX\Toon;

use bX\Exception;

class Decoder {
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
    const BACKSLASH = '\\';

    private $indent;
    private $strict;
    private $lines;
    private $currentLine;
    private $lineCount;

    public function __construct($options = []) {
        $this->indent = $options['indent'] ?? 2;
        $this->strict = $options['strict'] ?? true;
    }

    public function decode($toonString) {
        $this->lines = explode("\n", $toonString);
        $this->lineCount = count($this->lines);
        $this->currentLine = 0;

        $parsedLines = $this->parseLines();

        if (empty($parsedLines)) {
            return [];
        }

        $first = $parsedLines[0];

        # Detectar array raíz
        if ($this->isArrayHeader($first['content'])) {
            return $this->decodeArrayFromHeader(0, $parsedLines);
        }

        # Detectar primitivo único
        if (count($parsedLines) === 1 && !$this->isKeyValueLine($first['content'])) {
            return $this->parsePrimitive($first['content']);
        }

        # Objeto raíz
        return $this->decodeObject(0, $parsedLines, 0);
    }

    private function parseLines() {
        $parsed = [];

        foreach ($this->lines as $lineNum => $line) {
            # Ignorar líneas vacías
            if (trim($line) === '') {
                continue;
            }

            $depth = $this->calculateDepth($line);
            $content = ltrim($line);

            $parsed[] = [
                'depth' => $depth,
                'content' => $content,
                'lineNum' => $lineNum
            ];
        }

        return $parsed;
    }

    private function calculateDepth($line) {
        $spaces = 0;
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            if ($line[$i] === ' ') {
                $spaces++;
            } elseif ($line[$i] === "\t") {
                throw new Exception("Tabs not allowed for indentation");
            } else {
                break;
            }
        }

        if ($spaces % $this->indent !== 0) {
            throw new Exception("Invalid indentation: $spaces spaces");
        }

        return $spaces / $this->indent;
    }

    private function decodeObject($baseDepth, &$parsedLines, $startIndex) {
        $result = [];
        $i = $startIndex;

        while ($i < count($parsedLines)) {
            $line = $parsedLines[$i];

            if ($line['depth'] < $baseDepth) {
                break;
            }

            if ($line['depth'] > $baseDepth) {
                $i++;
                continue;
            }

            # Array header
            if ($this->isArrayHeader($line['content'])) {
                $header = $this->parseArrayHeader($line['content']);
                if ($header['key'] !== null) {
                    $result[$header['key']] = $this->decodeArrayFromHeader($i, $parsedLines);
                    $i++;
                    continue;
                }
            }

            # Key-value pair
            if ($this->isKeyValueLine($line['content'])) {
                list($key, $value, $nextIndex) = $this->decodeKeyValue($line, $parsedLines, $i, $baseDepth);
                $result[$key] = $value;
                $i = $nextIndex;
                continue;
            }

            $i++;
        }

        return $result;
    }

    private function decodeKeyValue($line, &$parsedLines, $index, $baseDepth) {
        $content = $line['content'];

        # Parsear clave
        $keyData = $this->parseKey($content);
        $key = $keyData['key'];
        $rest = trim(substr($content, $keyData['end']));

        # Si hay valor después del colon
        if ($rest !== '') {
            $value = $this->parsePrimitive($rest);
            return [$key, $value, $index + 1];
        }

        # Verificar siguiente línea
        if ($index + 1 < count($parsedLines)) {
            $nextLine = $parsedLines[$index + 1];

            if ($nextLine['depth'] > $baseDepth) {
                # Objeto anidado
                $value = $this->decodeObject($baseDepth + 1, $parsedLines, $index + 1);
                # Encontrar siguiente línea al mismo nivel
                $nextIndex = $index + 1;
                while ($nextIndex < count($parsedLines) && $parsedLines[$nextIndex]['depth'] > $baseDepth) {
                    $nextIndex++;
                }
                return [$key, $value, $nextIndex];
            }
        }

        # Objeto vacío
        return [$key, [], $index + 1];
    }

    private function decodeArrayFromHeader($index, &$parsedLines) {
        $line = $parsedLines[$index];
        $header = $this->parseArrayHeader($line['content']);
        $baseDepth = $line['depth'];

        $length = $header['length'];
        $delimiter = $header['delimiter'];
        $fields = $header['fields'];
        $inlineValues = $header['inlineValues'];

        # Array vacío
        if ($length === 0) {
            return [];
        }

        # Array inline de primitivos
        if ($inlineValues !== null && $fields === null) {
            return $this->parseDelimitedValues($inlineValues, $delimiter);
        }

        # Array tabular
        if ($fields !== null) {
            return $this->decodeTabularArray($index, $parsedLines, $baseDepth, $length, $fields, $delimiter);
        }

        # Array expandido
        return $this->decodeExpandedArray($index, $parsedLines, $baseDepth, $length);
    }

    private function decodeTabularArray($headerIndex, &$parsedLines, $baseDepth, $expectedLength, $fields, $delimiter) {
        $result = [];
        $i = $headerIndex + 1;

        while ($i < count($parsedLines) && count($result) < $expectedLength) {
            $line = $parsedLines[$i];

            if ($line['depth'] !== $baseDepth + 1) {
                break;
            }

            $values = $this->parseDelimitedValues($line['content'], $delimiter);

            $row = [];
            for ($j = 0; $j < count($fields); $j++) {
                $row[$fields[$j]] = isset($values[$j]) ? $values[$j] : null;
            }

            $result[] = $row;
            $i++;
        }

        if ($this->strict && count($result) !== $expectedLength) {
            throw new Exception("Expected $expectedLength rows, got " . count($result));
        }

        return $result;
    }

    private function decodeExpandedArray($headerIndex, &$parsedLines, $baseDepth, $expectedLength) {
        $result = [];
        $i = $headerIndex + 1;

        while ($i < count($parsedLines) && count($result) < $expectedLength) {
            $line = $parsedLines[$i];

            if ($line['depth'] !== $baseDepth + 1) {
                break;
            }

            if (strpos($line['content'], self::LIST_ITEM_PREFIX) === 0) {
                $itemContent = substr($line['content'], strlen(self::LIST_ITEM_PREFIX));

                # List item con key-value
                if ($this->isKeyValueLine($itemContent)) {
                    $obj = $this->decodeListItemObject($line, $parsedLines, $i, $baseDepth);
                    $result[] = $obj;
                    # Avanzar hasta siguiente item al mismo nivel
                    $i++;
                    while ($i < count($parsedLines) && $parsedLines[$i]['depth'] > $baseDepth + 1) {
                        $i++;
                    }
                    continue;
                }

                # List item primitivo o array inline
                if ($this->isArrayHeader($itemContent)) {
                    $itemHeader = $this->parseArrayHeader($itemContent);
                    if ($itemHeader['inlineValues'] !== null) {
                        $result[] = $this->parseDelimitedValues($itemHeader['inlineValues'], $itemHeader['delimiter']);
                    }
                } else {
                    $result[] = $this->parsePrimitive($itemContent);
                }
            }

            $i++;
        }

        if ($this->strict && count($result) !== $expectedLength) {
            throw new Exception("Expected $expectedLength items, got " . count($result));
        }

        return $result;
    }

    private function decodeListItemObject($line, &$parsedLines, $index, $baseDepth) {
        $itemContent = substr($line['content'], strlen(self::LIST_ITEM_PREFIX));

        # Parsear primer key-value del list item
        $keyData = $this->parseKey($itemContent);
        $key = $keyData['key'];
        $rest = trim(substr($itemContent, $keyData['end']));

        $result = [];

        if ($rest !== '') {
            $result[$key] = $this->parsePrimitive($rest);
        } elseif ($index + 1 < count($parsedLines) && $parsedLines[$index + 1]['depth'] === $baseDepth + 2) {
            # Valor anidado
            $result[$key] = $this->decodeObject($baseDepth + 2, $parsedLines, $index + 1);
        } else {
            $result[$key] = [];
        }

        # Resto de propiedades al nivel $baseDepth + 1
        $i = $index + 1;
        while ($i < count($parsedLines)) {
            $nextLine = $parsedLines[$i];

            if ($nextLine['depth'] < $baseDepth + 1) {
                break;
            }

            if ($nextLine['depth'] === $baseDepth + 1) {
                if (strpos($nextLine['content'], self::LIST_ITEM_PREFIX) === 0) {
                    # Siguiente list item
                    break;
                }

                # Propiedad adicional del objeto
                if ($this->isKeyValueLine($nextLine['content'])) {
                    list($k, $v, $nextIdx) = $this->decodeKeyValue($nextLine, $parsedLines, $i, $baseDepth + 1);
                    $result[$k] = $v;
                    $i = $nextIdx;
                    continue;
                }
            }

            $i++;
        }

        return $result;
    }

    private function parseArrayHeader($content) {
        $key = null;
        $bracketStart = strpos($content, self::OPEN_BRACKET);

        # Extraer key si existe
        if ($bracketStart > 0) {
            $rawKey = trim(substr($content, 0, $bracketStart));
            $key = $this->parseStringLiteral($rawKey);
        }

        $bracketEnd = strpos($content, self::CLOSE_BRACKET, $bracketStart);
        if ($bracketEnd === false) {
            throw new Exception("Invalid array header: missing closing bracket");
        }

        $bracketContent = substr($content, $bracketStart + 1, $bracketEnd - $bracketStart - 1);

        # Parsear longitud y delimiter
        $delimiter = self::COMMA;
        if (substr($bracketContent, -1) === self::TAB) {
            $delimiter = self::TAB;
            $bracketContent = substr($bracketContent, 0, -1);
        } elseif (substr($bracketContent, -1) === self::PIPE) {
            $delimiter = self::PIPE;
            $bracketContent = substr($bracketContent, 0, -1);
        }

        $length = intval($bracketContent);

        # Buscar fields
        $fields = null;
        $braceStart = strpos($content, self::OPEN_BRACE, $bracketEnd);
        $colonIndex = strpos($content, self::COLON, $bracketEnd);

        if ($braceStart !== false && $braceStart < $colonIndex) {
            $braceEnd = strpos($content, self::CLOSE_BRACE, $braceStart);
            if ($braceEnd !== false) {
                $fieldsContent = substr($content, $braceStart + 1, $braceEnd - $braceStart - 1);
                $fields = $this->parseDelimitedValues($fieldsContent, $delimiter);
            }
        }

        # Buscar valores inline
        $inlineValues = null;
        if ($colonIndex !== false) {
            $afterColon = trim(substr($content, $colonIndex + 1));
            if ($afterColon !== '') {
                $inlineValues = $afterColon;
            }
        }

        return [
            'key' => $key,
            'length' => $length,
            'delimiter' => $delimiter,
            'fields' => $fields,
            'inlineValues' => $inlineValues
        ];
    }

    private function parseDelimitedValues($input, $delimiter) {
        $values = [];
        $buffer = '';
        $inQuotes = false;
        $len = strlen($input);

        for ($i = 0; $i < $len; $i++) {
            $char = $input[$i];

            if ($char === self::BACKSLASH && $i + 1 < $len && $inQuotes) {
                $buffer .= $char . $input[$i + 1];
                $i++;
                continue;
            }

            if ($char === self::DOUBLE_QUOTE) {
                $inQuotes = !$inQuotes;
                $buffer .= $char;
                continue;
            }

            if ($char === $delimiter && !$inQuotes) {
                $values[] = $this->parsePrimitive(trim($buffer));
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if ($buffer !== '' || count($values) > 0) {
            $values[] = $this->parsePrimitive(trim($buffer));
        }

        return $values;
    }

    private function parsePrimitive($token) {
        $trimmed = trim($token);

        if ($trimmed === '') {
            return '';
        }

        # String con comillas
        if ($trimmed[0] === self::DOUBLE_QUOTE) {
            return $this->parseStringLiteral($trimmed);
        }

        # Literales boolean/null
        if ($trimmed === self::TRUE_LITERAL) {
            return true;
        }
        if ($trimmed === self::FALSE_LITERAL) {
            return false;
        }
        if ($trimmed === self::NULL_LITERAL) {
            return null;
        }

        # Número
        if (is_numeric($trimmed)) {
            if (strpos($trimmed, '.') !== false || strpos($trimmed, 'e') !== false || strpos($trimmed, 'E') !== false) {
                $num = floatval($trimmed);
                # Normalizar -0 a 0
                return ($num === 0.0 && 1 / $num === -INF) ? 0 : $num;
            }
            return intval($trimmed);
        }

        # String sin comillas
        return $trimmed;
    }

    private function parseStringLiteral($token) {
        $trimmed = trim($token);

        if ($trimmed[0] === self::DOUBLE_QUOTE) {
            $closingQuote = $this->findClosingQuote($trimmed, 0);

            if ($closingQuote === -1) {
                throw new Exception("Unterminated string");
            }

            if ($closingQuote !== strlen($trimmed) - 1) {
                throw new Exception("Unexpected characters after closing quote");
            }

            $content = substr($trimmed, 1, $closingQuote - 1);
            return $this->unescapeString($content);
        }

        return $trimmed;
    }

    private function findClosingQuote($str, $start) {
        $len = strlen($str);
        $i = $start + 1;

        while ($i < $len) {
            if ($str[$i] === self::BACKSLASH && $i + 1 < $len) {
                $i += 2;
                continue;
            }

            if ($str[$i] === self::DOUBLE_QUOTE) {
                return $i;
            }

            $i++;
        }

        return -1;
    }

    private function unescapeString($str) {
        $result = str_replace('\\\\', '\\', $str);
        $result = str_replace('\\"', '"', $result);
        $result = str_replace('\\n', "\n", $result);
        $result = str_replace('\\r', "\r", $result);
        $result = str_replace('\\t', "\t", $result);
        return $result;
    }

    private function parseKey($content) {
        $isQuoted = $content[0] === self::DOUBLE_QUOTE;

        if ($isQuoted) {
            $closingQuote = $this->findClosingQuote($content, 0);

            if ($closingQuote === -1) {
                throw new Exception("Unterminated quoted key");
            }

            $key = $this->unescapeString(substr($content, 1, $closingQuote - 1));
            $end = $closingQuote + 1;

            if ($end >= strlen($content) || $content[$end] !== self::COLON) {
                throw new Exception("Missing colon after key");
            }

            $end++;

            return ['key' => $key, 'end' => $end];
        }

        # Unquoted key
        $colonPos = strpos($content, self::COLON);

        if ($colonPos === false) {
            throw new Exception("Missing colon after key");
        }

        $key = trim(substr($content, 0, $colonPos));

        return ['key' => $key, 'end' => $colonPos + 1];
    }

    private function isArrayHeader($content) {
        return strpos($content, self::OPEN_BRACKET) !== false && strpos($content, self::COLON) !== false;
    }

    private function isKeyValueLine($content) {
        return strpos($content, self::COLON) !== false;
    }
}
