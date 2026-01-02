<?php
# bintelx/kernel/Math.php
# Centralized math wrapper for financial calculations (BCMath-based)
#
# Features:
#   - Safe operations (null handling, division by zero)
#   - Multiple rounding modes (HALF_UP, HALF_DOWN, BANKERS, FLOOR, CEIL)
#   - Configurable precision and internal scale
#   - Comparison helpers
#   - MIN/MAX with arbitrary arguments
#   - Percentage calculations
#   - Currency-aware formatting
#
# Usage:
#   Math::add('100.50', '50.25');           // '150.75'
#   Math::round('100.125', 2, 'HALF_UP');   // '100.13'
#   Math::div('100', '3', 4);               // '33.3333'
#   Math::isZero('0.00');                   // true
#
# @version 1.0.0

namespace bX;

class Math
{
    public const VERSION = '1.0.0';

    # Rounding modes
    public const ROUND_HALF_UP = 'HALF_UP';
    public const ROUND_HALF_DOWN = 'HALF_DOWN';
    public const ROUND_BANKERS = 'BANKERS';
    public const ROUND_FLOOR = 'FLOOR';
    public const ROUND_CEIL = 'CEIL';
    public const ROUND_TRUNCATE = 'TRUNCATE';

    # Default internal scale for intermediate calculations
    private const DEFAULT_INTERNAL_SCALE = 10;

    # Default output precision
    private const DEFAULT_PRECISION = 2;

    # =========================================================================
    # ARITHMETIC OPERATIONS
    # =========================================================================

    /**
     * Add two numbers
     */
    public static function add(
        ?string $a,
        ?string $b,
        ?int $scale = null
    ): string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        return bcadd(self::normalize($a), self::normalize($b), $scale);
    }

    /**
     * Subtract b from a
     */
    public static function sub(
        ?string $a,
        ?string $b,
        ?int $scale = null
    ): string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        return bcsub(self::normalize($a), self::normalize($b), $scale);
    }

    /**
     * Multiply two numbers
     */
    public static function mul(
        ?string $a,
        ?string $b,
        ?int $scale = null
    ): string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        return bcmul(self::normalize($a), self::normalize($b), $scale);
    }

    /**
     * Divide a by b (safe - returns null on division by zero)
     */
    public static function div(
        ?string $a,
        ?string $b,
        ?int $scale = null,
        bool $throwOnZero = false
    ): ?string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $bNorm = self::normalize($b);

        if (bccomp($bNorm, '0', $scale) === 0) {
            if ($throwOnZero) {
                throw new \InvalidArgumentException('Division by zero');
            }
            return null;
        }

        return bcdiv(self::normalize($a), $bNorm, $scale);
    }

    /**
     * Modulo operation
     */
    public static function mod(
        ?string $a,
        ?string $b,
        ?int $scale = null
    ): ?string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $bNorm = self::normalize($b);

        if (bccomp($bNorm, '0', $scale) === 0) {
            return null;
        }

        return bcmod(self::normalize($a), $bNorm, $scale);
    }

    /**
     * Power operation
     */
    public static function pow(
        ?string $base,
        int $exponent,
        ?int $scale = null
    ): string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        return bcpow(self::normalize($base), (string)$exponent, $scale);
    }

    /**
     * Square root
     */
    public static function sqrt(
        ?string $number,
        ?int $scale = null
    ): ?string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $norm = self::normalize($number);

        if (bccomp($norm, '0', $scale) < 0) {
            return null;
        }

        return bcsqrt($norm, $scale);
    }

    /**
     * Absolute value
     */
    public static function abs(?string $number, ?int $scale = null): string
    {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $norm = self::normalize($number);

        if (bccomp($norm, '0', $scale) < 0) {
            return bcmul($norm, '-1', $scale);
        }

        return $norm;
    }

    /**
     * Negate a number
     */
    public static function negate(?string $number, ?int $scale = null): string
    {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        return bcmul(self::normalize($number), '-1', $scale);
    }

    # =========================================================================
    # COMPARISON OPERATIONS
    # =========================================================================

    /**
     * Compare two numbers
     * Returns: -1 if a < b, 0 if a == b, 1 if a > b
     */
    public static function comp(
        ?string $a,
        ?string $b,
        ?int $scale = null
    ): int {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        return bccomp(self::normalize($a), self::normalize($b), $scale);
    }

    /**
     * Check if number is zero
     */
    public static function isZero(?string $number, ?int $scale = null): bool
    {
        return self::comp($number, '0', $scale) === 0;
    }

    /**
     * Check if number is positive (> 0)
     */
    public static function isPositive(?string $number, ?int $scale = null): bool
    {
        return self::comp($number, '0', $scale) > 0;
    }

    /**
     * Check if number is negative (< 0)
     */
    public static function isNegative(?string $number, ?int $scale = null): bool
    {
        return self::comp($number, '0', $scale) < 0;
    }

    /**
     * Check if a > b
     */
    public static function gt(?string $a, ?string $b, ?int $scale = null): bool
    {
        return self::comp($a, $b, $scale) > 0;
    }

    /**
     * Check if a >= b
     */
    public static function gte(?string $a, ?string $b, ?int $scale = null): bool
    {
        return self::comp($a, $b, $scale) >= 0;
    }

    /**
     * Check if a < b
     */
    public static function lt(?string $a, ?string $b, ?int $scale = null): bool
    {
        return self::comp($a, $b, $scale) < 0;
    }

    /**
     * Check if a <= b
     */
    public static function lte(?string $a, ?string $b, ?int $scale = null): bool
    {
        return self::comp($a, $b, $scale) <= 0;
    }

    /**
     * Check if a == b
     */
    public static function eq(?string $a, ?string $b, ?int $scale = null): bool
    {
        return self::comp($a, $b, $scale) === 0;
    }

    # =========================================================================
    # MIN/MAX OPERATIONS
    # =========================================================================

    /**
     * Get minimum value from arguments
     */
    public static function min(...$values): string
    {
        $values = array_filter($values, fn($v) => $v !== null);
        if (empty($values)) {
            return '0';
        }

        $min = self::normalize(array_shift($values));
        foreach ($values as $value) {
            $norm = self::normalize($value);
            if (bccomp($norm, $min, self::DEFAULT_INTERNAL_SCALE) < 0) {
                $min = $norm;
            }
        }

        return $min;
    }

    /**
     * Get maximum value from arguments
     */
    public static function max(...$values): string
    {
        $values = array_filter($values, fn($v) => $v !== null);
        if (empty($values)) {
            return '0';
        }

        $max = self::normalize(array_shift($values));
        foreach ($values as $value) {
            $norm = self::normalize($value);
            if (bccomp($norm, $max, self::DEFAULT_INTERNAL_SCALE) > 0) {
                $max = $norm;
            }
        }

        return $max;
    }

    /**
     * Clamp value between min and max
     */
    public static function clamp(
        ?string $value,
        ?string $min,
        ?string $max,
        ?int $scale = null
    ): string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $norm = self::normalize($value);

        if ($min !== null && bccomp($norm, self::normalize($min), $scale) < 0) {
            return self::normalize($min);
        }

        if ($max !== null && bccomp($norm, self::normalize($max), $scale) > 0) {
            return self::normalize($max);
        }

        return $norm;
    }

    # =========================================================================
    # ROUNDING OPERATIONS
    # =========================================================================

    /**
     * Round a number with specified precision and mode
     *
     * @param string|null $number Number to round
     * @param int $precision Decimal places (default: 2)
     * @param string $mode Rounding mode (default: HALF_UP)
     * @return string Rounded number
     */
    public static function round(
        ?string $number,
        int $precision = self::DEFAULT_PRECISION,
        string $mode = self::ROUND_HALF_UP
    ): string {
        $number = self::normalize($number);
        if ($precision < 0) {
            $precision = 0;
        }

        $isNegative = bccomp($number, '0', self::DEFAULT_INTERNAL_SCALE) < 0;
        $abs = $isNegative ? bcmul($number, '-1', self::DEFAULT_INTERNAL_SCALE) : $number;

        $factor = bcpow('10', (string)$precision);
        $scaled = bcmul($abs, $factor, self::DEFAULT_INTERNAL_SCALE);

        $intPart = self::truncateInternal($scaled);
        $fracPart = bcsub($scaled, $intPart, self::DEFAULT_INTERNAL_SCALE);

        switch ($mode) {
            case self::ROUND_HALF_UP:
                if (bccomp($fracPart, '0.5', self::DEFAULT_INTERNAL_SCALE) >= 0) {
                    $intPart = bcadd($intPart, '1', 0);
                }
                break;

            case self::ROUND_HALF_DOWN:
                if (bccomp($fracPart, '0.5', self::DEFAULT_INTERNAL_SCALE) > 0) {
                    $intPart = bcadd($intPart, '1', 0);
                }
                break;

            case self::ROUND_BANKERS:
                if (bccomp($fracPart, '0.5', self::DEFAULT_INTERNAL_SCALE) === 0) {
                    if (bcmod($intPart, '2') !== '0') {
                        $intPart = bcadd($intPart, '1', 0);
                    }
                } elseif (bccomp($fracPart, '0.5', self::DEFAULT_INTERNAL_SCALE) > 0) {
                    $intPart = bcadd($intPart, '1', 0);
                }
                break;

            case self::ROUND_CEIL:
                if (bccomp($fracPart, '0', self::DEFAULT_INTERNAL_SCALE) > 0) {
                    if (!$isNegative) {
                        $intPart = bcadd($intPart, '1', 0);
                    }
                }
                break;

            case self::ROUND_FLOOR:
                if (bccomp($fracPart, '0', self::DEFAULT_INTERNAL_SCALE) > 0) {
                    if ($isNegative) {
                        $intPart = bcadd($intPart, '1', 0);
                    }
                }
                break;

            case self::ROUND_TRUNCATE:
                break;

            default:
                if (bccomp($fracPart, '0.5', self::DEFAULT_INTERNAL_SCALE) >= 0) {
                    $intPart = bcadd($intPart, '1', 0);
                }
        }

        $result = bcdiv($intPart, $factor, $precision);

        if ($isNegative) {
            $result = bcmul($result, '-1', $precision);
        }

        return $result;
    }

    /**
     * Truncate toward zero (remove decimals)
     */
    public static function truncate(?string $number, int $precision = 0): string
    {
        $number = self::normalize($number);
        if ($precision < 0) {
            $precision = 0;
        }

        if ($precision === 0) {
            return bcadd($number, '0', 0);
        }

        $factor = bcpow('10', (string)$precision);
        $scaled = bcmul($number, $factor, self::DEFAULT_INTERNAL_SCALE);
        $truncated = bcadd($scaled, '0', 0);
        return bcdiv($truncated, $factor, $precision);
    }

    /**
     * Floor (round toward negative infinity)
     */
    public static function floor(?string $number, int $precision = 0): string
    {
        return self::round($number, $precision, self::ROUND_FLOOR);
    }

    /**
     * Ceil (round toward positive infinity)
     */
    public static function ceil(?string $number, int $precision = 0): string
    {
        return self::round($number, $precision, self::ROUND_CEIL);
    }

    # =========================================================================
    # PERCENTAGE OPERATIONS
    # =========================================================================

    /**
     * Calculate percentage: (value * rate / 100)
     */
    public static function percent(
        ?string $value,
        ?string $rate,
        ?int $scale = null
    ): string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $rateDecimal = bcdiv(self::normalize($rate), '100', $scale);
        return bcmul(self::normalize($value), $rateDecimal, $scale);
    }

    /**
     * Calculate percentage change: ((new - old) / old * 100)
     */
    public static function percentChange(
        ?string $oldValue,
        ?string $newValue,
        ?int $scale = null
    ): ?string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $old = self::normalize($oldValue);

        if (bccomp($old, '0', $scale) === 0) {
            return null;
        }

        $diff = bcsub(self::normalize($newValue), $old, $scale);
        $ratio = bcdiv($diff, $old, $scale);
        return bcmul($ratio, '100', $scale);
    }

    /**
     * Apply discount factor: value * (1 - factor)
     */
    public static function applyDiscount(
        ?string $value,
        ?string $factor,
        ?int $scale = null
    ): string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $factor = self::clamp($factor, '0', '1', $scale);
        $multiplier = bcsub('1', $factor, $scale);
        return bcmul(self::normalize($value), $multiplier, $scale);
    }

    /**
     * Calculate discount amount: value * factor
     */
    public static function discountAmount(
        ?string $value,
        ?string $factor,
        ?int $scale = null
    ): string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $factor = self::clamp($factor, '0', '1', $scale);
        return bcmul(self::normalize($value), $factor, $scale);
    }

    # =========================================================================
    # FINANCIAL HELPERS
    # =========================================================================

    /**
     * Calculate tax amount from net
     */
    public static function taxFromNet(
        ?string $net,
        ?string $taxRate,
        ?int $scale = null
    ): string {
        return self::percent($net, $taxRate, $scale);
    }

    /**
     * Calculate net from gross
     */
    public static function netFromGross(
        ?string $gross,
        ?string $taxRate,
        ?int $scale = null
    ): string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $divisor = bcadd('1', bcdiv(self::normalize($taxRate), '100', $scale), $scale);
        return self::div($gross, $divisor, $scale);
    }

    /**
     * Calculate gross from net
     */
    public static function grossFromNet(
        ?string $net,
        ?string $taxRate,
        ?int $scale = null
    ): string {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $multiplier = bcadd('1', bcdiv(self::normalize($taxRate), '100', $scale), $scale);
        return bcmul(self::normalize($net), $multiplier, $scale);
    }

    /**
     * Sum an array of values
     */
    public static function sum(array $values, ?int $scale = null): string
    {
        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $total = '0';

        foreach ($values as $value) {
            if ($value !== null) {
                $total = bcadd($total, self::normalize($value), $scale);
            }
        }

        return $total;
    }

    /**
     * Average of an array of values
     */
    public static function avg(array $values, ?int $scale = null): ?string
    {
        $values = array_filter($values, fn($v) => $v !== null);
        if (empty($values)) {
            return null;
        }

        $sum = self::sum($values, $scale);
        return self::div($sum, (string)count($values), $scale);
    }

    # =========================================================================
    # PRORATION
    # =========================================================================

    /**
     * Prorate amount based on days
     */
    public static function prorateDays(
        ?string $amount,
        int $workedDays,
        int $totalDays,
        ?int $scale = null
    ): string {
        if ($totalDays <= 0) {
            return '0';
        }

        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $ratio = bcdiv((string)$workedDays, (string)$totalDays, $scale);
        return bcmul(self::normalize($amount), $ratio, $scale);
    }

    /**
     * Prorate amount based on hours
     */
    public static function prorateHours(
        ?string $amount,
        ?string $workedHours,
        ?string $totalHours,
        ?int $scale = null
    ): string {
        $total = self::normalize($totalHours);
        if (bccomp($total, '0', 6) === 0) {
            return '0';
        }

        $scale = $scale ?? self::DEFAULT_INTERNAL_SCALE;
        $ratio = bcdiv(self::normalize($workedHours), $total, $scale);
        return bcmul(self::normalize($amount), $ratio, $scale);
    }

    /**
     * Allocate amount proportionally to buckets with rounding remainder distribution
     */
    public static function allocate(
        ?string $amount,
        array $weights,
        int $precision = 2,
        string $roundingMode = self::ROUND_HALF_UP
    ): array {
        $amount = self::normalize($amount);
        $totalWeight = self::sum($weights);

        if (bccomp($totalWeight, '0', self::DEFAULT_INTERNAL_SCALE) === 0) {
            return array_map(fn() => '0', $weights);
        }

        $allocated = [];
        $allocatedSum = '0';

        foreach ($weights as $key => $weight) {
            $ratio = self::div($weight, $totalWeight);
            $portion = bcmul($amount, $ratio, self::DEFAULT_INTERNAL_SCALE);
            $rounded = self::round($portion, $precision, $roundingMode);
            $allocated[$key] = $rounded;
            $allocatedSum = bcadd($allocatedSum, $rounded, $precision);
        }

        $remainder = bcsub($amount, $allocatedSum, $precision);
        if (bccomp($remainder, '0', $precision) !== 0) {
            $maxKey = null;
            $maxValue = '0';
            foreach ($allocated as $key => $value) {
                if ($maxKey === null || bccomp($value, $maxValue, $precision) > 0) {
                    $maxKey = $key;
                    $maxValue = $value;
                }
            }
            if ($maxKey !== null) {
                $allocated[$maxKey] = bcadd($allocated[$maxKey], $remainder, $precision);
            }
        }

        return $allocated;
    }

    # =========================================================================
    # NORMALIZATION & FORMATTING
    # =========================================================================

    /**
     * Normalize value to valid bcmath string
     */
    public static function normalize($value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_numeric($value)) {
            return (string)$value;
        }

        if (is_string($value)) {
            $cleaned = preg_replace('/[^\d.\-]/', '', $value);
            return $cleaned === '' ? '0' : $cleaned;
        }

        return '0';
    }

    /**
     * Format number with thousand separators
     */
    public static function format(
        ?string $number,
        int $decimals = 2,
        string $decimalSep = '.',
        string $thousandSep = ','
    ): string {
        $number = self::normalize($number);
        $rounded = self::round($number, $decimals);

        $parts = explode('.', $rounded);
        $integer = $parts[0];
        $decimal = $parts[1] ?? str_repeat('0', $decimals);

        $negative = false;
        if ($integer[0] === '-') {
            $negative = true;
            $integer = substr($integer, 1);
        }

        $formatted = '';
        $len = strlen($integer);
        for ($i = 0; $i < $len; $i++) {
            if ($i > 0 && ($len - $i) % 3 === 0) {
                $formatted .= $thousandSep;
            }
            $formatted .= $integer[$i];
        }

        $result = $formatted;
        if ($decimals > 0) {
            $result .= $decimalSep . str_pad($decimal, $decimals, '0');
        }

        return $negative ? '-' . $result : $result;
    }

    /**
     * Parse formatted number back to bcmath string
     */
    public static function parse(
        string $formatted,
        string $decimalSep = '.',
        string $thousandSep = ','
    ): string {
        $cleaned = str_replace($thousandSep, '', $formatted);
        if ($decimalSep !== '.') {
            $cleaned = str_replace($decimalSep, '.', $cleaned);
        }
        return self::normalize($cleaned);
    }

    # =========================================================================
    # INTERNAL HELPERS
    # =========================================================================

    private static function truncateInternal(string $number): string
    {
        return bcadd($number, '0', 0);
    }
}
