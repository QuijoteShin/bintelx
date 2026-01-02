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
#
# @version 1.0.0

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
    const VERSION = '1.0.0';

    # Event types
    const EVENT_SETTLE = 'SETTLE';      # Initial fee calculation
    const EVENT_ADJUST = 'ADJUST';      # Partial adjustment
    const EVENT_REFUND = 'REFUND';      # Refund (reversal)
    const EVENT_CHARGEBACK = 'CHARGEBACK'; # Chargeback

    # Status
    const STATUS_ACTIVE = 'active';
    const STATUS_ADJUSTED = 'adjusted';
    const STATUS_REVERSED = 'reversed';

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
     * @param string $originalEntryId Original ledger entry ID
     * @param array $adjustment Adjustment data
     * @param array $callbacks Storage callbacks
     * @return array Adjustment result
     */
    public static function adjust(
        string $originalEntryId,
        array $adjustment,
        array $callbacks
    ): array {
        # Load original entry
        $loadEntry = $callbacks['load_entry'] ?? null;
        if (!$loadEntry) {
            return self::buildError('MISSING_CALLBACK', 'load_entry callback required');
        }

        $original = $loadEntry($originalEntryId);
        if (!$original) {
            return self::buildError('ENTRY_NOT_FOUND', "Entry '$originalEntryId' not found");
        }

        # Calculate adjustment amount
        $adjustmentAmount = $adjustment['amount'] ?? '0';
        $adjustmentType = $adjustment['type'] ?? self::EVENT_ADJUST;
        $reason = $adjustment['reason'] ?? null;

        # For refunds, calculate proportional fee adjustment
        if ($adjustmentType === self::EVENT_REFUND) {
            $originalTotal = $original['total_fee'] ?? '0';
            $originalBase = $original['input_snapshot']['order_totals']['net'] ?? '0';

            if (bccomp($originalBase, '0', 2) > 0) {
                # Proportional: (refund_amount / original_base) * original_fee
                $proportion = bcdiv($adjustmentAmount, $originalBase, 8);
                $feeAdjustment = bcmul($proportion, $originalTotal, 2);
                $feeAdjustment = bcmul($feeAdjustment, '-1', 2); # Negative for reversal
            } else {
                $feeAdjustment = '0';
            }
        } else {
            $feeAdjustment = $adjustment['fee_amount'] ?? '0';
        }

        # Build adjustment entry
        $adjustmentEntry = [
            'entry_id' => null,
            'transaction_id' => $original['transaction_id'],
            'parent_entry_id' => $originalEntryId,
            'channel_key' => $original['channel_key'],
            'event_type' => $adjustmentType,
            'status' => self::STATUS_ACTIVE,
            'as_of' => date('Y-m-d H:i:s'),
            'currency' => $original['currency'],
            'total_fee' => $feeAdjustment,
            'adjustment_base' => $adjustmentAmount,
            'reason' => $reason,
            'original_snapshot' => [
                'entry_id' => $originalEntryId,
                'total_fee' => $original['total_fee'],
                'signature' => $original['signature']
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
            'running_total' => bcadd($original['total_fee'], $feeAdjustment, 2)
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
