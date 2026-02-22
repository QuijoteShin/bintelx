<?php # bintelx/kernel/Geo/BR.php
namespace bX\Geo;

/**
 * Brasil — CPF + CNPJ + Pasaporte
 * CPF: 11 dígitos, 2 verificadores (Módulo 11 con pesos decrecientes)
 * CNPJ: 14 dígitos, 2 verificadores (Módulo 11 con pesos específicos)
 */
class BR implements CountryDriverInterface
{
    use PassportTrait;

    public static function supportedTypes(): array
    {
        return [
            ['code' => 'CPF', 'label' => 'CPF', 'validation' => 'CHECKSUM', 'entity_scope' => 'person'],
            ['code' => 'CNPJ', 'label' => 'CNPJ', 'validation' => 'CHECKSUM', 'entity_scope' => 'company'],
            ['code' => 'PASSPORT', 'label' => 'Passaporte', 'validation' => 'REGEX', 'entity_scope' => 'person'],
        ];
    }

    public static function normalize(string $nationalId, ?string $type = null): string
    {
        if ($type === 'PASSPORT') return self::normalizePassport($nationalId);
        return preg_replace('/[^0-9]/', '', $nationalId);
    }

    public static function format(string $nationalId, ?string $type = null): string
    {
        if ($type === 'PASSPORT') return self::formatPassport($nationalId);

        $clean = self::normalize($nationalId);

        if (strlen($clean) === 11) {
            # CPF: XXX.XXX.XXX-XX
            return substr($clean, 0, 3) . '.' . substr($clean, 3, 3) . '.' .
                   substr($clean, 6, 3) . '-' . substr($clean, 9, 2);
        }

        if (strlen($clean) === 14) {
            # CNPJ: XX.XXX.XXX/XXXX-XX
            return substr($clean, 0, 2) . '.' . substr($clean, 2, 3) . '.' .
                   substr($clean, 5, 3) . '/' . substr($clean, 8, 4) . '-' . substr($clean, 12, 2);
        }

        return $clean;
    }

    public static function validate(string $nationalId, ?string $type = null): array
    {
        if ($type === 'PASSPORT') return self::validatePassport($nationalId);

        $detected = $type ?? self::detectType($nationalId);
        if ($detected === 'PASSPORT') return self::validatePassport($nationalId);

        $clean = self::normalize($nationalId);

        if (strlen($clean) === 11) {
            return self::validateCPF($clean);
        }

        if (strlen($clean) === 14) {
            return self::validateCNPJ($clean);
        }

        return ['valid' => false, 'type' => $detected, 'error' => 'INVALID_LENGTH', 'expected' => '11 (CPF) or 14 (CNPJ)'];
    }

    public static function detectType(string $nationalId): ?string
    {
        $clean = preg_replace('/[^0-9]/', '', $nationalId);
        if (strlen($clean) === 11) return 'CPF';
        if (strlen($clean) === 14) return 'CNPJ';
        # Si tiene letras → pasaporte
        $raw = preg_replace('/[\s\-\.]/', '', $nationalId);
        if (preg_match('/[A-Za-z]/', $raw) && strlen($raw) >= 5) return 'PASSPORT';
        return null;
    }

    /**
     * CPF — Módulo 11, pesos 10→2 (primer DV) y 11→2 (segundo DV)
     */
    private static function validateCPF(string $cpf): array
    {
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return ['valid' => false, 'type' => 'CPF', 'error' => 'REPEATED_DIGITS'];
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$cpf[$i] * (10 - $i);
        }
        $dv1 = 11 - ($sum % 11);
        if ($dv1 >= 10) $dv1 = 0;

        if ((int)$cpf[9] !== $dv1) {
            return ['valid' => false, 'type' => 'CPF', 'error' => 'INVALID_VERIFIER_1'];
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int)$cpf[$i] * (11 - $i);
        }
        $dv2 = 11 - ($sum % 11);
        if ($dv2 >= 10) $dv2 = 0;

        if ((int)$cpf[10] !== $dv2) {
            return ['valid' => false, 'type' => 'CPF', 'error' => 'INVALID_VERIFIER_2'];
        }

        return ['valid' => true, 'type' => 'CPF', 'validation' => 'CHECKSUM'];
    }

    /**
     * CNPJ — Módulo 11, pesos [5,4,3,2,9,8,7,6,5,4,3,2] y [6,5,4,3,2,9,8,7,6,5,4,3,2]
     */
    private static function validateCNPJ(string $cnpj): array
    {
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return ['valid' => false, 'type' => 'CNPJ', 'error' => 'REPEATED_DIGITS'];
        }

        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$cnpj[$i] * $weights1[$i];
        }
        $dv1 = $sum % 11;
        $dv1 = $dv1 < 2 ? 0 : 11 - $dv1;

        if ((int)$cnpj[12] !== $dv1) {
            return ['valid' => false, 'type' => 'CNPJ', 'error' => 'INVALID_VERIFIER_1'];
        }

        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int)$cnpj[$i] * $weights2[$i];
        }
        $dv2 = $sum % 11;
        $dv2 = $dv2 < 2 ? 0 : 11 - $dv2;

        if ((int)$cnpj[13] !== $dv2) {
            return ['valid' => false, 'type' => 'CNPJ', 'error' => 'INVALID_VERIFIER_2'];
        }

        return ['valid' => true, 'type' => 'CNPJ', 'validation' => 'CHECKSUM'];
    }
}
