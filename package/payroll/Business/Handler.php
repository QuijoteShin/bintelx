<?php # package/payroll/Business/Handler.php
namespace bX\Payroll;

use bX\CONN;
use bX\PayrollEngine;
use bX\Tenant;

/**
 * Handler - Lógica de negocio para nómina
 *
 * Handlers NO manejan HTTP, retornan arrays.
 * Signature: method(array $data, array $options, ?callable $callback): array
 */
class Handler
{
    /**
     * Calcular liquidación
     *
     * @param array $data ['employee_id', 'earnings', 'inputs']
     * @param array $options ['country_code', 'period_start', 'period_end', 'calc_type', 'scope_entity_id']
     * @param callable|null $callback Para streaming (no usado aquí)
     * @return array
     */
    public static function calculate(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        # Validar campos requeridos
        if (empty($data['employee_id'])) {
            return ['success' => false, 'error_code' => 'MISSING_EMPLOYEE_ID', 'message' => 'employee_id requerido'];
        }
        if (empty($options['country_code'])) {
            return ['success' => false, 'error_code' => 'MISSING_COUNTRY_CODE', 'message' => 'country_code requerido'];
        }
        if (empty($options['period_start']) || empty($options['period_end'])) {
            return ['success' => false, 'error_code' => 'MISSING_PERIOD', 'message' => 'period_start y period_end requeridos'];
        }

        # Validar tenant
        $check = Tenant::validateForWrite($options);
        if (!$check['valid']) {
            return ['success' => false, 'error_code' => 'TENANT_ERROR', 'message' => $check['error']];
        }

        # Ejecutar PayrollEngine
        $result = PayrollEngine::calculate($data, $options);

        if (!$result['success']) {
            return [
                'success' => false,
                'error_code' => $result['error_code'] ?? 'CALC_ERROR',
                'message' => $result['message'] ?? 'Error en cálculo',
                'errors' => $result['errors'] ?? [],
            ];
        }

        return [
            'success' => true,
            'data' => [
                'totals' => $result['totals'] ?? [],
                'lines' => $result['lines'] ?? [],
                'lineDetails' => $result['lineDetails'] ?? [],
                'metadata' => [
                    'employee_id' => $data['employee_id'],
                    'period' => $options['period_start'] . ' - ' . $options['period_end'],
                    'country_code' => $options['country_code'],
                    'calc_type' => $options['calc_type'] ?? 'MONTHLY',
                    'calculated_at' => date('c'),
                    'has_formula_errors' => $result['has_formula_errors'] ?? false,
                ],
            ],
        ];
    }

    /**
     * Obtener parámetros vigentes
     *
     * @param array $data [] (no usado)
     * @param array $options ['country_code', 'date', 'scope_entity_id']
     * @param callable|null $callback Para streaming
     * @return array
     */
    public static function getParams(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $country = strtoupper($options['country_code'] ?? 'CL');
        $date = $options['date'] ?? date('Y-m-d');
        $scopeId = $options['scope_entity_id'] ?? null;

        $params = [];
        $sql = "SELECT
                    pd.param_key,
                    pd.param_name,
                    pd.unit,
                    pv.value_json,
                    pv.effective_from,
                    pv.effective_to,
                    pv.source_reference
                FROM pay_param_def pd
                JOIN pay_param_value pv ON pv.param_def_id = pd.param_def_id
                WHERE pd.country_code = :country
                  AND pd.is_active = 1
                  AND pv.effective_from <= :date
                  AND (pv.effective_to >= :date OR pv.effective_to = '9999-12-31')
                  AND (pv.scope_entity_id IS NULL" . ($scopeId ? " OR pv.scope_entity_id = :scope" : "") . ")
                ORDER BY pd.param_key, pv.scope_entity_id DESC";

        $sqlParams = [':country' => $country, ':date' => $date];
        if ($scopeId) {
            $sqlParams[':scope'] = $scopeId;
        }

        if ($callback) {
            CONN::dml($sql, $sqlParams, $callback);
            return ['success' => true, 'streamed' => true];
        }

        CONN::dml($sql, $sqlParams, function($row) use (&$params) {
            $key = $row['param_key'];
            if (!isset($params[$key])) {
                $params[$key] = [
                    'key' => $key,
                    'name' => $row['param_name'],
                    'value' => json_decode($row['value_json'], true),
                    'unit' => $row['unit'],
                    'effective_from' => $row['effective_from'],
                    'effective_to' => $row['effective_to'],
                    'source' => $row['source_reference'],
                ];
            }
        });

        return [
            'success' => true,
            'data' => [
                'country_code' => $country,
                'effective_date' => $date,
                'params' => array_values($params),
                'count' => count($params),
            ],
        ];
    }

    /**
     * Obtener conceptos activos
     *
     * @param array $data [] (no usado)
     * @param array $options ['country_code', 'type']
     * @param callable|null $callback Para streaming
     * @return array
     */
    public static function getConcepts(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $country = strtoupper($options['country_code'] ?? 'CL');
        $type = $options['type'] ?? null;

        # Filtrar por prefijo del concept_code (ej: CL_E_SUELDO_BASE)
        $prefix = $country . '_%';

        $sql = "SELECT
                    concept_code,
                    type_code,
                    concept_name,
                    description,
                    unit,
                    taxable,
                    pensionable,
                    is_input
                FROM pay_concept
                WHERE concept_code LIKE :prefix";

        $sqlParams = [':prefix' => $prefix];

        if ($type) {
            $sql .= " AND type_code = :type";
            $sqlParams[':type'] = strtoupper($type);
        }

        $sql .= " ORDER BY type_code, concept_code";

        if ($callback) {
            CONN::dml($sql, $sqlParams, $callback);
            return ['success' => true, 'streamed' => true];
        }

        $concepts = [];
        CONN::dml($sql, $sqlParams, function($row) use (&$concepts) {
            $concepts[] = [
                'code' => $row['concept_code'],
                'type' => $row['type_code'],
                'name' => $row['concept_name'],
                'description' => $row['description'],
                'unit' => $row['unit'],
                'taxable' => (bool)$row['taxable'],
                'pensionable' => (bool)$row['pensionable'],
                'is_input' => (bool)$row['is_input'],
            ];
        });

        # Agrupar por tipo
        $grouped = [];
        foreach ($concepts as $c) {
            $grouped[$c['type']][] = $c;
        }

        return [
            'success' => true,
            'data' => [
                'country_code' => $country,
                'concepts' => $concepts,
                'by_type' => $grouped,
                'count' => count($concepts),
            ],
        ];
    }
}
