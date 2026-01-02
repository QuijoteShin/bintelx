<?php
# bintelx/kernel/TemporalResolver.php
# Temporal Value Resolution with Multi-Source Providers
# Evolution of ParamStore with pluggable data sources
#
# Features:
#   - Providers pattern: DB, EDC (DataCaptureService), In-Memory
#   - Unified API: resolve(key, date, scope)
#   - Effective dating with configurable fallback
#   - Snapshot generation for period calculations
#   - Deterministic hashing for audit
#
# @version 1.0.0

namespace bX;

require_once __DIR__ . '/Math.php';

interface TemporalProvider
{
    /**
     * Resolve a value at a specific point in time
     *
     * @param string $key Parameter key
     * @param string $date Effective date (Y-m-d)
     * @param array $scope Scope context ['entity_id' => int, 'employee_id' => int, 'country' => string]
     * @return array|null ['value' => string, 'effective_from' => date, 'effective_to' => date, 'source' => string] or null
     */
    public function resolve(string $key, string $date, array $scope): ?array;

    /**
     * Get provider name for audit trail
     */
    public function getName(): string;

    /**
     * Check if provider can handle this key
     */
    public function canHandle(string $key): bool;
}

class TemporalResolver
{
    public const VERSION = '1.0.0';

    # Provider priorities (lower = checked first)
    public const PRIORITY_EMPLOYEE = 10;
    public const PRIORITY_COMPANY = 20;
    public const PRIORITY_COUNTRY = 30;
    public const PRIORITY_GLOBAL = 40;

    # Error codes
    public const ERR_NOT_FOUND = 'VALUE_NOT_FOUND';
    public const ERR_NO_PROVIDER = 'NO_PROVIDER_FOR_KEY';
    public const ERR_INVALID_DATE = 'INVALID_DATE';

    # Instance state
    private array $providers = [];
    private string $defaultDate;
    private array $defaultScope = [];
    private array $cache = [];
    private array $accessed = [];
    private bool $cacheEnabled = true;

    public function __construct(?string $defaultDate = null)
    {
        $this->defaultDate = $defaultDate ?? date('Y-m-d');
    }

    /**
     * Register a provider
     *
     * @param TemporalProvider $provider Provider instance
     * @param int $priority Lower = checked first
     * @return self
     */
    public function addProvider(TemporalProvider $provider, int $priority = 50): self
    {
        $this->providers[] = ['provider' => $provider, 'priority' => $priority];
        usort($this->providers, fn($a, $b) => $a['priority'] <=> $b['priority']);
        return $this;
    }

    /**
     * Set default scope for all resolutions
     */
    public function setDefaultScope(array $scope): self
    {
        $this->defaultScope = $scope;
        return $this;
    }

    /**
     * Set default evaluation date
     */
    public function setDefaultDate(string $date): self
    {
        $this->defaultDate = $date;
        return $this;
    }

    /**
     * Enable/disable caching
     */
    public function setCacheEnabled(bool $enabled): self
    {
        $this->cacheEnabled = $enabled;
        return $this;
    }

    /**
     * Clear cache
     */
    public function clearCache(): self
    {
        $this->cache = [];
        return $this;
    }

    /**
     * Resolve a value at a point in time
     *
     * @param string $key Parameter key
     * @param string|null $date Effective date (default: defaultDate)
     * @param array $scope Override scope
     * @return string|null Resolved value or null
     */
    public function resolve(string $key, ?string $date = null, array $scope = []): ?string
    {
        $result = $this->resolveWithMeta($key, $date, $scope);
        return $result['value'] ?? null;
    }

    /**
     * Resolve with full metadata
     *
     * @param string $key Parameter key
     * @param string|null $date Effective date
     * @param array $scope Override scope
     * @return array ['success' => bool, 'value' => string, 'source' => string, ...]
     */
    public function resolveWithMeta(string $key, ?string $date = null, array $scope = []): array
    {
        $date = $date ?? $this->defaultDate;
        $mergedScope = array_merge($this->defaultScope, $scope);

        # Check cache
        $cacheKey = $this->buildCacheKey($key, $date, $mergedScope);
        if ($this->cacheEnabled && isset($this->cache[$cacheKey])) {
            $this->accessed[$cacheKey] = $this->cache[$cacheKey];
            return $this->cache[$cacheKey];
        }

        # Try each provider in priority order
        foreach ($this->providers as $entry) {
            $provider = $entry['provider'];

            if (!$provider->canHandle($key)) {
                continue;
            }

            $result = $provider->resolve($key, $date, $mergedScope);

            if ($result !== null) {
                $response = [
                    'success' => true,
                    'value' => $result['value'],
                    'key' => $key,
                    'date' => $date,
                    'scope' => $mergedScope,
                    'effective_from' => $result['effective_from'] ?? null,
                    'effective_to' => $result['effective_to'] ?? null,
                    'source' => $result['source'] ?? $provider->getName(),
                    'provider' => $provider->getName(),
                ];

                if ($this->cacheEnabled) {
                    $this->cache[$cacheKey] = $response;
                }
                $this->accessed[$cacheKey] = $response;

                return $response;
            }
        }

        # Not found
        return [
            'success' => false,
            'error' => self::ERR_NOT_FOUND,
            'key' => $key,
            'date' => $date,
            'scope' => $mergedScope,
        ];
    }

    /**
     * Resolve multiple keys at once
     *
     * @param array $keys List of keys
     * @param string|null $date Effective date
     * @param array $scope Scope
     * @return array ['key' => 'value', ...]
     */
    public function resolveMany(array $keys, ?string $date = null, array $scope = []): array
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->resolve($key, $date, $scope);
        }
        return $results;
    }

    /**
     * Generate a snapshot of all accessed values
     * Useful for deterministic recalculation
     *
     * @return array Snapshot with all accessed values and metadata
     */
    public function getSnapshot(): array
    {
        return [
            'version' => self::VERSION,
            'generated_at' => date('Y-m-d H:i:s'),
            'default_date' => $this->defaultDate,
            'default_scope' => $this->defaultScope,
            'values' => $this->accessed,
            'hash' => $this->computeSnapshotHash(),
        ];
    }

    /**
     * Get list of accessed parameters
     */
    public function getAccessedParams(): array
    {
        return array_values(array_map(fn($v) => [
            'key' => $v['key'],
            'value' => $v['value'],
            'date' => $v['date'],
            'provider' => $v['provider'] ?? 'unknown',
        ], $this->accessed));
    }

    /**
     * Compute deterministic hash of accessed values
     */
    public function computeSnapshotHash(): string
    {
        $data = [];
        foreach ($this->accessed as $key => $entry) {
            if (isset($entry['value'])) {
                $data[$key] = $entry['value'];
            }
        }
        ksort($data);
        return hash('sha256', json_encode($data));
    }

    /**
     * Build cache key
     */
    private function buildCacheKey(string $key, string $date, array $scope): string
    {
        $scopeParts = [];
        ksort($scope);
        foreach ($scope as $k => $v) {
            $scopeParts[] = "$k:$v";
        }
        return implode('|', [$key, $date, implode(',', $scopeParts)]);
    }

    /**
     * Create resolver callback for RulesEngine
     *
     * @return callable Callback for PARAM() function
     */
    public function createParamResolver(): callable
    {
        return function (string $key, ?string $date = null) {
            return $this->resolve($key, $date);
        };
    }

    /**
     * Create employee resolver callback for RulesEngine
     *
     * @return callable Callback for EMP_PARAM() function
     */
    public function createEmployeeResolver(): callable
    {
        return function (string $key, int $employeeId, ?string $date = null) {
            return $this->resolve($key, $date, ['employee_id' => $employeeId]);
        };
    }
}

# ============================================================================
# BUILT-IN PROVIDERS
# ============================================================================

/**
 * In-Memory Provider for testing and static configuration
 */
class InMemoryProvider implements TemporalProvider
{
    private array $values = [];
    private string $name;

    public function __construct(string $name = 'memory')
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function canHandle(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /**
     * Set a value with optional effective dates
     */
    public function set(string $key, string $value, ?string $effectiveFrom = null, ?string $effectiveTo = null): self
    {
        if (!isset($this->values[$key])) {
            $this->values[$key] = [];
        }

        $this->values[$key][] = [
            'value' => $value,
            'effective_from' => $effectiveFrom ?? '1900-01-01',
            'effective_to' => $effectiveTo ?? '9999-12-31',
        ];

        return $this;
    }

    /**
     * Bulk set values
     */
    public function setMany(array $values): self
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $this->set($key, $value['value'], $value['effective_from'] ?? null, $value['effective_to'] ?? null);
            } else {
                $this->set($key, (string)$value);
            }
        }
        return $this;
    }

    public function resolve(string $key, string $date, array $scope): ?array
    {
        if (!isset($this->values[$key])) {
            return null;
        }

        # Find value effective at date
        foreach ($this->values[$key] as $entry) {
            if ($date >= $entry['effective_from'] && $date <= $entry['effective_to']) {
                return [
                    'value' => $entry['value'],
                    'effective_from' => $entry['effective_from'],
                    'effective_to' => $entry['effective_to'],
                    'source' => $this->name,
                ];
            }
        }

        return null;
    }
}

/**
 * Database Provider for pay_param_value table
 */
class DbParamProvider implements TemporalProvider
{
    private string $countryCode;
    private string $name;

    public function __construct(string $countryCode, string $name = 'db')
    {
        $this->countryCode = $countryCode;
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function canHandle(string $key): bool
    {
        # Can handle any key that starts with country prefix or is in our country
        return true;
    }

    public function resolve(string $key, string $date, array $scope): ?array
    {
        $entityId = $scope['entity_id'] ?? $scope['scope_entity_id'] ?? null;
        $employeeId = $scope['employee_id'] ?? null;

        # Build query
        $sql = "
            SELECT
                v.value_json,
                v.effective_from,
                v.effective_to,
                v.source_reference,
                d.param_key,
                d.scope_level,
                d.allows_fallback
            FROM pay_param_value v
            JOIN pay_param_def d ON v.param_def_id = d.param_def_id
            WHERE d.param_key = :key
              AND d.country_code = :country
              AND v.effective_from <= :date
              AND v.effective_to >= :date
              AND d.is_active = 1
        ";

        $params = [
            ':key' => $key,
            ':country' => $this->countryCode,
            ':date' => $date,
        ];

        # Scope entity filter
        if ($employeeId !== null) {
            $sql .= " AND (v.scope_entity_id = :employee_id OR v.scope_entity_id IS NULL)";
            $params[':employee_id'] = $employeeId;
            $sql .= " ORDER BY v.scope_entity_id DESC LIMIT 1"; # Prefer employee-specific
        } elseif ($entityId !== null) {
            $sql .= " AND (v.scope_entity_id = :entity_id OR v.scope_entity_id IS NULL)";
            $params[':entity_id'] = $entityId;
            $sql .= " ORDER BY v.scope_entity_id DESC LIMIT 1"; # Prefer entity-specific
        } else {
            $sql .= " AND v.scope_entity_id IS NULL LIMIT 1";
        }

        try {
            $rows = CONN::dml($sql, $params);

            if (!empty($rows)) {
                $row = $rows[0];
                $value = json_decode($row['value_json'], true);
                if (is_array($value)) {
                    $value = $value[0] ?? json_encode($value);
                }

                return [
                    'value' => (string)$value,
                    'effective_from' => $row['effective_from'],
                    'effective_to' => $row['effective_to'],
                    'source' => $row['source_reference'] ?? 'db',
                    'scope_level' => $row['scope_level'],
                    'allows_fallback' => (bool)$row['allows_fallback'],
                ];
            }
        } catch (\Exception $e) {
            # Log error but don't fail
        }

        return null;
    }
}

/**
 * EDC Provider - Reads from DataCaptureService
 * For employee master data that changes over time
 */
class EdcProvider implements TemporalProvider
{
    private string $name;
    private array $fieldMapping = [];

    public function __construct(string $name = 'edc')
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Map payroll param keys to EDC variable names
     *
     * @param array $mapping ['CL_AFP_CODE' => 'employee.afp_code', ...]
     */
    public function setFieldMapping(array $mapping): self
    {
        $this->fieldMapping = $mapping;
        return $this;
    }

    public function canHandle(string $key): bool
    {
        return isset($this->fieldMapping[$key]);
    }

    public function resolve(string $key, string $date, array $scope): ?array
    {
        if (!isset($this->fieldMapping[$key])) {
            return null;
        }

        $variableName = $this->fieldMapping[$key];
        $employeeId = $scope['employee_id'] ?? null;

        if ($employeeId === null) {
            return null;
        }

        # Query DataCaptureService for historical value at date
        $sql = "
            SELECT
                h.value_string,
                h.value_decimal,
                h.timestamp,
                h.version,
                cg.created_at as effective_from
            FROM data_values_history h
            JOIN data_dictionary d ON h.variable_id = d.variable_id
            JOIN data_context_groups cg ON h.context_group_id = cg.context_group_id
            WHERE d.unique_name = :var_name
              AND h.entity_id = :employee_id
              AND h.timestamp <= :date
            ORDER BY h.timestamp DESC, h.version DESC
            LIMIT 1
        ";

        try {
            $rows = CONN::dml($sql, [
                ':var_name' => $variableName,
                ':employee_id' => $employeeId,
                ':date' => $date . ' 23:59:59',
            ]);

            if (!empty($rows)) {
                $row = $rows[0];
                $value = $row['value_string'] ?? $row['value_decimal'] ?? null;

                if ($value !== null) {
                    return [
                        'value' => (string)$value,
                        'effective_from' => substr($row['effective_from'], 0, 10),
                        'effective_to' => '9999-12-31',
                        'source' => 'edc:' . $variableName,
                    ];
                }
            }
        } catch (\Exception $e) {
            # Log error
        }

        return null;
    }
}
