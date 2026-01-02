<?php
# bintelx/kernel/ParamStore.php
# Parameter Store with Effective Dating for Payroll Engine
#
# Features:
#   - PARAM(key, date) - Global/Company level parameters
#   - EMP_PARAM(key, employee_id, date) - Employee level parameters
#   - Effective dating with fallback to last known value
#   - Proration strategy support (NONE, DAILY, HOURLY, CUTOFF)
#   - Version hashing for determinism
#   - Cache for performance
#
# @version 1.0.0

namespace bX;

class ParamStore
{
    public const VERSION = '1.0.0';

    # Proration strategies
    public const PRORATE_NONE = 'NONE';
    public const PRORATE_DAILY = 'DAILY';
    public const PRORATE_HOURLY = 'HOURLY';
    public const PRORATE_CUTOFF = 'CUTOFF';

    # Error codes
    public const ERR_NOT_FOUND = 'PARAM_NOT_FOUND';
    public const ERR_INVALID_DATE = 'INVALID_DATE';
    public const ERR_NO_FALLBACK = 'NO_FALLBACK_ALLOWED';
    public const ERR_SCOPE_MISMATCH = 'SCOPE_LEVEL_MISMATCH';
    public const ERR_OVERLAP_DETECTED = 'OVERLAPPING_VALUES';

    # Scope levels
    public const SCOPE_GLOBAL = 'GLOBAL';
    public const SCOPE_COMPANY = 'COMPANY';
    public const SCOPE_EMPLOYEE = 'EMPLOYEE';

    # Instance state
    private string $countryCode;
    private ?int $scopeEntityId;
    private string $evaluationDate;
    private array $cache = [];
    private array $empCache = [];
    private array $accessed = [];

    public function __construct(
        string $countryCode,
        ?int $scopeEntityId = null,
        ?string $evaluationDate = null
    ) {
        $this->countryCode = $countryCode;
        $this->scopeEntityId = $scopeEntityId;
        $this->evaluationDate = $evaluationDate ?? date('Y-m-d');
    }

    /**
     * Get a global/company parameter value
     *
     * @param string $key Parameter key
     * @param string|null $date Effective date (default: evaluation date)
     * @return string|null Parameter value or null if not found
     */
    public function get(string $key, ?string $date = null): ?string
    {
        $date = $date ?? $this->evaluationDate;
        $cacheKey = "{$key}|{$date}|{$this->scopeEntityId}";

        if (isset($this->cache[$cacheKey])) {
            $this->recordAccess($key, $date, 'GLOBAL', $this->cache[$cacheKey]);
            return $this->cache[$cacheKey]['value'];
        }

        $result = $this->loadParam($key, $date);

        if ($result !== null) {
            $this->cache[$cacheKey] = $result;
            $this->recordAccess($key, $date, 'GLOBAL', $result);
            return $result['value'];
        }

        return null;
    }

    /**
     * Get an employee-level parameter value
     *
     * @param string $key Parameter key
     * @param int $employeeId Employee ID
     * @param string|null $date Effective date
     * @return string|null Parameter value or null if not found
     */
    public function getEmployee(string $key, int $employeeId, ?string $date = null): ?string
    {
        $date = $date ?? $this->evaluationDate;
        $cacheKey = "{$key}|{$employeeId}|{$date}";

        if (isset($this->empCache[$cacheKey])) {
            $this->recordAccess($key, $date, self::SCOPE_EMPLOYEE, $this->empCache[$cacheKey], $employeeId);
            return $this->empCache[$cacheKey]['value'];
        }

        $result = $this->loadEmployeeParam($key, $employeeId, $date);

        if ($result !== null) {
            $this->empCache[$cacheKey] = $result;
            $this->recordAccess($key, $date, self::SCOPE_EMPLOYEE, $result, $employeeId);
            return $result['value'];
        }

        # Check if fallback to global is allowed
        $paramDef = $this->getParamDefinition($key);
        if ($paramDef !== null) {
            # If scope_level is EMPLOYEE, don't fallback to global
            if ($paramDef['scope_level'] === self::SCOPE_EMPLOYEE) {
                return null;
            }

            # If allows_fallback is false, don't fallback
            if (!$paramDef['allows_fallback']) {
                return null;
            }
        }

        # Fallback to global param
        return $this->get($key, $date);
    }

    /**
     * Get employee param with explicit fallback control
     *
     * @param string $key Parameter key
     * @param int $employeeId Employee ID
     * @param string|null $date Effective date
     * @param bool $allowFallback Override fallback behavior
     * @return array Result with value and metadata
     */
    public function getEmployeeWithMeta(
        string $key,
        int $employeeId,
        ?string $date = null,
        bool $allowFallback = true
    ): array {
        $date = $date ?? $this->evaluationDate;

        $result = $this->loadEmployeeParam($key, $employeeId, $date);

        if ($result !== null) {
            return [
                'success' => true,
                'value' => $result['value'],
                'scope' => self::SCOPE_EMPLOYEE,
                'employee_id' => $employeeId,
                'meta' => $result
            ];
        }

        # Check fallback rules
        $paramDef = $this->getParamDefinition($key);

        if ($paramDef === null) {
            return [
                'success' => false,
                'error' => self::ERR_NOT_FOUND,
                'key' => $key
            ];
        }

        if ($paramDef['scope_level'] === self::SCOPE_EMPLOYEE) {
            return [
                'success' => false,
                'error' => self::ERR_SCOPE_MISMATCH,
                'key' => $key,
                'message' => "Parameter {$key} requires employee-level value"
            ];
        }

        if (!$allowFallback || !$paramDef['allows_fallback']) {
            return [
                'success' => false,
                'error' => self::ERR_NO_FALLBACK,
                'key' => $key
            ];
        }

        # Fallback to global
        $globalResult = $this->loadParam($key, $date);

        if ($globalResult !== null) {
            return [
                'success' => true,
                'value' => $globalResult['value'],
                'scope' => self::SCOPE_GLOBAL,
                'is_fallback' => true,
                'meta' => $globalResult
            ];
        }

        return [
            'success' => false,
            'error' => self::ERR_NOT_FOUND,
            'key' => $key
        ];
    }

    /**
     * Get parameter with full metadata
     *
     * @param string $key Parameter key
     * @param string|null $date Effective date
     * @return array|null Full parameter info or null
     */
    public function getWithMeta(string $key, ?string $date = null): ?array
    {
        $date = $date ?? $this->evaluationDate;
        $cacheKey = "{$key}|{$date}|{$this->scopeEntityId}";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $result = $this->loadParam($key, $date);

        if ($result !== null) {
            $this->cache[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * Check if parameter exists for date
     */
    public function exists(string $key, ?string $date = null): bool
    {
        return $this->get($key, $date) !== null;
    }

    /**
     * Get all accessed parameters (for snapshot)
     */
    public function getAccessedParams(): array
    {
        return $this->accessed;
    }

    /**
     * Clear cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
        $this->empCache = [];
    }

    /**
     * Create resolver callback for RulesEngine
     */
    public function createParamResolver(): callable
    {
        return function(string $key, string $date): ?string {
            return $this->get($key, $date);
        };
    }

    /**
     * Create employee param resolver callback for RulesEngine
     */
    public function createEmpParamResolver(): callable
    {
        return function(string $key, int $employeeId, string $date): ?string {
            return $this->getEmployee($key, $employeeId, $date);
        };
    }

    /**
     * Load all parameters for a date into cache (bulk load)
     */
    public function preloadForDate(string $date): void
    {
        $sql = "SELECT
                    pd.param_key,
                    pd.data_type,
                    pd.precision_digits,
                    pd.allows_fallback,
                    pd.proration_strategy,
                    pv.value_json,
                    pv.effective_from,
                    pv.effective_to,
                    pv.version_hash,
                    pv.source_reference
                FROM pay_param_def pd
                JOIN pay_param_value pv ON pd.param_def_id = pv.param_def_id
                WHERE pd.country_code = :country
                  AND pd.is_active = 1
                  AND :date BETWEEN pv.effective_from AND pv.effective_to
                  AND (pv.scope_entity_id IS NULL OR pv.scope_entity_id = :scope)
                ORDER BY pv.scope_entity_id DESC";

        $params = [
            ':country' => $this->countryCode,
            ':date' => $date,
            ':scope' => $this->scopeEntityId
        ];

        CONN::dml($sql, $params, function($row) use ($date) {
            $key = $row['param_key'];
            $cacheKey = "{$key}|{$date}|{$this->scopeEntityId}";

            if (!isset($this->cache[$cacheKey])) {
                $this->cache[$cacheKey] = $this->parseParamRow($row);
            }
        });
    }

    /**
     * Get parameter value with proration for mid-period changes
     *
     * @param string $key Parameter key
     * @param string $periodStart Period start date
     * @param string $periodEnd Period end date
     * @param int $workedDays Days worked (for DAILY proration)
     * @param int $totalDays Total days in period
     * @return array Prorated result with explain
     */
    public function getProrated(
        string $key,
        string $periodStart,
        string $periodEnd,
        int $workedDays = 0,
        int $totalDays = 0
    ): array {
        $meta = $this->getWithMeta($key, $periodEnd);

        if ($meta === null) {
            return [
                'success' => false,
                'error' => self::ERR_NOT_FOUND,
                'key' => $key
            ];
        }

        $strategy = $meta['proration_strategy'] ?? self::PRORATE_NONE;

        if ($strategy === self::PRORATE_NONE) {
            return [
                'success' => true,
                'value' => $meta['value'],
                'prorated' => false,
                'meta' => $meta
            ];
        }

        # Check for mid-period change
        $effectiveFrom = $meta['effective_from'];
        if ($effectiveFrom > $periodStart && $effectiveFrom <= $periodEnd) {
            # Parameter changed mid-period
            $prevMeta = $this->loadParamBefore($key, $effectiveFrom);

            if ($prevMeta !== null && $strategy === self::PRORATE_DAILY) {
                return $this->prorateDailyMidPeriod(
                    $key,
                    $prevMeta,
                    $meta,
                    $periodStart,
                    $periodEnd,
                    $effectiveFrom,
                    $totalDays
                );
            }

            if ($strategy === self::PRORATE_CUTOFF) {
                # Use new value from cutoff date
                return [
                    'success' => true,
                    'value' => $meta['value'],
                    'prorated' => false,
                    'cutoff_date' => $effectiveFrom,
                    'meta' => $meta
                ];
            }
        }

        return [
            'success' => true,
            'value' => $meta['value'],
            'prorated' => false,
            'meta' => $meta
        ];
    }

    # =========================================================================
    # PRIVATE METHODS
    # =========================================================================

    /**
     * Get parameter definition (without values)
     */
    private function getParamDefinition(string $key): ?array
    {
        static $defCache = [];
        $cacheKey = "{$this->countryCode}|{$key}";

        if (isset($defCache[$cacheKey])) {
            return $defCache[$cacheKey];
        }

        $sql = "SELECT
                    param_key,
                    scope_level,
                    allows_fallback,
                    proration_strategy,
                    data_type,
                    precision_digits
                FROM pay_param_def
                WHERE param_key = :key
                  AND country_code = :country
                  AND is_active = 1
                LIMIT 1";

        $result = null;
        CONN::dml($sql, [
            ':key' => $key,
            ':country' => $this->countryCode
        ], function($row) use (&$result) {
            $result = [
                'key' => $row['param_key'],
                'scope_level' => $row['scope_level'] ?? self::SCOPE_GLOBAL,
                'allows_fallback' => (bool)($row['allows_fallback'] ?? true),
                'proration_strategy' => $row['proration_strategy'] ?? self::PRORATE_NONE,
                'data_type' => $row['data_type'],
                'precision' => (int)$row['precision_digits']
            ];
            return false;
        });

        $defCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Check for overlapping value ranges (validation)
     */
    public function validateNoOverlap(string $key): array
    {
        $sql = "SELECT
                    pv1.effective_from AS from1,
                    pv1.effective_to AS to1,
                    pv2.effective_from AS from2,
                    pv2.effective_to AS to2,
                    pv1.scope_entity_id AS scope1,
                    pv2.scope_entity_id AS scope2
                FROM pay_param_value pv1
                JOIN pay_param_value pv2 ON pv1.param_def_id = pv2.param_def_id
                    AND pv1.param_value_id < pv2.param_value_id
                    AND COALESCE(pv1.scope_entity_id, 0) = COALESCE(pv2.scope_entity_id, 0)
                JOIN pay_param_def pd ON pv1.param_def_id = pd.param_def_id
                WHERE pd.param_key = :key
                  AND pd.country_code = :country
                  AND pv1.effective_from <= pv2.effective_to
                  AND pv2.effective_from <= pv1.effective_to";

        $overlaps = [];
        CONN::dml($sql, [
            ':key' => $key,
            ':country' => $this->countryCode
        ], function($row) use (&$overlaps) {
            $overlaps[] = [
                'range1' => [$row['from1'], $row['to1']],
                'range2' => [$row['from2'], $row['to2']],
                'scope_entity_id' => $row['scope1'] ?: null
            ];
        });

        if (empty($overlaps)) {
            return ['valid' => true];
        }

        return [
            'valid' => false,
            'error' => self::ERR_OVERLAP_DETECTED,
            'overlaps' => $overlaps
        ];
    }

    private function loadParam(string $key, string $date): ?array
    {
        # First try exact match for date
        $sql = "SELECT
                    pd.param_key,
                    pd.data_type,
                    pd.precision_digits,
                    pd.allows_fallback,
                    pd.proration_strategy,
                    pv.value_json,
                    pv.effective_from,
                    pv.effective_to,
                    pv.version_hash,
                    pv.source_reference
                FROM pay_param_def pd
                JOIN pay_param_value pv ON pd.param_def_id = pv.param_def_id
                WHERE pd.param_key = :key
                  AND pd.country_code = :country
                  AND pd.is_active = 1
                  AND :date BETWEEN pv.effective_from AND pv.effective_to
                  AND (pv.scope_entity_id IS NULL OR pv.scope_entity_id = :scope)
                ORDER BY pv.scope_entity_id DESC
                LIMIT 1";

        $result = null;
        CONN::dml($sql, [
            ':key' => $key,
            ':country' => $this->countryCode,
            ':date' => $date,
            ':scope' => $this->scopeEntityId
        ], function($row) use (&$result) {
            $result = $this->parseParamRow($row);
            return false;
        });

        if ($result !== null) {
            return $result;
        }

        # Try fallback to last known value
        return $this->loadParamFallback($key, $date);
    }

    private function loadParamFallback(string $key, string $date): ?array
    {
        $sql = "SELECT
                    pd.param_key,
                    pd.data_type,
                    pd.precision_digits,
                    pd.allows_fallback,
                    pd.proration_strategy,
                    pv.value_json,
                    pv.effective_from,
                    pv.effective_to,
                    pv.version_hash,
                    pv.source_reference
                FROM pay_param_def pd
                JOIN pay_param_value pv ON pd.param_def_id = pv.param_def_id
                WHERE pd.param_key = :key
                  AND pd.country_code = :country
                  AND pd.is_active = 1
                  AND pd.allows_fallback = 1
                  AND pv.effective_from <= :date
                  AND (pv.scope_entity_id IS NULL OR pv.scope_entity_id = :scope)
                ORDER BY pv.effective_from DESC, pv.scope_entity_id DESC
                LIMIT 1";

        $result = null;
        CONN::dml($sql, [
            ':key' => $key,
            ':country' => $this->countryCode,
            ':date' => $date,
            ':scope' => $this->scopeEntityId
        ], function($row) use (&$result) {
            $result = $this->parseParamRow($row);
            $result['is_fallback'] = true;
            return false;
        });

        return $result;
    }

    private function loadParamBefore(string $key, string $date): ?array
    {
        $sql = "SELECT
                    pd.param_key,
                    pd.data_type,
                    pd.precision_digits,
                    pd.allows_fallback,
                    pd.proration_strategy,
                    pv.value_json,
                    pv.effective_from,
                    pv.effective_to,
                    pv.version_hash,
                    pv.source_reference
                FROM pay_param_def pd
                JOIN pay_param_value pv ON pd.param_def_id = pv.param_def_id
                WHERE pd.param_key = :key
                  AND pd.country_code = :country
                  AND pd.is_active = 1
                  AND pv.effective_to < :date
                  AND (pv.scope_entity_id IS NULL OR pv.scope_entity_id = :scope)
                ORDER BY pv.effective_to DESC
                LIMIT 1";

        $result = null;
        CONN::dml($sql, [
            ':key' => $key,
            ':country' => $this->countryCode,
            ':date' => $date,
            ':scope' => $this->scopeEntityId
        ], function($row) use (&$result) {
            $result = $this->parseParamRow($row);
            return false;
        });

        return $result;
    }

    private function loadEmployeeParam(string $key, int $employeeId, string $date): ?array
    {
        $sql = "SELECT
                    pd.param_key,
                    pd.data_type,
                    pd.precision_digits,
                    pd.allows_fallback,
                    pd.proration_strategy,
                    pve.value_json,
                    pve.effective_from,
                    pve.effective_to,
                    pve.version_hash,
                    pve.source_reference
                FROM pay_param_def pd
                JOIN pay_param_value_employee pve ON pd.param_def_id = pve.param_def_id
                WHERE pd.param_key = :key
                  AND pd.country_code = :country
                  AND pd.is_active = 1
                  AND pve.employee_id = :emp
                  AND :date BETWEEN pve.effective_from AND pve.effective_to
                ORDER BY pve.effective_from DESC
                LIMIT 1";

        $result = null;
        CONN::dml($sql, [
            ':key' => $key,
            ':country' => $this->countryCode,
            ':emp' => $employeeId,
            ':date' => $date
        ], function($row) use ($employeeId, &$result) {
            $result = $this->parseParamRow($row);
            $result['employee_id'] = $employeeId;
            return false;
        });

        return $result;
    }

    private function parseParamRow(array $row): array
    {
        $valueJson = $row['value_json'];
        $decoded = json_decode($valueJson, true);
        $value = is_array($decoded) ? (string)($decoded['value'] ?? $decoded[0] ?? $valueJson) : (string)$decoded;

        return [
            'key' => $row['param_key'],
            'value' => $value,
            'data_type' => $row['data_type'],
            'precision' => (int)$row['precision_digits'],
            'allows_fallback' => (bool)$row['allows_fallback'],
            'proration_strategy' => $row['proration_strategy'],
            'effective_from' => $row['effective_from'],
            'effective_to' => $row['effective_to'],
            'version_hash' => $row['version_hash'],
            'source_reference' => $row['source_reference'],
            'is_fallback' => false
        ];
    }

    private function recordAccess(
        string $key,
        string $date,
        string $scope,
        array $result,
        ?int $employeeId = null
    ): void {
        $this->accessed[$key] = [
            'value' => $result['value'],
            'date' => $date,
            'scope' => $scope,
            'employee_id' => $employeeId,
            'version_hash' => $result['version_hash'] ?? null,
            'effective_from' => $result['effective_from'] ?? null,
            'is_fallback' => $result['is_fallback'] ?? false
        ];
    }

    /**
     * Prorate parameter value for mid-period change (DAILY strategy)
     *
     * The changeDate is the FIRST DAY where the new value is effective.
     * Example: period Jan 1-31, change Jan 15 → daysBefore=14 (Jan 1-14), daysAfter=17 (Jan 15-31)
     */
    private function prorateDailyMidPeriod(
        string $key,
        array $prevMeta,
        array $newMeta,
        string $periodStart,
        string $periodEnd,
        string $changeDate,
        int $totalDays
    ): array {
        $start = new \DateTime($periodStart);
        $end = new \DateTime($periodEnd);
        $change = new \DateTime($changeDate);

        # Calculate total days (inclusive)
        if ($totalDays <= 0) {
            $totalDays = (int)$end->diff($start)->days + 1;
        }

        # Days BEFORE change (old value applies): periodStart to changeDate-1
        # The diff gives days between dates (exclusive of end), which is correct
        $daysBefore = (int)$change->diff($start)->days;

        # Days AFTER change (new value applies): changeDate to periodEnd (inclusive)
        $daysAfter = $totalDays - $daysBefore;

        # Validate: sum must equal total
        if ($daysBefore + $daysAfter !== $totalDays) {
            # Edge case: changeDate might be outside period bounds
            if ($change <= $start) {
                $daysBefore = 0;
                $daysAfter = $totalDays;
            } elseif ($change > $end) {
                $daysBefore = $totalDays;
                $daysAfter = 0;
            }
        }

        $prevValue = $prevMeta['value'];
        $newValue = $newMeta['value'];

        # Calculate prorated amounts with high precision
        $scale = 10;
        $proratedPrev = Math::mul(
            Math::div($prevValue, (string)$totalDays, $scale),
            (string)$daysBefore,
            $scale
        );

        $proratedNew = Math::mul(
            Math::div($newValue, (string)$totalDays, $scale),
            (string)$daysAfter,
            $scale
        );

        $totalProrated = Math::add($proratedPrev, $proratedNew, $scale);

        return [
            'success' => true,
            'value' => $totalProrated,
            'prorated' => true,
            'proration_type' => self::PRORATE_DAILY,
            'change_date' => $changeDate,
            'total_days' => $totalDays,
            'days_before' => $daysBefore,
            'days_after' => $daysAfter,
            'value_before' => $prevValue,
            'value_after' => $newValue,
            'explain' => "{$key}: ({$prevValue} × {$daysBefore}/{$totalDays}) + ({$newValue} × {$daysAfter}/{$totalDays}) = {$totalProrated}",
            'meta_before' => [
                'effective_from' => $prevMeta['effective_from'],
                'version_hash' => $prevMeta['version_hash']
            ],
            'meta_after' => [
                'effective_from' => $newMeta['effective_from'],
                'version_hash' => $newMeta['version_hash']
            ]
        ];
    }

    # =========================================================================
    # STATIC FACTORY
    # =========================================================================

    /**
     * Create a ParamStore for a country and optional scope
     */
    public static function forCountry(
        string $countryCode,
        ?int $scopeEntityId = null,
        ?string $date = null
    ): self {
        return new self($countryCode, $scopeEntityId, $date);
    }

    /**
     * Create snapshot of all params for a run (for determinism)
     */
    public static function createSnapshot(
        string $countryCode,
        ?int $scopeEntityId,
        string $date
    ): array {
        $store = new self($countryCode, $scopeEntityId, $date);
        $store->preloadForDate($date);

        $snapshot = [];
        foreach ($store->cache as $cacheKey => $data) {
            $snapshot[$data['key']] = [
                'value' => $data['value'],
                'effective_from' => $data['effective_from'],
                'version_hash' => $data['version_hash']
            ];
        }

        return [
            'country_code' => $countryCode,
            'scope_entity_id' => $scopeEntityId,
            'date' => $date,
            'params' => $snapshot,
            'snapshot_hash' => hash('sha256', json_encode($snapshot))
        ];
    }
}
