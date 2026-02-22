<?php # bintelx/kernel/Geo/DE.php
namespace bX\Geo;

/**
 * Alemania — Steuerliche Identifikationsnummer (Steuer-IdNr) + USt-IdNr + Pasaporte
 * Steuer-IdNr: 11 dígitos, verificador ISO/IEC 7064 Mod 11,10
 * USt-IdNr: DE + 9 dígitos (VAT europeo, solo validación de formato)
 */
class DE implements CountryDriverInterface
{
    use PassportTrait;

    public static function supportedTypes(): array
    {
        return [
            ['code' => 'TAX_ID', 'label' => 'Steuerliche Identifikationsnummer', 'validation' => 'CHECKSUM', 'entity_scope' => 'person'],
            ['code' => 'VAT', 'label' => 'Umsatzsteuer-Identifikationsnummer', 'validation' => 'REGEX', 'entity_scope' => 'company'],
            ['code' => 'PASSPORT', 'label' => 'Reisepass', 'validation' => 'REGEX', 'entity_scope' => 'person'],
        ];
    }

    public static function normalize(string $nationalId, ?string $type = null): string
    {
        if ($type === 'PASSPORT') return self::normalizePassport($nationalId);

        # Remover prefijo DE si existe (usado en VAT IDs europeos)
        $clean = preg_replace('/^DE/i', '', $nationalId);
        return preg_replace('/[^0-9]/', '', $clean);
    }

    public static function format(string $nationalId, ?string $type = null): string
    {
        if ($type === 'PASSPORT') return self::formatPassport($nationalId);

        $clean = self::normalize($nationalId, $type);

        if (strlen($clean) === 11) {
            # Steuer-IdNr: XX XXX XXX XXX
            return substr($clean, 0, 2) . ' ' . substr($clean, 2, 3) . ' ' .
                   substr($clean, 5, 3) . ' ' . substr($clean, 8, 3);
        }

        if (strlen($clean) === 9) {
            # USt-IdNr: DE XXXXXXXXX
            return 'DE' . $clean;
        }

        return $clean;
    }

    public static function validate(string $nationalId, ?string $type = null): array
    {
        if ($type === 'PASSPORT') return self::validatePassport($nationalId);

        $detected = $type ?? self::detectType($nationalId);
        if ($detected === 'PASSPORT') return self::validatePassport($nationalId);

        $clean = self::normalize($nationalId, $type);

        if (strlen($clean) === 11) {
            return self::validateIdNr($clean);
        }

        if (strlen($clean) === 9) {
            # USt-IdNr — solo validación de longitud, sin algoritmo público
            return ['valid' => true, 'type' => 'VAT', 'validation' => 'REGEX'];
        }

        return ['valid' => false, 'type' => $detected, 'error' => 'INVALID_LENGTH', 'expected' => '11 (IdNr) or 9 (USt-IdNr)'];
    }

    public static function detectType(string $nationalId): ?string
    {
        $raw = preg_replace('/[\s\-]/', '', $nationalId);
        # USt-IdNr con prefijo DE
        if (preg_match('/^DE\d{9}$/i', $raw)) return 'VAT';

        $clean = preg_replace('/[^0-9]/', '', $raw);
        if (strlen($clean) === 11) return 'TAX_ID';
        if (strlen($clean) === 9) return 'VAT';

        # Si tiene letras intercaladas → pasaporte
        if (preg_match('/[A-Za-z]/', $raw) && strlen($raw) >= 5) return 'PASSPORT';

        return null;
    }

    /**
     * Steuer-IdNr — ISO/IEC 7064 Mod 11,10
     * Primer dígito no puede ser 0
     * Exactamente un dígito aparece 2 o 3 veces, el resto exactamente 1 vez
     */
    private static function validateIdNr(string $idnr): array
    {
        if (!ctype_digit($idnr)) {
            return ['valid' => false, 'type' => 'TAX_ID', 'error' => 'NOT_NUMERIC'];
        }

        if ($idnr[0] === '0') {
            return ['valid' => false, 'type' => 'TAX_ID', 'error' => 'LEADING_ZERO'];
        }

        # Verificar distribución de dígitos (primeros 10)
        $body = substr($idnr, 0, 10);
        $freq = array_count_values(str_split($body));
        $counts = array_values($freq);
        sort($counts);

        $multiCount = array_filter($counts, fn($c) => $c > 1);
        if (count($multiCount) !== 1 || !in_array(end($multiCount), [2, 3])) {
            return ['valid' => false, 'type' => 'TAX_ID', 'error' => 'INVALID_DIGIT_DISTRIBUTION'];
        }

        # Algoritmo de verificación ISO/IEC 7064 Mod 11,10
        $product = 10;
        for ($i = 0; $i < 10; $i++) {
            $sum = ((int)$idnr[$i] + $product) % 10;
            if ($sum === 0) $sum = 10;
            $product = ($sum * 2) % 11;
        }

        $checkDigit = (11 - $product) % 10;

        if ((int)$idnr[10] !== $checkDigit) {
            return ['valid' => false, 'type' => 'TAX_ID', 'error' => 'INVALID_VERIFIER'];
        }

        return ['valid' => true, 'type' => 'TAX_ID', 'validation' => 'CHECKSUM'];
    }
}
