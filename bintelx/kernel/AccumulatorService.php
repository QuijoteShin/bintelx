<?php
# bintelx/kernel/AccumulatorService.php
# Period-Based Value Accumulation Service
# Calculates MTD, YTD, R12M, and custom period accumulations
#
# Features:
#   - Multiple accumulation types: SUM, COUNT, AVG, MIN, MAX
#   - Period types: MTD, YTD, R12M (Rolling 12 Months), CUSTOM
#   - Provider pattern for data sources (DB, In-Memory)
#   - Caching for performance
#   - Period boundary calculations
#   - Multi-concept accumulation
#
# @version 1.2.1 - Fixed C4: avgLastMonths divides by requested months, not found months
# @version 1.2.0 - Added avgLastMonths() for finiquito calculations
#                - Added avgLastMonthsSeveranceBase() for is_severance_base filtering

namespace bX;

require_once __DIR__ . '/Math.php';

# ============================================================================
# ACCUMULATION PROVIDER INTERFACE
# ============================================================================

interface AccumulationProvider
{
    /**
     * Get values for accumulation
     *
     * @param string $concept Concept key (e.g., 'gross_salary', 'tax_paid')
     * @param string $fromDate Start date (Y-m-d)
     * @param string $toDate End date (Y-m-d)
     * @param array $scope Scope context ['entity_id' => int, 'employee_id' => int]
     * @return array List of ['value' => string, 'date' => string, 'period' => string]
     */
    public function getValues(string $concept, string $fromDate, string $toDate, array $scope): array;

    /**
     * Provider name for audit
     */
    public function getName(): string;
}

# ============================================================================
# ACCUMULATOR SERVICE
# ============================================================================

class AccumulatorService
{
    public const VERSION = '1.2.1';

    # Period types
    public const PERIOD_MTD = 'MTD';     # Month-to-Date
    public const PERIOD_YTD = 'YTD';     # Year-to-Date
    public const PERIOD_R12M = 'R12M';   # Rolling 12 Months
    public const PERIOD_QTD = 'QTD';     # Quarter-to-Date
    public const PERIOD_CUSTOM = 'CUSTOM';

    # Accumulation types
    public const TYPE_SUM = 'SUM';
    public const TYPE_COUNT = 'COUNT';
    public const TYPE_AVG = 'AVG';
    public const TYPE_MIN = 'MIN';
    public const TYPE_MAX = 'MAX';
    public const TYPE_FIRST = 'FIRST';
    public const TYPE_LAST = 'LAST';

    # Instance state
    private array $providers = [];
    private array $defaultScope = [];
    private string $referenceDate;
    private array $cache = [];
    private bool $cacheEnabled = true;
    private int $precision = 8;

    public function __construct(?string $referenceDate = null)
    {
        $this->referenceDate = $referenceDate ?? date('Y-m-d');
    }

    /**
     * Register a provider
     */
    public function addProvider(AccumulationProvider $provider): self
    {
        $this->providers[] = $provider;
        return $this;
    }

    /**
     * Set default scope
     */
    public function setDefaultScope(array $scope): self
    {
        $this->defaultScope = $scope;
        return $this;
    }

    /**
     * Set reference date for period calculations
     */
    public function setReferenceDate(string $date): self
    {
        $this->referenceDate = $date;
        $this->cache = []; # Clear cache on date change
        return $this;
    }

    /**
     * Set calculation precision
     */
    public function setPrecision(int $precision): self
    {
        $this->precision = $precision;
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

    # ========================================================================
    # MAIN API
    # ========================================================================

    /**
     * Get MTD (Month-to-Date) accumulation
     *
     * @param string $concept Concept key
     * @param string $type Accumulation type (SUM, COUNT, etc.)
     * @param array $scope Override scope
     * @return array ['value' => string, 'period' => array, 'count' => int, ...]
     */
    public function mtd(string $concept, string $type = self::TYPE_SUM, array $scope = []): array
    {
        return $this->accumulate($concept, self::PERIOD_MTD, $type, $scope);
    }

    /**
     * Get YTD (Year-to-Date) accumulation
     */
    public function ytd(string $concept, string $type = self::TYPE_SUM, array $scope = []): array
    {
        return $this->accumulate($concept, self::PERIOD_YTD, $type, $scope);
    }

    /**
     * Get QTD (Quarter-to-Date) accumulation
     */
    public function qtd(string $concept, string $type = self::TYPE_SUM, array $scope = []): array
    {
        return $this->accumulate($concept, self::PERIOD_QTD, $type, $scope);
    }

    /**
     * Get R12M (Rolling 12 Months) accumulation
     */
    public function r12m(string $concept, string $type = self::TYPE_SUM, array $scope = []): array
    {
        return $this->accumulate($concept, self::PERIOD_R12M, $type, $scope);
    }

    /**
     * Get average of last N months (for finiquito calculations)
     * Chile: Indemnización = promedio últimos 3 meses con tope 90 UF
     *
     * @param string $concept Concept key (e.g., 'total_imponible')
     * @param int $months Number of months to average (default 3)
     * @param array $scope Override scope
     * @param string|null $capUF Optional UF cap (e.g., '90' for Chile indemnización)
     * @param string|null $ufValue UF value for cap calculation
     * @return array ['value' => avg, 'months' => [...], 'capped' => bool, ...]
     */
    public function avgLastMonths(
        string $concept,
        int $months = 3,
        array $scope = [],
        ?string $capUF = null,
        ?string $ufValue = null
    ): array {
        # Calculate date range (last N complete months before reference date)
        $refDate = new \DateTime($this->referenceDate);
        $refDate->modify('first day of this month');
        $toDate = (clone $refDate)->modify('-1 day'); # End of previous month
        $fromDate = (clone $refDate)->modify("-{$months} months"); # Start of N months ago

        $period = [
            'type' => 'LAST_N_MONTHS',
            'from' => $fromDate->format('Y-m-d'),
            'to' => $toDate->format('Y-m-d'),
            'months' => $months,
        ];

        # Get values per month
        $mergedScope = array_merge($this->defaultScope, $scope);
        $monthlyValues = [];
        $allValues = [];

        foreach ($this->providers as $provider) {
            $values = $provider->getValues(
                $concept,
                $period['from'],
                $period['to'],
                $mergedScope
            );
            foreach ($values as $v) {
                $allValues[] = $v;
                $monthKey = substr($v['period'] ?? $v['date'], 0, 7); # YYYY-MM
                if (!isset($monthlyValues[$monthKey])) {
                    $monthlyValues[$monthKey] = '0';
                }
                $monthlyValues[$monthKey] = Math::add($monthlyValues[$monthKey], $v['value']);
            }
        }

        # FIX C4: Calculate average using REQUESTED months, not found months
        # Months without data count as 0 in the average (critical for finiquito)
        $monthCount = count($monthlyValues);
        $total = Math::sum(array_values($monthlyValues));
        # Divide by requested months, not found months (empty months = 0)
        $average = $months > 0
            ? Math::div($total, (string)$months, $this->precision)
            : '0';

        # Apply UF cap if specified
        $capped = false;
        $capValue = null;
        if ($capUF !== null && $ufValue !== null) {
            $capValue = Math::mul($capUF, $ufValue);
            if (Math::gt($average, $capValue)) {
                $average = $capValue;
                $capped = true;
            }
        }

        return [
            'success' => true,
            'concept' => $concept,
            'value' => Math::round($average, 0),
            'total' => $total,
            'months_found' => $monthCount,
            'months_requested' => $months,
            'monthly_values' => $monthlyValues,
            'period' => $period,
            'capped' => $capped,
            'cap_uf' => $capUF,
            'cap_value' => $capValue,
            'reference_date' => $this->referenceDate,
        ];
    }

    /**
     * Get average of last N months for SEVERANCE BASE concepts only
     * Chile: Indemnización = promedio últimos 3 meses de remuneración (is_severance_base=1)
     *
     * This method filters concepts by is_severance_base flag to ensure only
     * legally computable concepts are included in the average (H4 fix).
     *
     * @param int $months Number of months to average (default 3)
     * @param array $scope Override scope
     * @param string|null $capUF Optional UF cap (e.g., '90' for Chile)
     * @param string|null $ufValue UF value for cap calculation
     * @param array|null $conceptFilter Explicit list of concept_codes to include
     * @return array ['value' => avg, 'months' => [...], 'capped' => bool, 'concepts_included' => [...]]
     */
    public function avgLastMonthsSeveranceBase(
        int $months = 3,
        array $scope = [],
        ?string $capUF = null,
        ?string $ufValue = null,
        ?array $conceptFilter = null
    ): array {
        # If explicit filter provided, use it; otherwise query DB for is_severance_base=1
        $severanceConcepts = $conceptFilter;

        if ($severanceConcepts === null) {
            # Query concepts marked as severance base
            try {
                $sql = "SELECT concept_code FROM pay_concept WHERE is_severance_base = 1";
                $rows = CONN::dml($sql);
                $severanceConcepts = array_column($rows, 'concept_code');
            } catch (\Exception $e) {
                # Fallback to common Chile concepts if DB fails
                $severanceConcepts = [
                    'CL_E_SUELDO_BASE',
                    'CL_E_GRATIFICACION',
                    'CL_E_BONO_PRODUCCION',
                    'CL_E_COMISION',
                    'CL_E_HORA_EXTRA_50',
                    'CL_E_HORA_EXTRA_100',
                    'CL_E_SEMANA_CORRIDA'
                ];
            }
        }

        if (empty($severanceConcepts)) {
            return [
                'success' => false,
                'error' => 'NO_SEVERANCE_CONCEPTS_DEFINED',
                'value' => '0',
                'concepts_included' => []
            ];
        }

        # Calculate date range (last N complete months before reference date)
        $refDate = new \DateTime($this->referenceDate);
        $refDate->modify('first day of this month');
        $toDate = (clone $refDate)->modify('-1 day');
        $fromDate = (clone $refDate)->modify("-{$months} months");

        $period = [
            'type' => 'LAST_N_MONTHS_SEVERANCE',
            'from' => $fromDate->format('Y-m-d'),
            'to' => $toDate->format('Y-m-d'),
            'months' => $months,
        ];

        # Get values per month for severance concepts only
        $mergedScope = array_merge($this->defaultScope, $scope);
        $monthlyTotals = [];
        $conceptsFound = [];

        foreach ($this->providers as $provider) {
            foreach ($severanceConcepts as $concept) {
                $values = $provider->getValues(
                    $concept,
                    $period['from'],
                    $period['to'],
                    $mergedScope
                );

                foreach ($values as $v) {
                    $monthKey = substr($v['period'] ?? $v['date'], 0, 7);
                    if (!isset($monthlyTotals[$monthKey])) {
                        $monthlyTotals[$monthKey] = '0';
                    }
                    $monthlyTotals[$monthKey] = Math::add($monthlyTotals[$monthKey], $v['value']);

                    if (!in_array($concept, $conceptsFound)) {
                        $conceptsFound[] = $concept;
                    }
                }
            }
        }

        # FIX C4: Calculate average using REQUESTED months, not found months
        # Months without data count as 0 in the average (critical for finiquito)
        $monthCount = count($monthlyTotals);
        $total = Math::sum(array_values($monthlyTotals));
        # Divide by requested months, not found months (empty months = 0)
        $average = $months > 0
            ? Math::div($total, (string)$months, $this->precision)
            : '0';

        # Apply UF cap if specified (Chile 90 UF for indemnización)
        $capped = false;
        $capValue = null;
        if ($capUF !== null && $ufValue !== null) {
            $capValue = Math::mul($capUF, $ufValue);
            if (Math::gt($average, $capValue)) {
                $average = $capValue;
                $capped = true;
            }
        }

        return [
            'success' => true,
            'value' => Math::round($average, 0),
            'total' => $total,
            'months_found' => $monthCount,
            'months_requested' => $months,
            'monthly_values' => $monthlyTotals,
            'period' => $period,
            'capped' => $capped,
            'cap_uf' => $capUF,
            'cap_value' => $capValue,
            'concepts_included' => $conceptsFound,
            'concepts_requested' => $severanceConcepts,
            'reference_date' => $this->referenceDate,
        ];
    }

    /**
     * Get custom period accumulation
     *
     * @param string $concept Concept key
     * @param string $fromDate Start date (Y-m-d)
     * @param string $toDate End date (Y-m-d)
     * @param string $type Accumulation type
     * @param array $scope Override scope
     */
    public function custom(
        string $concept,
        string $fromDate,
        string $toDate,
        string $type = self::TYPE_SUM,
        array $scope = []
    ): array {
        $period = [
            'type' => self::PERIOD_CUSTOM,
            'from' => $fromDate,
            'to' => $toDate,
        ];
        return $this->accumulateForPeriod($concept, $period, $type, $scope);
    }

    /**
     * Get multiple accumulations at once
     *
     * @param array $requests [['concept' => 'x', 'period' => 'YTD', 'type' => 'SUM'], ...]
     * @param array $scope Override scope
     * @return array ['concept_YTD_SUM' => [...], ...]
     */
    public function many(array $requests, array $scope = []): array
    {
        $results = [];

        foreach ($requests as $request) {
            $concept = $request['concept'];
            $periodType = $request['period'] ?? self::PERIOD_YTD;
            $type = $request['type'] ?? self::TYPE_SUM;
            $key = "{$concept}_{$periodType}_{$type}";

            $results[$key] = $this->accumulate($concept, $periodType, $type, $scope);
        }

        return $results;
    }

    /**
     * Get accumulation value only (convenience method)
     */
    public function value(
        string $concept,
        string $periodType = self::PERIOD_YTD,
        string $type = self::TYPE_SUM,
        array $scope = []
    ): string {
        $result = $this->accumulate($concept, $periodType, $type, $scope);
        return $result['value'];
    }

    # ========================================================================
    # CORE ACCUMULATION LOGIC
    # ========================================================================

    /**
     * Main accumulation method
     */
    public function accumulate(
        string $concept,
        string $periodType,
        string $type = self::TYPE_SUM,
        array $scope = []
    ): array {
        $period = $this->calculatePeriod($periodType);
        return $this->accumulateForPeriod($concept, $period, $type, $scope);
    }

    /**
     * Accumulate for specific period
     */
    private function accumulateForPeriod(
        string $concept,
        array $period,
        string $type,
        array $scope
    ): array {
        $mergedScope = array_merge($this->defaultScope, $scope);
        $cacheKey = $this->buildCacheKey($concept, $period, $type, $mergedScope);

        # Check cache
        if ($this->cacheEnabled && isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        # Collect values from all providers
        $allValues = [];
        foreach ($this->providers as $provider) {
            $values = $provider->getValues(
                $concept,
                $period['from'],
                $period['to'],
                $mergedScope
            );
            foreach ($values as $v) {
                $allValues[] = array_merge($v, ['provider' => $provider->getName()]);
            }
        }

        # Sort by date
        usort($allValues, fn($a, $b) => strcmp($a['date'], $b['date']));

        # Calculate accumulation
        $accumulated = $this->calculateAccumulation($allValues, $type);

        $result = [
            'success' => true,
            'concept' => $concept,
            'value' => $accumulated,
            'period' => $period,
            'type' => $type,
            'scope' => $mergedScope,
            'count' => count($allValues),
            'reference_date' => $this->referenceDate,
        ];

        # Cache result
        if ($this->cacheEnabled) {
            $this->cache[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * Calculate accumulation based on type
     */
    private function calculateAccumulation(array $values, string $type): string
    {
        if (empty($values)) {
            return '0';
        }

        $numericValues = array_map(fn($v) => $v['value'] ?? '0', $values);

        switch ($type) {
            case self::TYPE_SUM:
                return Math::sum($numericValues, $this->precision);

            case self::TYPE_COUNT:
                return (string)count($values);

            case self::TYPE_AVG:
                $sum = Math::sum($numericValues, $this->precision);
                return Math::div($sum, (string)count($values), $this->precision);

            case self::TYPE_MIN:
                $min = $numericValues[0];
                foreach ($numericValues as $v) {
                    if (Math::lt($v, $min)) {
                        $min = $v;
                    }
                }
                return $min;

            case self::TYPE_MAX:
                $max = $numericValues[0];
                foreach ($numericValues as $v) {
                    if (Math::gt($v, $max)) {
                        $max = $v;
                    }
                }
                return $max;

            case self::TYPE_FIRST:
                return $numericValues[0];

            case self::TYPE_LAST:
                return end($numericValues);

            default:
                return Math::sum($numericValues, $this->precision);
        }
    }

    # ========================================================================
    # PERIOD CALCULATIONS
    # ========================================================================

    /**
     * Calculate period boundaries based on reference date
     */
    public function calculatePeriod(string $periodType): array
    {
        $refDate = new \DateTime($this->referenceDate);
        $year = $refDate->format('Y');
        $month = $refDate->format('m');

        switch ($periodType) {
            case self::PERIOD_MTD:
                return [
                    'type' => self::PERIOD_MTD,
                    'from' => "$year-$month-01",
                    'to' => $this->referenceDate,
                ];

            case self::PERIOD_YTD:
                return [
                    'type' => self::PERIOD_YTD,
                    'from' => "$year-01-01",
                    'to' => $this->referenceDate,
                ];

            case self::PERIOD_QTD:
                $quarter = ceil((int)$month / 3);
                $quarterStart = sprintf('%s-%02d-01', $year, ($quarter - 1) * 3 + 1);
                return [
                    'type' => self::PERIOD_QTD,
                    'from' => $quarterStart,
                    'to' => $this->referenceDate,
                ];

            case self::PERIOD_R12M:
                $fromDate = (clone $refDate)->modify('-12 months')->modify('+1 day');
                return [
                    'type' => self::PERIOD_R12M,
                    'from' => $fromDate->format('Y-m-d'),
                    'to' => $this->referenceDate,
                ];

            default:
                return [
                    'type' => $periodType,
                    'from' => "$year-01-01",
                    'to' => $this->referenceDate,
                ];
        }
    }

    /**
     * Get period for a specific month
     */
    public function getPeriodForMonth(int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        return [
            'type' => 'MONTH',
            'from' => $start,
            'to' => $end,
            'year' => $year,
            'month' => $month,
        ];
    }

    /**
     * Get all periods in a year
     */
    public function getPeriodsForYear(int $year): array
    {
        $periods = [];
        for ($m = 1; $m <= 12; $m++) {
            $periods[] = $this->getPeriodForMonth($year, $m);
        }
        return $periods;
    }

    # ========================================================================
    # HELPERS
    # ========================================================================

    /**
     * Build cache key
     */
    private function buildCacheKey(string $concept, array $period, string $type, array $scope): string
    {
        ksort($scope);
        $scopeStr = json_encode($scope);
        return md5("$concept|{$period['from']}|{$period['to']}|$type|$scopeStr");
    }

    /**
     * Create RulesEngine callbacks for accumulation functions
     *
     * @return array ['YTD' => callable, 'MTD' => callable, ...]
     */
    public function createDslFunctions(): array
    {
        return [
            'YTD' => fn(string $concept) => $this->value($concept, self::PERIOD_YTD),
            'MTD' => fn(string $concept) => $this->value($concept, self::PERIOD_MTD),
            'QTD' => fn(string $concept) => $this->value($concept, self::PERIOD_QTD),
            'R12M' => fn(string $concept) => $this->value($concept, self::PERIOD_R12M),
            'YTD_COUNT' => fn(string $concept) => $this->value($concept, self::PERIOD_YTD, self::TYPE_COUNT),
            'YTD_AVG' => fn(string $concept) => $this->value($concept, self::PERIOD_YTD, self::TYPE_AVG),
        ];
    }
}

# ============================================================================
# BUILT-IN PROVIDERS
# ============================================================================

/**
 * In-Memory Provider for testing
 */
class InMemoryAccumulationProvider implements AccumulationProvider
{
    private string $name;
    private array $values = [];

    public function __construct(string $name = 'memory')
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Add a value
     *
     * @param string $concept Concept key
     * @param string $value Value
     * @param string $date Date (Y-m-d)
     * @param string|null $period Period identifier (e.g., '2025-01')
     * @param array $scope Optional scope for filtering
     */
    public function addValue(
        string $concept,
        string $value,
        string $date,
        ?string $period = null,
        array $scope = []
    ): self {
        $this->values[] = [
            'concept' => $concept,
            'value' => $value,
            'date' => $date,
            'period' => $period ?? substr($date, 0, 7),
            'scope' => $scope,
        ];
        return $this;
    }

    /**
     * Add multiple values at once
     */
    public function addMany(array $values): self
    {
        foreach ($values as $v) {
            $this->addValue(
                $v['concept'],
                $v['value'],
                $v['date'],
                $v['period'] ?? null,
                $v['scope'] ?? []
            );
        }
        return $this;
    }

    public function getValues(string $concept, string $fromDate, string $toDate, array $scope): array
    {
        $results = [];

        foreach ($this->values as $entry) {
            # Check concept
            if ($entry['concept'] !== $concept) {
                continue;
            }

            # Check date range
            if ($entry['date'] < $fromDate || $entry['date'] > $toDate) {
                continue;
            }

            # Check scope
            if (!empty($entry['scope'])) {
                $match = true;
                foreach ($entry['scope'] as $k => $v) {
                    if (!isset($scope[$k]) || $scope[$k] != $v) {
                        $match = false;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
            }

            $results[] = [
                'value' => $entry['value'],
                'date' => $entry['date'],
                'period' => $entry['period'],
            ];
        }

        return $results;
    }

    /**
     * Clear all values
     */
    public function clear(): self
    {
        $this->values = [];
        return $this;
    }
}

/**
 * Database Provider - Reads from payroll_result or fee_ledger tables
 */
class DbAccumulationProvider implements AccumulationProvider
{
    private string $name;
    private string $tableName;
    private string $conceptColumn;
    private string $valueColumn;
    private string $dateColumn;
    private string $scopeColumn;

    public function __construct(
        string $name = 'db',
        string $tableName = 'payroll_result_lines',
        string $conceptColumn = 'concept_code',
        string $valueColumn = 'amount',
        string $dateColumn = 'period_date',
        string $scopeColumn = 'employee_id'
    ) {
        $this->name = $name;
        $this->tableName = $tableName;
        $this->conceptColumn = $conceptColumn;
        $this->valueColumn = $valueColumn;
        $this->dateColumn = $dateColumn;
        $this->scopeColumn = $scopeColumn;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValues(string $concept, string $fromDate, string $toDate, array $scope): array
    {
        $sql = "
            SELECT
                {$this->valueColumn} as value,
                {$this->dateColumn} as date,
                DATE_FORMAT({$this->dateColumn}, '%Y-%m') as period
            FROM {$this->tableName}
            WHERE {$this->conceptColumn} = :concept
              AND {$this->dateColumn} >= :from_date
              AND {$this->dateColumn} <= :to_date
        ";

        $params = [
            ':concept' => $concept,
            ':from_date' => $fromDate,
            ':to_date' => $toDate,
        ];

        # Add scope filter
        if (isset($scope['employee_id'])) {
            $sql .= " AND {$this->scopeColumn} = :scope_id";
            $params[':scope_id'] = $scope['employee_id'];
        }

        $sql .= " ORDER BY {$this->dateColumn} ASC";

        try {
            $rows = CONN::dml($sql, $params);
            return array_map(fn($row) => [
                'value' => (string)$row['value'],
                'date' => $row['date'],
                'period' => $row['period'],
            ], $rows);
        } catch (\Exception $e) {
            return [];
        }
    }
}

/**
 * Fee Ledger Provider - Reads from fee_ledger for financial accumulations
 */
class FeeLedgerAccumulationProvider implements AccumulationProvider
{
    private string $name;

    public function __construct(string $name = 'fee_ledger')
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValues(string $concept, string $fromDate, string $toDate, array $scope): array
    {
        # Concept format: "component:fee_type" or just "fee_type"
        $parts = explode(':', $concept);
        $feeType = $parts[0];
        $component = $parts[1] ?? null;

        $sql = "
            SELECT
                l.amount as value,
                DATE(l.created_at) as date,
                DATE_FORMAT(l.created_at, '%Y-%m') as period
            FROM fee_ledger l
            WHERE l.fee_type = :fee_type
              AND l.event_type = 'SETTLE'
              AND l.created_at >= :from_date
              AND l.created_at <= :to_date
        ";

        $params = [
            ':fee_type' => $feeType,
            ':from_date' => $fromDate . ' 00:00:00',
            ':to_date' => $toDate . ' 23:59:59',
        ];

        if ($component) {
            $sql .= " AND l.component = :component";
            $params[':component'] = $component;
        }

        if (isset($scope['entity_id'])) {
            $sql .= " AND l.source_entity_id = :entity_id";
            $params[':entity_id'] = $scope['entity_id'];
        }

        $sql .= " ORDER BY l.created_at ASC";

        try {
            $rows = CONN::dml($sql, $params);
            return array_map(fn($row) => [
                'value' => (string)$row['value'],
                'date' => $row['date'],
                'period' => $row['period'],
            ], $rows);
        } catch (\Exception $e) {
            return [];
        }
    }
}
