<?php # bintelx/kernel/Geo/PassportTrait.php
namespace bX\Geo;

/**
 * Validación universal de pasaportes — ICAO Doc 9303
 * Compartido por todos los country drivers
 */
trait PassportTrait
{
    public static function normalizePassport(string $passport): string
    {
        return strtoupper(preg_replace('/[\s\-]/', '', $passport));
    }

    public static function formatPassport(string $passport): string
    {
        return self::normalizePassport($passport);
    }

    /**
     * Validación de formato ICAO
     * Pasaporte: 1 letra + 1-2 letras (código tipo) + hasta 35 alfanuméricos
     * En la práctica: 5-20 caracteres alfanuméricos
     */
    public static function validatePassport(string $passport): array
    {
        $clean = self::normalizePassport($passport);

        if (strlen($clean) < 5) {
            return ['valid' => false, 'type' => 'PASSPORT', 'error' => 'TOO_SHORT'];
        }

        if (strlen($clean) > 20) {
            return ['valid' => false, 'type' => 'PASSPORT', 'error' => 'TOO_LONG'];
        }

        if (!preg_match('/^[A-Z0-9]+$/', $clean)) {
            return ['valid' => false, 'type' => 'PASSPORT', 'error' => 'INVALID_CHARS'];
        }

        return ['valid' => true, 'type' => 'PASSPORT'];
    }
}
