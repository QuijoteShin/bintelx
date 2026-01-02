<?php
# bintelx/kernel/FeeStorageAdapter.php
# Storage adapter for FeeEngine/FeeLedger persistence
#
# Provides:
#   - Persist fee calculations to normalized tables
#   - Load entries by source object or entry ID
#   - Transaction support via CONN
#   - Efficient batch inserts
#
# Design:
#   - Stateless (all static methods)
#   - Uses CONN for DB operations
#   - Maps FeeEngine output to schema_fees.sql tables
#
# @version 1.0.0

namespace bX;

/**
 * FeeStorageAdapter - Persistence layer for FeeEngine calculations
 *
 * Maps FeeEngine::calculate() output to normalized database tables.
 * All methods are static; uses CONN for database operations.
 */
final class FeeStorageAdapter
{
    const VERSION = '1.0.0';

    # =========================================================================
    # PERSIST METHODS
    # =========================================================================

    /**
     * Persist a fee calculation result to database
     *
     * @param array $feeResult Output from FeeEngine::calculate()
     * @param array $source Source identification:
     *   - 'module': string (e.g., 'crm_labtronic')
     *   - 'object_type': string (e.g., 'order')
     *   - 'object_id': string (e.g., 'ORD-123')
     *   - 'scope_id': int|null (tenant scope)
     * @param array $policy The policy used (for snapshot)
     * @param array $options:
     *   - 'mode': 'SIMULATE'|'SETTLE' (default: 'SETTLE')
     *   - 'request_id': string|null (correlation ID)
     *   - 'use_transaction': bool (default: true)
     * @return array ['success' => bool, 'fee_entry_id' => int|null, 'error' => string|null]
     */
    public static function persist(
        array $feeResult,
        array $source,
        array $policy,
        array $options = []
    ): array {
        $mode = $options['mode'] ?? 'SETTLE';
        $requestId = $options['request_id'] ?? null;
        $useTransaction = $options['use_transaction'] ?? true;

        if (!$feeResult['success']) {
            return self::persistError($feeResult, $source, $policy, $options);
        }

        try {
            if ($useTransaction) {
                CONN::begin();
            }

            # 1. Insert fee_entries (header)
            $entryId = self::insertFeeEntry($feeResult, $source, $policy, $mode, $requestId);

            if (!$entryId) {
                throw new \Exception('Failed to insert fee_entry');
            }

            # 2. Insert components + tags
            self::insertComponents($entryId, $feeResult['breakdown'] ?? []);

            # 3. Insert lines
            self::insertLines($entryId, $feeResult['allocation'] ?? []);

            # 4. Insert line components
            self::insertLineComponents($entryId, $feeResult['allocation'] ?? []);

            # 5. Insert warnings
            self::insertWarnings($entryId, $feeResult['warnings'] ?? []);

            if ($useTransaction) {
                CONN::commit();
            }

            return [
                'success' => true,
                'fee_entry_id' => $entryId,
                'error' => null
            ];

        } catch (\Exception $e) {
            if ($useTransaction) {
                CONN::rollback();
            }
            Log::logError("FeeStorageAdapter::persist failed: " . $e->getMessage());
            return [
                'success' => false,
                'fee_entry_id' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Persist an adjustment (refund, chargeback)
     *
     * @param int $parentEntryId Original fee_entry_id
     * @param array $adjustResult Output from FeeLedger::adjust()
     * @param array $source Source identification
     * @param array $options
     * @return array
     */
    public static function persistAdjustment(
        int $parentEntryId,
        array $adjustResult,
        array $source,
        array $options = []
    ): array {
        $useTransaction = $options['use_transaction'] ?? true;

        if (!$adjustResult['success']) {
            return [
                'success' => false,
                'fee_entry_id' => null,
                'error' => $adjustResult['error_message'] ?? 'Adjustment failed'
            ];
        }

        try {
            if ($useTransaction) {
                CONN::begin();
            }

            $entry = $adjustResult['adjustment_entry'] ?? [];
            $refundPlan = $adjustResult['refund_plan'] ?? [];

            # 1. Insert adjustment entry
            $entryId = self::insertAdjustmentEntry($parentEntryId, $entry, $source, $options);

            if (!$entryId) {
                throw new \Exception('Failed to insert adjustment entry');
            }

            # 2. Insert refund plan
            if (!empty($refundPlan)) {
                self::insertRefundPlan($entryId, $refundPlan);
            }

            if ($useTransaction) {
                CONN::commit();
            }

            return [
                'success' => true,
                'fee_entry_id' => $entryId,
                'error' => null
            ];

        } catch (\Exception $e) {
            if ($useTransaction) {
                CONN::rollback();
            }
            Log::logError("FeeStorageAdapter::persistAdjustment failed: " . $e->getMessage());
            return [
                'success' => false,
                'fee_entry_id' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    # =========================================================================
    # LOAD METHODS
    # =========================================================================

    /**
     * Load fee entry by ID
     *
     * @param int $entryId
     * @param array $options:
     *   - 'include_breakdown': bool (default: true)
     *   - 'include_allocation': bool (default: true)
     *   - 'include_warnings': bool (default: false)
     * @return array|null Entry data or null if not found
     */
    public static function loadEntry(int $entryId, array $options = []): ?array
    {
        $includeBreakdown = $options['include_breakdown'] ?? true;
        $includeAllocation = $options['include_allocation'] ?? true;
        $includeWarnings = $options['include_warnings'] ?? false;

        # Load header
        $rows = CONN::dml(
            "SELECT * FROM fee_entries WHERE fee_entry_id = :id",
            [':id' => $entryId]
        );

        if (empty($rows)) {
            return null;
        }

        $entry = $rows[0];

        # Load breakdown
        if ($includeBreakdown) {
            $entry['breakdown'] = self::loadBreakdown($entryId);
        }

        # Load allocation
        if ($includeAllocation) {
            $entry['allocation'] = self::loadAllocation($entryId);
        }

        # Load warnings
        if ($includeWarnings) {
            $entry['warnings'] = self::loadWarnings($entryId);
        }

        # Load refund plan if adjustment
        if ($entry['mode'] === 'ADJUST') {
            $entry['refund_plan'] = self::loadRefundPlan($entryId);
        }

        return $entry;
    }

    /**
     * Load latest fee entry for a source object
     *
     * @param string $module
     * @param string $objectType
     * @param string $objectId
     * @param array $options
     * @return array|null
     */
    public static function loadLatest(
        string $module,
        string $objectType,
        string $objectId,
        array $options = []
    ): ?array {
        $rows = CONN::dml(
            "SELECT fee_entry_id FROM fee_entries
             WHERE source_module = :module
               AND source_object_type = :type
               AND source_object_id = :id
             ORDER BY created_at DESC
             LIMIT 1",
            [':module' => $module, ':type' => $objectType, ':id' => $objectId]
        );

        if (empty($rows)) {
            return null;
        }

        return self::loadEntry((int)$rows[0]['fee_entry_id'], $options);
    }

    /**
     * Load all entries for a source object
     *
     * @param string $module
     * @param string $objectType
     * @param string $objectId
     * @return array List of entries (headers only)
     */
    public static function loadAllForSource(
        string $module,
        string $objectType,
        string $objectId
    ): array {
        $entries = [];
        CONN::dml(
            "SELECT * FROM fee_entries
             WHERE source_module = :module
               AND source_object_type = :type
               AND source_object_id = :id
             ORDER BY created_at ASC",
            [':module' => $module, ':type' => $objectType, ':id' => $objectId],
            function($row) use (&$entries) {
                $entries[] = $row;
            }
        );
        return $entries;
    }

    /**
     * Get running totals for a source object
     *
     * @param string $module
     * @param string $objectType
     * @param string $objectId
     * @return array ['settled_fees' => string, 'adjustment_fees' => string, 'net_fees' => string]
     */
    public static function getRunningTotals(
        string $module,
        string $objectType,
        string $objectId
    ): array {
        $result = ['settled_fees' => '0', 'adjustment_fees' => '0'];
        CONN::dml(
            "SELECT
                SUM(CASE WHEN mode = 'SETTLE' THEN total_fee ELSE 0 END) as settled_fees,
                SUM(CASE WHEN mode = 'ADJUST' THEN COALESCE(adjustment_fee, 0) ELSE 0 END) as adjustment_fees
             FROM fee_entries
             WHERE source_module = :module
               AND source_object_type = :type
               AND source_object_id = :id
               AND success = 1",
            [':module' => $module, ':type' => $objectType, ':id' => $objectId],
            function($row) use (&$result) {
                $result['settled_fees'] = $row['settled_fees'] ?? '0';
                $result['adjustment_fees'] = $row['adjustment_fees'] ?? '0';
            }
        );

        $result['net_fees'] = bcadd($result['settled_fees'], $result['adjustment_fees'], 6);
        return $result;
    }

    /**
     * Count entries for a source object (efficient count query)
     *
     * @param string $module
     * @param string $objectType
     * @param string $objectId
     * @param string|null $mode Filter by mode (SETTLE, ADJUST, SIMULATE)
     * @return int
     */
    public static function countForSource(
        string $module,
        string $objectType,
        string $objectId,
        ?string $mode = null
    ): int {
        $count = 0;
        $query = "SELECT COUNT(*) as cnt FROM fee_entries
                  WHERE source_module = :module
                    AND source_object_type = :type
                    AND source_object_id = :id";
        $params = [':module' => $module, ':type' => $objectType, ':id' => $objectId];

        if ($mode !== null) {
            $query .= " AND mode = :mode";
            $params[':mode'] = $mode;
        }

        CONN::dml($query, $params, function($row) use (&$count) {
            $count = (int)($row['cnt'] ?? 0);
        });

        return $count;
    }

    /**
     * Iterate over entries for a source object (memory efficient)
     *
     * @param string $module
     * @param string $objectType
     * @param string $objectId
     * @param callable $callback Function($entry) called for each entry header
     * @param array $options Filter options
     */
    public static function iterateForSource(
        string $module,
        string $objectType,
        string $objectId,
        callable $callback,
        array $options = []
    ): void {
        $query = "SELECT * FROM fee_entries
                  WHERE source_module = :module
                    AND source_object_type = :type
                    AND source_object_id = :id";
        $params = [':module' => $module, ':type' => $objectType, ':id' => $objectId];

        if (isset($options['mode'])) {
            $query .= " AND mode = :mode";
            $params[':mode'] = $options['mode'];
        }
        if (isset($options['success_only']) && $options['success_only']) {
            $query .= " AND success = 1";
        }

        $query .= " ORDER BY created_at " . (($options['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC');

        CONN::dml($query, $params, $callback);
    }

    /**
     * Find entries by policy (for policy drift detection)
     *
     * @param string $policyKey
     * @param int|null $version
     * @param callable $callback Function($entry) for each match
     */
    public static function findByPolicy(
        string $policyKey,
        ?int $version,
        callable $callback
    ): void {
        $query = "SELECT * FROM fee_entries WHERE policy_key = :key";
        $params = [':key' => $policyKey];

        if ($version !== null) {
            $query .= " AND policy_version = :version";
            $params[':version'] = $version;
        }

        $query .= " ORDER BY created_at DESC";

        CONN::dml($query, $params, $callback);
    }

    /**
     * Get component fee totals by tag (for reports)
     *
     * @param string $module
     * @param string $objectType
     * @param string $objectId
     * @return array ['tag' => 'total_amount']
     */
    public static function getTotalsByTag(
        string $module,
        string $objectType,
        string $objectId
    ): array {
        $totals = [];
        CONN::dml(
            "SELECT t.tag, SUM(c.amount) as total_amount
             FROM fee_entries e
             JOIN fee_entry_components c ON e.fee_entry_id = c.fee_entry_id
             JOIN fee_entry_component_tags t ON c.fee_entry_id = t.fee_entry_id
                                             AND c.component_id = t.component_id
             WHERE e.source_module = :module
               AND e.source_object_type = :type
               AND e.source_object_id = :id
               AND e.success = 1
               AND c.applied = 1
             GROUP BY t.tag",
            [':module' => $module, ':type' => $objectType, ':id' => $objectId],
            function($row) use (&$totals) {
                $totals[$row['tag']] = $row['total_amount'];
            }
        );
        return $totals;
    }

    # =========================================================================
    # PRIVATE INSERT HELPERS
    # =========================================================================

    private static function insertFeeEntry(
        array $feeResult,
        array $source,
        array $policy,
        string $mode,
        ?string $requestId
    ): ?int {
        $meta = $feeResult['meta'] ?? [];

        $result = CONN::nodml(
            "INSERT INTO fee_entries (
                created_at, mode, currency, channel_key,
                policy_key, policy_version, policy_hash, signature,
                precision_scale, rounding_mode, request_id,
                total_fee, success, warnings_count,
                source_module, source_object_type, source_object_id, source_scope_id
            ) VALUES (
                NOW(), :mode, :currency, :channel,
                :policy_key, :policy_ver, :policy_hash, :signature,
                :precision, :rounding, :request_id,
                :total_fee, 1, :warnings_count,
                :module, :obj_type, :obj_id, :scope_id
            )",
            [
                ':mode' => $mode,
                ':currency' => $feeResult['currency'] ?? 'CLP',
                ':channel' => $source['channel_key'] ?? $policy['channel_key'] ?? 'default',
                ':policy_key' => $policy['policy_key'] ?? 'unknown',
                ':policy_ver' => $policy['version'] ?? 1,
                ':policy_hash' => $meta['policy_hash'] ?? '',
                ':signature' => $meta['signature'] ?? '',
                ':precision' => $meta['precision'] ?? 0,
                ':rounding' => 'HALF_UP',
                ':request_id' => $requestId,
                ':total_fee' => $feeResult['total_fee'] ?? '0',
                ':warnings_count' => count($feeResult['warnings'] ?? []),
                ':module' => $source['module'],
                ':obj_type' => $source['object_type'],
                ':obj_id' => $source['object_id'],
                ':scope_id' => $source['scope_id'] ?? null
            ]
        );

        return $result['success'] ? (int)$result['last_id'] : null;
    }

    private static function insertAdjustmentEntry(
        int $parentEntryId,
        array $entry,
        array $source,
        array $options
    ): ?int {
        $result = CONN::nodml(
            "INSERT INTO fee_entries (
                created_at, mode, currency, channel_key,
                policy_key, policy_version, policy_hash, signature,
                precision_scale, rounding_mode,
                total_fee, success,
                source_module, source_object_type, source_object_id, source_scope_id,
                parent_fee_entry_id, adjustment_type, adjustment_amount, adjustment_fee, refund_mode, running_total
            ) VALUES (
                NOW(), 'ADJUST', :currency, :channel,
                :policy_key, :policy_ver, :policy_hash, :signature,
                :precision, 'HALF_UP',
                :total_fee, 1,
                :module, :obj_type, :obj_id, :scope_id,
                :parent_id, :adj_type, :adj_amount, :adj_fee, :refund_mode, :running_total
            )",
            [
                ':currency' => $entry['currency'] ?? $options['currency'] ?? 'CLP',
                ':channel' => $entry['channel_key'] ?? 'default',
                ':policy_key' => $entry['original_snapshot']['policy_key'] ?? 'unknown',
                ':policy_ver' => $entry['original_snapshot']['policy_version'] ?? $options['policy_version'] ?? 1,
                ':policy_hash' => $entry['original_snapshot']['policy_hash'] ?? '',
                ':signature' => $entry['original_snapshot']['signature'] ?? '',
                ':precision' => $options['precision'] ?? 0,
                ':total_fee' => $entry['total_fee'] ?? '0',
                ':module' => $source['module'],
                ':obj_type' => $source['object_type'],
                ':obj_id' => $source['object_id'],
                ':scope_id' => $source['scope_id'] ?? null,
                ':parent_id' => $parentEntryId,
                ':adj_type' => $entry['event_type'] ?? 'REFUND',
                ':adj_amount' => $entry['adjustment_base'] ?? '0',
                ':adj_fee' => $entry['total_fee'] ?? '0',
                ':refund_mode' => $options['refund_mode'] ?? 'AUTO',
                ':running_total' => $options['running_total'] ?? null
            ]
        );

        return $result['success'] ? (int)$result['last_id'] : null;
    }

    private static function insertComponents(int $entryId, array $breakdown): void
    {
        foreach ($breakdown as $comp) {
            # Insert component
            CONN::nodml(
                "INSERT INTO fee_entry_components (
                    fee_entry_id, component_id, component_label, component_type, scope, precedence,
                    base_used, rate_or_pp, fixed_value,
                    tier_by, tier_selected_min, tier_selected_max, tier_selected_value,
                    cap_min, cap_max, cap_target_sum, cap_delta,
                    override_excludes, override_reason,
                    amount, applied, discard_reason,
                    applied_lines_count, excluded_lines_count
                ) VALUES (
                    :entry_id, :comp_id, :label, :type, :scope, :precedence,
                    :base_used, :rate, :fixed,
                    :tier_by, :tier_min, :tier_max, :tier_val,
                    :cap_min, :cap_max, :cap_sum, :cap_delta,
                    :override_excludes, :override_reason,
                    :amount, :applied, :discard,
                    :applied_count, :excluded_count
                )",
                [
                    ':entry_id' => $entryId,
                    ':comp_id' => $comp['component_id'],
                    ':label' => $comp['component_name'] ?? null,
                    ':type' => $comp['component_type'],
                    ':scope' => $comp['scope'] ?? 'line',
                    ':precedence' => $comp['precedence'] ?? null,
                    ':base_used' => $comp['base_used'] ?? '0',
                    ':rate' => $comp['rate'] ?? $comp['pp'] ?? null,
                    ':fixed' => $comp['fixed'] ?? null,
                    ':tier_by' => $comp['tier_by'] ?? null,
                    ':tier_min' => $comp['tier_selected']['min'] ?? null,
                    ':tier_max' => $comp['tier_selected']['max'] ?? null,
                    ':tier_val' => $comp['tier_selected']['fixed'] ?? $comp['tier_selected']['rate'] ?? null,
                    ':cap_min' => $comp['cap_min'] ?? null,
                    ':cap_max' => $comp['cap_max'] ?? null,
                    ':cap_sum' => $comp['cap_target_sum'] ?? null,
                    ':cap_delta' => $comp['cap_delta'] ?? null,
                    ':override_excludes' => isset($comp['excludes']) ? implode(',', $comp['excludes']) : null,
                    ':override_reason' => $comp['override_reason'] ?? null,
                    ':amount' => $comp['amount'] ?? '0',
                    ':applied' => ($comp['applied'] ?? true) ? 1 : 0,
                    ':discard' => $comp['discard_reason'] ?? null,
                    ':applied_count' => count($comp['applied_line_ids'] ?? []),
                    ':excluded_count' => count($comp['skipped_lines'] ?? [])
                ]
            );

            # Insert tags
            $tags = $comp['tags'] ?? [];
            foreach ($tags as $tag) {
                CONN::nodml(
                    "INSERT INTO fee_entry_component_tags (fee_entry_id, component_id, tag)
                     VALUES (:entry_id, :comp_id, :tag)",
                    [':entry_id' => $entryId, ':comp_id' => $comp['component_id'], ':tag' => $tag]
                );
            }
        }
    }

    private static function insertLines(int $entryId, array $allocation): void
    {
        foreach ($allocation as $line) {
            CONN::nodml(
                "INSERT INTO fee_entry_lines (fee_entry_id, line_id, line_fee_total, reconciled, reconcile_delta)
                 VALUES (:entry_id, :line_id, :total, :reconciled, :delta)",
                [
                    ':entry_id' => $entryId,
                    ':line_id' => $line['line_id'],
                    ':total' => $line['fee_amount'] ?? '0',
                    ':reconciled' => isset($line['reconcile_delta']) ? 1 : 0,
                    ':delta' => $line['reconcile_delta'] ?? null
                ]
            );
        }
    }

    private static function insertLineComponents(int $entryId, array $allocation): void
    {
        foreach ($allocation as $line) {
            $components = $line['components'] ?? [];
            foreach ($components as $comp) {
                CONN::nodml(
                    "INSERT INTO fee_entry_line_components (
                        fee_entry_id, line_id, component_id,
                        base_used, rate_applied, amount, proration_method, proration_weight
                    ) VALUES (
                        :entry_id, :line_id, :comp_id,
                        :base, :rate, :amount, :proration, :weight
                    )",
                    [
                        ':entry_id' => $entryId,
                        ':line_id' => $line['line_id'],
                        ':comp_id' => $comp['component_id'],
                        ':base' => $comp['base_used'] ?? null,
                        ':rate' => $comp['rate'] ?? null,
                        ':amount' => $comp['amount'] ?? '0',
                        ':proration' => $comp['proration_method'] ?? null,
                        ':weight' => $comp['proration_weight'] ?? null
                    ]
                );
            }
        }
    }

    private static function insertWarnings(int $entryId, array $warnings): void
    {
        foreach ($warnings as $warning) {
            $code = is_array($warning) ? ($warning['code'] ?? 'UNKNOWN') : 'UNKNOWN';
            $message = is_array($warning) ? ($warning['message'] ?? $warning) : $warning;
            $compId = is_array($warning) ? ($warning['component_id'] ?? null) : null;

            CONN::nodml(
                "INSERT INTO fee_entry_warnings (fee_entry_id, warning_code, warning_message, component_id)
                 VALUES (:entry_id, :code, :message, :comp_id)",
                [':entry_id' => $entryId, ':code' => $code, ':message' => $message, ':comp_id' => $compId]
            );
        }
    }

    private static function insertRefundPlan(int $entryId, array $refundPlan): void
    {
        foreach ($refundPlan as $plan) {
            if (isset($plan['mode']) && $plan['mode'] === 'MANUAL') {
                continue; # Skip manual entries
            }

            CONN::nodml(
                "INSERT INTO fee_refund_plan (
                    fee_entry_id, component_id,
                    original_fee, refunded_ratio, refunded_fee,
                    refundable, behavior, reason_code
                ) VALUES (
                    :entry_id, :comp_id,
                    :original, :ratio, :refunded,
                    :refundable, :behavior, :reason
                )",
                [
                    ':entry_id' => $entryId,
                    ':comp_id' => $plan['component_id'],
                    ':original' => $plan['original_fee'] ?? '0',
                    ':ratio' => $plan['refunded_ratio'] ?? '0',
                    ':refunded' => $plan['refunded_fee'] ?? '0',
                    ':refundable' => ($plan['refundable'] ?? true) ? 1 : 0,
                    ':behavior' => $plan['behavior'] ?? 'PROPORTIONAL',
                    ':reason' => $plan['reason_code'] ?? null
                ]
            );
        }
    }

    private static function persistError(
        array $feeResult,
        array $source,
        array $policy,
        array $options
    ): array {
        # Persist failed calculation for audit
        $result = CONN::nodml(
            "INSERT INTO fee_entries (
                created_at, mode, currency, channel_key,
                policy_key, policy_version, policy_hash, signature,
                precision_scale, total_fee, success, error_code, error_message,
                source_module, source_object_type, source_object_id, source_scope_id
            ) VALUES (
                NOW(), :mode, :currency, :channel,
                :policy_key, :policy_ver, '', '',
                0, 0, 0, :error_code, :error_msg,
                :module, :obj_type, :obj_id, :scope_id
            )",
            [
                ':mode' => $options['mode'] ?? 'SETTLE',
                ':currency' => $options['currency'] ?? $policy['currency'] ?? 'CLP',
                ':channel' => $source['channel_key'] ?? $policy['channel_key'] ?? 'default',
                ':policy_key' => $policy['policy_key'] ?? 'unknown',
                ':policy_ver' => $policy['version'] ?? 1,
                ':error_code' => $feeResult['error_code'] ?? 'UNKNOWN',
                ':error_msg' => $feeResult['error_message'] ?? '',
                ':module' => $source['module'],
                ':obj_type' => $source['object_type'],
                ':obj_id' => $source['object_id'],
                ':scope_id' => $source['scope_id'] ?? null
            ]
        );

        return [
            'success' => false,
            'fee_entry_id' => $result['success'] ? (int)$result['last_id'] : null,
            'error' => $feeResult['error_message'] ?? 'Unknown error'
        ];
    }

    # =========================================================================
    # PRIVATE LOAD HELPERS
    # =========================================================================

    private static function loadBreakdown(int $entryId): array
    {
        # Load components with callback
        $components = [];
        $componentIndex = [];
        CONN::dml(
            "SELECT * FROM fee_entry_components WHERE fee_entry_id = :id ORDER BY precedence, component_id",
            [':id' => $entryId],
            function($row) use (&$components, &$componentIndex) {
                $row['tags'] = []; # Initialize tags array
                $componentIndex[$row['component_id']] = count($components);
                $components[] = $row;
            }
        );

        # Load tags and merge directly
        CONN::dml(
            "SELECT component_id, tag FROM fee_entry_component_tags WHERE fee_entry_id = :id",
            [':id' => $entryId],
            function($row) use (&$components, &$componentIndex) {
                $compId = $row['component_id'];
                if (isset($componentIndex[$compId])) {
                    $components[$componentIndex[$compId]]['tags'][] = $row['tag'];
                }
            }
        );

        return $components;
    }

    private static function loadAllocation(int $entryId): array
    {
        # Load lines with callback
        $lines = [];
        $lineIndex = [];
        CONN::dml(
            "SELECT * FROM fee_entry_lines WHERE fee_entry_id = :id ORDER BY line_id",
            [':id' => $entryId],
            function($row) use (&$lines, &$lineIndex) {
                $row['components'] = []; # Initialize components array
                $lineIndex[$row['line_id']] = count($lines);
                $lines[] = $row;
            }
        );

        # Load line components and merge directly
        CONN::dml(
            "SELECT * FROM fee_entry_line_components WHERE fee_entry_id = :id",
            [':id' => $entryId],
            function($row) use (&$lines, &$lineIndex) {
                $lineId = $row['line_id'];
                if (isset($lineIndex[$lineId])) {
                    $lines[$lineIndex[$lineId]]['components'][] = $row;
                }
            }
        );

        return $lines;
    }

    private static function loadWarnings(int $entryId): array
    {
        $warnings = [];
        CONN::dml(
            "SELECT * FROM fee_entry_warnings WHERE fee_entry_id = :id",
            [':id' => $entryId],
            function($row) use (&$warnings) {
                $warnings[] = $row;
            }
        );
        return $warnings;
    }

    private static function loadRefundPlan(int $entryId): array
    {
        $plan = [];
        CONN::dml(
            "SELECT * FROM fee_refund_plan WHERE fee_entry_id = :id",
            [':id' => $entryId],
            function($row) use (&$plan) {
                $plan[] = $row;
            }
        );
        return $plan;
    }
}
