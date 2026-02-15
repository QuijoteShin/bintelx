<?php
# kernel/Scon/Scon.php
namespace bX\Scon;

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
