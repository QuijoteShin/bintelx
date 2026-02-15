<?php
# kernel/Scon/Decoder.php
namespace bX\Scon;

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
    const SEMICOLON = ';';

    private int $indent;
    private bool $strict;
    private SchemaRegistry $registry;
    private array $directives;

    public function __construct(array $options = []) {
        $this->indent = $options['indent'] ?? 2;
        $this->strict = $options['strict'] ?? true;
        $this->registry = new SchemaRegistry();
        $this->directives = [];
    }

    public function getRegistry(): SchemaRegistry {
        return $this->registry;
    }

    public function getDirectives(): array {
        return $this->directives;
    }

    # Decode SCON string to PHP array
    public function decode(string $sconString): array {
        # Expand if minified
        if ($this->isMinified($sconString)) {
            $sconString = $this->expandMinified($sconString);
        }

        $lines = explode("\n", $sconString);
        $parsedLines = [];
        $bodyStart = 0;

        # First pass: extract header, directives, and definitions
        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);

            # Skip empty lines and comments
            if ($trimmed === '' || $trimmed[0] === '#') {
                # Check for header
                if (str_starts_with($trimmed, '#!scon/')) {
                    continue;
                }
                continue;
            }

            # Directives (@@)
            if (str_starts_with($trimmed, '@@')) {
                $this->parseDirective($trimmed);
                continue;
            }

            # Schema definition (s:name ...)
            if (preg_match('/^s:(\S+)\s+/', $trimmed, $m)) {
                $name = $m[1];
                $defStr = substr($trimmed, strlen($m[0]));
                $def = $this->parseInlineValue($defStr);
                $this->registry->register('s', $name, is_array($def) ? $def : []);
                continue;
            }

            # Response group definition (r:name ...)
            if (preg_match('/^r:(\S+)\s+/', $trimmed, $m)) {
                $name = $m[1];
                $defStr = substr($trimmed, strlen($m[0]));
                $def = $this->parseResponseGroup($defStr);
                $this->registry->register('r', $name, $def);
                continue;
            }

            # Security group definition (sec:name ...)
            if (preg_match('/^sec:(\S+)\s+/', $trimmed, $m)) {
                $name = $m[1];
                $defStr = substr($trimmed, strlen($m[0]));
                $def = $this->parseInlineValue($defStr);
                $this->registry->register('sec', $name, is_array($def) ? $def : []);
                continue;
            }

            # @use import (deferred - store for later)
            if (str_starts_with($trimmed, '@use ')) {
                $this->directives['imports'][] = $trimmed;
                continue;
            }

            # Body line - parse normally
            $depth = $this->calculateDepth($line);
            $content = ltrim($line);
            $parsedLines[] = [
                'depth' => $depth,
                'content' => $content,
                'lineNum' => $lineNum
            ];
        }

        if (empty($parsedLines)) {
            return [];
        }

        # Second pass: parse body with ref resolution
        $first = $parsedLines[0];

        if ($this->isArrayHeader($first['content'])) {
            $header = $this->parseArrayHeader($first['content']);
            if ($header['key'] === null) {
                return $this->decodeArrayFromHeader(0, $parsedLines);
            }
            # Has a key prefix (e.g. tags[3]:) — fall through to decodeObject
        }

        if (count($parsedLines) === 1 && !$this->isKeyValueLine($first['content'])) {
            $val = $this->parsePrimitive($first['content']);
            return is_array($val) ? $val : [$val];
        }

        return $this->decodeObject(0, $parsedLines, 0);
    }

    # Parse @@ directive
    private function parseDirective(string $line): void {
        $directive = substr($line, 2); # remove @@
        if (str_starts_with($directive, 'enforce(') && str_ends_with($directive, ')')) {
            $spec = substr($directive, 8, -1);
            $this->directives['enforce'] = $spec;
        } else {
            $this->directives['mode'] = $directive; # loose, warn, strict
        }
    }

    # Parse response group inline: {200:"desc" @s:ok, 400:"desc" @s:err}
    private function parseResponseGroup(string $input): array {
        $input = trim($input);
        if ($input[0] !== self::OPEN_BRACE) return [];

        $inner = $this->extractBraceContent($input);
        $result = [];
        $parts = $this->splitTopLevel($inner, self::COMMA);

        foreach ($parts as $part) {
            $part = trim($part);
            # Pattern: 200:"description" @s:schemaName {overrides}
            if (preg_match('/^(\d+):("(?:[^"\\\\]|\\\\.)*")\s*(?:@s:(\S+))?\s*(.*)$/', $part, $m)) {
                $code = $m[1];
                $desc = $this->parseStringLiteral($m[2]);
                $entry = ['description' => $desc];
                if (!empty($m[3])) {
                    $entry['schemaRef'] = $m[3];
                }
                if (!empty($m[4])) {
                    $overridesStr = trim($m[4]);
                    if ($overridesStr !== '' && $overridesStr[0] === self::OPEN_BRACE) {
                        $entry['overrides'] = $this->parseInlineValue($overridesStr);
                    }
                }
                $result[$code] = $entry;
            }
        }

        return $result;
    }

    # Parse inline value: {key:val, key2:val2} or [a, b, c] or primitive
    private function parseInlineValue(string $input): mixed {
        $input = trim($input);
        if ($input === '') return '';

        # Object
        if ($input[0] === self::OPEN_BRACE) {
            $inner = $this->extractBraceContent($input);
            return $this->parseInlineObject($inner);
        }

        # Array
        if ($input[0] === self::OPEN_BRACKET) {
            $close = $this->findMatchingBracket($input, 0);
            if ($close !== false) {
                $inner = substr($input, 1, $close - 1);
                $items = $this->splitTopLevel($inner, self::COMMA);
                return array_map(fn($i) => $this->parseInlineValue(trim($i)), $items);
            }
        }

        # Reference
        if (str_starts_with($input, '@s:') || str_starts_with($input, '@r:') || str_starts_with($input, '@sec:')) {
            return $this->resolveReference($input);
        }

        return $this->parsePrimitive($input);
    }

    # Parse inline object: key:val, key2:val2
    private function parseInlineObject(string $inner): array {
        $result = [];
        $parts = $this->splitTopLevel($inner, self::COMMA);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;

            # Find the key:value separator
            $colonPos = $this->findKeyColon($part);
            if ($colonPos === false) continue;

            $key = trim(substr($part, 0, $colonPos));
            $val = trim(substr($part, $colonPos + 1));

            # Unquote key if needed
            $key = $this->parseStringLiteral($key);

            # Dot-notation key: expand to nested structure
            if (str_contains($key, '.')) {
                $this->setDotPath($result, $key, $this->parseInlineValue($val));
            } else {
                $result[$key] = $this->parseInlineValue($val);
            }
        }

        return $result;
    }

    # Resolve @type:name reference, optionally with overrides
    private function resolveReference(string $refStr): mixed {
        # Parse: @type:name {overrides} or @s:a | @s:b (polymorphic)
        if (str_contains($refStr, ' | ')) {
            $refs = [];
            foreach (explode(' | ', $refStr) as $r) {
                $r = trim($r);
                if (preg_match('/^@(s|r|sec):(\S+)/', $r, $m)) {
                    $refs[] = ['type' => $m[1], 'name' => $m[2]];
                }
            }
            return $this->registry->resolvePolymorphic($refs);
        }

        if (preg_match('/^@(s|r|sec):(\S+)\s*(.*)$/', $refStr, $m)) {
            $type = $m[1];
            $name = $m[2];
            $rest = trim($m[3] ?? '');

            if ($rest !== '' && $rest[0] === self::OPEN_BRACE) {
                $overrides = $this->parseInlineValue($rest);
                return $this->registry->resolveWithOverride($type, $name, is_array($overrides) ? $overrides : []);
            }

            return $this->registry->resolve($type, $name);
        }

        return $refStr;
    }

    # Check if string is minified (contains ; but no newlines in non-quoted areas)
    private function isMinified(string $str): bool {
        return !str_contains($str, "\n") && str_contains($str, self::SEMICOLON);
    }

    # Expand minified SCON to indented format (delegates to Minifier)
    private function expandMinified(string $input): string {
        return Minifier::expand($input, $this->indent);
    }

    # --- TOON-compatible body parsing ---

    private function calculateDepth(string $line): int {
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
        if ($this->indent > 0 && $spaces % $this->indent !== 0) {
            throw new Exception("Invalid indentation: $spaces spaces");
        }
        return $this->indent > 0 ? $spaces / $this->indent : 0;
    }

    private function decodeObject(int $baseDepth, array &$parsedLines, int $startIndex): array {
        $result = [];
        $i = $startIndex;

        while ($i < count($parsedLines)) {
            $line = $parsedLines[$i];
            if ($line['depth'] < $baseDepth) break;
            if ($line['depth'] > $baseDepth) { $i++; continue; }

            $content = $line['content'];

            # Array header
            if ($this->isArrayHeader($content)) {
                $header = $this->parseArrayHeader($content);
                if ($header['key'] !== null) {
                    $result[$header['key']] = $this->decodeArrayFromHeader($i, $parsedLines);
                    $i++;
                    while ($i < count($parsedLines) && $parsedLines[$i]['depth'] > $baseDepth) $i++;
                    continue;
                }
            }

            # Key-value
            if ($this->isKeyValueLine($content)) {
                list($key, $value, $nextIndex) = $this->decodeKeyValue($line, $parsedLines, $i, $baseDepth);
                $result[$key] = $value;
                $i = $nextIndex;
                continue;
            }

            $i++;
        }

        return $result;
    }

    private function decodeKeyValue(array $line, array &$parsedLines, int $index, int $baseDepth): array {
        $content = $line['content'];
        $keyData = $this->parseKey($content);
        $key = $keyData['key'];
        $rest = trim(substr($content, $keyData['end']));

        # Check for reference value
        if ($rest !== '' && str_starts_with($rest, '@')) {
            $value = $this->resolveReference($rest);
            return [$key, $value, $index + 1];
        }

        if ($rest !== '') {
            $value = $this->parsePrimitive($rest);
            return [$key, $value, $index + 1];
        }

        # Nested object
        if ($index + 1 < count($parsedLines) && $parsedLines[$index + 1]['depth'] > $baseDepth) {
            $value = $this->decodeObject($baseDepth + 1, $parsedLines, $index + 1);
            $nextIndex = $index + 1;
            while ($nextIndex < count($parsedLines) && $parsedLines[$nextIndex]['depth'] > $baseDepth) {
                $nextIndex++;
            }
            return [$key, $value, $nextIndex];
        }

        return [$key, [], $index + 1];
    }

    private function decodeArrayFromHeader(int $index, array &$parsedLines): array {
        $line = $parsedLines[$index];
        $header = $this->parseArrayHeader($line['content']);
        $baseDepth = $line['depth'];

        if ($header['length'] === 0) return [];

        if ($header['inlineValues'] !== null && $header['fields'] === null) {
            return $this->parseDelimitedValues($header['inlineValues'], $header['delimiter']);
        }

        if ($header['fields'] !== null) {
            return $this->decodeTabularArray($index, $parsedLines, $baseDepth, $header['length'], $header['fields'], $header['delimiter']);
        }

        return $this->decodeExpandedArray($index, $parsedLines, $baseDepth, $header['length']);
    }

    private function decodeTabularArray(int $headerIdx, array &$parsedLines, int $baseDepth, int $expected, array $fields, string $delim): array {
        $result = [];
        $i = $headerIdx + 1;

        while ($i < count($parsedLines) && count($result) < $expected) {
            if ($parsedLines[$i]['depth'] !== $baseDepth + 1) break;
            $values = $this->parseDelimitedValues($parsedLines[$i]['content'], $delim);
            $row = [];
            for ($j = 0; $j < count($fields); $j++) {
                $row[$fields[$j]] = $values[$j] ?? null;
            }
            $result[] = $row;
            $i++;
        }

        return $result;
    }

    private function decodeExpandedArray(int $headerIdx, array &$parsedLines, int $baseDepth, int $expected): array {
        $result = [];
        $i = $headerIdx + 1;

        while ($i < count($parsedLines) && count($result) < $expected) {
            $line = $parsedLines[$i];
            if ($line['depth'] !== $baseDepth + 1) break;

            if (strpos($line['content'], self::LIST_ITEM_PREFIX) === 0) {
                $itemContent = substr($line['content'], strlen(self::LIST_ITEM_PREFIX));

                # Schema/response/security reference as list item (e.g. - @s:name)
                if (str_starts_with($itemContent, '@s:') || str_starts_with($itemContent, '@r:') || str_starts_with($itemContent, '@sec:')) {
                    $result[] = $this->resolveReference($itemContent);
                    $i++;
                    continue;
                }

                if ($this->isKeyValueLine($itemContent)) {
                    $obj = $this->decodeListItemObject($line, $parsedLines, $i, $baseDepth);
                    $result[] = $obj;
                    $i++;
                    while ($i < count($parsedLines) && $parsedLines[$i]['depth'] > $baseDepth + 1) $i++;
                    continue;
                }

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

        return $result;
    }

    private function decodeListItemObject(array $line, array &$parsedLines, int $index, int $baseDepth): array {
        $itemContent = substr($line['content'], strlen(self::LIST_ITEM_PREFIX));
        $keyData = $this->parseKey($itemContent);
        $key = $keyData['key'];
        $rest = trim(substr($itemContent, $keyData['end']));

        $result = [];
        $contDepth = $baseDepth + 2; # continuation fields are 2 levels deeper than array header

        if ($rest !== '' && str_starts_with($rest, '@')) {
            $result[$key] = $this->resolveReference($rest);
        } elseif ($rest !== '') {
            $result[$key] = $this->parsePrimitive($rest);
        } elseif ($index + 1 < count($parsedLines) && $parsedLines[$index + 1]['depth'] >= $contDepth) {
            $result[$key] = $this->decodeObject($contDepth, $parsedLines, $index + 1);
        } else {
            $result[$key] = [];
        }

        # Parse continuation fields (same indent as content after "- ")
        $i = $index + 1;
        while ($i < count($parsedLines)) {
            $nextLine = $parsedLines[$i];
            if ($nextLine['depth'] < $contDepth) break;
            if ($nextLine['depth'] === $contDepth) {
                if (strpos($nextLine['content'], self::LIST_ITEM_PREFIX) === 0) break;
                # Array header in continuation (e.g. tags[2]: x, y)
                if ($this->isArrayHeader($nextLine['content'])) {
                    $header = $this->parseArrayHeader($nextLine['content']);
                    if ($header['key'] !== null) {
                        $result[$header['key']] = $this->decodeArrayFromHeader($i, $parsedLines);
                        $i++;
                        while ($i < count($parsedLines) && $parsedLines[$i]['depth'] > $contDepth) $i++;
                        continue;
                    }
                }
                if ($this->isKeyValueLine($nextLine['content'])) {
                    list($k, $v, $nextIdx) = $this->decodeKeyValue($nextLine, $parsedLines, $i, $contDepth);
                    $result[$k] = $v;
                    $i = $nextIdx;
                    continue;
                }
            }
            $i++;
        }

        return $result;
    }

    # --- Parsing helpers ---

    private function parseArrayHeader(string $content): array {
        $key = null;
        $bracketStart = strpos($content, self::OPEN_BRACKET);

        if ($bracketStart > 0) {
            $rawKey = trim(substr($content, 0, $bracketStart));
            $key = $this->parseStringLiteral($rawKey);
        }

        $bracketEnd = strpos($content, self::CLOSE_BRACKET, $bracketStart);
        if ($bracketEnd === false) {
            throw new Exception("Invalid array header: missing ]");
        }

        $bracketContent = substr($content, $bracketStart + 1, $bracketEnd - $bracketStart - 1);

        $delimiter = self::COMMA;
        if (substr($bracketContent, -1) === self::TAB) {
            $delimiter = self::TAB;
            $bracketContent = substr($bracketContent, 0, -1);
        } elseif (substr($bracketContent, -1) === self::PIPE) {
            $delimiter = self::PIPE;
            $bracketContent = substr($bracketContent, 0, -1);
        }

        $length = intval($bracketContent);
        $fields = null;
        $braceStart = strpos($content, self::OPEN_BRACE, $bracketEnd);
        $colonIndex = strpos($content, self::COLON, $bracketEnd);

        if ($braceStart !== false && ($colonIndex === false || $braceStart < $colonIndex)) {
            $braceEnd = strpos($content, self::CLOSE_BRACE, $braceStart);
            if ($braceEnd !== false) {
                $fieldsContent = substr($content, $braceStart + 1, $braceEnd - $braceStart - 1);
                $fields = $this->parseDelimitedValues($fieldsContent, $delimiter);
                $colonIndex = strpos($content, self::COLON, $braceEnd);
            }
        }

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

    private function parseDelimitedValues(string $input, string $delimiter): array {
        $values = [];
        $buffer = '';
        $inQuotes = false;
        $braceDepth = 0;
        $len = strlen($input);

        for ($i = 0; $i < $len; $i++) {
            $char = $input[$i];

            if ($char === self::BACKSLASH && $inQuotes && $i + 1 < $len) {
                $buffer .= $char . $input[$i + 1];
                $i++;
                continue;
            }

            if ($char === self::DOUBLE_QUOTE) {
                $inQuotes = !$inQuotes;
                $buffer .= $char;
                continue;
            }

            if (!$inQuotes) {
                if ($char === self::OPEN_BRACE) $braceDepth++;
                if ($char === self::CLOSE_BRACE) $braceDepth--;
            }

            if ($char === $delimiter && !$inQuotes && $braceDepth === 0) {
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

    private function parsePrimitive(string $token): mixed {
        $trimmed = trim($token);
        if ($trimmed === '') return '';
        if ($trimmed === '[]') return [];

        if ($trimmed[0] === self::DOUBLE_QUOTE) {
            return $this->parseStringLiteral($trimmed);
        }

        if ($trimmed === self::TRUE_LITERAL) return true;
        if ($trimmed === self::FALSE_LITERAL) return false;
        if ($trimmed === self::NULL_LITERAL) return null;

        if (is_numeric($trimmed)) {
            if (str_contains($trimmed, '.') || str_contains($trimmed, 'e') || str_contains($trimmed, 'E')) {
                $num = floatval($trimmed);
                return ($num === 0.0 && 1 / $num === -INF) ? 0 : $num;
            }
            return intval($trimmed);
        }

        return $trimmed;
    }

    private function parseStringLiteral(string $token): string {
        $trimmed = trim($token);
        if ($trimmed === '' || $trimmed[0] !== self::DOUBLE_QUOTE) return $trimmed;

        $closingQuote = $this->findClosingQuote($trimmed, 0);
        if ($closingQuote === -1) {
            throw new Exception("Unterminated string");
        }

        return $this->unescapeString(substr($trimmed, 1, $closingQuote - 1));
    }

    private function findClosingQuote(string $str, int $start): int {
        $len = strlen($str);
        $i = $start + 1;
        while ($i < $len) {
            if ($str[$i] === self::BACKSLASH && $i + 1 < $len) { $i += 2; continue; }
            if ($str[$i] === self::DOUBLE_QUOTE) return $i;
            $i++;
        }
        return -1;
    }

    private function unescapeString(string $str): string {
        $result = str_replace('\\\\', "\x00BACKSLASH\x00", $str);
        $result = str_replace('\\"', '"', $result);
        $result = str_replace('\\n', "\n", $result);
        $result = str_replace('\\r', "\r", $result);
        $result = str_replace('\\t', "\t", $result);
        $result = str_replace('\\;', ';', $result);
        $result = str_replace("\x00BACKSLASH\x00", '\\', $result);
        return $result;
    }

    private function parseKey(string $content): array {
        $isQuoted = $content[0] === self::DOUBLE_QUOTE;

        if ($isQuoted) {
            $closingQuote = $this->findClosingQuote($content, 0);
            if ($closingQuote === -1) throw new Exception("Unterminated quoted key");
            $key = $this->unescapeString(substr($content, 1, $closingQuote - 1));
            $end = $closingQuote + 1;
            if ($end >= strlen($content) || $content[$end] !== self::COLON) {
                throw new Exception("Missing colon after key");
            }
            return ['key' => $key, 'end' => $end + 1];
        }

        $colonPos = strpos($content, self::COLON);
        if ($colonPos === false) throw new Exception("Missing colon after key");

        return ['key' => trim(substr($content, 0, $colonPos)), 'end' => $colonPos + 1];
    }

    private function findKeyColon(string $str): int|false {
        $inQuotes = false;
        $braceDepth = 0;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $char = $str[$i];
            if ($char === self::BACKSLASH && $inQuotes && $i + 1 < $len) { $i++; continue; }
            if ($char === self::DOUBLE_QUOTE) { $inQuotes = !$inQuotes; continue; }
            if (!$inQuotes) {
                if ($char === self::OPEN_BRACE) $braceDepth++;
                if ($char === self::CLOSE_BRACE) $braceDepth--;
                if ($char === self::COLON && $braceDepth === 0) return $i;
            }
        }

        return false;
    }

    # Split string at top-level delimiter (respecting quotes and braces)
    private function splitTopLevel(string $input, string $delimiter): array {
        $parts = [];
        $buffer = '';
        $inQuotes = false;
        $braceDepth = 0;
        $bracketDepth = 0;
        $len = strlen($input);

        for ($i = 0; $i < $len; $i++) {
            $char = $input[$i];
            if ($char === self::BACKSLASH && $inQuotes && $i + 1 < $len) {
                $buffer .= $char . $input[$i + 1];
                $i++;
                continue;
            }
            if ($char === self::DOUBLE_QUOTE) { $inQuotes = !$inQuotes; }
            if (!$inQuotes) {
                if ($char === self::OPEN_BRACE) $braceDepth++;
                if ($char === self::CLOSE_BRACE) $braceDepth--;
                if ($char === self::OPEN_BRACKET) $bracketDepth++;
                if ($char === self::CLOSE_BRACKET) $bracketDepth--;
            }
            if ($char === $delimiter && !$inQuotes && $braceDepth === 0 && $bracketDepth === 0) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        if ($buffer !== '') $parts[] = $buffer;
        return $parts;
    }

    # Find matching ] for [ at given position (respecting nesting and quotes)
    private function findMatchingBracket(string $str, int $start): int|false {
        $depth = 0;
        $inQuotes = false;
        $len = strlen($str);

        for ($i = $start; $i < $len; $i++) {
            $char = $str[$i];
            if ($char === self::BACKSLASH && $inQuotes && $i + 1 < $len) { $i++; continue; }
            if ($char === self::DOUBLE_QUOTE) { $inQuotes = !$inQuotes; continue; }
            if (!$inQuotes) {
                if ($char === self::OPEN_BRACKET) $depth++;
                if ($char === self::CLOSE_BRACKET) {
                    $depth--;
                    if ($depth === 0) return $i;
                }
            }
        }

        return false;
    }

    private function extractBraceContent(string $input): string {
        $depth = 0;
        $start = -1;
        $len = strlen($input);
        $inQuotes = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $input[$i];
            if ($char === self::BACKSLASH && $inQuotes && $i + 1 < $len) { $i++; continue; }
            if ($char === self::DOUBLE_QUOTE) { $inQuotes = !$inQuotes; continue; }
            if (!$inQuotes) {
                if ($char === self::OPEN_BRACE) {
                    if ($depth === 0) $start = $i;
                    $depth++;
                }
                if ($char === self::CLOSE_BRACE) {
                    $depth--;
                    if ($depth === 0) {
                        return substr($input, $start + 1, $i - $start - 1);
                    }
                }
            }
        }

        return '';
    }

    private function isArrayHeader(string $content): bool {
        $bracketPos = strpos($content, self::OPEN_BRACKET);
        $colonPos = strpos($content, self::COLON);
        # [N]: pattern requires bracket BEFORE colon (e.g. tags[3]: not key: [])
        return $bracketPos !== false && $colonPos !== false && $bracketPos < $colonPos;
    }

    private function isKeyValueLine(string $content): bool {
        return str_contains($content, self::COLON);
    }

    # Set value at dot-notation path in array
    private function setDotPath(array &$arr, string $path, mixed $val): void {
        $keys = explode('.', $path);
        $ref = &$arr;
        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $ref[$key] = $val;
            } else {
                if (!isset($ref[$key]) || !is_array($ref[$key])) $ref[$key] = [];
                $ref = &$ref[$key];
            }
        }
    }
}
