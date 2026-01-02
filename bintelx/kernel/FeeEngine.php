<?php
# bintelx/kernel/FeeEngine.php
# Domain-agnostic commission/fee calculation engine
#
# Pure mathematical fee calculation with NO floats, NO side effects.
# Supports multi-component stacks, tiers, overrides, caps, allocation.
# ALL calculations use bcmath (string-based).
#
# @version 1.0.0

namespace bX;

/**
 * FeeEngine - Pure mathematical fee calculator
 *
 * Stateless engine for commission/fee calculations:
 * - Multi-component stacks (%, fixed, tiers, +pp, caps)
 * - Policy-based with version/effective dating
 * - Explainability (breakdown + explain_plan)
 * - Order-to-line allocation with reconciliation
 * - bcmath ONLY (all amounts as strings, NO floats)
 * - HALF_UP rounding (pure bcmath)
 * - Deterministic signatures (ALCOA+)
 * - No database, no HTTP, no business logic
 *
 * Usage:
 *   $result = FeeEngine::calculate($input, $policy);
 *   $result = FeeEngine::simulate($input, $policies); # Auto-select policy
 *
 * @package bX
 * @version 1.0
 */
final class FeeEngine
{
    const VERSION = '1.5.0'; # P3: line_selector, P2: Canonical policy hash, P1: Cap targets

    # =========================================================================
    # ERROR TYPES & CODES
    # =========================================================================
    const ERROR_INPUT = 'input';
    const ERROR_POLICY = 'policy';
    const ERROR_CALCULATION = 'calculation';
    const ERROR_STRICT = 'strict';  # P0: Strict mode errors

    const ERR_MISSING_CHANNEL = 'MISSING_CHANNEL_KEY';
    const ERR_MISSING_LINES = 'MISSING_LINES';
    const ERR_INVALID_LINE = 'INVALID_LINE';
    const ERR_NO_POLICY = 'NO_MATCHING_POLICY';
    const ERR_INVALID_COMPONENT = 'INVALID_COMPONENT_TYPE';
    const ERR_INVALID_BASE_SPEC = 'INVALID_BASE_SPEC';
    const ERR_TIER_NOT_FOUND = 'TIER_NOT_FOUND';
    const ERR_DIVISION_ZERO = 'DIVISION_BY_ZERO';
    const ERR_FIELD_MISSING = 'FIELD_MISSING';  # P0: Missing required field
    const ERR_CAP_TARGETS_EMPTY = 'CAP_TARGETS_EMPTY';  # P1: Empty targets in strict
    const ERR_CAP_NO_MATCH = 'CAP_TARGETS_NO_MATCH';    # P1: No targets matched
    const ERR_CAP_INVALID = 'CAP_INVALID_BOUNDS';       # P1: min > max

    # P3: Line selector errors
    const ERR_LINE_SELECTOR_EMPTY = 'LINE_SELECTOR_EMPTY';
    const ERR_LINE_SELECTOR_FIELD_MISSING = 'LINE_SELECTOR_FIELD_MISSING';
    const ERR_LINE_SELECTOR_BAD_OPERATOR = 'LINE_SELECTOR_BAD_OPERATOR';
    const ERR_LINE_SELECTOR_NO_MATCH = 'LINE_SELECTOR_NO_MATCH';
    const ERR_LINE_SELECTOR_NOT_ALLOWED_FOR_ORDER = 'LINE_SELECTOR_NOT_ALLOWED_FOR_ORDER';

    # =========================================================================
    # COMPONENT TYPES
    # =========================================================================
    const COMP_RATE = 'rate';              # % sobre base
    const COMP_RATE_PP = 'rate_pp';        # +pp adicionales
    const COMP_FIXED_UNIT = 'fixed_unit';  # Fijo por unidad
    const COMP_FIXED_ORDER = 'fixed_order'; # Fijo por orden
    const COMP_TIER = 'tier';              # Escalonado
    const COMP_CAP = 'cap';                # Mínimo/máximo
    const COMP_OVERRIDE = 'override';      # Excepción/anulación

    # =========================================================================
    # SCOPES
    # =========================================================================
    const SCOPE_LINE = 'line';
    const SCOPE_ORDER = 'order';

    # =========================================================================
    # BASE SPEC FIELDS (available for BaseSpec evaluation)
    # =========================================================================
    const BASE_NET = 'net';                # Neto (sin IVA)
    const BASE_TAX = 'tax';                # IVA
    const BASE_GROSS = 'gross';            # Bruto (net + tax)
    const BASE_SHIPPING = 'shipping';      # Shipping
    const BASE_DISCOUNT = 'discount';      # Descuentos aplicados
    const BASE_QUANTITY = 'quantity';      # Cantidad

    # =========================================================================
    # ALLOCATION STRATEGIES
    # =========================================================================
    const ALLOC_BY_NET = 'by_net';         # Proporcional al neto
    const ALLOC_BY_GROSS = 'by_gross';     # Proporcional al bruto
    const ALLOC_BY_QUANTITY = 'by_quantity'; # Por cantidad
    const ALLOC_EQUAL = 'equal';           # Igual entre líneas

    # =========================================================================
    # LINE SELECTOR MODES (P3)
    # =========================================================================
    const SELECTOR_INCLUDE = 'include';    # Aplica solo a líneas que matchean
    const SELECTOR_EXCLUDE = 'exclude';    # Aplica a todas excepto las que matchean

    # =========================================================================
    # MAIN API
    # =========================================================================

    /**
     * Calculate fees for an input using a specific policy
     *
     * Pure calculation - no policy selection logic.
     *
     * @param array $input CommissionInput structure
     * @param array $policy Policy with components
     * @param array $options Calculation options
     * @return array CommissionResult
     */
    public static function calculate(
        array $input,
        array $policy,
        array $options = []
    ): array {
        $precision = (int)($options['precision'] ?? 2);
        $internalPrecision = $precision + 6;
        $strict = (bool)($options['strict'] ?? $policy['strict'] ?? false);  # P0: Strict mode

        # 1. VALIDATE INPUT
        $validation = self::validateInput($input);
        if (!$validation['valid']) {
            return self::buildError(
                $validation['error_type'],
                $validation['error_code'],
                $validation['error_message']
            );
        }

        # 2. NORMALIZE AMOUNTS (build AmountModel)
        $amountModel = self::normalizeAmounts($input, $precision);

        # 3. RESOLVE ELIGIBLE COMPONENTS
        $resolution = self::resolveComponents($policy, $input, $amountModel);
        $eligibleComponents = $resolution['eligible'];
        $discardedComponents = $resolution['discarded'];

        # 4. EVALUATE COMPONENTS
        $componentResults = [];
        $totalFee = '0';
        $lineAllocations = [];
        $warnings = [];

        # Initialize line allocations
        foreach ($amountModel['lines'] as $lineId => $lineData) {
            $lineAllocations[$lineId] = [
                'line_id' => $lineId,
                'fee_amount' => '0',
                'components' => []
            ];
        }

        # Process components by precedence
        usort($eligibleComponents, fn($a, $b) => ($a['precedence'] ?? 100) <=> ($b['precedence'] ?? 100));

        foreach ($eligibleComponents as $component) {
            $compResult = self::evaluateComponent(
                $component,
                $amountModel,
                $input,
                $componentResults,
                $precision,
                $internalPrecision,
                $strict  # P0: Pass strict mode
            );

            if (!$compResult['success']) {
                # P0: In strict mode, propagate errors as failures
                if ($strict && ($compResult['error_type'] ?? '') === self::ERROR_STRICT) {
                    return $compResult;  # Fail entire calculation
                }
                $warnings[] = $compResult['warning'] ?? $compResult['error_message'];
                continue;
            }

            $componentResults[] = $compResult;

            # Accumulate fee
            $totalFee = bcadd($totalFee, $compResult['amount'], $internalPrecision);

            # Track allocations
            if ($compResult['scope'] === self::SCOPE_LINE) {
                foreach ($compResult['line_amounts'] as $lineId => $lineAmount) {
                    $lineAllocations[$lineId]['fee_amount'] = bcadd(
                        $lineAllocations[$lineId]['fee_amount'],
                        $lineAmount,
                        $internalPrecision
                    );
                    $lineAllocations[$lineId]['components'][] = [
                        'component_id' => $compResult['component_id'],
                        'amount' => $lineAmount
                    ];
                }
            }
        }

        # 5. ALLOCATE ORDER-LEVEL FEES TO LINES
        $orderLevelFees = array_filter($componentResults, fn($c) => $c['scope'] === self::SCOPE_ORDER);

        if (!empty($orderLevelFees)) {
            $allocationResult = self::allocateOrderFees(
                $orderLevelFees,
                $amountModel,
                $options['allocation_strategy'] ?? self::ALLOC_BY_NET,
                $precision,
                $internalPrecision
            );

            foreach ($allocationResult['allocations'] as $lineId => $alloc) {
                $lineAllocations[$lineId]['fee_amount'] = bcadd(
                    $lineAllocations[$lineId]['fee_amount'],
                    $alloc['amount'],
                    $internalPrecision
                );
                $lineAllocations[$lineId]['components'] = array_merge(
                    $lineAllocations[$lineId]['components'],
                    $alloc['components']
                );
            }
        }

        # 6. RECONCILIATION
        $totalFee = self::bcRound($totalFee, $precision);
        $reconciliation = self::reconcileAllocations($lineAllocations, $totalFee, $precision);

        # Round line allocations
        foreach ($lineAllocations as &$alloc) {
            $alloc['fee_amount'] = self::bcRound($alloc['fee_amount'], $precision);
        }
        unset($alloc);

        # 7. BUILD BREAKDOWN
        $breakdown = [];
        $componentLineCoverage = [];  # P3: Track line coverage per component

        foreach ($componentResults as $comp) {
            $entry = [
                'component_id' => $comp['component_id'],
                'component_name' => $comp['component_name'] ?? $comp['component_id'],
                'component_type' => $comp['component_type'],
                'tags' => $comp['tags'] ?? [],
                'scope' => $comp['scope'],
                'base_used' => $comp['base_used'],
                'rate' => $comp['rate'] ?? null,
                'fixed' => $comp['fixed'] ?? null,
                'tier_selected' => $comp['tier_selected'] ?? null,
                'caps_applied' => $comp['caps_applied'] ?? null,
                'amount' => self::bcRound($comp['amount'], $precision),
                'line_ref' => $comp['line_ref'] ?? null
            ];

            # P1: Add cap-specific fields
            if (($comp['component_type'] ?? '') === self::COMP_CAP) {
                $entry['targets'] = $comp['targets'] ?? null;
                $entry['targets_matched'] = $comp['targets_matched'] ?? [];
                $entry['targets_matched_count'] = $comp['targets_matched_count'] ?? 0;
                $entry['target_sum_before'] = $comp['target_sum_before'] ?? '0';
            }

            # P3: Add line selector fields
            $appliedLineIds = $comp['applied_line_ids'] ?? [];
            $skippedLines = $comp['skipped_lines'] ?? [];
            if (!empty($appliedLineIds) || !empty($skippedLines)) {
                $entry['applied_line_ids'] = $appliedLineIds;
                $entry['skipped_lines'] = $skippedLines;
                if (isset($comp['selector_mode'])) {
                    $entry['selector_mode'] = $comp['selector_mode'];
                }
            }

            # P3: Build coverage stats
            $componentLineCoverage[$comp['component_id']] = [
                'applied_count' => count($appliedLineIds),
                'excluded_count' => count($skippedLines)
            ];

            $breakdown[] = $entry;
        }

        # 8. EXPLAIN PLAN
        # P1: Detect if any cap used targeting
        $hasCapTargeting = false;
        foreach ($componentResults as $cr) {
            if (($cr['component_type'] ?? '') === self::COMP_CAP && !empty($cr['targets'])) {
                $hasCapTargeting = true;
                break;
            }
        }

        $explainPlan = [
            'policy_key' => $policy['policy_key'] ?? 'unknown',
            'policy_version' => $policy['version'] ?? 1,
            'effective_from' => $policy['effective_from'] ?? null,
            'components_eligible' => count($eligibleComponents),
            'components_discarded' => $discardedComponents,
            'evaluation_order' => array_column($componentResults, 'component_id'),
            'cap_targeting' => $hasCapTargeting,  # P1: true if targeted caps used
            'component_line_coverage' => $componentLineCoverage  # P3: line coverage per component
        ];

        # 9. SIGNATURE
        $signature = self::generateSignature($input, $policy, $options);

        return [
            'success' => true,
            'total_fee' => $totalFee,
            'currency' => $input['currency'] ?? 'CLP',
            'breakdown' => $breakdown,
            'allocation' => array_values($lineAllocations),
            'explain_plan' => $explainPlan,
            'reconciliation' => $reconciliation,
            'warnings' => $warnings,
            'meta' => [
                'version' => self::VERSION,
                'signature' => $signature,
                'mode' => $input['mode'] ?? 'SIMULATE',
                'as_of' => $input['as_of'] ?? date('Y-m-d H:i:s'),
                'precision' => $precision,
                'policy_hash' => self::generatePolicyHash($policy, 2),
                'hash_version' => 2
            ]
        ];
    }

    /**
     * Simulate fees with automatic policy selection
     *
     * Selects best matching policy from array based on context and as_of.
     *
     * @param array $input CommissionInput
     * @param array $policies Array of policies
     * @param array $options Calculation options
     * @return array CommissionResult
     */
    public static function simulate(
        array $input,
        array $policies,
        array $options = []
    ): array {
        $asOf = $input['as_of'] ?? date('Y-m-d H:i:s');
        $channelKey = $input['channel_key'] ?? null;

        if (!$channelKey) {
            return self::buildError(
                self::ERROR_INPUT,
                self::ERR_MISSING_CHANNEL,
                'channel_key is required for policy selection'
            );
        }

        # Select matching policy
        $selectedPolicy = self::selectPolicy($policies, $channelKey, $asOf, $input['context'] ?? []);

        if (!$selectedPolicy) {
            return self::buildError(
                self::ERROR_POLICY,
                self::ERR_NO_POLICY,
                "No matching policy for channel '$channelKey' as of $asOf"
            );
        }

        return self::calculate($input, $selectedPolicy, $options);
    }

    # =========================================================================
    # VALIDATION
    # =========================================================================

    /**
     * Validate input structure
     */
    private static function validateInput(array $input): array
    {
        if (empty($input['lines']) && empty($input['order'])) {
            return [
                'valid' => false,
                'error_type' => self::ERROR_INPUT,
                'error_code' => self::ERR_MISSING_LINES,
                'error_message' => 'Input must have lines or order data'
            ];
        }

        # Validate lines if present
        if (!empty($input['lines'])) {
            foreach ($input['lines'] as $idx => $line) {
                if (!isset($line['net']) && !isset($line['gross'])) {
                    return [
                        'valid' => false,
                        'error_type' => self::ERROR_INPUT,
                        'error_code' => self::ERR_INVALID_LINE,
                        'error_message' => "Line $idx: must have 'net' or 'gross'"
                    ];
                }
            }
        }

        return ['valid' => true];
    }

    # =========================================================================
    # AMOUNT NORMALIZATION (AmountModel)
    # =========================================================================

    /**
     * Normalize input amounts to consistent AmountModel
     *
     * Creates a normalized structure with all bases pre-computed.
     */
    private static function normalizeAmounts(array $input, int $precision): array
    {
        $internalPrecision = $precision + 6;
        $lines = [];
        $orderTotals = [
            'net' => '0',
            'tax' => '0',
            'gross' => '0',
            'shipping' => '0',
            'discount' => '0',
            'quantity' => '0'
        ];

        foreach ($input['lines'] ?? [] as $idx => $line) {
            $lineId = $line['line_id'] ?? "LINE-$idx";
            $quantity = (string)($line['quantity'] ?? '1');

            # Compute bases
            $net = $line['net'] ?? '0';
            $tax = $line['tax'] ?? '0';
            $gross = $line['gross'] ?? bcadd($net, $tax, $internalPrecision);
            $shipping = $line['shipping'] ?? '0';
            $discount = $line['discount'] ?? '0';

            # If only gross provided, estimate net (assume tax rate if available)
            if (!isset($line['net']) && isset($line['gross'])) {
                $taxRate = $line['tax_rate'] ?? '0';
                if (bccomp($taxRate, '0', 4) > 0) {
                    $divisor = bcadd('1', bcdiv($taxRate, '100', 6), 6);
                    $net = bcdiv($gross, $divisor, $internalPrecision);
                    $tax = bcsub($gross, $net, $internalPrecision);
                } else {
                    $net = $gross;
                    $tax = '0';
                }
            }

            $lines[$lineId] = [
                'line_id' => $lineId,
                'quantity' => $quantity,
                'net' => $net,
                'tax' => $tax,
                'gross' => $gross,
                'shipping' => $shipping,
                'discount' => $discount,
                'category' => $line['category'] ?? null,
                'flags' => $line['flags'] ?? [],
                'meta' => $line['meta'] ?? []
            ];

            # Accumulate order totals
            $orderTotals['net'] = bcadd($orderTotals['net'], $net, $internalPrecision);
            $orderTotals['tax'] = bcadd($orderTotals['tax'], $tax, $internalPrecision);
            $orderTotals['gross'] = bcadd($orderTotals['gross'], $gross, $internalPrecision);
            $orderTotals['shipping'] = bcadd($orderTotals['shipping'], $shipping, $internalPrecision);
            $orderTotals['discount'] = bcadd($orderTotals['discount'], $discount, $internalPrecision);
            $orderTotals['quantity'] = bcadd($orderTotals['quantity'], $quantity, 0);
        }

        # Override with explicit order totals if provided
        if (isset($input['order'])) {
            foreach (['net', 'tax', 'gross', 'shipping', 'discount'] as $field) {
                if (isset($input['order'][$field])) {
                    $orderTotals[$field] = (string)$input['order'][$field];
                }
            }
        }

        return [
            'lines' => $lines,
            'order' => $orderTotals,
            'line_count' => count($lines)
        ];
    }

    # =========================================================================
    # POLICY SELECTION & RESOLUTION
    # =========================================================================

    /**
     * Select best matching policy from array
     */
    private static function selectPolicy(
        array $policies,
        string $channelKey,
        string $asOf,
        array $context = []
    ): ?array {
        $candidates = [];

        foreach ($policies as $policy) {
            # Match channel
            if (($policy['channel_key'] ?? '') !== $channelKey) {
                continue;
            }

            # Check effective dating
            $effectiveFrom = $policy['effective_from'] ?? '1970-01-01';
            $effectiveTo = $policy['effective_to'] ?? '9999-12-31';

            if ($asOf < $effectiveFrom || $asOf > $effectiveTo) {
                continue;
            }

            # Check global conditions
            if (!self::evaluateConditions($policy['conditions'] ?? [], $context)) {
                continue;
            }

            $candidates[] = [
                'policy' => $policy,
                'priority' => $policy['priority'] ?? 0,
                'effective_from' => $effectiveFrom
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        # Sort by priority (higher first), then by effective_from (newer first)
        usort($candidates, function($a, $b) {
            $pCmp = $b['priority'] <=> $a['priority'];
            if ($pCmp !== 0) return $pCmp;
            return $b['effective_from'] <=> $a['effective_from'];
        });

        return $candidates[0]['policy'];
    }

    /**
     * Resolve eligible components from policy
     *
     * Evaluates conditions and handles overrides/exclusions.
     */
    private static function resolveComponents(
        array $policy,
        array $input,
        array $amountModel
    ): array {
        $components = $policy['components'] ?? [];
        $context = array_merge(
            $input['context'] ?? [],
            ['order' => $amountModel['order']]
        );

        $eligible = [];
        $discarded = [];
        $overrides = [];

        # First pass: collect overrides
        foreach ($components as $comp) {
            if (($comp['type'] ?? '') === self::COMP_OVERRIDE) {
                if (self::evaluateConditions($comp['conditions'] ?? [], $context, $amountModel)) {
                    $overrides = array_merge($overrides, $comp['excludes'] ?? []);
                }
            }
        }

        # Second pass: filter by conditions and overrides
        foreach ($components as $comp) {
            $compId = $comp['component_id'] ?? uniqid('COMP-');
            $comp['component_id'] = $compId;

            # Skip if overridden
            if (in_array($compId, $overrides)) {
                $discarded[] = [
                    'component_id' => $compId,
                    'reason' => 'override_excluded'
                ];
                continue;
            }

            # Skip override components (already processed)
            if (($comp['type'] ?? '') === self::COMP_OVERRIDE) {
                continue;
            }

            # Evaluate conditions
            if (!self::evaluateConditions($comp['conditions'] ?? [], $context, $amountModel)) {
                $discarded[] = [
                    'component_id' => $compId,
                    'reason' => 'condition_failed',
                    'conditions' => $comp['conditions'] ?? []
                ];
                continue;
            }

            $eligible[] = $comp;
        }

        return [
            'eligible' => $eligible,
            'discarded' => $discarded
        ];
    }

    /**
     * Evaluate conditions against context
     */
    private static function evaluateConditions(
        array $conditions,
        array $context,
        ?array $amountModel = null
    ): bool {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? 'eq';
            $value = $condition['value'] ?? null;

            # Get field value from context
            $fieldValue = self::getNestedValue($context, $field);

            # Also check amountModel if provided
            if ($fieldValue === null && $amountModel !== null) {
                $fieldValue = self::getNestedValue($amountModel, $field);
            }

            $result = self::compareValues($fieldValue, $operator, $value);

            # AND logic: all conditions must pass
            if (!$result) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get nested value from array using dot notation
     */
    private static function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Compare values with operator
     *
     * P0: Normalize values for safe bccomp operations (null/empty → '0')
     */
    private static function compareValues($fieldValue, string $operator, $compareValue): bool
    {
        switch ($operator) {
            case 'eq':
                return $fieldValue == $compareValue;
            case 'neq':
                return $fieldValue != $compareValue;
            case 'gt':
                return bccomp(self::bcNormalize($fieldValue), self::bcNormalize($compareValue), 6) > 0;
            case 'gte':
                return bccomp(self::bcNormalize($fieldValue), self::bcNormalize($compareValue), 6) >= 0;
            case 'lt':
                return bccomp(self::bcNormalize($fieldValue), self::bcNormalize($compareValue), 6) < 0;
            case 'lte':
                return bccomp(self::bcNormalize($fieldValue), self::bcNormalize($compareValue), 6) <= 0;
            case 'in':
                return is_array($compareValue) && in_array($fieldValue, $compareValue);
            case 'not_in':
                return is_array($compareValue) && !in_array($fieldValue, $compareValue);
            case 'contains':
                return is_array($fieldValue) && in_array($compareValue, $fieldValue);
            case 'has_flag':
                return is_array($fieldValue) && in_array($compareValue, $fieldValue);
            default:
                return false;
        }
    }

    /**
     * Normalize value for bcmath operations
     *
     * P0: Handles null, empty string, non-numeric safely
     */
    private static function bcNormalize($value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        $str = (string)$value;
        # Remove whitespace
        $str = trim($str);
        # Validate numeric (allow negative and decimals)
        if (!preg_match('/^-?\d*\.?\d+$/', $str)) {
            return '0';
        }
        return $str;
    }

    # =========================================================================
    # LINE SELECTOR (P3)
    # =========================================================================

    /**
     * Build line applicability map for a component
     *
     * Returns array of line_id => applies (bool)
     *
     * @param array $component Component with optional line_selector
     * @param array $amountModel Normalized amount model
     * @param array $context Input context
     * @param bool $strict Strict mode
     * @return array ['success' => bool, 'map' => [], 'skipped' => [], 'error_*' => ...]
     */
    private static function buildLineApplicabilityMap(
        array $component,
        array $amountModel,
        array $context,
        bool $strict
    ): array {
        $selector = $component['line_selector'] ?? null;
        $scope = $component['scope'] ?? self::SCOPE_LINE;
        $compId = $component['component_id'] ?? 'unknown';

        # No selector = all lines apply
        if ($selector === null) {
            $map = [];
            foreach ($amountModel['lines'] as $line) {
                $map[$line['line_id']] = true;
            }
            return [
                'success' => true,
                'map' => $map,
                'skipped' => []
            ];
        }

        # line_selector not allowed for scope=order
        if ($scope === self::SCOPE_ORDER) {
            if ($strict) {
                return [
                    'success' => false,
                    'error_type' => self::ERROR_STRICT,
                    'error_code' => self::ERR_LINE_SELECTOR_NOT_ALLOWED_FOR_ORDER,
                    'error_message' => "line_selector not allowed for scope=order in component '$compId'"
                ];
            }
            # Non-strict: ignore selector, apply to all
            $map = [];
            foreach ($amountModel['lines'] as $line) {
                $map[$line['line_id']] = true;
            }
            return [
                'success' => true,
                'map' => $map,
                'skipped' => [],
                'warning' => "line_selector ignored for scope=order in component '$compId'"
            ];
        }

        # Validate selector structure
        $mode = $selector['mode'] ?? self::SELECTOR_INCLUDE;
        $where = $selector['where'] ?? [];
        $anyOf = $selector['any_of'] ?? [];
        $requireMatch = $selector['require_match'] ?? false;
        $selectorStrict = $selector['strict'] ?? $strict;

        # Empty where + empty any_of in strict = error
        if (empty($where) && empty($anyOf) && $selectorStrict) {
            return [
                'success' => false,
                'error_type' => self::ERROR_STRICT,
                'error_code' => self::ERR_LINE_SELECTOR_EMPTY,
                'error_message' => "line_selector.where is empty in component '$compId'"
            ];
        }

        # Build map
        $map = [];
        $skipped = [];
        $matchCount = 0;

        foreach ($amountModel['lines'] as $line) {
            $lineId = $line['line_id'];
            $lineContext = array_merge($context, ['line' => $line]);

            $matchResult = self::evaluateLineSelector($selector, $line, $lineContext, $selectorStrict);

            if (!$matchResult['success']) {
                return $matchResult; # Propagate error
            }

            $matches = $matchResult['matches'];

            # Apply mode
            if ($mode === self::SELECTOR_INCLUDE) {
                $applies = $matches;
            } else { # exclude
                $applies = !$matches;
            }

            $map[$lineId] = $applies;

            if ($applies) {
                $matchCount++;
            } else {
                $skipped[] = [
                    'line_id' => $lineId,
                    'reason' => $matches ? 'selector_excluded' : 'selector_not_matched'
                ];
            }
        }

        # require_match check
        if ($requireMatch && $matchCount === 0 && $selectorStrict) {
            return [
                'success' => false,
                'error_type' => self::ERROR_STRICT,
                'error_code' => self::ERR_LINE_SELECTOR_NO_MATCH,
                'error_message' => "line_selector matched no lines in component '$compId' (require_match=true)"
            ];
        }

        return [
            'success' => true,
            'map' => $map,
            'skipped' => $skipped,
            'match_count' => $matchCount,
            'selector_mode' => $mode
        ];
    }

    /**
     * Evaluate line selector against a single line
     *
     * Logic: (where AND) AND (any_of OR)
     * - where: all conditions must match (AND)
     * - any_of: at least one group must match; each group is AND
     *
     * @param array $selector The line_selector config
     * @param array $line The line from AmountModel
     * @param array $lineContext Merged context with line
     * @param bool $strict Strict mode
     * @return array ['success' => bool, 'matches' => bool]
     */
    private static function evaluateLineSelector(
        array $selector,
        array $line,
        array $lineContext,
        bool $strict
    ): array {
        $where = $selector['where'] ?? [];
        $anyOf = $selector['any_of'] ?? [];

        # Evaluate 'where' (AND)
        $whereMatch = true;
        foreach ($where as $condition) {
            $evalResult = self::evaluateSelectorCondition($condition, $line, $lineContext, $strict);
            if (!$evalResult['success']) {
                return $evalResult;
            }
            if (!$evalResult['matches']) {
                $whereMatch = false;
                break;
            }
        }

        # If where failed, no need to check any_of
        if (!$whereMatch) {
            return ['success' => true, 'matches' => false];
        }

        # If no any_of, return where result
        if (empty($anyOf)) {
            return ['success' => true, 'matches' => $whereMatch];
        }

        # Evaluate 'any_of' (OR of AND groups)
        $anyOfMatch = false;
        foreach ($anyOf as $group) {
            $groupMatch = true;
            foreach ($group as $condition) {
                $evalResult = self::evaluateSelectorCondition($condition, $line, $lineContext, $strict);
                if (!$evalResult['success']) {
                    return $evalResult;
                }
                if (!$evalResult['matches']) {
                    $groupMatch = false;
                    break;
                }
            }
            if ($groupMatch) {
                $anyOfMatch = true;
                break;
            }
        }

        # Final: where AND any_of
        return ['success' => true, 'matches' => $whereMatch && $anyOfMatch];
    }

    /**
     * Evaluate a single selector condition
     *
     * @param array $condition The condition {field, operator, value}
     * @param array $line The line data
     * @param array $lineContext Merged context
     * @param bool $strict Strict mode
     * @return array ['success' => bool, 'matches' => bool]
     */
    private static function evaluateSelectorCondition(
        array $condition,
        array $line,
        array $lineContext,
        bool $strict
    ): array {
        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? 'eq';
        $value = $condition['value'] ?? null;

        # Validate operator
        $validOperators = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'exists', 'not_exists'];
        if (!in_array($operator, $validOperators)) {
            if ($strict) {
                return [
                    'success' => false,
                    'error_type' => self::ERROR_STRICT,
                    'error_code' => self::ERR_LINE_SELECTOR_BAD_OPERATOR,
                    'error_message' => "Invalid operator '$operator' in line_selector"
                ];
            }
            return ['success' => true, 'matches' => false];
        }

        # Resolve field value
        # Support 'line.' prefix for explicit line fields
        if (strpos($field, 'line.') === 0) {
            $fieldPath = substr($field, 5); # Remove 'line.' prefix
            $fieldValue = self::getNestedValue($line, $fieldPath);
        } else {
            # Try line first, then context
            $fieldValue = self::getNestedValue($line, $field);
            if ($fieldValue === null) {
                $fieldValue = self::getNestedValue($lineContext, $field);
            }
        }

        # Handle exists/not_exists operators
        if ($operator === 'exists') {
            return ['success' => true, 'matches' => $fieldValue !== null];
        }
        if ($operator === 'not_exists') {
            return ['success' => true, 'matches' => $fieldValue === null];
        }

        # Check for missing field in strict mode
        if ($fieldValue === null && $strict && $operator !== 'eq' && $value !== null) {
            return [
                'success' => false,
                'error_type' => self::ERROR_STRICT,
                'error_code' => self::ERR_LINE_SELECTOR_FIELD_MISSING,
                'error_message' => "Field '$field' not found in line for line_selector"
            ];
        }

        # Use existing compareValues for consistency
        $matches = self::compareValues($fieldValue, $operator, $value);

        return ['success' => true, 'matches' => $matches];
    }

    # =========================================================================
    # COMPONENT EVALUATION
    # =========================================================================

    /**
     * Evaluate a single component
     *
     * P0: Added strict mode parameter
     * P3: Added line_selector support
     */
    private static function evaluateComponent(
        array $component,
        array $amountModel,
        array $input,
        array $previousResults,
        int $precision,
        int $internalPrecision,
        bool $strict = false
    ): array {
        $type = $component['type'] ?? self::COMP_RATE;
        $scope = $component['scope'] ?? self::SCOPE_LINE;
        $compId = $component['component_id'];
        $compStrict = $strict || ($component['strict'] ?? false);  # Component-level strict

        # P3: Build line applicability map if component has line_selector
        $lineApplicability = null;
        $filteredAmountModel = $amountModel;
        $appliedLineIds = [];
        $skippedLines = [];

        # P3: Validate line_selector not allowed for scope=order
        if (isset($component['line_selector']) && $scope === self::SCOPE_ORDER) {
            if ($compStrict) {
                return [
                    'success' => false,
                    'error_type' => self::ERROR_STRICT,
                    'error_code' => self::ERR_LINE_SELECTOR_NOT_ALLOWED_FOR_ORDER,
                    'error_message' => "line_selector not allowed for scope=order in component '$compId'"
                ];
            }
            # Non-strict: ignore selector, continue with all lines
        }

        if (isset($component['line_selector']) && $scope === self::SCOPE_LINE) {
            $context = $input['context'] ?? [];
            $mapResult = self::buildLineApplicabilityMap($component, $amountModel, $context, $compStrict);

            if (!$mapResult['success']) {
                return $mapResult; # Propagate error
            }

            $lineApplicability = $mapResult;
            $skippedLines = $mapResult['skipped'] ?? [];

            # Filter amountModel to only include applicable lines
            $filteredLines = [];
            foreach ($amountModel['lines'] as $lineId => $line) {
                if ($mapResult['map'][$lineId] ?? false) {
                    $filteredLines[$lineId] = $line;
                    $appliedLineIds[] = $lineId;
                }
            }

            # Recalculate order totals based on filtered lines
            $filteredAmountModel = $amountModel;
            $filteredAmountModel['lines'] = $filteredLines;
            $filteredAmountModel['order'] = self::recalculateOrderTotals($filteredLines);
        } else {
            # No selector: all lines apply
            foreach ($amountModel['lines'] as $lineId => $line) {
                $appliedLineIds[] = $lineId;
            }
        }

        # Evaluate component with filtered model
        switch ($type) {
            case self::COMP_RATE:
                $result = self::evaluateRateComponent($component, $filteredAmountModel, $scope, $precision, $internalPrecision, $compStrict);
                break;

            case self::COMP_RATE_PP:
                $result = self::evaluateRatePPComponent($component, $filteredAmountModel, $previousResults, $scope, $precision, $internalPrecision, $compStrict);
                break;

            case self::COMP_FIXED_UNIT:
                $result = self::evaluateFixedUnitComponent($component, $filteredAmountModel, $precision, $internalPrecision);
                break;

            case self::COMP_FIXED_ORDER:
                $result = self::evaluateFixedOrderComponent($component, $precision);
                break;

            case self::COMP_TIER:
                $result = self::evaluateTierComponent($component, $filteredAmountModel, $scope, $precision, $internalPrecision, $compStrict);
                break;

            case self::COMP_CAP:
                $result = self::evaluateCapComponent($component, $previousResults, $precision, $compStrict);
                break;

            default:
                return [
                    'success' => false,
                    'error_type' => self::ERROR_CALCULATION,
                    'error_code' => self::ERR_INVALID_COMPONENT,
                    'error_message' => "Unknown component type: $type"
                ];
        }

        # P3: Add line selector explainability
        if ($result['success']) {
            $result['applied_line_ids'] = $appliedLineIds;
            $result['skipped_lines'] = $skippedLines;
            if ($lineApplicability !== null) {
                $result['selector_mode'] = $lineApplicability['selector_mode'] ?? null;
            }
        }

        return $result;
    }

    /**
     * Recalculate order totals from filtered lines
     *
     * P3: Used when line_selector filters lines
     */
    private static function recalculateOrderTotals(array $lines): array
    {
        $orderNet = '0';
        $orderTax = '0';
        $orderGross = '0';
        $orderQuantity = '0';

        foreach ($lines as $line) {
            $orderNet = bcadd($orderNet, $line['net'] ?? '0', 6);
            $orderTax = bcadd($orderTax, $line['tax'] ?? '0', 6);
            $orderGross = bcadd($orderGross, $line['gross'] ?? '0', 6);
            $orderQuantity = bcadd($orderQuantity, $line['quantity'] ?? '0', 6);
        }

        return [
            'net' => $orderNet,
            'tax' => $orderTax,
            'gross' => $orderGross,
            'quantity' => $orderQuantity
        ];
    }

    /**
     * Evaluate rate (%) component
     *
     * P0: Added strict mode - fails on invalid base spec
     */
    private static function evaluateRateComponent(
        array $component,
        array $amountModel,
        string $scope,
        int $precision,
        int $internalPrecision,
        bool $strict = false
    ): array {
        $rate = $component['rate'] ?? '0';
        $baseSpec = $component['base_spec'] ?? self::BASE_NET;

        $totalAmount = '0';
        $lineAmounts = [];

        if ($scope === self::SCOPE_LINE) {
            foreach ($amountModel['lines'] as $lineId => $line) {
                $baseResult = self::evaluateBaseSpecSafe($baseSpec, $line, null, $internalPrecision, $strict);
                if (!$baseResult['success']) {
                    return $baseResult;  # P0: Propagate error in strict mode
                }
                $base = $baseResult['value'];
                $amount = bcmul($base, bcdiv($rate, '100', 6), $internalPrecision);
                $lineAmounts[$lineId] = $amount;
                $totalAmount = bcadd($totalAmount, $amount, $internalPrecision);
            }
        } else {
            $baseResult = self::evaluateBaseSpecSafe($baseSpec, null, $amountModel['order'], $internalPrecision, $strict);
            if (!$baseResult['success']) {
                return $baseResult;
            }
            $base = $baseResult['value'];
            $totalAmount = bcmul($base, bcdiv($rate, '100', 6), $internalPrecision);
        }

        return [
            'success' => true,
            'component_id' => $component['component_id'],
            'component_name' => $component['name'] ?? null,
            'component_type' => self::COMP_RATE,
            'tags' => $component['tags'] ?? [],
            'scope' => $scope,
            'base_used' => $baseSpec,
            'rate' => $rate,
            'amount' => $totalAmount,
            'line_amounts' => $lineAmounts
        ];
    }

    /**
     * Evaluate rate_pp (+pp) component
     *
     * P0: Added strict mode support
     */
    private static function evaluateRatePPComponent(
        array $component,
        array $amountModel,
        array $previousResults,
        string $scope,
        int $precision,
        int $internalPrecision,
        bool $strict = false
    ): array {
        $pp = $component['pp'] ?? '0';
        $baseSpec = $component['base_spec'] ?? self::BASE_NET;

        $totalAmount = '0';
        $lineAmounts = [];

        if ($scope === self::SCOPE_LINE) {
            foreach ($amountModel['lines'] as $lineId => $line) {
                $baseResult = self::evaluateBaseSpecSafe($baseSpec, $line, null, $internalPrecision, $strict);
                if (!$baseResult['success']) {
                    return $baseResult;
                }
                $base = $baseResult['value'];
                $amount = bcmul($base, bcdiv($pp, '100', 6), $internalPrecision);
                $lineAmounts[$lineId] = $amount;
                $totalAmount = bcadd($totalAmount, $amount, $internalPrecision);
            }
        } else {
            $baseResult = self::evaluateBaseSpecSafe($baseSpec, null, $amountModel['order'], $internalPrecision, $strict);
            if (!$baseResult['success']) {
                return $baseResult;
            }
            $base = $baseResult['value'];
            $totalAmount = bcmul($base, bcdiv($pp, '100', 6), $internalPrecision);
        }

        return [
            'success' => true,
            'component_id' => $component['component_id'],
            'component_name' => $component['name'] ?? null,
            'component_type' => self::COMP_RATE_PP,
            'tags' => $component['tags'] ?? [],
            'scope' => $scope,
            'base_used' => $baseSpec,
            'rate' => $pp,
            'amount' => $totalAmount,
            'line_amounts' => $lineAmounts
        ];
    }

    /**
     * Evaluate fixed per unit component
     */
    private static function evaluateFixedUnitComponent(
        array $component,
        array $amountModel,
        int $precision,
        int $internalPrecision
    ): array {
        $fixedPerUnit = $component['fixed'] ?? '0';

        $totalAmount = '0';
        $lineAmounts = [];

        foreach ($amountModel['lines'] as $lineId => $line) {
            $qty = $line['quantity'] ?? '1';
            $amount = bcmul($fixedPerUnit, $qty, $internalPrecision);
            $lineAmounts[$lineId] = $amount;
            $totalAmount = bcadd($totalAmount, $amount, $internalPrecision);
        }

        return [
            'success' => true,
            'component_id' => $component['component_id'],
            'component_name' => $component['name'] ?? null,
            'component_type' => self::COMP_FIXED_UNIT,
            'tags' => $component['tags'] ?? [],
            'scope' => self::SCOPE_LINE,
            'base_used' => 'quantity',
            'fixed' => $fixedPerUnit,
            'amount' => $totalAmount,
            'line_amounts' => $lineAmounts
        ];
    }

    /**
     * Evaluate fixed per order component
     */
    private static function evaluateFixedOrderComponent(
        array $component,
        int $precision
    ): array {
        $fixedAmount = $component['fixed'] ?? '0';

        return [
            'success' => true,
            'component_id' => $component['component_id'],
            'component_name' => $component['name'] ?? null,
            'component_type' => self::COMP_FIXED_ORDER,
            'tags' => $component['tags'] ?? [],
            'scope' => self::SCOPE_ORDER,
            'base_used' => 'order',
            'fixed' => $fixedAmount,
            'amount' => $fixedAmount,
            'line_amounts' => []
        ];
    }

    /**
     * Evaluate tier component
     *
     * Supports tiers by: unit_price, line_total, order_total, quantity
     * P0: Added strict mode - fails if no matching tier found
     */
    private static function evaluateTierComponent(
        array $component,
        array $amountModel,
        string $scope,
        int $precision,
        int $internalPrecision,
        bool $strict = false
    ): array {
        $tierBy = $component['tier_by'] ?? 'unit_price';
        $tiers = $component['tiers'] ?? [];

        $totalAmount = '0';
        $lineAmounts = [];
        $selectedTiers = [];
        $unmatchedLines = [];

        if ($scope === self::SCOPE_LINE) {
            foreach ($amountModel['lines'] as $lineId => $line) {
                # Determine tier value based on tier_by
                switch ($tierBy) {
                    case 'unit_price':
                        $qty = self::bcNormalize($line['quantity'] ?? '1');
                        $tierValue = bccomp($qty, '0', 0) > 0
                            ? bcdiv(self::bcNormalize($line['net']), $qty, $internalPrecision)
                            : '0';
                        break;
                    case 'line_total':
                        $tierValue = self::bcNormalize($line['net']);
                        break;
                    case 'quantity':
                        $tierValue = self::bcNormalize($line['quantity']);
                        break;
                    default:
                        $tierValue = self::bcNormalize($line['net']);
                }

                # Find matching tier
                $tier = self::findMatchingTier($tiers, $tierValue);

                if ($tier === null) {
                    $unmatchedLines[] = $lineId;
                    $lineAmounts[$lineId] = '0';
                    continue;
                }

                $selectedTiers[$lineId] = $tier;

                # Calculate fee based on tier type
                $amount = self::calculateTierAmount(
                    $tier,
                    $line,
                    $internalPrecision
                );

                $lineAmounts[$lineId] = $amount;
                $totalAmount = bcadd($totalAmount, $amount, $internalPrecision);
            }

            # P0: Strict mode - fail if any line has no matching tier
            if ($strict && !empty($unmatchedLines)) {
                return [
                    'success' => false,
                    'error_type' => self::ERROR_STRICT,
                    'error_code' => self::ERR_TIER_NOT_FOUND,
                    'error_message' => 'No matching tier for lines: ' . implode(', ', $unmatchedLines)
                ];
            }
        } else {
            # Order-level tier
            $orderData = $amountModel['order'];

            switch ($tierBy) {
                case 'order_total':
                    $tierValue = self::bcNormalize($orderData['net']);
                    break;
                case 'order_gross':
                    $tierValue = self::bcNormalize($orderData['gross']);
                    break;
                default:
                    $tierValue = self::bcNormalize($orderData['net']);
            }

            $tier = self::findMatchingTier($tiers, $tierValue);

            if ($tier === null) {
                # P0: Strict mode - fail if no order tier found
                if ($strict) {
                    return [
                        'success' => false,
                        'error_type' => self::ERROR_STRICT,
                        'error_code' => self::ERR_TIER_NOT_FOUND,
                        'error_message' => "No matching tier for order ($tierBy = $tierValue)"
                    ];
                }
            } else {
                $selectedTiers['order'] = $tier;
                $totalAmount = self::calculateTierAmount($tier, $orderData, $internalPrecision);
            }
        }

        return [
            'success' => true,
            'component_id' => $component['component_id'],
            'component_name' => $component['name'] ?? null,
            'component_type' => self::COMP_TIER,
            'tags' => $component['tags'] ?? [],
            'scope' => $scope,
            'base_used' => $tierBy,
            'tier_selected' => $selectedTiers,
            'amount' => $totalAmount,
            'line_amounts' => $lineAmounts,
            'unmatched_lines' => $unmatchedLines  # P0: Track for debugging
        ];
    }

    /**
     * Find matching tier for a value
     *
     * FIX Codex: Sort tiers by min DESC to ensure highest matching tier is selected first.
     * P0: Normalize all values for safe bccomp operations.
     */
    private static function findMatchingTier(array $tiers, string $value): ?array
    {
        $normalizedValue = self::bcNormalize($value);

        # Sort tiers by min DESC (highest threshold first)
        usort($tiers, function($a, $b) {
            return bccomp(
                self::bcNormalize($b['min'] ?? '0'),
                self::bcNormalize($a['min'] ?? '0'),
                6
            );
        });

        foreach ($tiers as $tier) {
            $min = self::bcNormalize($tier['min'] ?? '0');
            $max = self::bcNormalize($tier['max'] ?? '999999999999');

            $isAboveMin = bccomp($normalizedValue, $min, 6) >= 0;
            $isBelowMax = bccomp($normalizedValue, $max, 6) <= 0;

            if ($isAboveMin && $isBelowMax) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * Calculate amount from tier definition
     */
    private static function calculateTierAmount(
        array $tier,
        array $data,
        int $internalPrecision
    ): string {
        $amount = '0';

        # Fixed amount
        if (isset($tier['fixed'])) {
            $qty = $data['quantity'] ?? '1';
            $amount = bcmul($tier['fixed'], $qty, $internalPrecision);
        }

        # Rate
        if (isset($tier['rate'])) {
            $base = $data['net'] ?? '0';
            $rateAmount = bcmul($base, bcdiv($tier['rate'], '100', 6), $internalPrecision);
            $amount = bcadd($amount, $rateAmount, $internalPrecision);
        }

        return $amount;
    }

    /**
     * Evaluate cap (min/max) component
     *
     * P1: Supports targeted caps via `targets` object.
     * If targets not specified, applies to all previous components (legacy behavior).
     *
     * targets: {
     *   component_ids: ["fee1", "fee2"],  // exact match
     *   tags_any: ["platform"],           // has at least one tag
     *   types: ["rate", "tier"],          // component type match
     *   scopes: ["line", "order"]         // scope match
     * }
     */
    private static function evaluateCapComponent(
        array $component,
        array $previousResults,
        int $precision,
        bool $strict = false
    ): array {
        $min = $component['min'] ?? null;
        $max = $component['max'] ?? null;
        $targets = $component['targets'] ?? null;
        $scope = $component['scope'] ?? self::SCOPE_ORDER;

        # P1: Validate min/max bounds
        if ($min !== null && $max !== null) {
            $minNorm = self::bcNormalize($min);
            $maxNorm = self::bcNormalize($max);
            if (bccomp($minNorm, $maxNorm, $precision) > 0) {
                return [
                    'success' => false,
                    'error_type' => self::ERROR_CALCULATION,
                    'error_code' => self::ERR_CAP_INVALID,
                    'error_message' => "Cap min ($min) > max ($max)"
                ];
            }
        }

        # P1: Filter components by targets (or use all if no targets)
        $matchResult = self::matchCapTargets($previousResults, $targets, $strict);

        if (!$matchResult['success']) {
            return $matchResult;  # Propagate error
        }

        $matchedComponents = $matchResult['matched'];
        $matchedIds = $matchResult['matched_ids'];
        $noMatch = $matchResult['no_match'] ?? false;

        # P1: If targets specified but nothing matched, skip cap (delta = 0, warning)
        if ($noMatch && $targets !== null) {
            return [
                'success' => true,
                'component_id' => $component['component_id'],
                'component_name' => $component['name'] ?? null,
                'component_type' => self::COMP_CAP,
                'tags' => $component['tags'] ?? [],
                'scope' => $scope,
                'base_used' => 'accumulated_fee',
                'caps_applied' => null,
                'amount' => '0',
                'line_amounts' => [],
                'targets' => $targets,
                'targets_matched' => [],
                'targets_matched_count' => 0,
                'target_sum_before' => '0',
                'warning' => 'Cap targets matched no components'
            ];
        }

        # Calculate target sum (only matched components)
        $targetSum = '0';
        foreach ($matchedComponents as $result) {
            $targetSum = bcadd($targetSum, $result['amount'], $precision + 4);
        }
        $targetSum = self::bcRound($targetSum, $precision);

        # Calculate delta
        $delta = '0';
        $capApplied = null;

        # Apply minimum
        if ($min !== null) {
            $minNorm = self::bcNormalize($min);
            if (bccomp($targetSum, $minNorm, $precision) < 0) {
                $delta = bcsub($minNorm, $targetSum, $precision);
                $capApplied = [
                    'type' => 'min',
                    'value' => $min,
                    'target_sum_before' => $targetSum,
                    'delta' => $delta
                ];
            }
        }

        # Apply maximum (overrides min if both trigger, which shouldn't happen with valid bounds)
        if ($max !== null) {
            $maxNorm = self::bcNormalize($max);
            if (bccomp($targetSum, $maxNorm, $precision) > 0) {
                $delta = bcsub($maxNorm, $targetSum, $precision);  # Negative delta
                $capApplied = [
                    'type' => 'max',
                    'value' => $max,
                    'target_sum_before' => $targetSum,
                    'delta' => $delta
                ];
            }
        }

        return [
            'success' => true,
            'component_id' => $component['component_id'],
            'component_name' => $component['name'] ?? null,
            'component_type' => self::COMP_CAP,
            'tags' => $component['tags'] ?? [],
            'scope' => $scope,
            'base_used' => 'accumulated_fee',
            'caps_applied' => $capApplied,
            'amount' => $delta,
            'line_amounts' => [],
            # P1: Explainability
            'targets' => $targets,
            'targets_matched' => $matchedIds,
            'targets_matched_count' => count($matchedIds),
            'target_sum_before' => $targetSum
        ];
    }

    /**
     * Match components against cap targets
     *
     * P1: Returns matched components and their IDs for explainability.
     * If targets is null, returns all components (legacy behavior).
     */
    private static function matchCapTargets(
        array $previousResults,
        ?array $targets,
        bool $strict = false
    ): array {
        # No targets = match all (legacy behavior)
        if ($targets === null) {
            return [
                'success' => true,
                'matched' => $previousResults,
                'matched_ids' => array_column($previousResults, 'component_id')
            ];
        }

        # P1: Validate targets not empty in strict mode
        $hasAnyTarget = !empty($targets['component_ids'])
            || !empty($targets['tags_any'])
            || !empty($targets['types'])
            || !empty($targets['scopes']);

        if (!$hasAnyTarget && $strict) {
            return [
                'success' => false,
                'error_type' => self::ERROR_STRICT,
                'error_code' => self::ERR_CAP_TARGETS_EMPTY,
                'error_message' => 'Cap targets object is empty in strict mode'
            ];
        }

        if (!$hasAnyTarget) {
            # Empty targets without strict = match all (backwards compatible)
            return [
                'success' => true,
                'matched' => $previousResults,
                'matched_ids' => array_column($previousResults, 'component_id')
            ];
        }

        # Filter by targets (AND between categories, OR within each category)
        $matched = [];
        $matchedIds = [];

        foreach ($previousResults as $result) {
            if (self::componentMatchesTargets($result, $targets)) {
                $matched[] = $result;
                $matchedIds[] = $result['component_id'];
            }
        }

        # P1: No matches in strict mode = error
        if (empty($matched) && $strict) {
            return [
                'success' => false,
                'error_type' => self::ERROR_STRICT,
                'error_code' => self::ERR_CAP_NO_MATCH,
                'error_message' => 'Cap targets matched no components'
            ];
        }

        return [
            'success' => true,
            'matched' => $matched,
            'matched_ids' => $matchedIds,
            'no_match' => empty($matched),  # P1: Flag to skip cap calculation
            'warning' => empty($matched) ? 'Cap targets matched no components' : null
        ];
    }

    /**
     * Check if a component matches the target criteria
     *
     * Matching logic:
     * - Each target category (component_ids, tags_any, types, scopes) is optional
     * - If a category is specified, component must match at least one value in it
     * - All specified categories must match (AND logic between categories)
     */
    private static function componentMatchesTargets(array $component, array $targets): bool
    {
        # Check component_ids (exact match)
        if (!empty($targets['component_ids'])) {
            $compId = $component['component_id'] ?? '';
            if (!in_array($compId, $targets['component_ids'])) {
                return false;
            }
        }

        # Check tags_any (has at least one)
        if (!empty($targets['tags_any'])) {
            $compTags = $component['tags'] ?? [];
            $hasMatch = false;
            foreach ($targets['tags_any'] as $tag) {
                if (in_array($tag, $compTags)) {
                    $hasMatch = true;
                    break;
                }
            }
            if (!$hasMatch) {
                return false;
            }
        }

        # Check types
        if (!empty($targets['types'])) {
            $compType = $component['component_type'] ?? '';
            if (!in_array($compType, $targets['types'])) {
                return false;
            }
        }

        # Check scopes
        if (!empty($targets['scopes'])) {
            $compScope = $component['scope'] ?? '';
            if (!in_array($compScope, $targets['scopes'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate base spec to get numeric value
     */
    private static function evaluateBaseSpec(
        $baseSpec,
        ?array $lineData,
        ?array $orderData,
        int $internalPrecision
    ): string {
        $result = self::evaluateBaseSpecSafe($baseSpec, $lineData, $orderData, $internalPrecision, false);
        return $result['value'];
    }

    /**
     * Evaluate base spec with strict mode support
     *
     * P0: Returns error if strict mode and base spec is invalid
     */
    private static function evaluateBaseSpecSafe(
        $baseSpec,
        ?array $lineData,
        ?array $orderData,
        int $internalPrecision,
        bool $strict = false
    ): array {
        $data = $lineData ?? $orderData ?? [];

        # Simple field reference
        if (is_string($baseSpec)) {
            if (!isset($data[$baseSpec])) {
                if ($strict) {
                    return [
                        'success' => false,
                        'error_type' => self::ERROR_STRICT,
                        'error_code' => self::ERR_FIELD_MISSING,
                        'error_message' => "Field '$baseSpec' not found in data"
                    ];
                }
                return ['success' => true, 'value' => '0'];
            }
            return ['success' => true, 'value' => $data[$baseSpec]];
        }

        # Complex expression
        if (is_array($baseSpec)) {
            $result = self::evaluateBaseExpressionSafe($baseSpec, $data, $internalPrecision, $strict);
            return $result;
        }

        if ($strict) {
            return [
                'success' => false,
                'error_type' => self::ERROR_STRICT,
                'error_code' => self::ERR_INVALID_BASE_SPEC,
                'error_message' => 'Invalid base_spec type: ' . gettype($baseSpec)
            ];
        }

        return ['success' => true, 'value' => '0'];
    }

    /**
     * Evaluate complex base expression
     *
     * FIX Codex: Added mul, div, min, max, abs operations.
     * Supports: field, add, sub, mul, div, min, max, abs, value
     */
    private static function evaluateBaseExpression(
        array $expr,
        array $data,
        int $internalPrecision
    ): string {
        $result = self::evaluateBaseExpressionSafe($expr, $data, $internalPrecision, false);
        return $result['value'];
    }

    /**
     * Evaluate complex base expression with strict mode
     *
     * P0: Returns error in strict mode for invalid ops or division by zero
     */
    private static function evaluateBaseExpressionSafe(
        array $expr,
        array $data,
        int $internalPrecision,
        bool $strict = false
    ): array {
        $op = $expr['op'] ?? 'field';

        switch ($op) {
            case 'field':
                $fieldName = $expr['field'] ?? 'net';
                if (!isset($data[$fieldName])) {
                    if ($strict) {
                        return [
                            'success' => false,
                            'error_type' => self::ERROR_STRICT,
                            'error_code' => self::ERR_FIELD_MISSING,
                            'error_message' => "Field '$fieldName' not found"
                        ];
                    }
                    return ['success' => true, 'value' => '0'];
                }
                return ['success' => true, 'value' => $data[$fieldName]];

            case 'add':
                $left = self::evaluateBaseExpressionSafe($expr['left'] ?? [], $data, $internalPrecision, $strict);
                if (!$left['success']) return $left;
                $right = self::evaluateBaseExpressionSafe($expr['right'] ?? [], $data, $internalPrecision, $strict);
                if (!$right['success']) return $right;
                return ['success' => true, 'value' => bcadd($left['value'], $right['value'], $internalPrecision)];

            case 'sub':
                $left = self::evaluateBaseExpressionSafe($expr['left'] ?? [], $data, $internalPrecision, $strict);
                if (!$left['success']) return $left;
                $right = self::evaluateBaseExpressionSafe($expr['right'] ?? [], $data, $internalPrecision, $strict);
                if (!$right['success']) return $right;
                return ['success' => true, 'value' => bcsub($left['value'], $right['value'], $internalPrecision)];

            case 'mul':
                $left = self::evaluateBaseExpressionSafe($expr['left'] ?? [], $data, $internalPrecision, $strict);
                if (!$left['success']) return $left;
                $right = self::evaluateBaseExpressionSafe($expr['right'] ?? [], $data, $internalPrecision, $strict);
                if (!$right['success']) return $right;
                return ['success' => true, 'value' => bcmul($left['value'], $right['value'], $internalPrecision)];

            case 'div':
                $left = self::evaluateBaseExpressionSafe($expr['left'] ?? [], $data, $internalPrecision, $strict);
                if (!$left['success']) return $left;
                $right = self::evaluateBaseExpressionSafe($expr['right'] ?? [], $data, $internalPrecision, $strict);
                if (!$right['success']) return $right;
                if (bccomp($right['value'], '0', $internalPrecision) === 0) {
                    if ($strict) {
                        return [
                            'success' => false,
                            'error_type' => self::ERROR_STRICT,
                            'error_code' => self::ERR_DIVISION_ZERO,
                            'error_message' => 'Division by zero in base_spec'
                        ];
                    }
                    return ['success' => true, 'value' => '0'];
                }
                return ['success' => true, 'value' => bcdiv($left['value'], $right['value'], $internalPrecision)];

            case 'min':
                $left = self::evaluateBaseExpressionSafe($expr['left'] ?? [], $data, $internalPrecision, $strict);
                if (!$left['success']) return $left;
                $right = self::evaluateBaseExpressionSafe($expr['right'] ?? [], $data, $internalPrecision, $strict);
                if (!$right['success']) return $right;
                $val = bccomp($left['value'], $right['value'], $internalPrecision) <= 0 ? $left['value'] : $right['value'];
                return ['success' => true, 'value' => $val];

            case 'max':
                $left = self::evaluateBaseExpressionSafe($expr['left'] ?? [], $data, $internalPrecision, $strict);
                if (!$left['success']) return $left;
                $right = self::evaluateBaseExpressionSafe($expr['right'] ?? [], $data, $internalPrecision, $strict);
                if (!$right['success']) return $right;
                $val = bccomp($left['value'], $right['value'], $internalPrecision) >= 0 ? $left['value'] : $right['value'];
                return ['success' => true, 'value' => $val];

            case 'abs':
                $inner = self::evaluateBaseExpressionSafe($expr['value'] ?? [], $data, $internalPrecision, $strict);
                if (!$inner['success']) return $inner;
                $val = bccomp($inner['value'], '0', $internalPrecision) < 0
                    ? bcmul($inner['value'], '-1', $internalPrecision)
                    : $inner['value'];
                return ['success' => true, 'value' => $val];

            case 'value':
                return ['success' => true, 'value' => (string)($expr['value'] ?? '0')];

            default:
                if ($strict) {
                    return [
                        'success' => false,
                        'error_type' => self::ERROR_STRICT,
                        'error_code' => self::ERR_INVALID_BASE_SPEC,
                        'error_message' => "Unknown base_spec operation: '$op'"
                    ];
                }
                return ['success' => true, 'value' => '0'];
        }
    }

    # =========================================================================
    # ALLOCATION & RECONCILIATION
    # =========================================================================

    /**
     * Allocate order-level fees to lines
     */
    private static function allocateOrderFees(
        array $orderFees,
        array $amountModel,
        string $strategy,
        int $precision,
        int $internalPrecision
    ): array {
        $allocations = [];
        $totalOrderFee = '0';

        foreach ($orderFees as $fee) {
            $totalOrderFee = bcadd($totalOrderFee, $fee['amount'], $internalPrecision);
        }

        if (bccomp($totalOrderFee, '0', $precision) === 0) {
            return ['allocations' => $allocations];
        }

        # Calculate weights based on strategy
        $weights = self::calculateAllocationWeights($amountModel, $strategy, $internalPrecision);

        foreach ($amountModel['lines'] as $lineId => $line) {
            $weight = $weights[$lineId] ?? '0';
            $allocatedAmount = bcmul($totalOrderFee, $weight, $internalPrecision);

            $allocations[$lineId] = [
                'amount' => $allocatedAmount,
                'components' => []
            ];

            # Allocate each component proportionally
            foreach ($orderFees as $fee) {
                $compAmount = bcmul($fee['amount'], $weight, $internalPrecision);
                $allocations[$lineId]['components'][] = [
                    'component_id' => $fee['component_id'],
                    'amount' => $compAmount
                ];
            }
        }

        return ['allocations' => $allocations];
    }

    /**
     * Calculate allocation weights
     */
    private static function calculateAllocationWeights(
        array $amountModel,
        string $strategy,
        int $internalPrecision
    ): array {
        $weights = [];
        $total = '0';

        # Calculate totals based on strategy
        foreach ($amountModel['lines'] as $lineId => $line) {
            switch ($strategy) {
                case self::ALLOC_BY_NET:
                    $value = $line['net'];
                    break;
                case self::ALLOC_BY_GROSS:
                    $value = $line['gross'];
                    break;
                case self::ALLOC_BY_QUANTITY:
                    $value = $line['quantity'];
                    break;
                case self::ALLOC_EQUAL:
                    $value = '1';
                    break;
                default:
                    $value = $line['net'];
            }
            $weights[$lineId] = $value;
            $total = bcadd($total, $value, $internalPrecision);
        }

        # Convert to proportions
        if (bccomp($total, '0', $internalPrecision) > 0) {
            foreach ($weights as $lineId => $value) {
                $weights[$lineId] = bcdiv($value, $total, $internalPrecision);
            }
        }

        return $weights;
    }

    /**
     * Reconcile allocation rounding differences
     *
     * FIX Codex: Apply adjustment to line with largest fee (fairer distribution).
     * If all equal, use first line.
     */
    private static function reconcileAllocations(
        array &$allocations,
        string $totalFee,
        int $precision
    ): array {
        # Sum allocated amounts
        $allocatedSum = '0';
        foreach ($allocations as $alloc) {
            $allocatedSum = bcadd($allocatedSum, $alloc['fee_amount'], $precision + 4);
        }

        $allocatedSum = self::bcRound($allocatedSum, $precision);
        $difference = bcsub($totalFee, $allocatedSum, $precision);

        if (bccomp($difference, '0', $precision) === 0) {
            return [
                'adjusted' => false,
                'difference' => '0'
            ];
        }

        # Find line with largest fee (fairer to adjust there)
        $largestKey = null;
        $largestFee = '0';
        foreach ($allocations as $key => $alloc) {
            if (bccomp($alloc['fee_amount'], $largestFee, $precision) > 0) {
                $largestFee = $alloc['fee_amount'];
                $largestKey = $key;
            }
        }

        # Fallback to first if all zero
        if ($largestKey === null) {
            $largestKey = array_key_first($allocations);
        }

        if ($largestKey !== null) {
            $allocations[$largestKey]['fee_amount'] = bcadd(
                $allocations[$largestKey]['fee_amount'],
                $difference,
                $precision + 4
            );
        }

        return [
            'adjusted' => true,
            'difference' => $difference,
            'adjusted_line' => $largestKey
        ];
    }

    # =========================================================================
    # UTILITIES
    # =========================================================================

    /**
     * Round using HALF_UP (pure bcmath)
     */
    private static function bcRound(string $number, int $precision = 2): string
    {
        if ($precision < 0) {
            $precision = 0;
        }

        $factor = bcpow('10', (string)$precision, 0);
        $scaled = bcmul($number, $factor, $precision + 2);

        if (bccomp($scaled, '0', 0) >= 0) {
            $scaled = bcadd($scaled, '0.5', 0);
        } else {
            $scaled = bcsub($scaled, '0.5', 0);
        }

        $integer = bcadd($scaled, '0', 0);
        return bcdiv($integer, $factor, $precision);
    }

    /**
     * Canonicalize policy for deterministic hashing
     *
     * RFC 8785-inspired: sort components, arrays, keys recursively.
     * Ensures same policy with different order produces same hash.
     *
     * @param array $policy Policy to canonicalize
     * @return array Canonicalized policy
     */
    public static function canonicalizePolicy(array $policy): array
    {
        $components = array_map([self::class, 'normalizeComponent'], $policy['components'] ?? []);

        # Sort by component_id, then type as tiebreaker
        usort($components, function($a, $b) {
            $cmp = strcmp($a['component_id'] ?? '', $b['component_id'] ?? '');
            if ($cmp !== 0) return $cmp;
            return strcmp($a['type'] ?? '', $b['type'] ?? '');
        });

        return [
            'policy_key' => $policy['policy_key'] ?? null,
            'version' => $policy['version'] ?? 1,
            'channel_key' => $policy['channel_key'] ?? null,
            'components' => $components
        ];
    }

    /**
     * Normalize a single component for canonical representation
     */
    private static function normalizeComponent(array $c): array
    {
        $out = $c;

        # Sort tags alphabetically
        if (isset($out['tags']) && is_array($out['tags'])) {
            sort($out['tags'], SORT_STRING);
        }

        # Sort conditions by condition_id or hash of content
        if (isset($out['conditions']) && is_array($out['conditions'])) {
            usort($out['conditions'], function($a, $b) {
                $aKey = $a['condition_id'] ?? $a['field'] ?? md5(json_encode($a));
                $bKey = $b['condition_id'] ?? $b['field'] ?? md5(json_encode($b));
                return strcmp($aKey, $bKey);
            });
        }

        # Sort base_spec fields if present
        if (isset($out['base_spec']['fields']) && is_array($out['base_spec']['fields'])) {
            sort($out['base_spec']['fields'], SORT_STRING);
        }

        # Sort base_spec ops by field name
        if (isset($out['base_spec']['ops']) && is_array($out['base_spec']['ops'])) {
            usort($out['base_spec']['ops'], function($a, $b) {
                return strcmp($a['field'] ?? '', $b['field'] ?? '');
            });
        }

        # Sort tiers by min value
        if (isset($out['tiers']) && is_array($out['tiers'])) {
            usort($out['tiers'], function($a, $b) {
                return bccomp($a['min'] ?? '0', $b['min'] ?? '0', 4);
            });
        }

        # Sort cap targets
        if (isset($out['targets'])) {
            if (isset($out['targets']['component_ids']) && is_array($out['targets']['component_ids'])) {
                sort($out['targets']['component_ids'], SORT_STRING);
            }
            if (isset($out['targets']['tags_any']) && is_array($out['targets']['tags_any'])) {
                sort($out['targets']['tags_any'], SORT_STRING);
            }
            if (isset($out['targets']['types']) && is_array($out['targets']['types'])) {
                sort($out['targets']['types'], SORT_STRING);
            }
            if (isset($out['targets']['scopes']) && is_array($out['targets']['scopes'])) {
                sort($out['targets']['scopes'], SORT_STRING);
            }
            ksort($out['targets']);
        }

        # Sort all keys
        ksort($out);

        return $out;
    }

    /**
     * Generate canonical JSON (RFC 8785 simplified)
     *
     * Recursively sorts object keys, stable numeric/string handling.
     */
    private static function canonicalJson($data): string
    {
        return json_encode(
            self::recursiveKsort($data),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Recursively sort associative arrays by key
     */
    private static function recursiveKsort($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        # Check if associative array (object) vs indexed (array)
        $isAssoc = array_keys($data) !== range(0, count($data) - 1);

        if ($isAssoc) {
            ksort($data, SORT_STRING);
        }

        foreach ($data as $key => $value) {
            $data[$key] = self::recursiveKsort($value);
        }

        return $data;
    }

    /**
     * Generate canonical policy hash
     *
     * v2: Uses canonicalized policy for deterministic hash
     * v1: Legacy (order-dependent, deprecated)
     */
    public static function generatePolicyHash(array $policy, int $version = 2): string
    {
        if ($version === 1) {
            # Legacy: order-dependent (deprecated)
            return 'v1:' . substr(md5(json_encode($policy['components'] ?? [])), 0, 16);
        }

        # v2: Canonical hash
        $canonical = self::canonicalizePolicy($policy);
        $payload = self::canonicalJson($canonical);
        return 'v2:' . substr(hash('sha256', $payload), 0, 16);
    }

    /**
     * Generate deterministic signature
     *
     * Uses canonical policy hash for reproducible signatures.
     */
    public static function generateSignature(array $input, array $policy, array $options): string
    {
        # v2: Canonical policy hash
        $policyHash = self::generatePolicyHash($policy, 2);

        $normalized = [
            'version' => self::VERSION,
            'channel_key' => $input['channel_key'] ?? '',
            'policy_key' => $policy['policy_key'] ?? '',
            'policy_version' => $policy['version'] ?? 1,
            'policy_hash' => $policyHash,
            'lines_hash' => md5(json_encode($input['lines'] ?? [])),
            'precision' => $options['precision'] ?? 2
        ];

        ksort($normalized);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Build error response
     */
    private static function buildError(string $errorType, string $errorCode, string $message): array
    {
        return [
            'success' => false,
            'error_type' => $errorType,
            'error_code' => $errorCode,
            'error_message' => $message
        ];
    }

    # =========================================================================
    # CONVENIENCE METHODS
    # =========================================================================

    /**
     * Create a simple rate policy
     *
     * Helper for common case: single % fee on net.
     */
    public static function createSimpleRatePolicy(
        string $channelKey,
        string $rate,
        array $options = []
    ): array {
        return [
            'policy_key' => $channelKey . '_rate',
            'channel_key' => $channelKey,
            'version' => 1,
            'effective_from' => $options['effective_from'] ?? '1970-01-01',
            'effective_to' => $options['effective_to'] ?? '9999-12-31',
            'components' => [
                [
                    'component_id' => 'platform_fee',
                    'name' => 'Platform Fee',
                    'type' => self::COMP_RATE,
                    'scope' => $options['scope'] ?? self::SCOPE_LINE,
                    'rate' => $rate,
                    'base_spec' => $options['base_spec'] ?? self::BASE_NET,
                    'tags' => ['platform_fee']
                ]
            ]
        ];
    }

    /**
     * Create a tiered policy (e.g., Mercado Libre style)
     *
     * Helper for marketplace-style tiered fees.
     */
    public static function createTieredPolicy(
        string $channelKey,
        array $tiers,
        array $options = []
    ): array {
        return [
            'policy_key' => $channelKey . '_tiered',
            'channel_key' => $channelKey,
            'version' => 1,
            'effective_from' => $options['effective_from'] ?? '1970-01-01',
            'effective_to' => $options['effective_to'] ?? '9999-12-31',
            'components' => [
                [
                    'component_id' => 'tiered_fee',
                    'name' => 'Tiered Fee',
                    'type' => self::COMP_TIER,
                    'scope' => self::SCOPE_LINE,
                    'tier_by' => $options['tier_by'] ?? 'unit_price',
                    'tiers' => $tiers,
                    'tags' => ['platform_fee', 'tiered']
                ]
            ]
        ];
    }
}
