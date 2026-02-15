<?php
# kernel/Scon/Scon.php
namespace bX\Scon;

#
# S.C.O.N. — Schema-Compact Object Notation
# Extension: .scon | Content-Type: text/scon; charset=utf-8
#
# Performance comparison (OpenAPI 3.1 spec, 71 endpoints):
#
# Format           | Bytes   | Ratio | Lines | Words  | Gzip    |
# -----------------|---------|-------|-------|--------|---------|
# JSON             | 90,886  | 1.00x |     1 |  1,297 |  4,632  |
# JSON (pretty)    | 90,886  | 1.00x | 3,000+|  1,297 |  4,632  |
# TOON             |157,752  | 1.74x | 5,179 |  8,682 |  5,932  |
# SCON             | 26,347  | 0.29x |   826 |  1,981 |  3,969  |
# SCON (minified)  | 20,211  | 0.22x |     1 |  1,157 |  3,818  |
#
# SCON achieves ~71% reduction vs JSON and ~83% vs TOON by extracting
# repeated schema definitions (s:, r:, sec:) and referencing them (@s:).
# Minification adds `;` for newlines and `;;`/`;;;` for dedent operators.
# Even gzipped, SCON is 17% smaller than JSON due to structural dedup.
#

class Scon {

    # Encode PHP data to SCON string
    public static function encode(mixed $data, array $options = []): string {
        $encoder = new Encoder($options);
        $schemas = $options['schemas'] ?? [];
        $responses = $options['responses'] ?? [];
        $security = $options['security'] ?? [];
        return $encoder->encode($data, $schemas, $responses, $security);
    }

    # Decode SCON string to PHP array
    public static function decode(string $sconString, array $options = []): array {
        $decoder = new Decoder($options);
        return $decoder->decode($sconString);
    }

    # Minify SCON string to single line
    public static function minify(string $sconString): string {
        return Minifier::minify($sconString);
    }

    # Expand minified SCON to indented format
    public static function expand(string $minifiedString, array $options = []): string {
        return Minifier::expand($minifiedString, $options['indent'] ?? 2);
    }

    # Validate SCON data against rules
    public static function validate(mixed $data, array $options = []): array {
        $validator = new Validator($options);
        return $validator->validate($data);
    }
}
