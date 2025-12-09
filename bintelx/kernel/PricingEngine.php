<?php
# bintelx/kernel/PricingEngine.php
# Domain-agnostic pricing calculation engine
#
# Pure mathematical pricing with NO floats, NO side effects.
# Supports multi-tax, fees, discounts.
# ALL calculations use bcmath (string-based).

namespace bX;

/**
 * PricingEngine - Pure mathematical pricing calculator
 *
 * Stateless engine for pricing calculations:
 * - Multi-tax support (different rates per line)
 * - Global discount application (SII Chile compliant)
 * - Margin calculation with edge cases
 * - bcmath ONLY (all amounts as strings, NO floats)
 * - HALF_UP rounding (pure bcmath)
 * - No database, no HTTP, no business logic
 *
 * @package bX
 * @version 1.0
 */
final class PricingEngine
{
    /**
     * Calculate pricing for a document
     *
     * @param array $document Document structure with lines
     * @param array $options Calculation options
     * @return array Calculated pricing with breakdown
     */
    public static function priceDocument(
        array $document,
        array $options = []
    ): array {
        $currency = $document['currency'] ?? 'CLP';
        $lines = $document['lines'] ?? [];
        $discountFactor = $document['discount_factor'] ?? '0';

        # Options
        $taxRates = $options['tax_rates'] ?? ['VAT19' => '19.00'];
        $precision = (int)($options['precision'] ?? 2);
        $roundPerLine = (bool)($options['round_per_line'] ?? true);

        # Internal precision (higher for accumulation)
        $internalPrecision = $precision + 4;

        $pricedLines = [];
        $subtotalNet = '0';
        $subtotalTax = '0';
        $taxBreakdown = [];
        $totalCost = '0';

        # Process each line
        foreach ($lines as $line) {
            $lineId = $line['line_id'] ?? uniqid('LINE-');
            $kind = $line['kind'] ?? 'other';
            $quantity = $line['quantity'] ?? '1';
            $unitNet = $line['unit_net'] ?? '0';
            $taxCode = $line['tax_code'] ?? 'VAT19';
            $costPerUnit = $line['cost_per_unit'] ?? null;
            $feeCode = $line['fee_code'] ?? null;

            # Get tax rate for this line
            $taxRate = $taxRates[$taxCode] ?? '0';

            # Calculate unit prices
            $taxResult = self::calculateTax($unitNet, $taxRate, $precision);
            $unitTax = $taxResult['tax_amount'];
            $unitTotal = $taxResult['total_amount'];

            # Calculate subtotals (quantity × unit) with internal precision
            $subtotalLineNet = bcmul($unitNet, $quantity, $internalPrecision);
            $subtotalLineTax = bcmul($unitTax, $quantity, $internalPrecision);
            $subtotalLineTotal = bcmul($unitTotal, $quantity, $internalPrecision);

            # For display: always round to final precision
            $displaySubtotalNet = self::bcRound($subtotalLineNet, $precision);
            $displaySubtotalTax = self::bcRound($subtotalLineTax, $precision);
            $displaySubtotalTotal = self::bcRound($subtotalLineTotal, $precision);

            # Accumulate: use rounded if round_per_line, else full precision
            $accumulateNet = $roundPerLine ? $displaySubtotalNet : $subtotalLineNet;
            $accumulateTax = $roundPerLine ? $displaySubtotalTax : $subtotalLineTax;

            $subtotalNet = bcadd($subtotalNet, $accumulateNet, $internalPrecision);
            $subtotalTax = bcadd($subtotalTax, $accumulateTax, $internalPrecision);

            # Tax breakdown by category
            if (!isset($taxBreakdown[$taxCode])) {
                $taxBreakdown[$taxCode] = [
                    'tax_code' => $taxCode,
                    'tax_rate' => $taxRate,
                    'taxable_base_before' => '0',
                    'taxable_base_after' => '0',
                    'tax_amount' => '0'
                ];
            }
            $taxBreakdown[$taxCode]['taxable_base_before'] = bcadd(
                $taxBreakdown[$taxCode]['taxable_base_before'],
                $accumulateNet,
                $internalPrecision
            );

            # Margin calculation (if cost provided)
            $margin = null;
            if ($costPerUnit !== null && bccomp($costPerUnit, '0', $precision) > 0) {
                $totalCostLine = bcmul($costPerUnit, $quantity, $internalPrecision);
                $marginResult = self::calculateMargin($displaySubtotalNet, $totalCostLine, $precision);

                if ($marginResult !== null) {
                    $margin = [
                        'unit_cost' => $costPerUnit,
                        'total_cost' => self::bcRound($totalCostLine, $precision),
                        'gross_margin' => $marginResult['gross_margin'],
                        'margin_rate' => $marginResult['margin_rate']
                    ];

                    $totalCost = bcadd($totalCost, $totalCostLine, $internalPrecision);
                }
            }

            # Build priced line (always display precision)
            $pricedLines[] = [
                'line_id' => $lineId,
                'kind' => $kind,
                'quantity' => $quantity,
                'unit_net' => $unitNet,
                'unit_tax' => $unitTax,
                'unit_total' => $unitTotal,
                'subtotal_net' => $displaySubtotalNet,
                'subtotal_tax' => $displaySubtotalTax,
                'subtotal_total' => $displaySubtotalTotal,
                'tax_code' => $taxCode,
                'tax_rate' => $taxRate,
                'fee_code' => $feeCode,
                'margin' => $margin
            ];
        }

        # Round final subtotals
        $subtotalNet = self::bcRound($subtotalNet, $precision);
        $subtotalTax = self::bcRound($subtotalTax, $precision);

        # Apply discount (SII Chile compliant: on taxable base)
        $totalNetAfter = $subtotalNet;
        $totalTaxAfter = '0';
        $discountInfo = null;

        if (bccomp($discountFactor, '0', 4) > 0) {
            # WITH DISCOUNT: apply to net, recalculate tax
            $discountResult = self::applyDiscount($subtotalNet, $discountFactor, $precision);
            $totalNetAfter = $discountResult['discounted_amount'];
            $discountAmountNet = $discountResult['discount_amount'];

            # Recalculate TAX proportionally by category
            foreach ($taxBreakdown as &$taxInfo) {
                $proportionOfNet = bccomp($subtotalNet, '0', 6) > 0
                    ? bcdiv($taxInfo['taxable_base_before'], $subtotalNet, 6)
                    : '0';

                $taxableAfter = bcmul($totalNetAfter, $proportionOfNet, $internalPrecision);
                $taxAmount = bcmul($taxableAfter, bcdiv($taxInfo['tax_rate'], '100', 6), $internalPrecision);

                $taxableAfter = self::bcRound($taxableAfter, $precision);
                $taxAmount = self::bcRound($taxAmount, $precision);

                $taxInfo['taxable_base_after'] = $taxableAfter;
                $taxInfo['tax_amount'] = $taxAmount;

                $totalTaxAfter = bcadd($totalTaxAfter, $taxAmount, $internalPrecision);
            }
            unset($taxInfo);

            $totalTaxAfter = self::bcRound($totalTaxAfter, $precision);

            $discountAmountTax = bcsub($subtotalTax, $totalTaxAfter, $precision);
            $discountAmountTotal = bcadd($discountAmountNet, $discountAmountTax, $precision);

            $discountInfo = [
                'factor' => $discountFactor,
                'discount_amount_net' => $discountAmountNet,
                'discount_amount_tax' => $discountAmountTax,
                'discount_amount_total' => $discountAmountTotal
            ];
        } else {
            # NO DISCOUNT: recalculate tax breakdown for consistency
            foreach ($taxBreakdown as &$taxInfo) {
                $taxInfo['taxable_base_after'] = $taxInfo['taxable_base_before'];
                $taxAmount = bcmul(
                    $taxInfo['taxable_base_after'],
                    bcdiv($taxInfo['tax_rate'], '100', 6),
                    $internalPrecision
                );
                $taxInfo['tax_amount'] = self::bcRound($taxAmount, $precision);

                $totalTaxAfter = bcadd($totalTaxAfter, $taxInfo['tax_amount'], $internalPrecision);
            }
            unset($taxInfo);

            $totalTaxAfter = self::bcRound($totalTaxAfter, $precision);

            # Synchronize subtotal_tax with recalculated tax (auditor-level consistency)
            $subtotalTax = $totalTaxAfter;
        }

        # Final totals
        $subtotalGross = bcadd($subtotalNet, $subtotalTax, $precision);

        $totalsBeforeDiscount = [
            'subtotal_net' => $subtotalNet,
            'subtotal_tax' => $subtotalTax,
            'subtotal_gross' => $subtotalGross
        ];

        $totalFinal = bcadd($totalNetAfter, $totalTaxAfter, $precision);

        $totalsAfterDiscount = [
            'total_net' => $totalNetAfter,
            'total_tax' => $totalTaxAfter,
            'total_final' => $totalFinal
        ];

        # Margin summary
        $marginSummary = null;
        if (bccomp($totalCost, '0', $precision) > 0) {
            $totalCost = self::bcRound($totalCost, $precision);

            $grossMargin = bcsub($totalNetAfter, $totalCost, $precision);
            $averageMarginRate = bcmul(
                bcdiv($grossMargin, $totalCost, 6),
                '100',
                $precision
            );

            $marginSummary = [
                'total_cost' => $totalCost,
                'total_revenue_net' => $totalNetAfter,
                'total_gross_margin' => $grossMargin,
                'average_margin_rate' => $averageMarginRate
            ];
        }

        # Round all taxBreakdown values for display
        foreach ($taxBreakdown as &$taxInfo) {
            $taxInfo['taxable_base_before'] = self::bcRound($taxInfo['taxable_base_before'], $precision);
            $taxInfo['taxable_base_after'] = self::bcRound($taxInfo['taxable_base_after'], $precision);
        }
        unset($taxInfo);

        return [
            'success' => true,
            'currency' => $currency,
            'lines' => $pricedLines,
            'totals_before_discount' => $totalsBeforeDiscount,
            'discount' => $discountInfo,
            'totals_after_discount' => $totalsAfterDiscount,
            'totals' => $totalsAfterDiscount,  // Alias for compatibility
            'tax_breakdown' => array_values($taxBreakdown),
            'margin_summary' => $marginSummary
        ];
    }

    /**
     * Calculate tax for an amount
     *
     * @param string $netAmount Net amount (bcmath string)
     * @param string $taxRate Tax rate (e.g. "19.00" for 19%)
     * @param int $precision Decimal precision
     * @return array ['tax_amount' => string, 'total_amount' => string]
     */
    public static function calculateTax(
        string $netAmount,
        string $taxRate,
        int $precision = 2
    ): array {
        $rate = bcdiv($taxRate, '100', 6);
        $taxAmount = bcmul($netAmount, $rate, $precision + 4);
        $totalAmount = bcadd($netAmount, $taxAmount, $precision + 4);

        # Round to final precision
        $taxAmount = self::bcRound($taxAmount, $precision);
        $totalAmount = self::bcRound($totalAmount, $precision);

        return [
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount
        ];
    }

    /**
     * Calculate margin
     *
     * Returns NULL if cost is zero (division by zero edge case).
     *
     * @param string $sellingPrice Selling price (net)
     * @param string $cost Cost (net)
     * @param int $precision Decimal precision
     * @return array|null ['gross_margin' => string, 'margin_rate' => string] or NULL
     */
    public static function calculateMargin(
        string $sellingPrice,
        string $cost,
        int $precision = 2
    ): ?array {
        # Edge case: cost = 0 → cannot calculate margin
        if (bccomp($cost, '0', $precision) === 0) {
            return null;
        }

        $margin = bcsub($sellingPrice, $cost, $precision);
        $rate = bcmul(
            bcdiv($margin, $cost, 6),
            '100',
            $precision
        );

        return [
            'gross_margin' => $margin,
            'margin_rate' => $rate
        ];
    }

    /**
     * Apply discount factor with clamping
     *
     * Clamps discountFactor to [0, 1] range to prevent errors.
     *
     * @param string $amount Original amount
     * @param string $discountFactor Discount factor (0-1, e.g. "0.15" = 15% off)
     * @param int $precision Decimal precision
     * @return array ['discounted_amount' => string, 'discount_amount' => string]
     */
    public static function applyDiscount(
        string $amount,
        string $discountFactor,
        int $precision = 2
    ): array {
        # Clamp to [0, 1]
        if (bccomp($discountFactor, '0', 4) < 0) {
            $discountFactor = '0';
        }
        if (bccomp($discountFactor, '1', 4) > 0) {
            $discountFactor = '1';
        }

        $discountAmount = bcmul($amount, $discountFactor, $precision);
        $discountedAmount = bcsub($amount, $discountAmount, $precision);

        return [
            'discounted_amount' => $discountedAmount,
            'discount_amount' => $discountAmount
        ];
    }

    /**
     * Round a bcmath string to specified precision (HALF_UP)
     *
     * Pure bcmath rounding, NO float conversion.
     * Implements HALF_UP rounding (0.125 → 0.13).
     *
     * @param string $number Number to round (bcmath string)
     * @param int $precision Decimal places
     * @return string Rounded number
     */
    private static function bcRound(string $number, int $precision = 2): string {
        if ($precision < 0) {
            $precision = 0;
        }

        # Scale factor (10^precision)
        $factor = bcpow('10', (string)$precision, 0);

        # Scale number up
        $scaled = bcmul($number, $factor, $precision + 2);

        # HALF_UP: add 0.5 if positive, subtract 0.5 if negative
        if (bccomp($scaled, '0', 0) >= 0) {
            $scaled = bcadd($scaled, '0.5', 0);
        } else {
            $scaled = bcsub($scaled, '0.5', 0);
        }

        # Truncate to integer (removes decimals)
        $integer = bcadd($scaled, '0', 0);

        # Scale back down
        return bcdiv($integer, $factor, $precision);
    }
}
