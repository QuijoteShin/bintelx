<?php # bintelx/kernel/Geo/CountryDriverInterface.php
namespace bX\Geo;

/**
 * Contrato para drivers de validación por país
 *
 * Niveles de validación:
 *   - REGEX:    limpieza + formato (solo parece válido)
 *   - CHECKSUM: coherencia matemática (algorítmicamente correcto)
 *   - NONE:     sin validación posible (solo almacenamiento)
 *
 * Verificación real (KYC, fuente autoritativa) NO es responsabilidad del driver.
 * Eso es identity_assurance = 'verified' y se registra en EAV.
 *
 * PASSPORT siempre debe estar soportado en todos los drivers.
 */
interface CountryDriverInterface
{
    /**
     * Lista los tipos de identificación soportados por el país
     * Retorna array de metadata:
     * [
     *   ['code' => 'TAX_ID', 'label' => 'RUT', 'validation' => 'CHECKSUM', 'entity_scope' => 'both'],
     *   ['code' => 'PASSPORT', 'label' => 'Pasaporte', 'validation' => 'REGEX', 'entity_scope' => 'person'],
     * ]
     *
     * entity_scope: 'person', 'company', 'both'
     * validation: 'CHECKSUM', 'REGEX', 'NONE'
     */
    public static function supportedTypes(): array;

    /**
     * Normaliza el identificador según su tipo y reglas del país
     */
    public static function normalize(string $nationalId, ?string $type = null): string;

    /**
     * Formatea el identificador con la presentación oficial del país
     */
    public static function format(string $nationalId, ?string $type = null): string;

    /**
     * Valida el identificador según el nivel disponible (checksum o regex)
     * Retorna ['valid' => bool, 'type' => string, 'validation' => string, 'error' => ?string]
     */
    public static function validate(string $nationalId, ?string $type = null): array;

    /**
     * Detecta el tipo de identificador por su formato/longitud
     */
    public static function detectType(string $nationalId): ?string;
}
