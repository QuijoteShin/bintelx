<?php
# bintelx/kernel/AllocationService.php
# Retroactive Allocation Service for Variable Income Reliquidation
# Chile: Reliquidación de rentas variables (bonos, comisiones retroactivas)
#
# @version 1.0.0

namespace bX;

class AllocationService
{
    public const VERSION = '1.0.0';

    # Allocation strategies
    public const STRATEGY_EQUAL = 'EQUAL';           # Distribute equally across periods
    public const STRATEGY_WEIGHTED = 'WEIGHTED';     # Distribute by worked days
    public const STRATEGY_ORIGIN = 'ORIGIN';         # Assign to origin period(s)

    # Reasons
    public const REASON_VARIABLE_INCOME = 'VARIABLE_INCOME';
    public const REASON_BONUS = 'BONUS';
    public const REASON_COMMISSION = 'COMMISSION';
    public const REASON_OVERTIME = 'OVERTIME';
    public const REASON_ADJUSTMENT = 'ADJUSTMENT';

    /**
     * Allocate a variable income payment to its origin period(s)
     *
     * Chile example:
     *   - Bono de Marzo que devengó Enero-Febrero
     *   - Comisiones pagadas en Mayo por ventas de Marzo-Abril
     *
     * @param string $amount Total amount being paid
     * @param string $paymentPeriod Payment period (YYYY-MM)
     * @param array $accrualPeriods Origin periods with optional weights
     *              ['2025-01' => 0.5, '2025-02' => 0.5] or ['2025-01', '2025-02']
     * @param string $strategy Allocation strategy
     * @return array Allocation breakdown
     */
    public static function allocate(
        string $amount,
        string $paymentPeriod,
        array $accrualPeriods,
        string $strategy = self::STRATEGY_EQUAL
    ): array {
        if (empty($accrualPeriods) || Math::comp($amount, '0') <= 0) {
            return [
                'success' => false,
                'error' => 'NO_PERIODS_OR_ZERO_AMOUNT',
                'allocations' => []
            ];
        }

        # Normalize periods to associative array with weights
        $periods = self::normalizePeriods($accrualPeriods, $strategy);

        # Calculate allocations
        $allocations = [];
        $remaining = $amount;
        $count = count($periods);
        $i = 0;

        foreach ($periods as $period => $weight) {
            $i++;
            if ($i === $count) {
                # Last period gets remainder (avoid rounding issues)
                $allocated = $remaining;
            } else {
                $allocated = Math::mul($amount, (string)$weight, 2);
                $remaining = Math::sub($remaining, $allocated, 2);
            }

            $allocations[] = [
                'accrual_period' => $period,
                'payment_period' => $paymentPeriod,
                'amount' => $allocated,
                'weight' => $weight,
                'year' => (int)substr($period, 0, 4),
                'month' => (int)substr($period, 5, 2)
            ];
        }

        return [
            'success' => true,
            'original_amount' => $amount,
            'payment_period' => $paymentPeriod,
            'strategy' => $strategy,
            'allocations' => $allocations
        ];
    }

    /**
     * Recalculate tax with allocated income (Chile reliquidación)
     *
     * When variable income is allocated back to origin periods,
     * we need to recalculate the tax for each period and determine
     * the difference.
     *
     * @param array $allocations From allocate()
     * @param int $employeeId Employee ID
     * @param callable $taxCalculator Function to calculate tax for a period
     *                 fn(int $empId, string $period, string $additionalIncome): array
     * @return array Tax recalculation results
     */
    public static function recalculateTax(
        array $allocations,
        int $employeeId,
        callable $taxCalculator
    ): array {
        $results = [];
        $totalOriginalTax = '0';
        $totalRecalculatedTax = '0';

        foreach ($allocations as $alloc) {
            $period = $alloc['accrual_period'];
            $additionalIncome = $alloc['amount'];

            # Calculate tax with additional income
            $taxResult = $taxCalculator($employeeId, $period, $additionalIncome);

            $originalTax = $taxResult['original_tax'] ?? '0';
            $recalculatedTax = $taxResult['recalculated_tax'] ?? '0';
            $difference = Math::sub($recalculatedTax, $originalTax, 2);

            $results[] = [
                'period' => $period,
                'allocated_amount' => $additionalIncome,
                'original_tax' => $originalTax,
                'recalculated_tax' => $recalculatedTax,
                'tax_difference' => $difference,
                'utm_used' => $taxResult['utm_used'] ?? null
            ];

            $totalOriginalTax = Math::add($totalOriginalTax, $originalTax, 2);
            $totalRecalculatedTax = Math::add($totalRecalculatedTax, $recalculatedTax, 2);
        }

        $totalDifference = Math::sub($totalRecalculatedTax, $totalOriginalTax, 2);

        return [
            'success' => true,
            'employee_id' => $employeeId,
            'periods' => $results,
            'total_original_tax' => $totalOriginalTax,
            'total_recalculated_tax' => $totalRecalculatedTax,
            'total_difference' => $totalDifference,
            'requires_additional_tax' => Math::comp($totalDifference, '0') > 0,
            'refund_due' => Math::comp($totalDifference, '0') < 0
                ? Math::negate($totalDifference)
                : '0'
        ];
    }

    /**
     * Create allocation records for database persistence
     *
     * @param array $allocateResult Result from allocate()
     * @param int $runId Payroll run ID
     * @param int $employeeId Employee ID
     * @param string $conceptCode Concept being allocated
     * @param string $reason Allocation reason
     * @return array Records to insert into pay_retroactive_allocation
     */
    public static function createAllocationRecords(
        array $allocateResult,
        int $runId,
        int $employeeId,
        string $conceptCode,
        string $reason = self::REASON_VARIABLE_INCOME
    ): array {
        if (!$allocateResult['success']) {
            return [];
        }

        $records = [];
        $paymentPeriod = $allocateResult['payment_period'];
        $payYear = (int)substr($paymentPeriod, 0, 4);
        $payMonth = (int)substr($paymentPeriod, 5, 2);

        foreach ($allocateResult['allocations'] as $alloc) {
            $records[] = [
                'run_id' => $runId,
                'employee_id' => $employeeId,
                'concept_code' => $conceptCode,
                'original_amount' => $allocateResult['original_amount'],
                'allocated_amount' => $alloc['amount'],
                'payment_period_year' => $payYear,
                'payment_period_month' => $payMonth,
                'accrual_period_year' => $alloc['year'],
                'accrual_period_month' => $alloc['month'],
                'requires_tax_recalc' => 1,
                'tax_recalc_done' => 0,
                'allocation_reason' => $reason
            ];
        }

        return $records;
    }

    /**
     * Get pending allocations that need tax recalculation
     *
     * @param int $employeeId
     * @return array
     */
    public static function getPendingRecalculations(int $employeeId): array
    {
        $sql = "
            SELECT *
            FROM pay_retroactive_allocation
            WHERE employee_id = :emp_id
              AND requires_tax_recalc = 1
              AND tax_recalc_done = 0
            ORDER BY accrual_period_year, accrual_period_month
        ";

        return CONN::dml($sql, [':emp_id' => $employeeId]);
    }

    /**
     * Mark allocations as recalculated
     *
     * @param array $allocationIds
     * @param array $taxResults Tax recalculation results
     * @return bool
     */
    public static function markRecalculated(array $allocationIds, array $taxResults): bool
    {
        if (empty($allocationIds)) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($allocationIds), '?'));

        $sql = "
            UPDATE pay_retroactive_allocation
            SET tax_recalc_done = 1
            WHERE allocation_id IN ($placeholders)
        ";

        try {
            CONN::dml($sql, $allocationIds);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    # =========================================================================
    # HELPERS
    # =========================================================================

    /**
     * Normalize periods to weighted array
     */
    private static function normalizePeriods(array $periods, string $strategy): array
    {
        # If already associative with weights
        if (self::isAssociativeArray($periods)) {
            $total = array_sum($periods);
            if ($total > 0 && abs($total - 1.0) > 0.001) {
                # Normalize weights to sum to 1
                $normalized = [];
                foreach ($periods as $period => $weight) {
                    $normalized[$period] = $weight / $total;
                }
                return $normalized;
            }
            return $periods;
        }

        # Sequential array - apply strategy
        $count = count($periods);

        if ($strategy === self::STRATEGY_EQUAL || $count === 0) {
            $weight = 1.0 / $count;
            $result = [];
            foreach ($periods as $period) {
                $result[$period] = $weight;
            }
            return $result;
        }

        # For WEIGHTED and ORIGIN, caller should provide weights
        # Default to equal if not provided
        $weight = 1.0 / $count;
        $result = [];
        foreach ($periods as $period) {
            $result[$period] = $weight;
        }
        return $result;
    }

    /**
     * Check if array is associative
     */
    private static function isAssociativeArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Calculate periods between two dates
     *
     * @param string $fromPeriod YYYY-MM
     * @param string $toPeriod YYYY-MM
     * @return array List of periods
     */
    public static function getPeriodRange(string $fromPeriod, string $toPeriod): array
    {
        $periods = [];
        $current = new \DateTime($fromPeriod . '-01');
        $end = new \DateTime($toPeriod . '-01');

        while ($current <= $end) {
            $periods[] = $current->format('Y-m');
            $current->modify('+1 month');
        }

        return $periods;
    }

    /**
     * Create DSL functions for RulesEngine
     */
    public static function createDslFunctions(): array
    {
        return [
            'ALLOCATE_EQUAL' => function(string $amount, string $paymentPeriod, string $fromPeriod, string $toPeriod) {
                $periods = self::getPeriodRange($fromPeriod, $toPeriod);
                $result = self::allocate($amount, $paymentPeriod, $periods, self::STRATEGY_EQUAL);
                # Return just the amounts as JSON for further processing
                return json_encode($result['allocations'] ?? []);
            },

            'PERIOD_COUNT' => function(string $fromPeriod, string $toPeriod) {
                return (string)count(self::getPeriodRange($fromPeriod, $toPeriod));
            },
        ];
    }
}
