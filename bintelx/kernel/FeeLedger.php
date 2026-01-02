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
#   - P5: FeeStorageAdapter integration (optional)
#
# @version 1.2.0

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
    const VERSION = '1.2.0';

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

        # Build per-line refund map and calculate affected lines
        $lineRefundMap = [];
        $affectedLines = [];
        $unaffectedLines = [];
        $isPartialRefund = !empty($refundLines);

        if ($isPartialRefund) {
            foreach ($refundLines as $lr) {
                $lineId = $lr['line_id'];
                $lineRefundMap[$lineId] = $lr['amount'] ?? '0';
                $affectedLines[] = $lineId;
            }
            # Validate lines exist in allocation
            foreach ($affectedLines as $lineId) {
                $found = false;
                foreach ($allocation as $alloc) {
                    if ($alloc['line_id'] === $lineId) {
                        $found = true;
                        break;
                    }
                }
                if (!$found && $strict) {
                    return self::buildError(self::ERR_LINE_NOT_FOUND,
                        "Line '$lineId' not found in original allocation");
                }
            }
            # Determine unaffected lines
            foreach ($allocation as $alloc) {
                if (!in_array($alloc['line_id'], $affectedLines)) {
                    $unaffectedLines[] = $alloc['line_id'];
                }
            }
        } else {
            # All lines affected - global refund
            foreach ($allocation as $alloc) {
                $affectedLines[] = $alloc['line_id'];
            }
        }

        # Validate refund doesn't exceed original
        if ($strict && bccomp($refundAmount, $originalBase, $precision) > 0) {
            return self::buildError(self::ERR_EXCEEDS_ORIGINAL,
                "Refund amount $refundAmount exceeds original base $originalBase");
        }

        # Calculate global refund ratio (for full refunds)
        $globalRefundRatio = '0';
        if (bccomp($originalBase, '0', $precision) > 0) {
            $globalRefundRatio = bcdiv($refundAmount, $originalBase, 8);
        }

        # Build allocation lookup by line_id for partial refunds
        $allocationByLine = [];
        foreach ($allocation as $alloc) {
            $allocationByLine[$alloc['line_id']] = $alloc;
        }

        # Process each component
        $refundPlan = [];
        $totalFeeRefund = '0';

        foreach ($breakdown as $comp) {
            $compId = $comp['component_id'];
            $originalFee = $comp['amount'];
            $tags = $comp['tags'] ?? [];
            $scope = $comp['scope'] ?? 'line';

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
            $effectiveRatio = $globalRefundRatio;

            if ($isRefundable && $behavior !== self::REFUND_NONE) {
                if ($isPartialRefund) {
                    # P4 FIX: Calculate per-line refund for affected lines only
                    if ($scope === 'line') {
                        # Line-scope: sum refunds from affected lines only
                        $componentRefund = '0';
                        foreach ($affectedLines as $lineId) {
                            # Find this component's contribution to this line
                            $lineAlloc = $allocationByLine[$lineId] ?? null;
                            if (!$lineAlloc) continue;

                            $lineNet = '0'; # Original net for this line
                            foreach ($allocation as $a) {
                                if ($a['line_id'] === $lineId) {
                                    # Get the line's original net from components or breakdown
                                    foreach ($a['components'] ?? [] as $c) {
                                        if ($c['component_id'] === $compId) {
                                            $lineCompFee = $c['amount'] ?? '0';
                                            $lineRefundAmt = $lineRefundMap[$lineId] ?? '0';
                                            # Calculate ratio for this line
                                            $lineBase = $c['base_used'] ?? '0';
                                            if (bccomp($lineBase, '0', $precision) > 0) {
                                                $lineRatio = bcdiv($lineRefundAmt, $lineBase, 8);
                                            } else {
                                                $lineRatio = '1'; # Full refund if no base
                                            }
                                            $lineComponentRefund = bcmul($lineCompFee, $lineRatio, $precision);
                                            $componentRefund = bcadd($componentRefund, $lineComponentRefund, $precision);
                                            break;
                                        }
                                    }
                                    break;
                                }
                            }
                        }
                        $effectiveRatio = null; # Per-line calculated
                    } else {
                        # Order-scope: prorate by sum of affected line refunds / original order base
                        $totalRefundForAffected = '0';
                        foreach ($affectedLines as $lineId) {
                            $totalRefundForAffected = bcadd($totalRefundForAffected, $lineRefundMap[$lineId] ?? '0', $precision);
                        }
                        if (bccomp($originalBase, '0', $precision) > 0) {
                            $effectiveRatio = bcdiv($totalRefundForAffected, $originalBase, 8);
                        }
                        $componentRefund = bcmul($originalFee, $effectiveRatio, $precision);
                    }
                } else {
                    # Global refund: use global ratio
                    switch ($behavior) {
                        case self::REFUND_PROPORTIONAL:
                            $componentRefund = bcmul($originalFee, $globalRefundRatio, $precision);
                            break;

                        case self::REFUND_FIXED_ONLY:
                            # Only refund if component is fixed type
                            $compType = $comp['component_type'] ?? '';
                            if (in_array($compType, ['fixed_unit', 'fixed_order'])) {
                                $componentRefund = bcmul($originalFee, $globalRefundRatio, $precision);
                            }
                            break;
                    }
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
                'refunded_ratio' => $effectiveRatio ?? 'per_line',
                'refunded_fee' => $componentRefundNegative,
                'refundable' => $isRefundable,
                'behavior' => $isRefundable ? $behavior : self::REFUND_NONE,
                'affected_lines' => $isPartialRefund ? $affectedLines : null
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
                'refund_ratio' => $globalRefundRatio,
                'is_partial' => $isPartialRefund,
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

    # =========================================================================
    # P5: FeeStorageAdapter Integration
    # =========================================================================

    /**
     * Calculate and persist fees using FeeStorageAdapter
     *
     * Alternative to settle() that uses kernel tables directly.
     *
     * @param array $input CommissionInput
     * @param array $policy Fee policy
     * @param array $source Source identification:
     *   - 'module': string (e.g., 'crm_labtronic')
     *   - 'object_type': string (e.g., 'order')
     *   - 'object_id': string (e.g., 'ORD-123')
     *   - 'scope_id': int|null (tenant scope)
     *   - 'channel_key': string (optional, defaults from policy)
     * @param array $options Calculation options + storage options
     * @return array Result with fee_entry_id
     */
    public static function settleWithStorage(
        array $input,
        array $policy,
        array $source,
        array $options = []
    ): array {
        # Calculate fees
        $feeResult = FeeEngine::calculate($input, $policy, $options);

        if (!$feeResult['success']) {
            # Persist error for audit if requested
            if ($options['persist_errors'] ?? false) {
                FeeStorageAdapter::persist($feeResult, $source, $policy, [
                    'mode' => $options['mode'] ?? 'SETTLE'
                ]);
            }
            return $feeResult;
        }

        # Persist via adapter
        $persistResult = FeeStorageAdapter::persist(
            $feeResult,
            $source,
            $policy,
            [
                'mode' => $options['mode'] ?? 'SETTLE',
                'request_id' => $options['request_id'] ?? null,
                'use_transaction' => $options['use_transaction'] ?? true
            ]
        );

        if (!$persistResult['success']) {
            return self::buildError('STORAGE_ERROR', $persistResult['error']);
        }

        return [
            'success' => true,
            'fee_entry_id' => $persistResult['fee_entry_id'],
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
     * Register adjustment using FeeStorageAdapter
     *
     * Alternative to adjust() that uses kernel tables directly.
     *
     * @param int $parentEntryId Original fee_entry_id (from storage)
     * @param array $adjustment Adjustment data
     * @param array $source Source identification
     * @param array $options Options
     * @return array Adjustment result
     */
    public static function adjustWithStorage(
        int $parentEntryId,
        array $adjustment,
        array $source,
        array $options = []
    ): array {
        $strict = $options['strict'] ?? false;
        $precision = $options['precision'] ?? 2;

        # Load original from storage
        $original = FeeStorageAdapter::loadEntry($parentEntryId, [
            'include_breakdown' => true,
            'include_allocation' => true
        ]);

        if (!$original) {
            return self::buildError(self::ERR_ENTRY_NOT_FOUND, "Entry $parentEntryId not found");
        }

        # Map storage format to internal format
        $originalMapped = self::mapStorageToInternal($original);

        # Use existing adjust logic with mapped callbacks
        $callbacks = [
            'load_entry' => function($id) use ($originalMapped) {
                return $originalMapped;
            },
            'save_entry' => function($entry) {
                return null; # We'll persist separately
            },
            'update_entry' => function($id, $data) {
                # No-op for now
            }
        ];

        $adjustResult = self::adjust(
            (string)$parentEntryId,
            $adjustment,
            $callbacks,
            $options
        );

        if (!$adjustResult['success']) {
            return $adjustResult;
        }

        # Calculate running total
        $runningTotals = FeeStorageAdapter::getRunningTotals(
            $source['module'],
            $source['object_type'],
            $source['object_id']
        );
        $newRunningTotal = bcadd(
            $runningTotals['net_fees'],
            $adjustResult['fee_adjustment'],
            6
        );

        # Persist adjustment
        $persistResult = FeeStorageAdapter::persistAdjustment(
            $parentEntryId,
            $adjustResult,
            $source,
            [
                'precision' => $precision,
                'refund_mode' => $adjustment['mode'] ?? 'AUTO',
                'running_total' => $newRunningTotal,
                'use_transaction' => $options['use_transaction'] ?? true
            ]
        );

        if (!$persistResult['success']) {
            return self::buildError('STORAGE_ERROR', $persistResult['error']);
        }

        return [
            'success' => true,
            'fee_entry_id' => $persistResult['fee_entry_id'],
            'parent_entry_id' => $parentEntryId,
            'fee_adjustment' => $adjustResult['fee_adjustment'],
            'running_total' => $newRunningTotal,
            'refund_plan' => $adjustResult['refund_plan']
        ];
    }

    /**
     * Load entry from storage with full details
     *
     * @param int $entryId
     * @param array $options
     * @return array|null
     */
    public static function loadFromStorage(int $entryId, array $options = []): ?array
    {
        $entry = FeeStorageAdapter::loadEntry($entryId, $options);
        if (!$entry) {
            return null;
        }
        return self::mapStorageToInternal($entry);
    }

    /**
     * Load latest entry for a source object
     *
     * @param string $module
     * @param string $objectType
     * @param string $objectId
     * @param array $options
     * @return array|null
     */
    public static function loadLatestFromStorage(
        string $module,
        string $objectType,
        string $objectId,
        array $options = []
    ): ?array {
        $entry = FeeStorageAdapter::loadLatest($module, $objectType, $objectId, $options);
        if (!$entry) {
            return null;
        }
        return self::mapStorageToInternal($entry);
    }

    /**
     * Get running totals from storage
     *
     * @param string $module
     * @param string $objectType
     * @param string $objectId
     * @return array
     */
    public static function getRunningTotalsFromStorage(
        string $module,
        string $objectType,
        string $objectId
    ): array {
        return FeeStorageAdapter::getRunningTotals($module, $objectType, $objectId);
    }

    /**
     * Iterate entries from storage (memory efficient)
     *
     * @param string $module
     * @param string $objectType
     * @param string $objectId
     * @param callable $callback
     * @param array $options
     */
    public static function iterateFromStorage(
        string $module,
        string $objectType,
        string $objectId,
        callable $callback,
        array $options = []
    ): void {
        FeeStorageAdapter::iterateForSource(
            $module,
            $objectType,
            $objectId,
            function($row) use ($callback) {
                $callback(self::mapStorageToInternal($row));
            },
            $options
        );
    }

    /**
     * Get fee totals by tag from storage
     *
     * @param string $module
     * @param string $objectType
     * @param string $objectId
     * @return array ['tag' => 'total_amount']
     */
    public static function getTotalsByTagFromStorage(
        string $module,
        string $objectType,
        string $objectId
    ): array {
        return FeeStorageAdapter::getTotalsByTag($module, $objectType, $objectId);
    }

    /**
     * Map storage row format to internal FeeLedger format
     */
    private static function mapStorageToInternal(array $row): array
    {
        # Handle breakdown mapping (DB columns → internal format)
        $breakdown = [];
        foreach ($row['breakdown'] ?? [] as $comp) {
            $breakdown[] = [
                'component_id' => $comp['component_id'],
                'component_name' => $comp['component_label'] ?? $comp['component_id'],
                'component_type' => $comp['component_type'],
                'scope' => $comp['scope'],
                'amount' => $comp['amount'],
                'base_used' => $comp['base_used'],
                'rate' => $comp['rate_or_pp'],
                'fixed' => $comp['fixed_value'],
                'tags' => $comp['tags'] ?? [],
                'applied' => (bool)($comp['applied'] ?? true),
                'discard_reason' => $comp['discard_reason'] ?? null,
                'tier_by' => $comp['tier_by'] ?? null,
                'tier_selected' => $comp['tier_selected_value'] ? [
                    'min' => $comp['tier_selected_min'],
                    'max' => $comp['tier_selected_max'],
                    'value' => $comp['tier_selected_value']
                ] : null,
                'refund' => [
                    'refundable' => !in_array('non_refundable', $comp['tags'] ?? []),
                    'behavior' => self::REFUND_PROPORTIONAL
                ]
            ];
        }

        # Handle allocation mapping
        $allocation = [];
        foreach ($row['allocation'] ?? [] as $line) {
            $allocation[] = [
                'line_id' => $line['line_id'],
                'fee_amount' => $line['line_fee_total'],
                'reconcile_delta' => $line['reconcile_delta'] ?? null,
                'components' => array_map(function($c) {
                    return [
                        'component_id' => $c['component_id'],
                        'amount' => $c['amount'],
                        'base_used' => $c['base_used'],
                        'rate' => $c['rate_applied']
                    ];
                }, $line['components'] ?? [])
            ];
        }

        return [
            'entry_id' => $row['fee_entry_id'],
            'transaction_id' => $row['source_object_id'],
            'channel_key' => $row['channel_key'],
            'event_type' => $row['mode'],
            'status' => $row['success'] ? self::STATUS_ACTIVE : 'failed',
            'currency' => $row['currency'],
            'total_fee' => $row['total_fee'],
            'breakdown' => $breakdown,
            'allocation' => $allocation,
            'warnings' => $row['warnings'] ?? [],
            'policy_snapshot' => [
                'policy_key' => $row['policy_key'],
                'version' => $row['policy_version']
            ],
            'input_snapshot' => [
                'order_totals' => ['net' => $row['adjustment_amount'] ?? '0']
            ],
            'signature' => $row['signature'],
            'created_at' => $row['created_at'],
            # Adjustment-specific
            'parent_entry_id' => $row['parent_fee_entry_id'] ?? null,
            'adjustment_type' => $row['adjustment_type'] ?? null,
            'adjustment_amount' => $row['adjustment_amount'] ?? null,
            'adjustment_fee' => $row['adjustment_fee'] ?? null,
            'running_total' => $row['running_total'] ?? null,
            'refund_plan' => $row['refund_plan'] ?? []
        ];
    }
}
