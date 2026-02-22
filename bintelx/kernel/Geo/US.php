<?php # bintelx/kernel/Geo/US.php
namespace bX\Geo;

/**
 * United States — EIN (Employer Identification Number) + SSN/ITIN + Passport
 *
 * EIN: XX-XXXXXXX (9 dígitos, asignado por IRS a empresas)
 *   - Prefijo 2 dígitos indica campus del IRS emisor
 *   - No tiene dígito verificador público (validación = REGEX)
 *
 * SSN: XXX-XX-XXXX (9 dígitos, personas físicas)
 *   - Area Number (3) + Group Number (2) + Serial Number (4)
 *   - No puede comenzar con 000, 666, o 900-999
 *   - Group y Serial no pueden ser todos ceros
 *
 * ITIN: 9XX-XX-XXXX (9 dígitos, contribuyentes sin SSN)
 *   - Siempre comienza con 9
 *   - Cuarto y quinto dígito: 50-65, 70-88, 90-92, 94-99
 *
 * Nota: SSN e ITIN son PII sensible. El sistema los almacena normalizados
 * y hasheados. La validación es solo formato (no existe checksum público).
 */
class US implements CountryDriverInterface
{
    use PassportTrait;

    public static function supportedTypes(): array
    {
        return [
            ['code' => 'EIN',      'label' => 'EIN',  'validation' => 'REGEX', 'entity_scope' => 'company'],
            ['code' => 'SSN',      'label' => 'SSN',  'validation' => 'REGEX', 'entity_scope' => 'person'],
            ['code' => 'ITIN',     'label' => 'ITIN', 'validation' => 'REGEX', 'entity_scope' => 'person'],
            ['code' => 'PASSPORT', 'label' => 'Passport', 'validation' => 'REGEX', 'entity_scope' => 'person'],
        ];
    }

    public static function normalize(string $nationalId, ?string $type = null): string
    {
        if ($type === 'PASSPORT') return self::normalizePassport($nationalId);

        # Todos los tipos US son solo dígitos
        return preg_replace('/[^0-9]/', '', $nationalId);
    }

    public static function format(string $nationalId, ?string $type = null): string
    {
        if ($type === 'PASSPORT') return self::formatPassport($nationalId);

        $clean = self::normalize($nationalId);

        if (strlen($clean) !== 9) return $clean;

        $type = $type ?? self::detectType($nationalId) ?? 'EIN';

        switch ($type) {
            case 'EIN':
                # XX-XXXXXXX
                return substr($clean, 0, 2) . '-' . substr($clean, 2);

            case 'SSN':
            case 'ITIN':
                # XXX-XX-XXXX
                return substr($clean, 0, 3) . '-' . substr($clean, 3, 2) . '-' . substr($clean, 5);

            default:
                return $clean;
        }
    }

    public static function validate(string $nationalId, ?string $type = null): array
    {
        if ($type === 'PASSPORT') return self::validatePassport($nationalId);

        $type = $type ?? self::detectType($nationalId);
        if ($type === 'PASSPORT') return self::validatePassport($nationalId);

        $clean = self::normalize($nationalId);

        if (strlen($clean) < 9) {
            return ['valid' => false, 'type' => $type ?? 'EIN', 'error' => 'TOO_SHORT'];
        }
        if (strlen($clean) > 9) {
            return ['valid' => false, 'type' => $type ?? 'EIN', 'error' => 'TOO_LONG'];
        }

        switch ($type) {
            case 'EIN':
                return self::validateEIN($clean);
            case 'SSN':
                return self::validateSSN($clean);
            case 'ITIN':
                return self::validateITIN($clean);
            default:
                return self::validateEIN($clean);
        }
    }

    public static function detectType(string $nationalId): ?string
    {
        $clean = preg_replace('/[^0-9A-Za-z]/', '', $nationalId);

        # Si tiene letras → pasaporte
        if (preg_match('/[A-Za-z]/', $clean)) {
            return strlen($clean) >= 5 ? 'PASSPORT' : null;
        }

        $digits = preg_replace('/[^0-9]/', '', $nationalId);
        if (strlen($digits) !== 9) return null;

        # ITIN: comienza con 9, dígitos 4-5 en rangos específicos
        if ($digits[0] === '9') {
            $group = (int)substr($digits, 3, 2);
            if (self::isValidITINGroup($group)) {
                return 'ITIN';
            }
        }

        # SSN: no comienza con 000, 666, ni 9xx
        $area = (int)substr($digits, 0, 3);
        if ($area !== 0 && $area !== 666 && $area < 900) {
            return 'SSN';
        }

        # Default: EIN (empresas)
        return 'EIN';
    }

    # --- Validaciones internas ---

    private static function validateEIN(string $clean): array
    {
        if (!ctype_digit($clean) || strlen($clean) !== 9) {
            return ['valid' => false, 'type' => 'EIN', 'error' => 'INVALID_FORMAT'];
        }

        # EIN prefijo no puede ser 00
        $prefix = (int)substr($clean, 0, 2);
        if ($prefix === 0) {
            return ['valid' => false, 'type' => 'EIN', 'error' => 'INVALID_PREFIX'];
        }

        return ['valid' => true, 'type' => 'EIN', 'validation' => 'REGEX'];
    }

    private static function validateSSN(string $clean): array
    {
        if (!ctype_digit($clean) || strlen($clean) !== 9) {
            return ['valid' => false, 'type' => 'SSN', 'error' => 'INVALID_FORMAT'];
        }

        $area = (int)substr($clean, 0, 3);
        $group = (int)substr($clean, 3, 2);
        $serial = (int)substr($clean, 5, 4);

        # Area no puede ser 000, 666, o 900-999
        if ($area === 0 || $area === 666 || $area >= 900) {
            return ['valid' => false, 'type' => 'SSN', 'error' => 'INVALID_AREA'];
        }

        # Group no puede ser 00
        if ($group === 0) {
            return ['valid' => false, 'type' => 'SSN', 'error' => 'INVALID_GROUP'];
        }

        # Serial no puede ser 0000
        if ($serial === 0) {
            return ['valid' => false, 'type' => 'SSN', 'error' => 'INVALID_SERIAL'];
        }

        return ['valid' => true, 'type' => 'SSN', 'validation' => 'REGEX'];
    }

    private static function validateITIN(string $clean): array
    {
        if (!ctype_digit($clean) || strlen($clean) !== 9) {
            return ['valid' => false, 'type' => 'ITIN', 'error' => 'INVALID_FORMAT'];
        }

        # ITIN siempre comienza con 9
        if ($clean[0] !== '9') {
            return ['valid' => false, 'type' => 'ITIN', 'error' => 'MUST_START_WITH_9'];
        }

        # Dígitos 4-5 (group) deben estar en rangos válidos del IRS
        $group = (int)substr($clean, 3, 2);
        if (!self::isValidITINGroup($group)) {
            return ['valid' => false, 'type' => 'ITIN', 'error' => 'INVALID_GROUP'];
        }

        return ['valid' => true, 'type' => 'ITIN', 'validation' => 'REGEX'];
    }

    /**
     * Rangos válidos para dígitos 4-5 del ITIN (IRS Publication 1915)
     */
    private static function isValidITINGroup(int $group): bool
    {
        return ($group >= 50 && $group <= 65)
            || ($group >= 70 && $group <= 88)
            || ($group >= 90 && $group <= 92)
            || ($group >= 94 && $group <= 99);
    }
}
