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
 * - Input validation (validateLineInput)
 * - Deterministic signatures (ALCOA+)
 * - Fees summary (optional)
 * - Error contract (error_type, error_code)
 * - No database, no HTTP, no business logic
 *
 * @package bX
 * @version 1.2
 */
final class PricingEngine
{
    const VERSION = '1.2.0';

    # Error types
    const ERROR_INPUT = 'input';
    const ERROR_INTERNAL = 'internal';

    # Error codes
    const ERR_LINE_MISSING_FIELD = 'LINE_MISSING_REQUIRED_FIELD';
    const ERR_LINE_INVALID_QUANTITY = 'LINE_INVALID_QUANTITY';
    const ERR_LINE_INVALID_PRICE = 'LINE_INVALID_PRICE';
    const ERR_LINE_AMBIGUOUS_FIELDS = 'LINE_AMBIGUOUS_FIELDS';
    const ERR_MARGIN_ZERO_PRICE = 'MARGIN_SELLING_PRICE_ZERO';
    const ERR_MARGIN_ZERO_COST = 'MARGIN_COST_ZERO';
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

        # Process each line (v1.2: validate first)
        foreach ($lines as $lineIndex => $line) {
            # VALIDATE LINE (v1.2)
            $validation = self::validateLineInput($line);
            if (!$validation['valid']) {
                return self::buildError(
                    $validation['error_type'],
                    $validation['error_code'],
                    "Line $lineIndex: " . $validation['error_message']
                );
            }
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

        # Margin summary (STANDARD financial definition)
        $marginSummary = null;
        if (bccomp($totalCost, '0', $precision) > 0 && bccomp($totalNetAfter, '0', $precision) > 0) {
            $totalCost = self::bcRound($totalCost, $precision);

            $grossMargin = bcsub($totalNetAfter, $totalCost, $precision);

            # MARGIN RATE (standard): margin / revenue × 100
            $averageMarginRate = bcmul(
                bcdiv($grossMargin, $totalNetAfter, 6),
                '100',
                $precision
            );

            # MARKUP RATE (optional): margin / cost × 100
            $averageMarkupRate = bcmul(
                bcdiv($grossMargin, $totalCost, 6),
                '100',
                $precision
            );

            $marginSummary = [
                'total_cost' => $totalCost,
                'total_revenue_net' => $totalNetAfter,
                'total_gross_margin' => $grossMargin,
                'average_margin_rate' => $averageMarginRate,   // Standard: on revenue
                'average_markup_rate' => $averageMarkupRate    // Optional: on cost
            ];
        }

        # Round all taxBreakdown values for display
        foreach ($taxBreakdown as &$taxInfo) {
            $taxInfo['taxable_base_before'] = self::bcRound($taxInfo['taxable_base_before'], $precision);
            $taxInfo['taxable_base_after'] = self::bcRound($taxInfo['taxable_base_after'], $precision);
        }
        unset($taxInfo);

        # v1.2: Build fees summary (if any fees)
        $feesSummary = self::buildFeesSummary($pricedLines, $precision);

        # v1.2: Generate signature (deterministic, ALCOA+)
        $signature = self::generateSignature($document, $options);

        return [
            'success' => true,
            'currency' => $currency,
            'lines' => $pricedLines,
            'totals_before_discount' => $totalsBeforeDiscount,
            'discount' => $discountInfo,
            'totals_after_discount' => $totalsAfterDiscount,
            'totals' => $totalsAfterDiscount,  // Alias for compatibility
            'tax_breakdown' => array_values($taxBreakdown),
            'margin_summary' => $marginSummary,
            'fees_summary' => $feesSummary,  // v1.2
            'meta' => [  // v1.2
                'version' => self::VERSION,
                'signature' => $signature,
                'rounding_mode' => 'HALF_UP',
                'round_per_line' => $roundPerLine,
                'precision' => $precision
            ]
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
     * Calculate margin (standard financial definition)
     *
     * Returns NULL if sellingPrice is zero (division by zero edge case).
     *
     * STANDARD FORMULA (IFRS, P&L):
     *   margin_rate = (Revenue - Cost) / Revenue × 100  (on selling price)
     *   markup_rate = (Revenue - Cost) / Cost × 100     (on cost)
     *
     * This method returns MARGIN (standard), not markup.
     *
     * @param string $sellingPrice Selling price (net, base imponible)
     * @param string $cost Cost (net)
     * @param int $precision Decimal precision
     * @return array|null ['gross_margin' => string, 'margin_rate' => string, 'markup_rate' => string] or NULL
     */
    public static function calculateMargin(
        string $sellingPrice,
        string $cost,
        int $precision = 2
    ): ?array {
        # Edge case: sellingPrice = 0 → cannot calculate margin
        if (bccomp($sellingPrice, '0', $precision) === 0) {
            return null;
        }

        $margin = bcsub($sellingPrice, $cost, $precision);

        # MARGIN RATE (standard): margin / selling price × 100
        $marginRate = bcmul(
            bcdiv($margin, $sellingPrice, 6),
            '100',
            $precision
        );

        # MARKUP RATE (optional): margin / cost × 100
        $markupRate = null;
        if (bccomp($cost, '0', $precision) > 0) {
            $markupRate = bcmul(
                bcdiv($margin, $cost, 6),
                '100',
                $precision
            );
        }

        return [
            'gross_margin' => $margin,
            'margin_rate' => $marginRate,    // Standard: on revenue
            'markup_rate' => $markupRate     // Optional: on cost
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

    // ========================================================================
    // v1.2 METHODS (Enterprise Features)
    // ========================================================================

    /**
     * Validate line input structure (v1.2)
     *
     * Validates required fields and detects ambiguous field combinations.
     * Returns error contract if invalid.
     *
     * @param array $line Line item to validate
     * @return array ['valid' => bool, 'error_type' => string, 'error_code' => string, 'error_message' => string]
     */
    private static function validateLineInput(array $line): array
    {
        # Required fields
        if (!isset($line['unit_net']) || trim($line['unit_net']) === '') {
            return [
                'valid' => false,
                'error_type' => self::ERROR_INPUT,
                'error_code' => self::ERR_LINE_MISSING_FIELD,
                'error_message' => 'Line missing required field: unit_net'
            ];
        }

        # Validate quantity > 0
        $quantity = $line['quantity'] ?? 1;
        if (bccomp((string)$quantity, '0', 0) <= 0) {
            return [
                'valid' => false,
                'error_type' => self::ERROR_INPUT,
                'error_code' => self::ERR_LINE_INVALID_QUANTITY,
                'error_message' => "Invalid quantity: $quantity (must be > 0)"
            ];
        }

        # Validate unit_net > 0 (or allow 0 for fees, adjust as needed)
        $unitNet = $line['unit_net'] ?? '0';
        if (bccomp($unitNet, '0', 2) < 0) {
            return [
                'valid' => false,
                'error_type' => self::ERROR_INPUT,
                'error_code' => self::ERR_LINE_INVALID_PRICE,
                'error_message' => "Invalid unit_net: $unitNet (cannot be negative)"
            ];
        }

        # Detect ambiguous fields (unit_price_net AND unit_price_total both provided)
        if (isset($line['unit_price_net']) && isset($line['unit_price_total']) && isset($line['unit_net'])) {
            # Multiple price fields - ambiguous
            return [
                'valid' => false,
                'error_type' => self::ERROR_INPUT,
                'error_code' => self::ERR_LINE_AMBIGUOUS_FIELDS,
                'error_message' => 'Line has ambiguous price fields (unit_net, unit_price_net, unit_price_total)'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Generate deterministic signature for pricing calculation (v1.2)
     *
     * Generates SHA-256 hash of input for ALCOA+ compliance and reproducibility.
     * Same input + same version → same signature.
     *
     * @param array $document Original document
     * @param array $options Pricing options
     * @return string SHA-256 signature
     */
    public static function generateSignature(array $document, array $options): string
    {
        # Normalize input for deterministic hashing
        $normalized = [
            'version' => self::VERSION,
            'currency' => $document['currency'] ?? 'CLP',
            'discount_factor' => $document['discount_factor'] ?? '0',
            'lines' => $document['lines'] ?? [],
            'tax_rates' => $options['tax_rates'] ?? [],
            'precision' => $options['precision'] ?? 2,
            'round_per_line' => $options['round_per_line'] ?? true
        ];

        # Sort arrays for determinism (same data different order = same hash)
        ksort($normalized);
        if (isset($normalized['tax_rates'])) {
            ksort($normalized['tax_rates']);
        }

        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        return hash('sha256', $json);
    }

    /**
     * Build fees summary from priced lines (v1.2)
     *
     * Aggregates fees by fee_code for documentary/reporting purposes.
     *
     * @param array $pricedLines Lines already priced
     * @param int $precision Decimal precision
     * @return array Fees summary grouped by fee_code
     */
    private static function buildFeesSummary(array $pricedLines, int $precision): array
    {
        $feesSummary = [];

        foreach ($pricedLines as $line) {
            $feeCode = $line['fee_code'] ?? null;

            if ($feeCode) {
                if (!isset($feesSummary[$feeCode])) {
                    $feesSummary[$feeCode] = [
                        'fee_code' => $feeCode,
                        'fee_net' => '0',
                        'fee_tax' => '0',
                        'fee_total' => '0'
                    ];
                }

                # Accumulate
                $feesSummary[$feeCode]['fee_net'] = bcadd(
                    $feesSummary[$feeCode]['fee_net'],
                    $line['subtotal_net'],
                    $precision
                );

                $feesSummary[$feeCode]['fee_tax'] = bcadd(
                    $feesSummary[$feeCode]['fee_tax'],
                    $line['subtotal_tax'],
                    $precision
                );

                $feesSummary[$feeCode]['fee_total'] = bcadd(
                    $feesSummary[$feeCode]['fee_total'],
                    $line['subtotal_total'],
                    $precision
                );
            }
        }

        return array_values($feesSummary);
    }

    /**
     * Build error response (v1.2)
     *
     * Standard error contract for PricingEngine.
     *
     * @param string $errorType 'input' or 'internal'
     * @param string $errorCode Error code constant
     * @param string $errorMessage Human-readable message
     * @return array Error response
     */
    private static function buildError(string $errorType, string $errorCode, string $errorMessage): array
    {
        return [
            'success' => false,
            'error_type' => $errorType,
            'error_code' => $errorCode,
            'error_message' => $errorMessage
        ];
    }
}
