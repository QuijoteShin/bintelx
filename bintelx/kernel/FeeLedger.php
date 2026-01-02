<?php
# bintelx/kernel/FeeLedger.php
# Persistence layer for FeeEngine calculations
#
# Provides:
#   - Immutable fee records per transaction
#   - Descriptive names from policy
#   - On-the-fly calculation for items/orders
#   - Integration with PricingEngine
#   - Adjustment events (refunds, chargebacks)
#   - P4: Component-aware refunds (refundable/non_refundable)
#
# @version 1.1.0

namespace bX;

/**
 * FeeLedger - Persistence layer for FeeEngine
 *
 * Stateless orchestrator that:
 * - Loads policies from storage (callback-based)
 * - Calls FeeEngine for calculations
 * - Persists results with snapshots
 * - Tracks adjustment events
 *
 * Storage is abstracted via callbacks - no direct DB dependency.
 *
 * @package bX
 * @version 1.0
 */
final class FeeLedger
{
    const VERSION = '1.1.0';

    # Event types
    const EVENT_SETTLE = 'SETTLE';      # Initial fee calculation
    const EVENT_ADJUST = 'ADJUST';      # Partial adjustment
    const EVENT_REFUND = 'REFUND';      # Refund (reversal)
    const EVENT_CHARGEBACK = 'CHARGEBACK'; # Chargeback

    # Status
    const STATUS_ACTIVE = 'active';
    const STATUS_ADJUSTED = 'adjusted';
    const STATUS_REVERSED = 'reversed';

    # Refund behaviors (P4)
    const REFUND_PROPORTIONAL = 'PROPORTIONAL';
    const REFUND_NONE = 'NONE';
    const REFUND_FIXED_ONLY = 'FIXED_ONLY';

    # Proration methods
    const PRORATE_BY_NET = 'BY_NET';
    const PRORATE_BY_GROSS = 'BY_GROSS';
    const PRORATE_BY_QUANTITY = 'BY_QUANTITY';
    const PRORATE_EQUAL = 'EQUAL';

    # Error codes (P4)
    const ERR_ENTRY_NOT_FOUND = 'ERR_LEDGER_ENTRY_NOT_FOUND';
    const ERR_NO_BREAKDOWN = 'ERR_REFUND_NO_ORIGINAL_BREAKDOWN';
    const ERR_EXCEEDS_ORIGINAL = 'ERR_REFUND_EXCEEDS_ORIGINAL';
    const ERR_LINE_NOT_FOUND = 'ERR_REFUND_LINE_NOT_FOUND';
    const ERR_CURRENCY_MISMATCH = 'ERR_REFUND_CURRENCY_MISMATCH';

    /**
     * Calculate and persist fees for a transaction
     *
     * @param array $input CommissionInput (lines, channel_key, etc.)
     * @param array $callbacks Storage callbacks:
     *   - 'load_policy': fn(string $channelKey, string $asOf) => ?array
     *   - 'save_entry': fn(array $entry) => string|int (returns entry_id)
     *   - 'load_entry': fn(string $transactionId) => ?array
     * @param array $options Calculation options
     * @return array LedgerResult with entry_id, fees, breakdown
     */
    public static function settle(
        array $input,
        array $callbacks,
        array $options = []
    ): array {
        $transactionId = $input['transaction_id'] ?? uniqid('TXN-');
        $channelKey = $input['channel_key'] ?? null;
        $asOf = $input['as_of'] ?? date('Y-m-d H:i:s');

        if (!$channelKey) {
            return self::buildError('MISSING_CHANNEL', 'channel_key is required');
        }

        # 1. Load policy via callback
        $loadPolicy = $callbacks['load_policy'] ?? null;
        if (!$loadPolicy) {
            return self::buildError('MISSING_CALLBACK', 'load_policy callback required');
        }

        $policy = $loadPolicy($channelKey, $asOf);
        if (!$policy) {
            return self::buildError('NO_POLICY', "No policy found for channel '$channelKey'");
        }

        # 2. Calculate fees via FeeEngine
        $feeResult = FeeEngine::calculate($input, $policy, $options);

        if (!$feeResult['success']) {
            return $feeResult; # Pass through error
        }

        # 3. Build ledger entry
        $entry = [
            'entry_id' => null, # Set by storage
            'transaction_id' => $transactionId,
            'channel_key' => $channelKey,
            'event_type' => self::EVENT_SETTLE,
            'status' => self::STATUS_ACTIVE,
            'as_of' => $asOf,
            'currency' => $feeResult['currency'],
            'total_fee' => $feeResult['total_fee'],
            'breakdown' => $feeResult['breakdown'],
            'allocation' => $feeResult['allocation'],
            'explain_plan' => $feeResult['explain_plan'],
            'reconciliation' => $feeResult['reconciliation'],
            'warnings' => $feeResult['warnings'],
            'policy_snapshot' => [
                'policy_key' => $policy['policy_key'] ?? null,
                'version' => $policy['version'] ?? 1,
                'components_count' => count($policy['components'] ?? [])
            ],
            'input_snapshot' => [
                'lines_count' => count($input['lines'] ?? []),
                'order_totals' => $input['order'] ?? [
                    'net' => array_reduce($input['lines'] ?? [], fn($sum, $l) => bcadd($sum, $l['net'] ?? '0', 2), '0')
                ]
            ],
            'signature' => $feeResult['meta']['signature'],
            'idempotency_key' => $input['idempotency_key'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'meta' => $feeResult['meta']
        ];

        # 4. Persist via callback
        $saveEntry = $callbacks['save_entry'] ?? null;
        if ($saveEntry) {
            $entryId = $saveEntry($entry);
            $entry['entry_id'] = $entryId;
        }

        return [
            'success' => true,
            'entry' => $entry,
            'fees' => self::buildFeesSummary($feeResult)
        ];
    }

    /**
     * Calculate fees on-the-fly without persistence
     *
     * Useful for cart preview, unit economics, simulations.
     *
     * @param array $input CommissionInput
     * @param array $policy Policy (or use callbacks)
     * @param array $options Calculation options
     * @return array FeeResult with summary
     */
    public static function simulate(
        array $input,
        array $policy,
        array $options = []
    ): array {
        $feeResult = FeeEngine::calculate($input, $policy, $options);

        if (!$feeResult['success']) {
            return $feeResult;
        }

        return [
            'success' => true,
            'total_fee' => $feeResult['total_fee'],
            'currency' => $feeResult['currency'],
            'fees' => self::buildFeesSummary($feeResult),
            'breakdown' => $feeResult['breakdown'],
            'allocation' => $feeResult['allocation'],
            'warnings' => $feeResult['warnings'],
            'meta' => $feeResult['meta']
        ];
    }

    /**
     * Calculate fees for a single item (line)
     *
     * Convenience method for item-level fee calculation.
     *
     * @param array $item Single item data (net, quantity, etc.)
     * @param array $policy Policy
     * @param array $options Options
     * @return array Item fee result
     */
    public static function calculateForItem(
        array $item,
        array $policy,
        array $options = []
    ): array {
        $input = [
            'channel_key' => $policy['channel_key'] ?? 'default',
            'lines' => [$item]
        ];

        $result = FeeEngine::calculate($input, $policy, $options);

        if (!$result['success']) {
            return $result;
        }

        # Extract single line result
        $lineAlloc = $result['allocation'][0] ?? [];

        return [
            'success' => true,
            'item_fee' => $lineAlloc['fee_amount'] ?? '0',
            'currency' => $result['currency'],
            'components' => $lineAlloc['components'] ?? [],
            'breakdown' => $result['breakdown'],
            'meta' => $result['meta']
        ];
    }

    /**
     * Register an adjustment event (partial refund, etc.)
     *
     * P4: Component-aware refunds respecting refundable/non_refundable rules.
     *
     * @param string $originalEntryId Original ledger entry ID
     * @param array $adjustment Adjustment data:
     *   - type: REFUND | CHARGEBACK | ADJUST
     *   - amount: Total amount being refunded
     *   - lines: (optional) Per-line refund amounts
     *   - reason: Reason text
     *   - mode: AUTO (use breakdown) | MANUAL (explicit fee_amount)
     *   - strict: Strict mode for errors
     * @param array $callbacks Storage callbacks
     * @param array $options Options (precision, etc.)
     * @return array Adjustment result with refund_plan
     */
    public static function adjust(
        string $originalEntryId,
        array $adjustment,
        array $callbacks,
        array $options = []
    ): array {
        $strict = $options['strict'] ?? false;
        $precision = $options['precision'] ?? 2;

        # Load original entry
        $loadEntry = $callbacks['load_entry'] ?? null;
        if (!$loadEntry) {
            return self::buildError('MISSING_CALLBACK', 'load_entry callback required');
        }

        $original = $loadEntry($originalEntryId);
        if (!$original) {
            return self::buildError(self::ERR_ENTRY_NOT_FOUND, "Entry '$originalEntryId' not found");
        }

        $adjustmentAmount = $adjustment['amount'] ?? '0';
        $adjustmentType = $adjustment['type'] ?? self::EVENT_ADJUST;
        $reason = $adjustment['reason'] ?? null;
        $mode = $adjustment['mode'] ?? 'AUTO';
        $currency = $adjustment['currency'] ?? $original['currency'];

        # P4: Currency validation
        if ($currency !== $original['currency']) {
            if ($strict) {
                return self::buildError(self::ERR_CURRENCY_MISMATCH,
                    "Currency mismatch: adjustment=$currency, original={$original['currency']}");
            }
        }

        # Mode MANUAL: use explicit fee_amount
        if ($mode === 'MANUAL') {
            $feeAdjustment = $adjustment['fee_amount'] ?? '0';
            $refundPlan = [['mode' => 'MANUAL', 'fee_amount' => $feeAdjustment]];
        }
        # Mode AUTO: component-aware calculation
        else {
            $result = self::calculateComponentAwareRefund(
                $original,
                $adjustment,
                $precision,
                $strict
            );

            if (!$result['success']) {
                return $result;
            }

            $feeAdjustment = $result['total_fee_refund'];
            $refundPlan = $result['refund_plan'];
        }

        # Build adjustment entry
        $adjustmentEntry = [
            'entry_id' => null,
            'transaction_id' => $original['transaction_id'],
            'parent_entry_id' => $originalEntryId,
            'channel_key' => $original['channel_key'],
            'event_type' => $adjustmentType,
            'status' => self::STATUS_ACTIVE,
            'as_of' => $adjustment['as_of'] ?? date('Y-m-d H:i:s'),
            'currency' => $original['currency'],
            'total_fee' => $feeAdjustment,
            'adjustment_base' => $adjustmentAmount,
            'reason' => $reason,
            'refund_plan' => $refundPlan,
            'coverage' => $result['coverage'] ?? null,
            'reconciliation' => $result['reconciliation'] ?? null,
            'original_snapshot' => [
                'entry_id' => $originalEntryId,
                'total_fee' => $original['total_fee'],
                'signature' => $original['signature'] ?? null
            ],
            'created_at' => date('Y-m-d H:i:s')
        ];

        # Persist adjustment
        $saveEntry = $callbacks['save_entry'] ?? null;
        if ($saveEntry) {
            $entryId = $saveEntry($adjustmentEntry);
            $adjustmentEntry['entry_id'] = $entryId;
        }

        # Update original entry status
        $updateEntry = $callbacks['update_entry'] ?? null;
        if ($updateEntry) {
            $updateEntry($originalEntryId, ['status' => self::STATUS_ADJUSTED]);
        }

        return [
            'success' => true,
            'adjustment_entry' => $adjustmentEntry,
            'fee_adjustment' => $feeAdjustment,
            'running_total' => bcadd($original['total_fee'], $feeAdjustment, $precision),
            'refund_plan' => $refundPlan
        ];
    }

    /**
     * P4: Calculate component-aware refund
     *
     * Iterates through original breakdown, respecting per-component refundability.
     */
    private static function calculateComponentAwareRefund(
        array $original,
        array $adjustment,
        int $precision,
        bool $strict
    ): array {
        $breakdown = $original['breakdown'] ?? [];
        $allocation = $original['allocation'] ?? [];

        if (empty($breakdown)) {
            if ($strict) {
                return self::buildError(self::ERR_NO_BREAKDOWN,
                    'No breakdown in original entry for component-aware refund');
            }
            # Fallback to simple proportional
            return self::calculateSimpleProportionalRefund($original, $adjustment, $precision);
        }

        $refundAmount = $adjustment['amount'] ?? '0';
        $refundLines = $adjustment['lines'] ?? [];
        $originalBase = $original['input_snapshot']['order_totals']['net'] ?? '0';

        # Validate refund doesn't exceed original
        if ($strict && bccomp($refundAmount, $originalBase, $precision) > 0) {
            return self::buildError(self::ERR_EXCEEDS_ORIGINAL,
                "Refund amount $refundAmount exceeds original base $originalBase");
        }

        # Calculate global refund ratio
        $refundRatio = '0';
        if (bccomp($originalBase, '0', $precision) > 0) {
            $refundRatio = bcdiv($refundAmount, $originalBase, 8);
        }

        # Build per-line refund map if specified
        $lineRefundMap = [];
        $affectedLines = [];
        $unaffectedLines = [];

        if (!empty($refundLines)) {
            foreach ($refundLines as $lr) {
                $lineId = $lr['line_id'];
                $lineRefundMap[$lineId] = $lr['amount'];
                $affectedLines[] = $lineId;
            }
            # Determine unaffected lines
            foreach ($allocation as $alloc) {
                if (!in_array($alloc['line_id'], $affectedLines)) {
                    $unaffectedLines[] = $alloc['line_id'];
                }
            }
        } else {
            # All lines affected
            foreach ($allocation as $alloc) {
                $affectedLines[] = $alloc['line_id'];
            }
        }

        # Process each component
        $refundPlan = [];
        $totalFeeRefund = '0';

        foreach ($breakdown as $comp) {
            $compId = $comp['component_id'];
            $originalFee = $comp['amount'];
            $tags = $comp['tags'] ?? [];

            # Determine refundability
            $refundConfig = $comp['refund'] ?? [];
            $isRefundable = $refundConfig['refundable'] ?? true;
            $behavior = $refundConfig['behavior'] ?? self::REFUND_PROPORTIONAL;

            # Tag shortcuts
            if (in_array('non_refundable', $tags)) {
                $isRefundable = false;
            }

            # Calculate component refund
            $componentRefund = '0';

            if ($isRefundable) {
                switch ($behavior) {
                    case self::REFUND_PROPORTIONAL:
                        $componentRefund = bcmul($originalFee, $refundRatio, $precision);
                        break;

                    case self::REFUND_FIXED_ONLY:
                        # Only refund if component is fixed type
                        $compType = $comp['component_type'] ?? '';
                        if (in_array($compType, ['fixed_unit', 'fixed_order'])) {
                            $componentRefund = bcmul($originalFee, $refundRatio, $precision);
                        }
                        break;

                    case self::REFUND_NONE:
                    default:
                        $componentRefund = '0';
                        break;
                }

                # Cap refund to original
                $capToOriginal = $refundConfig['cap_refund_to_original'] ?? true;
                if ($capToOriginal && bccomp($componentRefund, $originalFee, $precision) > 0) {
                    $componentRefund = $originalFee;
                }
            }

            # Make negative for reversal
            $componentRefundNegative = bcmul($componentRefund, '-1', $precision);
            $totalFeeRefund = bcadd($totalFeeRefund, $componentRefundNegative, $precision);

            $refundPlan[] = [
                'component_id' => $compId,
                'original_fee' => $originalFee,
                'refunded_ratio' => $refundRatio,
                'refunded_fee' => $componentRefundNegative,
                'refundable' => $isRefundable,
                'behavior' => $isRefundable ? $behavior : self::REFUND_NONE
            ];
        }

        return [
            'success' => true,
            'total_fee_refund' => $totalFeeRefund,
            'refund_plan' => $refundPlan,
            'coverage' => [
                'affected_lines' => $affectedLines,
                'unaffected_lines' => $unaffectedLines
            ],
            'reconciliation' => [
                'refund_amount' => $refundAmount,
                'refund_ratio' => $refundRatio,
                'components_processed' => count($breakdown)
            ]
        ];
    }

    /**
     * Simple proportional refund (fallback when no breakdown)
     */
    private static function calculateSimpleProportionalRefund(
        array $original,
        array $adjustment,
        int $precision
    ): array {
        $refundAmount = $adjustment['amount'] ?? '0';
        $originalTotal = $original['total_fee'] ?? '0';
        $originalBase = $original['input_snapshot']['order_totals']['net'] ?? '0';

        $feeRefund = '0';
        if (bccomp($originalBase, '0', $precision) > 0) {
            $proportion = bcdiv($refundAmount, $originalBase, 8);
            $feeRefund = bcmul($proportion, $originalTotal, $precision);
            $feeRefund = bcmul($feeRefund, '-1', $precision);
        }

        return [
            'success' => true,
            'total_fee_refund' => $feeRefund,
            'refund_plan' => [
                [
                    'component_id' => '_global',
                    'original_fee' => $originalTotal,
                    'refunded_ratio' => $proportion ?? '0',
                    'refunded_fee' => $feeRefund,
                    'refundable' => true,
                    'behavior' => self::REFUND_PROPORTIONAL
                ]
            ],
            'coverage' => null,
            'reconciliation' => [
                'refund_amount' => $refundAmount,
                'refund_ratio' => $proportion ?? '0',
                'fallback' => true
            ]
        ];
    }

    /**
     * Get cumulative fees for a transaction
     *
     * Sums all entries (settle + adjustments) for running total.
     *
     * @param string $transactionId Transaction ID
     * @param array $callbacks Storage callbacks
     * @return array Running totals
     */
    public static function getTransactionFees(
        string $transactionId,
        array $callbacks
    ): array {
        $loadEntries = $callbacks['load_entries_by_transaction'] ?? null;
        if (!$loadEntries) {
            return self::buildError('MISSING_CALLBACK', 'load_entries_by_transaction required');
        }

        $entries = $loadEntries($transactionId);

        $totalFees = '0';
        $totalAdjustments = '0';
        $breakdown = [];

        foreach ($entries as $entry) {
            $fee = $entry['total_fee'] ?? '0';

            if ($entry['event_type'] === self::EVENT_SETTLE) {
                $totalFees = bcadd($totalFees, $fee, 2);
                $breakdown = array_merge($breakdown, $entry['breakdown'] ?? []);
            } else {
                $totalAdjustments = bcadd($totalAdjustments, $fee, 2);
            }
        }

        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'total_fees' => $totalFees,
            'total_adjustments' => $totalAdjustments,
            'net_fees' => bcadd($totalFees, $totalAdjustments, 2),
            'entries_count' => count($entries),
            'breakdown' => $breakdown
        ];
    }

    /**
     * Integrate with PricingEngine result
     *
     * Takes PricingEngine output and calculates channel fees.
     *
     * @param array $pricingResult PricingEngine::priceDocument result
     * @param array $policy Fee policy
     * @param array $options Options
     * @return array Combined pricing + fees
     */
    public static function withPricing(
        array $pricingResult,
        array $policy,
        array $options = []
    ): array {
        if (!$pricingResult['success']) {
            return $pricingResult;
        }

        # Map PricingEngine lines to FeeEngine input
        $feeInput = [
            'channel_key' => $policy['channel_key'] ?? 'default',
            'lines' => array_map(function($line) {
                return [
                    'line_id' => $line['line_id'],
                    'net' => $line['subtotal_net'],
                    'tax' => $line['subtotal_tax'],
                    'gross' => $line['subtotal_total'],
                    'quantity' => $line['quantity']
                ];
            }, $pricingResult['lines']),
            'order' => [
                'net' => $pricingResult['totals']['total_net'] ?? $pricingResult['totals_after_discount']['total_net'],
                'tax' => $pricingResult['totals']['total_tax'] ?? $pricingResult['totals_after_discount']['total_tax'],
                'gross' => $pricingResult['totals']['total_final'] ?? $pricingResult['totals_after_discount']['total_final']
            ]
        ];

        # Calculate fees
        $feeResult = FeeEngine::calculate($feeInput, $policy, $options);

        if (!$feeResult['success']) {
            return [
                'success' => true,
                'pricing' => $pricingResult,
                'fees' => null,
                'fees_error' => $feeResult['error_message']
            ];
        }

        # Calculate margin after fees
        $marginSummary = $pricingResult['margin_summary'] ?? null;
        $marginAfterFees = null;

        if ($marginSummary && isset($marginSummary['total_gross_margin'])) {
            $marginAfterFees = [
                'gross_margin_before_fees' => $marginSummary['total_gross_margin'],
                'channel_fees' => $feeResult['total_fee'],
                'gross_margin_after_fees' => bcsub(
                    $marginSummary['total_gross_margin'],
                    $feeResult['total_fee'],
                    $options['precision'] ?? 2
                )
            ];
        }

        return [
            'success' => true,
            'pricing' => $pricingResult,
            'fees' => [
                'total_fee' => $feeResult['total_fee'],
                'currency' => $feeResult['currency'],
                'breakdown' => $feeResult['breakdown'],
                'allocation' => $feeResult['allocation']
            ],
            'margin_after_fees' => $marginAfterFees,
            'meta' => [
                'pricing_version' => $pricingResult['meta']['version'] ?? null,
                'fee_version' => $feeResult['meta']['version'],
                'fee_signature' => $feeResult['meta']['signature']
            ]
        ];
    }

    # =========================================================================
    # HELPERS
    # =========================================================================

    /**
     * Build fees summary with descriptive names
     */
    private static function buildFeesSummary(array $feeResult): array
    {
        $summary = [];

        foreach ($feeResult['breakdown'] as $comp) {
            $summary[] = [
                'id' => $comp['component_id'],
                'name' => $comp['component_name'] ?? $comp['component_id'],
                'type' => $comp['component_type'],
                'amount' => $comp['amount'],
                'tags' => $comp['tags'] ?? [],
                'applied_to' => $comp['scope'],
                'details' => [
                    'base' => $comp['base_used'],
                    'rate' => $comp['rate'] ?? null,
                    'fixed' => $comp['fixed'] ?? null,
                    'tier' => $comp['tier_selected'] ?? null,
                    'cap' => $comp['caps_applied'] ?? null
                ]
            ];
        }

        return $summary;
    }

    /**
     * Build error response
     */
    private static function buildError(string $code, string $message): array
    {
        return [
            'success' => false,
            'error_code' => $code,
            'error_message' => $message
        ];
    }
}
