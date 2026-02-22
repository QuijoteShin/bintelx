<?php # bintelx/kernel/Geo/ES.php
namespace bX\Geo;

/**
 * España — NIF/NIE/CIF + Pasaporte
 *
 * NIF (Número de Identificación Fiscal) — personas físicas españolas:
 *   8 dígitos + 1 letra verificadora (tabla fija módulo 23)
 *
 * NIE (Número de Identidad de Extranjero) — residentes extranjeros:
 *   X/Y/Z + 7 dígitos + 1 letra verificadora
 *   X→0, Y→1, Z→2 para el cálculo del verificador
 *
 * CIF (Código de Identificación Fiscal) — personas jurídicas:
 *   1 letra (tipo sociedad) + 7 dígitos + 1 carácter control (dígito o letra)
 *   Letras tipo: A,B,C,D,E,F,G,H,J,N,P,Q,R,S,U,V,W
 *   Control: suma pares + suma impares*2 (tomar unidades) → 10-último dígito
 */
class ES implements CountryDriverInterface
{
    use PassportTrait;

    # Tabla de verificación NIF/NIE — posición = número mod 23
    private const NIF_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    # Letras de tipo de sociedad válidas para CIF
    private const CIF_TYPES = 'ABCDEFGHJNPQRSUVW';

    # CIFs que usan letra como control (no dígito)
    private const CIF_LETTER_CONTROL = 'PQSW';

    public static function supportedTypes(): array
    {
        return [
            ['code' => 'NIF', 'label' => 'NIF', 'validation' => 'CHECKSUM', 'entity_scope' => 'person'],
            ['code' => 'NIE', 'label' => 'NIE', 'validation' => 'CHECKSUM', 'entity_scope' => 'person'],
            ['code' => 'CIF', 'label' => 'CIF', 'validation' => 'CHECKSUM', 'entity_scope' => 'company'],
            ['code' => 'PASSPORT', 'label' => 'Pasaporte', 'validation' => 'REGEX', 'entity_scope' => 'person'],
        ];
    }

    public static function normalize(string $nationalId, ?string $type = null): string
    {
        if ($type === 'PASSPORT') return self::normalizePassport($nationalId);

        # Quitar espacios, guiones, puntos
        return strtoupper(preg_replace('/[\s\-\.]/', '', $nationalId));
    }

    public static function format(string $nationalId, ?string $type = null): string
    {
        if ($type === 'PASSPORT') return self::formatPassport($nationalId);

        $clean = self::normalize($nationalId, $type);

        # NIF: 12345678Z → 12.345.678-Z
        if (strlen($clean) === 9 && ctype_digit(substr($clean, 0, 8)) && ctype_alpha($clean[8])) {
            $body = substr($clean, 0, 8);
            $letter = $clean[8];
            return number_format((int)$body, 0, '', '.') . '-' . $letter;
        }

        # NIE: X1234567Z → X-1234567-Z
        if (strlen($clean) === 9 && in_array($clean[0], ['X', 'Y', 'Z'])) {
            return $clean[0] . '-' . substr($clean, 1, 7) . '-' . $clean[8];
        }

        # CIF: A12345678 → A-12345678
        if (strlen($clean) === 9 && strpos(self::CIF_TYPES, $clean[0]) !== false) {
            return $clean[0] . '-' . substr($clean, 1);
        }

        return $clean;
    }

    public static function validate(string $nationalId, ?string $type = null): array
    {
        if ($type === 'PASSPORT') return self::validatePassport($nationalId);

        $detected = $type ?? self::detectType($nationalId);
        if ($detected === 'PASSPORT') return self::validatePassport($nationalId);

        $clean = self::normalize($nationalId);

        if (strlen($clean) !== 9) {
            return ['valid' => false, 'type' => $detected ?? 'NIF', 'error' => 'INVALID_LENGTH'];
        }

        switch ($detected) {
            case 'NIF': return self::validateNIF($clean);
            case 'NIE': return self::validateNIE($clean);
            case 'CIF': return self::validateCIF($clean);
            default:    return ['valid' => false, 'type' => 'NIF', 'error' => 'UNKNOWN_FORMAT'];
        }
    }

    public static function detectType(string $nationalId): ?string
    {
        $clean = strtoupper(preg_replace('/[\s\-\.]/', '', $nationalId));

        if (strlen($clean) < 8) return null;

        $first = $clean[0];

        # NIE: comienza con X, Y, Z
        if (in_array($first, ['X', 'Y', 'Z'])) return 'NIE';

        # CIF: comienza con letra de tipo de sociedad
        if (strpos(self::CIF_TYPES, $first) !== false) return 'CIF';

        # NIF: comienza con dígito
        if (ctype_digit($first)) return 'NIF';

        # Si tiene letras intercaladas → pasaporte
        if (preg_match('/[A-Za-z]/', $clean) && strlen($clean) >= 5) return 'PASSPORT';

        return null;
    }

    # --- Validaciones internas ---

    private static function validateNIF(string $clean): array
    {
        $body = substr($clean, 0, 8);
        $letter = $clean[8];

        if (!ctype_digit($body) || !ctype_alpha($letter)) {
            return ['valid' => false, 'type' => 'NIF', 'error' => 'INVALID_FORMAT'];
        }

        $expected = self::NIF_LETTERS[(int)$body % 23];

        if ($letter !== $expected) {
            return ['valid' => false, 'type' => 'NIF', 'error' => 'INVALID_VERIFIER', 'expected' => $expected, 'got' => $letter];
        }

        return ['valid' => true, 'type' => 'NIF', 'validation' => 'CHECKSUM'];
    }

    private static function validateNIE(string $clean): array
    {
        $prefix = $clean[0];
        $body = substr($clean, 1, 7);
        $letter = $clean[8];

        if (!ctype_digit($body) || !ctype_alpha($letter)) {
            return ['valid' => false, 'type' => 'NIE', 'error' => 'INVALID_FORMAT'];
        }

        # X→0, Y→1, Z→2
        $prefixMap = ['X' => '0', 'Y' => '1', 'Z' => '2'];
        $numericId = ($prefixMap[$prefix] ?? '0') . $body;

        $expected = self::NIF_LETTERS[(int)$numericId % 23];

        if ($letter !== $expected) {
            return ['valid' => false, 'type' => 'NIE', 'error' => 'INVALID_VERIFIER', 'expected' => $expected, 'got' => $letter];
        }

        return ['valid' => true, 'type' => 'NIE', 'validation' => 'CHECKSUM'];
    }

    private static function validateCIF(string $clean): array
    {
        $societyLetter = $clean[0];
        $digits = substr($clean, 1, 7);
        $control = $clean[8];

        if (strpos(self::CIF_TYPES, $societyLetter) === false) {
            return ['valid' => false, 'type' => 'CIF', 'error' => 'INVALID_SOCIETY_TYPE'];
        }

        if (!ctype_digit($digits)) {
            return ['valid' => false, 'type' => 'CIF', 'error' => 'INVALID_FORMAT'];
        }

        # Algoritmo: posiciones pares (1-indexed) se suman directamente,
        # posiciones impares se multiplican por 2 y se suman las cifras del resultado
        $sumOdd = 0;
        $sumEven = 0;

        for ($i = 0; $i < 7; $i++) {
            $d = (int)$digits[$i];
            if ($i % 2 === 0) {
                # Posiciones impares (1,3,5,7) → multiplicar por 2, sumar cifras
                $doubled = $d * 2;
                $sumOdd += intdiv($doubled, 10) + ($doubled % 10);
            } else {
                # Posiciones pares (2,4,6) → sumar directamente
                $sumEven += $d;
            }
        }

        $total = $sumOdd + $sumEven;
        $controlDigit = (10 - ($total % 10)) % 10;
        $controlLetter = chr(64 + $controlDigit); # A=1, B=2... J=10→0

        # Ciertos tipos usan letra, otros dígito, algunos ambos
        $usesLetter = strpos(self::CIF_LETTER_CONTROL, $societyLetter) !== false;

        if ($usesLetter) {
            $expected = $controlDigit === 0 ? 'J' : $controlLetter;
            if ($control !== $expected) {
                return ['valid' => false, 'type' => 'CIF', 'error' => 'INVALID_VERIFIER'];
            }
        } else {
            # Puede ser dígito o letra según tipo
            if ($control !== (string)$controlDigit && $control !== ($controlDigit === 0 ? 'J' : $controlLetter)) {
                return ['valid' => false, 'type' => 'CIF', 'error' => 'INVALID_VERIFIER'];
            }
        }

        return ['valid' => true, 'type' => 'CIF', 'validation' => 'CHECKSUM'];
    }
}
