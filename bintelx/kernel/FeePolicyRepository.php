<?php
# bintelx/kernel/FeePolicyRepository.php
# Repository for loading fee policies from database
#
# Provides:
#   - Load policy by channel_key with effective dating
#   - Convert DB format to FeeEngine policy format
#   - Cache support for performance
#   - Scope filtering (tenant-specific policies)
#
# @version 1.0.0

namespace bX;

/**
 * FeePolicyRepository - Load fee policies from normalized tables
 *
 * Converts database records to FeeEngine-compatible policy arrays.
 * Supports effective dating and scope-based filtering.
 */
final class FeePolicyRepository
{
    const VERSION = '1.0.0';

    # Cache namespace (transparente: Swoole\Table o static array via bX\Cache)
    private const CACHE_NS = 'global:fee:policy';
    private const CACHE_TTL = 3600; # 1h

    /**
     * Load active policy for a channel
     *
     * @param string $channelKey Channel identifier
     * @param string|null $asOf Date for effective dating (Y-m-d), null = today
     * @param int|null $scopeId Scope entity ID for tenant filtering
     * @return array|null FeeEngine-compatible policy or null
     */
    public static function loadByChannel(
        string $channelKey,
        ?string $asOf = null,
        ?int $scopeId = null
    ): ?array {
        $asOf = $asOf ?? date('Y-m-d');
        $cacheKey = "{$channelKey}:{$asOf}:{$scopeId}";

        return Cache::getOrSet(self::CACHE_NS, $cacheKey, self::CACHE_TTL, function() use ($channelKey, $asOf, $scopeId) {
            $policy = null;

            # Tenant-specific policy first
            if ($scopeId !== null) {
                $policy = self::findPolicy($channelKey, $asOf, $scopeId);
            }

            # Fallback to global tenant policy
            if (!$policy && !empty(Tenant::globalIds())) {
                $policy = self::findPolicy($channelKey, $asOf, Tenant::globalIds()[0]);
            }

            if (!$policy) {
                return null;
            }

            return self::convertToFeeEngineFormat($policy);
        });
    }

    /**
     * Load policy by ID
     *
     * @param int $policyId
     * @return array|null
     */
    public static function loadById(int $policyId): ?array
    {
        $policy = null;
        CONN::dml(
            "SELECT * FROM fee_policies WHERE fee_policy_id = :id AND is_active = 1",
            [':id' => $policyId],
            function($row) use (&$policy) {
                $policy = $row;
                return false;
            }
        );

        if (!$policy) {
            return null;
        }

        return self::convertToFeeEngineFormat($policy);
    }

    /**
     * List all active policies
     *
     * @param string|null $channelKey Filter by channel
     * @return array List of policy summaries
     */
    public static function listActive(?string $channelKey = null): array
    {
        $policies = [];
        $query = "SELECT fee_policy_id, policy_key, channel_key, version, name, valid_from, valid_to, scope_entity_id
                  FROM fee_policies
                  WHERE is_active = 1
                    AND valid_from <= CURDATE()
                    AND (valid_to IS NULL OR valid_to >= CURDATE())";
        $params = [];

        if ($channelKey !== null) {
            $query .= " AND channel_key = :channel";
            $params[':channel'] = $channelKey;
        }

        # Tenant scope filter (admin sees all, non-admin sees own + global)
        $query = Tenant::applySql($query, 'scope_entity_id', [], $params);

        $query .= " ORDER BY channel_key, valid_from DESC";

        CONN::dml($query, $params, function($row) use (&$policies) {
            $policies[] = $row;
        });

        return $policies;
    }

    /**
     * Clear cache (useful for testing or after policy updates)
     */
    public static function clearCache(): void
    {
        Cache::flush(self::CACHE_NS);
        Cache::notifyChannel(self::CACHE_NS);
    }

    # =========================================================================
    # PRIVATE HELPERS
    # =========================================================================

    /**
     * Build FeeEngine-compatible base_spec from DB fields
     *
     * Converts comma-separated field names to FeeEngine format:
     *   - Single field: returns string directly ('net', 'gross')
     *   - Multiple fields: builds recursive add expression tree
     *
     * BUSINESS RULE: Multiple fields are summed together.
     * Example: 'net,shipping' -> fee calculated on (net + shipping)
     *
     * @param array $fields Array of field names ['net'], ['net','shipping'], etc.
     * @return string|array String for single field, expression tree for multiple
     */
    private static function buildBaseSpec(array $fields): string|array
    {
        $fields = array_filter($fields); # Remove empty

        if (empty($fields)) {
            return 'net'; # Fallback to net if empty
        }

        if (count($fields) === 1) {
            return $fields[0]; # Single field: return string
        }

        # Multiple fields: build add expression tree
        # Example: ['net','tax','shipping'] becomes:
        # add(add(net, tax), shipping)
        return self::buildAddExpression($fields);
    }

    /**
     * Build recursive add expression for multiple fields
     *
     * @param array $fields Fields to add together
     * @return array Expression tree compatible with FeeEngine::evaluateBaseExpressionSafe
     */
    private static function buildAddExpression(array $fields): array
    {
        if (count($fields) === 1) {
            return ['op' => 'field', 'field' => $fields[0]];
        }

        $first = array_shift($fields);
        return [
            'op' => 'add',
            'left' => ['op' => 'field', 'field' => $first],
            'right' => count($fields) === 1
                ? ['op' => 'field', 'field' => $fields[0]]
                : self::buildAddExpression($fields)
        ];
    }

    /**
     * Find policy matching criteria
     */
    private static function findPolicy(string $channelKey, string $asOf, int $scopeId): ?array
    {
        $policy = null;

        $query = "SELECT * FROM fee_policies
                  WHERE channel_key = :channel
                    AND is_active = 1
                    AND valid_from <= :as_of
                    AND (valid_to IS NULL OR valid_to >= :as_of)
                    AND scope_entity_id = :scope";
        $params = [':channel' => $channelKey, ':as_of' => $asOf, ':scope' => $scopeId];

        $query .= " ORDER BY version DESC LIMIT 1";

        CONN::dml($query, $params, function($row) use (&$policy) {
            $policy = $row;
            return false;
        });

        return $policy;
    }

    /**
     * Convert DB policy to FeeEngine format
     */
    private static function convertToFeeEngineFormat(array $policy): array
    {
        $policyId = (int)$policy['fee_policy_id'];

        # Load components
        $components = [];
        $componentIndex = [];

        CONN::dml(
            "SELECT * FROM fee_policy_components
             WHERE fee_policy_id = :id AND is_active = 1
             ORDER BY precedence, fee_policy_component_id",
            [':id' => $policyId],
            function($row) use (&$components, &$componentIndex) {
                $row['tags'] = [];
                $row['tiers'] = [];
                $row['conditions'] = [];
                $row['cap_targets'] = [];
                $row['line_selector'] = null;
                $componentIndex[$row['fee_policy_component_id']] = count($components);
                $components[] = $row;
            }
        );

        # Load tags
        CONN::dml(
            "SELECT t.fee_policy_component_id, t.tag
             FROM fee_policy_component_tags t
             JOIN fee_policy_components c ON t.fee_policy_component_id = c.fee_policy_component_id
             WHERE c.fee_policy_id = :id",
            [':id' => $policyId],
            function($row) use (&$components, &$componentIndex) {
                $idx = $componentIndex[$row['fee_policy_component_id']] ?? null;
                if ($idx !== null) {
                    $components[$idx]['tags'][] = $row['tag'];
                }
            }
        );

        # Load tiers
        CONN::dml(
            "SELECT t.fee_policy_component_id, t.tier_min, t.tier_max, t.tier_rate, t.tier_fixed
             FROM fee_policy_tiers t
             JOIN fee_policy_components c ON t.fee_policy_component_id = c.fee_policy_component_id
             WHERE c.fee_policy_id = :id
             ORDER BY t.tier_order",
            [':id' => $policyId],
            function($row) use (&$components, &$componentIndex) {
                $idx = $componentIndex[$row['fee_policy_component_id']] ?? null;
                if ($idx !== null) {
                    $tier = [
                        'min' => $row['tier_min'],
                        'max' => $row['tier_max']
                    ];
                    if ($row['tier_rate'] !== null) {
                        # Convert decimal to percentage (same as rate)
                        $tier['rate'] = bcmul($row['tier_rate'], '100', 6);
                    }
                    if ($row['tier_fixed'] !== null) {
                        $tier['fixed'] = $row['tier_fixed'];
                    }
                    $components[$idx]['tiers'][] = $tier;
                }
            }
        );

        # Load conditions
        CONN::dml(
            "SELECT cn.fee_policy_component_id, cn.field, cn.operator, cn.value, cn.condition_group
             FROM fee_policy_conditions cn
             JOIN fee_policy_components c ON cn.fee_policy_component_id = c.fee_policy_component_id
             WHERE c.fee_policy_id = :id",
            [':id' => $policyId],
            function($row) use (&$components, &$componentIndex) {
                $idx = $componentIndex[$row['fee_policy_component_id']] ?? null;
                if ($idx !== null) {
                    $components[$idx]['conditions'][] = [
                        'field' => $row['field'],
                        'operator' => $row['operator'],
                        'value' => $row['value']
                    ];
                }
            }
        );

        # Load cap targets
        CONN::dml(
            "SELECT ct.fee_policy_component_id, ct.target_type, ct.target_value
             FROM fee_policy_cap_targets ct
             JOIN fee_policy_components c ON ct.fee_policy_component_id = c.fee_policy_component_id
             WHERE c.fee_policy_id = :id",
            [':id' => $policyId],
            function($row) use (&$components, &$componentIndex) {
                $idx = $componentIndex[$row['fee_policy_component_id']] ?? null;
                if ($idx !== null) {
                    $type = $row['target_type'];
                    $value = $row['target_value'];

                    # Map to FeeEngine targets format
                    if (!isset($components[$idx]['cap_targets']['component_ids'])) {
                        $components[$idx]['cap_targets'] = [
                            'component_ids' => [],
                            'tags_any' => [],
                            'types' => [],
                            'scopes' => []
                        ];
                    }

                    switch ($type) {
                        case 'component_id':
                            $components[$idx]['cap_targets']['component_ids'][] = $value;
                            break;
                        case 'tag':
                            $components[$idx]['cap_targets']['tags_any'][] = $value;
                            break;
                        case 'type':
                            $components[$idx]['cap_targets']['types'][] = $value;
                            break;
                        case 'scope':
                            $components[$idx]['cap_targets']['scopes'][] = $value;
                            break;
                    }
                }
            }
        );

        # Load line selectors
        CONN::dml(
            "SELECT ls.fee_policy_component_id, ls.selector_mode, ls.field, ls.operator, ls.value, ls.logic_type
             FROM fee_policy_line_selectors ls
             JOIN fee_policy_components c ON ls.fee_policy_component_id = c.fee_policy_component_id
             WHERE c.fee_policy_id = :id",
            [':id' => $policyId],
            function($row) use (&$components, &$componentIndex) {
                $idx = $componentIndex[$row['fee_policy_component_id']] ?? null;
                if ($idx !== null) {
                    if ($components[$idx]['line_selector'] === null) {
                        $components[$idx]['line_selector'] = [
                            'mode' => $row['selector_mode'],
                            'where' => [],
                            'any_of' => []
                        ];
                    }

                    $cond = [
                        'field' => $row['field'],
                        'operator' => $row['operator'],
                        'value' => $row['value']
                    ];

                    if ($row['logic_type'] === 'any_of') {
                        $components[$idx]['line_selector']['any_of'][] = $cond;
                    } else {
                        $components[$idx]['line_selector']['where'][] = $cond;
                    }
                }
            }
        );

        # Convert components to FeeEngine format
        $feeComponents = [];
        foreach ($components as $comp) {
            $feeComp = [
                'component_id' => $comp['component_id'],
                'component_name' => $comp['component_name'],
                'type' => $comp['component_type'],
                'scope' => $comp['scope'],
                'precedence' => (int)$comp['precedence'],
                'tags' => $comp['tags']
            ];

            # Base spec conversion from DB format to FeeEngine format
            # DB stores comma-separated fields: 'net', 'gross', 'net,shipping', etc.
            # FeeEngine expects:
            #   - Simple string for single field: 'net', 'gross'
            #   - Expression tree for multiple fields: ['op'=>'add', 'left'=>..., 'right'=>...]
            # CAVEAT: Multiple fields are summed (add). For subtract use explicit ops in DB.
            $baseFields = array_map('trim', explode(',', $comp['base_fields'] ?? 'net'));
            $feeComp['base_spec'] = self::buildBaseSpec($baseFields);

            # Type-specific fields
            # RATE CONVERSION: DB stores decimal (0.13 = 13%), FeeEngine expects percentage (13.00)
            # FeeEngine does: bcmul($base, bcdiv($rate, '100', 6), ...)
            # So we multiply by 100 to convert 0.13 → 13.00
            switch ($comp['component_type']) {
                case 'rate':
                    $feeComp['rate'] = bcmul($comp['rate'] ?? '0', '100', 6);
                    break;

                case 'rate_pp':
                    $feeComp['pp'] = bcmul($comp['pp'] ?? '0', '100', 6);
                    break;

                case 'fixed_unit':
                case 'fixed_order':
                    $feeComp['fixed'] = $comp['fixed'];
                    break;

                case 'tier':
                    $feeComp['tier_by'] = $comp['tier_by'];
                    $feeComp['tiers'] = $comp['tiers'];
                    break;

                case 'cap':
                    if ($comp['cap_min'] !== null) $feeComp['min'] = $comp['cap_min'];
                    if ($comp['cap_max'] !== null) $feeComp['max'] = $comp['cap_max'];
                    if (!empty($comp['cap_targets']['component_ids']) ||
                        !empty($comp['cap_targets']['tags_any']) ||
                        !empty($comp['cap_targets']['types']) ||
                        !empty($comp['cap_targets']['scopes'])) {
                        $feeComp['targets'] = $comp['cap_targets'];
                    }
                    break;

                case 'override':
                    if ($comp['override_excludes']) {
                        $feeComp['excludes'] = explode(',', $comp['override_excludes']);
                    }
                    if ($comp['override_reason']) {
                        $feeComp['override_reason'] = $comp['override_reason'];
                    }
                    break;
            }

            # Conditions
            if (!empty($comp['conditions'])) {
                $feeComp['conditions'] = $comp['conditions'];
            }

            # Line selector
            if ($comp['line_selector'] !== null) {
                $feeComp['line_selector'] = $comp['line_selector'];
            }

            # Refund behavior
            if (!$comp['refundable']) {
                $feeComp['refund'] = [
                    'refundable' => false,
                    'behavior' => $comp['refund_behavior']
                ];
            }

            # Allocation method
            if ($comp['allocation_method']) {
                $feeComp['allocation_method'] = $comp['allocation_method'];
            }

            $feeComponents[] = $feeComp;
        }

        $result = [
            'policy_key' => $policy['policy_key'],
            'channel_key' => $policy['channel_key'],
            'version' => (int)$policy['version'],
            'name' => $policy['name'],
            'currency' => $policy['currency'],
            'precision' => (int)$policy['precision_scale'],
            'components' => $feeComponents,
            '_meta' => [
                'fee_policy_id' => $policyId,
                'valid_from' => $policy['valid_from'],
                'valid_to' => $policy['valid_to'],
                'scope_entity_id' => $policy['scope_entity_id']
            ]
        ];

        # Generate canonical policy_hash using FeeEngine
        $result['policy_hash'] = FeeEngine::generatePolicyHash($result);

        return $result;
    }

    /**
     * Validate no overlapping policies for same channel/scope
     *
     * @param string $channelKey
     * @param int|null $scopeId
     * @param string $validFrom
     * @param string|null $validTo
     * @param int|null $excludePolicyId Exclude this policy (for updates)
     * @return array ['valid' => bool, 'conflicts' => array]
     */
    public static function validateNoOverlap(
        string $channelKey,
        int $scopeId,
        string $validFrom,
        ?string $validTo,
        ?int $excludePolicyId = null
    ): array {
        $conflicts = [];

        $query = "SELECT fee_policy_id, policy_key, valid_from, valid_to
                  FROM fee_policies
                  WHERE channel_key = :channel
                    AND is_active = 1
                    AND scope_entity_id = :scope
                    AND (
                        (valid_from <= :from AND (valid_to IS NULL OR valid_to >= :from))
                        OR (valid_from <= :to_check AND (valid_to IS NULL OR valid_to >= :to_check))
                        OR (valid_from >= :from AND (valid_to IS NULL OR valid_to <= :to_check))
                    )";

        $params = [
            ':channel' => $channelKey,
            ':scope' => $scopeId,
            ':from' => $validFrom,
            ':to_check' => $validTo ?? '9999-12-31'
        ];

        if ($excludePolicyId !== null) {
            $query .= " AND fee_policy_id != :exclude";
            $params[':exclude'] = $excludePolicyId;
        }

        CONN::dml($query, $params, function($row) use (&$conflicts) {
            $conflicts[] = $row;
        });

        return [
            'valid' => empty($conflicts),
            'conflicts' => $conflicts
        ];
    }

    /**
     * Create or update a policy with validation
     *
     * @param array $policyData Policy data
     * @return array ['success' => bool, 'fee_policy_id' => int|null, 'error' => string|null]
     */
    public static function savePolicy(array $policyData): array
    {
        $channelKey = $policyData['channel_key'] ?? null;
        $validFrom = $policyData['valid_from'] ?? date('Y-m-d');
        $validTo = $policyData['valid_to'] ?? null;
        $scopeId = (int)($policyData['scope_entity_id'] ?? 0);
        $policyId = $policyData['fee_policy_id'] ?? null;

        if (!$channelKey) {
            return ['success' => false, 'fee_policy_id' => null, 'error' => 'channel_key required'];
        }

        if ($scopeId <= 0) {
            return ['success' => false, 'fee_policy_id' => null, 'error' => 'scope_entity_id required'];
        }

        # Validate no overlap
        $validation = self::validateNoOverlap($channelKey, $scopeId, $validFrom, $validTo, $policyId);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'fee_policy_id' => null,
                'error' => 'Policy overlaps with existing: ' . json_encode($validation['conflicts'])
            ];
        }

        # Insert or update logic would go here
        # For now, return validation result
        return ['success' => true, 'fee_policy_id' => $policyId, 'error' => null];
    }
}
