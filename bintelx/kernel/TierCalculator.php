<?php
# bintelx/kernel/TierCalculator.php
# Universal Tier/Bracket Calculator for Progressive Taxes and Fees
# Supports: MARGINAL (progressive), FLAT (volume), INTERPOLATED

namespace bX;

class TierCalculator {

    # Calculation modes
    public const MODE_MARGINAL = 'MARGINAL';       # Each tier applies to its range only (tax brackets)
    public const MODE_FLAT = 'FLAT';               # Entire amount uses rate of matching tier
    public const MODE_INTERPOLATED = 'INTERPOLATED'; # Linear interpolation between tiers

    # Tier definition structure
    # [
    #   ['from' => 0, 'to' => 1000, 'rate' => 0.075, 'deduction' => 0],
    #   ['from' => 1000, 'to' => 2000, 'rate' => 0.09, 'deduction' => 15],
    #   ...
    # ]

    /**
     * Calculate amount using tier/bracket system
     *
     * @param string $base Base amount (string for BCMath precision)
     * @param array $tiers Array of tier definitions
     * @param string $mode Calculation mode (MARGINAL, FLAT, INTERPOLATED)
     * @param int $precision Decimal precision for result
     * @return array ['amount' => calculated, 'effective_rate' => %, 'breakdown' => [...]]
     */
    public static function calculate(
        string $base,
        array $tiers,
        string $mode = self::MODE_MARGINAL,
        int $precision = 2
    ): array {
        if (empty($tiers)) {
            return [
                'amount' => '0',
                'effective_rate' => '0',
                'breakdown' => [],
                'error' => 'NO_TIERS_DEFINED'
            ];
        }

        # Normalize and sort tiers
        $tiers = self::normalizeTiers($tiers);

        return match ($mode) {
            self::MODE_MARGINAL => self::calculateMarginal($base, $tiers, $precision),
            self::MODE_FLAT => self::calculateFlat($base, $tiers, $precision),
            self::MODE_INTERPOLATED => self::calculateInterpolated($base, $tiers, $precision),
            default => ['amount' => '0', 'error' => 'UNKNOWN_MODE']
        };
    }

    /**
     * Progressive/Marginal calculation (tax brackets style)
     * Each tier's rate applies only to the portion within that tier
     *
     * Example: Base = 3000, Tiers: [0-1000 @ 7.5%, 1000-2000 @ 9%, 2000+ @ 12%]
     * Result: (1000 * 0.075) + (1000 * 0.09) + (1000 * 0.12) = 75 + 90 + 120 = 285
     */
    private static function calculateMarginal(string $base, array $tiers, int $precision): array {
        $total = '0';
        $breakdown = [];
        $remaining = $base;

        foreach ($tiers as $tier) {
            $from = $tier['from'];
            $to = $tier['to'];
            $rate = $tier['rate'];
            $deduction = $tier['deduction'] ?? '0';

            # Skip if base hasn't reached this tier
            if (Math::comp($base, $from) <= 0) {
                continue;
            }

            # Calculate taxable amount in this tier
            $tierBase = Math::sub(Math::min($base, $to), $from, $precision);

            if (Math::comp($tierBase, '0') <= 0) {
                continue;
            }

            # Apply rate to tier portion
            $tierAmount = Math::mul($tierBase, $rate, $precision);

            $breakdown[] = [
                'tier_from' => $from,
                'tier_to' => $to,
                'rate' => $rate,
                'base_in_tier' => $tierBase,
                'amount' => $tierAmount
            ];

            $total = Math::add($total, $tierAmount, $precision);
        }

        # Apply final deduction if last tier has one (Chilean/Brazilian style)
        $lastAppliedTier = end($breakdown);
        if ($lastAppliedTier && isset($tiers[array_key_last($tiers)]['deduction'])) {
            $finalDeduction = $tiers[array_key_last($tiers)]['deduction'] ?? '0';
            if (Math::comp($finalDeduction, '0') > 0) {
                $total = Math::sub($total, $finalDeduction, $precision);
                $total = Math::max($total, '0'); # Never negative
            }
        }

        $effectiveRate = Math::comp($base, '0') > 0
            ? Math::div($total, $base, 6)
            : '0';

        return [
            'amount' => $total,
            'effective_rate' => $effectiveRate,
            'breakdown' => $breakdown
        ];
    }

    /**
     * Flat/Volume calculation
     * Entire amount uses the rate of the tier it falls into
     *
     * Example: Base = 3000, Tiers: [0-1000 @ 5%, 1000-5000 @ 3%, 5000+ @ 2%]
     * Result: 3000 * 0.03 = 90 (uses 3% because 3000 is in 1000-5000 range)
     */
    private static function calculateFlat(string $base, array $tiers, int $precision): array {
        $matchedTier = null;

        foreach ($tiers as $tier) {
            $from = $tier['from'];
            $to = $tier['to'];

            if (Math::comp($base, $from) >= 0 && Math::comp($base, $to) < 0) {
                $matchedTier = $tier;
                break;
            }
        }

        # If no tier matched, use the last one (for amounts exceeding all tiers)
        if (!$matchedTier && !empty($tiers)) {
            $lastTier = end($tiers);
            if (Math::comp($base, $lastTier['from']) >= 0) {
                $matchedTier = $lastTier;
            }
        }

        if (!$matchedTier) {
            return [
                'amount' => '0',
                'effective_rate' => '0',
                'breakdown' => [],
                'error' => 'NO_MATCHING_TIER'
            ];
        }

        $rate = $matchedTier['rate'];
        $amount = Math::mul($base, $rate, $precision);

        return [
            'amount' => $amount,
            'effective_rate' => $rate,
            'breakdown' => [[
                'tier_from' => $matchedTier['from'],
                'tier_to' => $matchedTier['to'],
                'rate' => $rate,
                'base_in_tier' => $base,
                'amount' => $amount
            ]]
        ];
    }

    /**
     * Interpolated calculation
     * Rate is linearly interpolated based on position within tier
     */
    private static function calculateInterpolated(string $base, array $tiers, int $precision): array {
        # Find surrounding tiers
        $lowerTier = null;
        $upperTier = null;

        foreach ($tiers as $i => $tier) {
            if (Math::comp($base, $tier['from']) >= 0) {
                $lowerTier = $tier;
                $upperTier = $tiers[$i + 1] ?? $tier;
            }
        }

        if (!$lowerTier) {
            return ['amount' => '0', 'effective_rate' => '0', 'breakdown' => []];
        }

        # If same tier (at boundaries or last tier), use flat rate
        if ($lowerTier === $upperTier) {
            return self::calculateFlat($base, $tiers, $precision);
        }

        # Linear interpolation of rate
        $range = Math::sub($upperTier['from'], $lowerTier['from'], $precision);
        $position = Math::sub($base, $lowerTier['from'], $precision);
        $rateDiff = Math::sub($upperTier['rate'], $lowerTier['rate'], 6);

        $interpolatedRate = Math::add(
            $lowerTier['rate'],
            Math::mul(Math::div($position, $range, 6), $rateDiff, 6),
            6
        );

        $amount = Math::mul($base, $interpolatedRate, $precision);

        return [
            'amount' => $amount,
            'effective_rate' => $interpolatedRate,
            'breakdown' => [[
                'interpolated' => true,
                'lower_tier' => $lowerTier,
                'upper_tier' => $upperTier,
                'rate' => $interpolatedRate,
                'amount' => $amount
            ]]
        ];
    }

    /**
     * Normalize tier definitions to consistent format
     */
    private static function normalizeTiers(array $tiers): array {
        $normalized = [];

        foreach ($tiers as $tier) {
            $normalized[] = [
                'from' => (string)($tier['from'] ?? $tier['min'] ?? '0'),
                'to' => (string)($tier['to'] ?? $tier['max'] ?? PHP_INT_MAX),
                'rate' => (string)($tier['rate'] ?? $tier['pct'] ?? '0'),
                'deduction' => (string)($tier['deduction'] ?? $tier['rebate'] ?? '0'),
            ];
        }

        # Sort by 'from' ascending
        usort($normalized, fn($a, $b) => Math::comp($a['from'], $b['from']));

        return $normalized;
    }

    # =========================================================================
    # COUNTRY-SPECIFIC PRESETS
    # =========================================================================

    /**
     * Chile: Impuesto Único 2ª Categoría
     * Progressive tax with UTM-based brackets
     *
     * @param string $baseTributable Base tributable (after deductions)
     * @param string $utm UTM value for the period
     * @return array Calculation result
     */
    public static function chileImpuestoUnico(string $baseTributable, string $utm): array {
        # Convert base to UTM
        $baseUtm = Math::div($baseTributable, $utm, 4);

        # Chile 2025 tax brackets (in UTM)
        $tiers = [
            ['from' => '0',     'to' => '13.5',  'rate' => '0',      'deduction' => '0'],
            ['from' => '13.5',  'to' => '30',    'rate' => '0.04',   'deduction' => '0.54'],      # 0.54 UTM
            ['from' => '30',    'to' => '50',    'rate' => '0.08',   'deduction' => '1.74'],      # 1.74 UTM
            ['from' => '50',    'to' => '70',    'rate' => '0.135',  'deduction' => '4.49'],      # 4.49 UTM
            ['from' => '70',    'to' => '90',    'rate' => '0.23',   'deduction' => '11.14'],     # 11.14 UTM
            ['from' => '90',    'to' => '120',   'rate' => '0.304',  'deduction' => '17.80'],     # 17.80 UTM
            ['from' => '120',   'to' => '310',   'rate' => '0.35',   'deduction' => '23.32'],     # 23.32 UTM
            ['from' => '310',   'to' => '999999','rate' => '0.40',   'deduction' => '38.82'],     # 38.82 UTM
        ];

        # Calculate in UTM
        $resultUtm = self::calculateChileanProgressive($baseUtm, $tiers);

        # Convert back to CLP
        $amountClp = Math::mul($resultUtm['amount'], $utm, 0);

        return [
            'amount' => $amountClp,
            'amount_utm' => $resultUtm['amount'],
            'effective_rate' => $resultUtm['effective_rate'],
            'base_utm' => $baseUtm,
            'utm_value' => $utm,
            'breakdown' => $resultUtm['breakdown']
        ];
    }

    /**
     * Chilean progressive calculation with deduction
     * Formula: (Base × Rate) - Deduction
     */
    private static function calculateChileanProgressive(string $baseUtm, array $tiers): array {
        # Find applicable tier
        $applicableTier = null;
        foreach ($tiers as $tier) {
            if (Math::comp($baseUtm, $tier['from']) > 0 && Math::comp($baseUtm, $tier['to']) <= 0) {
                $applicableTier = $tier;
                break;
            }
        }

        # Handle amounts above last tier
        if (!$applicableTier && Math::comp($baseUtm, '310') > 0) {
            $applicableTier = end($tiers);
        }

        if (!$applicableTier || $applicableTier['rate'] === '0') {
            return ['amount' => '0', 'effective_rate' => '0', 'breakdown' => ['exempt' => true]];
        }

        # Chilean formula: (Base × Rate) - Deduction
        $grossTax = Math::mul($baseUtm, $applicableTier['rate'], 4);
        $netTax = Math::sub($grossTax, $applicableTier['deduction'], 4);
        $netTax = Math::max($netTax, '0');

        $effectiveRate = Math::comp($baseUtm, '0') > 0
            ? Math::div($netTax, $baseUtm, 6)
            : '0';

        return [
            'amount' => $netTax,
            'effective_rate' => $effectiveRate,
            'breakdown' => [
                'tier' => $applicableTier,
                'gross_tax' => $grossTax,
                'deduction' => $applicableTier['deduction'],
                'net_tax' => $netTax
            ]
        ];
    }

    /**
     * Brazil: INSS Progressivo (desde 2020)
     * True marginal/progressive calculation
     *
     * @param string $baseInss Base de cálculo INSS
     * @return array Calculation result
     */
    public static function brazilInssProgressivo(string $baseInss): array {
        # Brazil 2025 INSS brackets (progressive/marginal)
        $tiers = [
            ['from' => '0',       'to' => '1518.00',   'rate' => '0.075'],  # 7.5%
            ['from' => '1518.00', 'to' => '2793.88',   'rate' => '0.09'],   # 9%
            ['from' => '2793.88', 'to' => '4190.83',   'rate' => '0.12'],   # 12%
            ['from' => '4190.83', 'to' => '8157.41',   'rate' => '0.14'],   # 14%
        ];

        # Cap at teto
        $tetoInss = '8157.41';
        $baseCalculo = Math::min($baseInss, $tetoInss);

        return self::calculate($baseCalculo, $tiers, self::MODE_MARGINAL, 2);
    }

    /**
     * Brazil: IRRF Progressivo
     * Uses deduction method (similar to Chile but different brackets)
     *
     * @param string $baseIrrf Base de cálculo IRRF (já deduzido INSS e dependentes)
     * @return array Calculation result
     */
    public static function brazilIrrfProgressivo(string $baseIrrf): array {
        # Brazil 2025 IRRF brackets with deduction
        $tiers = [
            ['from' => '0',       'to' => '2259.20',  'rate' => '0',      'deduction' => '0'],
            ['from' => '2259.20', 'to' => '2826.65',  'rate' => '0.075',  'deduction' => '169.44'],
            ['from' => '2826.65', 'to' => '3751.05',  'rate' => '0.15',   'deduction' => '381.44'],
            ['from' => '3751.05', 'to' => '4664.68',  'rate' => '0.225',  'deduction' => '662.77'],
            ['from' => '4664.68', 'to' => '999999',   'rate' => '0.275',  'deduction' => '896.00'],
        ];

        return self::calculateBrazilianProgressive($baseIrrf, $tiers);
    }

    /**
     * Brazilian progressive calculation with deduction
     * Formula: (Base × Rate) - Deduction (same as Chilean mathematically)
     */
    private static function calculateBrazilianProgressive(string $base, array $tiers): array {
        # Find applicable tier
        $applicableTier = null;
        foreach ($tiers as $tier) {
            if (Math::comp($base, $tier['from']) > 0 && Math::comp($base, $tier['to']) <= 0) {
                $applicableTier = $tier;
                break;
            }
        }

        # Handle amounts above last tier
        if (!$applicableTier && Math::comp($base, '4664.68') > 0) {
            $applicableTier = end($tiers);
        }

        if (!$applicableTier || $applicableTier['rate'] === '0') {
            return ['amount' => '0', 'effective_rate' => '0', 'breakdown' => ['exempt' => true]];
        }

        # Brazilian formula: (Base × Rate) - Deduction
        $grossTax = Math::mul($base, $applicableTier['rate'], 2);
        $netTax = Math::sub($grossTax, $applicableTier['deduction'], 2);
        $netTax = Math::max($netTax, '0');

        $effectiveRate = Math::comp($base, '0') > 0
            ? Math::div($netTax, $base, 6)
            : '0';

        return [
            'amount' => $netTax,
            'effective_rate' => $effectiveRate,
            'breakdown' => [
                'tier' => $applicableTier,
                'gross_tax' => $grossTax,
                'deduction' => $applicableTier['deduction'],
                'net_tax' => $netTax
            ]
        ];
    }

    # =========================================================================
    # FEE ENGINE INTEGRATION
    # =========================================================================

    /**
     * Calculate tiered fee (for FeeEngine integration)
     * Supports both volume-based and progressive fees
     *
     * @param string $volume Transaction volume or amount
     * @param array $feeConfig Fee configuration from pay_fee_policy
     * @return array Calculation result
     */
    public static function calculateFee(string $volume, array $feeConfig): array {
        $mode = $feeConfig['tier_mode'] ?? self::MODE_FLAT;
        $tiers = $feeConfig['tiers'] ?? [];

        return self::calculate($volume, $tiers, $mode, 4);
    }
}
