<?php
# kernel/Scon/Minifier.php
namespace bX\Scon;

class Minifier {

    # Minify SCON to single line
    # Rules:
    #   ; = newline (same depth)
    #   N semicolons (N>=2) = dedent (N-1) levels
    #   ;; = dedent 1, ;;; = dedent 2, ;;;; = dedent 3, etc.
    #   Strings with ; must be quoted (\; escape)
    public static function minify(string $scon): string {
        $lines = explode("\n", $scon);
        $result = '';
        $prevDepth = 0;
        $isFirst = true;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            # Skip empty lines and comments
            if ($trimmed === '' || $trimmed[0] === '#') {
                # Preserve header
                if (str_starts_with($trimmed, '#!scon/')) {
                    $result .= $trimmed . ';';
                }
                continue;
            }

            $depth = self::calculateDepth($line);

            if (!$isFirst) {
                $diff = $prevDepth - $depth;
                if ($diff >= 2) {
                    $result .= str_repeat(';', $diff + 1);
                } elseif ($diff === 1) {
                    $result .= ';;';
                } else {
                    $result .= ';';
                }
            }

            $result .= $trimmed;
            $prevDepth = $depth;

            # Scope openers: increase expected depth for children
            if (preg_match('/:$/', $trimmed)) {
                $prevDepth = $depth + 1;
            }
            # List item (- key: val) children are indented 1 level deeper
            if (str_starts_with($trimmed, '- ')) {
                $prevDepth = $depth + 1;
            }

            $isFirst = false;
        }

        return $result;
    }

    # Expand minified SCON to indented format
    public static function expand(string $minified, int $indent = 2): string {
        $lines = [];
        $depth = 0;
        $buffer = '';
        $inQuotes = false;
        $len = strlen($minified);

        for ($i = 0; $i < $len; $i++) {
            $char = $minified[$i];

            # Handle escape in quotes
            if ($char === '\\' && $inQuotes && $i + 1 < $len) {
                $buffer .= $char . $minified[$i + 1];
                $i++;
                continue;
            }

            if ($char === '"') {
                $inQuotes = !$inQuotes;
                $buffer .= $char;
                continue;
            }

            if ($char === ';' && !$inQuotes) {
                # Count consecutive semicolons
                $semiCount = 1;
                while ($i + 1 < $len && $minified[$i + 1] === ';') {
                    $semiCount++;
                    $i++;
                }

                # Emit current buffer
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $lines[] = str_repeat(' ', $indent * $depth) . $trimmed;

                    # Scope openers: increase depth for children
                    if (preg_match('/:$/', $trimmed) && !preg_match('/:\s*\S/', $trimmed)) {
                        $depth++;
                    }
                    # List item children are indented 1 level deeper
                    if (str_starts_with($trimmed, '- ')) {
                        $depth++;
                    }
                }

                $buffer = '';

                # Apply dedent: N semicolons = dedent (N-1) levels
                if ($semiCount >= 2) {
                    $depth = max(0, $depth - ($semiCount - 1));
                }

                continue;
            }

            $buffer .= $char;
        }

        # Last buffer
        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $lines[] = str_repeat(' ', $indent * $depth) . $trimmed;
        }

        return implode("\n", $lines);
    }

    private static function calculateDepth(string $line): int {
        $spaces = 0;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            if ($line[$i] === ' ') $spaces++;
            else break;
        }
        return intdiv($spaces, 2);
    }
}
