<?php # bintelx/kernel/Geo/CL.php
namespace bX\Geo;

/**
 * Chile — RUT (Rol Único Tributario) + Pasaporte
 * RUT: XX.XXX.XXX-V donde V es dígito verificador (0-9, K)
 * Algoritmo: Módulo 11 con factores 2-7 cíclicos
 */
class CL implements CountryDriverInterface
{
    use PassportTrait;

    public static function supportedTypes(): array
    {
        return [
            ['code' => 'TAX_ID', 'label' => 'RUT', 'validation' => 'CHECKSUM', 'entity_scope' => 'both'],
            ['code' => 'PASSPORT', 'label' => 'Pasaporte', 'validation' => 'REGEX', 'entity_scope' => 'person'],
        ];
    }

    public static function normalize(string $nationalId, ?string $type = null): string
    {
        if ($type === 'PASSPORT') return self::normalizePassport($nationalId);

        $clean = preg_replace('/[^0-9Kk]/', '', $nationalId);
        return strtoupper($clean);
    }

    public static function format(string $nationalId, ?string $type = null): string
    {
        if ($type === 'PASSPORT') return self::formatPassport($nationalId);

        $clean = self::normalize($nationalId);
        if (strlen($clean) < 2) return $clean;

        $dv = substr($clean, -1);
        $body = substr($clean, 0, -1);
        $formatted = number_format((int)$body, 0, '', '.');
        return $formatted . '-' . $dv;
    }

    public static function validate(string $nationalId, ?string $type = null): array
    {
        if ($type === 'PASSPORT') return self::validatePassport($nationalId);

        # Auto-detect si no se especifica tipo
        $detected = $type ?? self::detectType($nationalId);
        if ($detected === 'PASSPORT') return self::validatePassport($nationalId);

        $clean = self::normalize($nationalId);
        if (strlen($clean) < 2) {
            return ['valid' => false, 'type' => 'TAX_ID', 'error' => 'TOO_SHORT'];
        }

        $dv = substr($clean, -1);
        $body = substr($clean, 0, -1);

        if (!ctype_digit($body)) {
            return ['valid' => false, 'type' => 'TAX_ID', 'error' => 'INVALID_BODY'];
        }

        $computed = self::computeVerifier($body);

        if ($computed !== $dv) {
            return ['valid' => false, 'type' => 'TAX_ID', 'error' => 'INVALID_VERIFIER', 'expected' => $computed, 'got' => $dv];
        }

        return ['valid' => true, 'type' => 'TAX_ID', 'validation' => 'CHECKSUM'];
    }

    public static function detectType(string $nationalId): ?string
    {
        $clean = preg_replace('/[\s\-\.]/', '', $nationalId);
        # RUT: solo dígitos y opcionalmente K al final
        if (preg_match('/^[0-9]+[0-9Kk]$/', $clean) && strlen($clean) >= 7 && strlen($clean) <= 10) {
            return 'TAX_ID';
        }
        # Si tiene letras intercaladas → pasaporte
        if (preg_match('/[A-Za-z]/', $clean) && strlen($clean) >= 5) {
            return 'PASSPORT';
        }
        return null;
    }

    /**
     * Módulo 11 — factores 2,3,4,5,6,7 cíclicos de derecha a izquierda
     */
    private static function computeVerifier(string $body): string
    {
        $sum = 0;
        $factor = 2;
        $digits = str_split(strrev($body));

        foreach ($digits as $digit) {
            $sum += (int)$digit * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }

        $remainder = 11 - ($sum % 11);

        if ($remainder === 11) return '0';
        if ($remainder === 10) return 'K';
        return (string)$remainder;
    }
}
