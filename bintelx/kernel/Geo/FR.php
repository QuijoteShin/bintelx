<?php # bintelx/kernel/Geo/FR.php
namespace bX\Geo;

/**
 * Francia — SIREN/SIRET (empresas) + NIR/INSEE (personas) + Pasaporte
 * SIREN: 9 dígitos (Luhn)
 * SIRET: 14 dígitos (SIREN + NIC de 5 dígitos, Luhn sobre los 14)
 * NIR: 15 dígitos (13 + 2 clave de control, Módulo 97)
 */
class FR implements CountryDriverInterface
{
    use PassportTrait;

    public static function supportedTypes(): array
    {
        return [
            ['code' => 'VAT', 'label' => 'SIREN', 'validation' => 'CHECKSUM', 'entity_scope' => 'company'],
            ['code' => 'SIRET', 'label' => 'SIRET', 'validation' => 'CHECKSUM', 'entity_scope' => 'company'],
            ['code' => 'NATIONAL_ID', 'label' => 'NIR (INSEE)', 'validation' => 'CHECKSUM', 'entity_scope' => 'person'],
            ['code' => 'PASSPORT', 'label' => 'Passeport', 'validation' => 'REGEX', 'entity_scope' => 'person'],
        ];
    }

    public static function normalize(string $nationalId, ?string $type = null): string
    {
        if ($type === 'PASSPORT') return self::normalizePassport($nationalId);

        # NIR puede contener letras (2A, 2B para Córcega)
        $clean = preg_replace('/[\s\-\.]/', '', $nationalId);
        return strtoupper($clean);
    }

    public static function format(string $nationalId, ?string $type = null): string
    {
        if ($type === 'PASSPORT') return self::formatPassport($nationalId);

        $clean = self::normalize($nationalId, $type);

        if (strlen($clean) === 9 && ctype_digit($clean)) {
            # SIREN: XXX XXX XXX
            return substr($clean, 0, 3) . ' ' . substr($clean, 3, 3) . ' ' . substr($clean, 6, 3);
        }

        if (strlen($clean) === 14 && ctype_digit($clean)) {
            # SIRET: XXX XXX XXX XXXXX
            return substr($clean, 0, 3) . ' ' . substr($clean, 3, 3) . ' ' .
                   substr($clean, 6, 3) . ' ' . substr($clean, 9, 5);
        }

        if (strlen($clean) === 15) {
            # NIR: X XX XX XX XXX XXX XX
            return $clean[0] . ' ' . substr($clean, 1, 2) . ' ' . substr($clean, 3, 2) . ' ' .
                   substr($clean, 5, 2) . ' ' . substr($clean, 7, 3) . ' ' .
                   substr($clean, 10, 3) . ' ' . substr($clean, 13, 2);
        }

        return $clean;
    }

    public static function validate(string $nationalId, ?string $type = null): array
    {
        if ($type === 'PASSPORT') return self::validatePassport($nationalId);

        $detected = $type ?? self::detectType($nationalId);
        if ($detected === 'PASSPORT') return self::validatePassport($nationalId);

        $clean = self::normalize($nationalId, $type);

        if (strlen($clean) === 9 && ctype_digit($clean)) {
            return self::validateSIREN($clean);
        }

        if (strlen($clean) === 14 && ctype_digit($clean)) {
            return self::validateSIRET($clean);
        }

        if (strlen($clean) === 15) {
            return self::validateNIR($clean);
        }

        return ['valid' => false, 'type' => $detected, 'error' => 'INVALID_LENGTH', 'expected' => '9 (SIREN), 14 (SIRET), or 15 (NIR)'];
    }

    public static function detectType(string $nationalId): ?string
    {
        $clean = preg_replace('/[\s\-\.]/', '', $nationalId);
        $upper = strtoupper($clean);

        if (strlen($upper) === 9 && ctype_digit($upper)) return 'VAT';
        if (strlen($upper) === 14 && ctype_digit($upper)) return 'SIRET';
        if (strlen($upper) === 15) return 'NATIONAL_ID';

        # Si tiene letras intercaladas → pasaporte
        if (preg_match('/[A-Za-z]/', $clean) && strlen($clean) >= 5) return 'PASSPORT';

        return null;
    }

    /**
     * SIREN — Algoritmo de Luhn sobre 9 dígitos
     */
    private static function validateSIREN(string $siren): array
    {
        if (!self::luhnCheck($siren)) {
            return ['valid' => false, 'type' => 'VAT', 'error' => 'INVALID_LUHN'];
        }
        return ['valid' => true, 'type' => 'VAT', 'validation' => 'CHECKSUM'];
    }

    /**
     * SIRET — Algoritmo de Luhn sobre 14 dígitos
     * Excepción: La Poste (SIREN 356000000) usa suma simple
     */
    private static function validateSIRET(string $siret): array
    {
        $siren = substr($siret, 0, 9);

        # Caso especial La Poste
        if ($siren === '356000000') {
            $sum = 0;
            for ($i = 0; $i < 14; $i++) {
                $sum += (int)$siret[$i];
            }
            if ($sum % 5 !== 0) {
                return ['valid' => false, 'type' => 'SIRET', 'error' => 'INVALID_LAPOSTE_SUM'];
            }
            return ['valid' => true, 'type' => 'SIRET', 'validation' => 'CHECKSUM'];
        }

        if (!self::luhnCheck($siret)) {
            return ['valid' => false, 'type' => 'SIRET', 'error' => 'INVALID_LUHN'];
        }
        return ['valid' => true, 'type' => 'SIRET', 'validation' => 'CHECKSUM'];
    }

    /**
     * NIR (INSEE) — Clave de control Módulo 97
     * Soporta 2A/2B para Córcega (reemplaza por 19/18 para cálculo)
     */
    private static function validateNIR(string $nir): array
    {
        $body = substr($nir, 0, 13);
        $key = substr($nir, 13, 2);

        if (!ctype_digit($key)) {
            return ['valid' => false, 'type' => 'NATIONAL_ID', 'error' => 'INVALID_KEY_FORMAT'];
        }

        # Córcega: 2A → 19, 2B → 18 para cálculo numérico
        $numBody = str_replace(['2A', '2B'], ['19', '18'], $body);

        if (!ctype_digit($numBody)) {
            return ['valid' => false, 'type' => 'NATIONAL_ID', 'error' => 'INVALID_BODY'];
        }

        # Clave = 97 - (cuerpo mod 97)
        $mod = bcmod($numBody, '97');
        $expectedKey = 97 - (int)$mod;

        if ((int)$key !== $expectedKey) {
            return ['valid' => false, 'type' => 'NATIONAL_ID', 'error' => 'INVALID_CONTROL_KEY', 'expected' => sprintf('%02d', $expectedKey), 'got' => $key];
        }

        return ['valid' => true, 'type' => 'NATIONAL_ID', 'validation' => 'CHECKSUM'];
    }

    /**
     * Algoritmo de Luhn estándar
     */
    private static function luhnCheck(string $number): bool
    {
        $sum = 0;
        $len = strlen($number);
        $parity = $len % 2;

        for ($i = 0; $i < $len; $i++) {
            $digit = (int)$number[$i];
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) $digit -= 9;
            }
            $sum += $digit;
        }

        return ($sum % 10) === 0;
    }
}
