<?php
# bintelx/kernel/Matcher.php
# Universal Condition Matcher for Rules/Policies
# Unifies PayrollEngine::evaluateConditions and FeeEngine::evaluateConditions
#
# Supports:
#   - Simple conditions: [{field, operator, value}]
#   - Nested paths: "context.country", "line.amount"
#   - Logical modes: AND (all), OR (any), NOT
#   - Operators: eq, neq, gt, gte, lt, lte, in, not_in, contains, between, regex
#   - BCMath precision for numeric comparisons
#
# @version 1.0.0

namespace bX;

require_once __DIR__ . '/Math.php';

class Matcher
{
    public const VERSION = '1.0.0';

    # Logic modes
    public const LOGIC_AND = 'AND';
    public const LOGIC_OR = 'OR';

    # Match modes for collections
    public const MODE_INCLUDE = 'include'; # Match if ANY predicate matches
    public const MODE_EXCLUDE = 'exclude'; # Match if NO predicate matches

    # Operators
    public const OP_EQ = 'eq';
    public const OP_NEQ = 'neq';
    public const OP_GT = 'gt';
    public const OP_GTE = 'gte';
    public const OP_LT = 'lt';
    public const OP_LTE = 'lte';
    public const OP_IN = 'in';
    public const OP_NOT_IN = 'not_in';
    public const OP_CONTAINS = 'contains';
    public const OP_BETWEEN = 'between';
    public const OP_REGEX = 'regex';
    public const OP_STARTS_WITH = 'starts_with';
    public const OP_ENDS_WITH = 'ends_with';
    public const OP_IS_NULL = 'is_null';
    public const OP_IS_NOT_NULL = 'is_not_null';

    /**
     * Match context against rules
     *
     * @param array $context Data to match against (e.g., ['country' => 'CL', 'amount' => 1000])
     * @param array $rules Rules definition
     * @return array ['match' => bool, 'details' => [...]]
     *
     * Rules structure:
     * [
     *   'mode' => 'include',  # or 'exclude'
     *   'logic' => 'AND',     # or 'OR'
     *   'predicates' => [
     *     ['var' => 'context.country', 'op' => 'eq', 'val' => 'CL'],
     *     ['var' => 'line.amount', 'op' => 'gte', 'val' => 1000],
     *   ]
     * ]
     *
     * Or simple array (legacy format, AND logic):
     * [
     *   ['field' => 'country', 'operator' => 'eq', 'value' => 'CL'],
     * ]
     */
    public static function matches(array $context, array $rules): array
    {
        if (empty($rules)) {
            return ['match' => true, 'details' => ['reason' => 'empty_rules']];
        }

        # Detect format and normalize
        $normalized = self::normalizeRules($rules);
        $predicates = $normalized['predicates'];
        $logic = $normalized['logic'];
        $mode = $normalized['mode'];

        if (empty($predicates)) {
            return ['match' => true, 'details' => ['reason' => 'no_predicates']];
        }

        $results = [];
        foreach ($predicates as $predicate) {
            $var = $predicate['var'] ?? $predicate['field'] ?? '';
            $op = $predicate['op'] ?? $predicate['operator'] ?? self::OP_EQ;
            $val = $predicate['val'] ?? $predicate['value'] ?? null;

            $fieldValue = self::getNestedValue($context, $var);
            $matched = self::evaluateOperator($fieldValue, $op, $val);

            $results[] = [
                'var' => $var,
                'op' => $op,
                'val' => $val,
                'field_value' => $fieldValue,
                'matched' => $matched,
            ];
        }

        # Apply logic
        $logicResult = self::applyLogic($results, $logic);

        # Apply mode
        $finalMatch = $mode === self::MODE_EXCLUDE ? !$logicResult : $logicResult;

        return [
            'match' => $finalMatch,
            'details' => [
                'logic' => $logic,
                'mode' => $mode,
                'results' => $results,
            ],
        ];
    }

    /**
     * Simple match - returns only boolean
     */
    public static function match(array $context, array $rules): bool
    {
        return self::matches($context, $rules)['match'];
    }

    /**
     * Match multiple contexts against rules (filter)
     *
     * @param array $items Array of contexts
     * @param array $rules Rules to match
     * @return array Filtered items that match
     */
    public static function filter(array $items, array $rules): array
    {
        return array_values(array_filter($items, fn($item) => self::match($item, $rules)));
    }

    /**
     * Find first matching item
     */
    public static function findFirst(array $items, array $rules): ?array
    {
        foreach ($items as $item) {
            if (self::match($item, $rules)) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Count matches
     */
    public static function count(array $items, array $rules): int
    {
        return count(self::filter($items, $rules));
    }

    /**
     * Normalize rules to standard format
     */
    private static function normalizeRules(array $rules): array
    {
        # Already in new format
        if (isset($rules['predicates'])) {
            return [
                'mode' => $rules['mode'] ?? self::MODE_INCLUDE,
                'logic' => strtoupper($rules['logic'] ?? self::LOGIC_AND),
                'predicates' => $rules['predicates'],
            ];
        }

        # Legacy format: array of conditions
        # Check if it's a flat array of conditions (has 'field' or 'var' keys)
        if (isset($rules[0]) && (isset($rules[0]['field']) || isset($rules[0]['var']))) {
            return [
                'mode' => self::MODE_INCLUDE,
                'logic' => self::LOGIC_AND,
                'predicates' => $rules,
            ];
        }

        # Unknown format, return as-is with defaults
        return [
            'mode' => self::MODE_INCLUDE,
            'logic' => self::LOGIC_AND,
            'predicates' => $rules,
        ];
    }

    /**
     * Get nested value from array using dot notation
     *
     * @param array $data Source data
     * @param string $path Path like "context.country" or "line.amount"
     * @return mixed Value or null if not found
     */
    public static function getNestedValue(array $data, string $path)
    {
        if (empty($path)) {
            return null;
        }

        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            # Handle case-insensitive keys
            $keyLower = strtolower($key);

            if (is_array($value)) {
                if (array_key_exists($key, $value)) {
                    $value = $value[$key];
                } elseif (array_key_exists($keyLower, $value)) {
                    $value = $value[$keyLower];
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Evaluate single operator
     */
    private static function evaluateOperator($fieldValue, string $op, $compareValue): bool
    {
        switch ($op) {
            case self::OP_EQ:
                # String comparison for equality
                return (string)$fieldValue === (string)$compareValue;

            case self::OP_NEQ:
                return (string)$fieldValue !== (string)$compareValue;

            case self::OP_GT:
                return Math::gt(
                    self::normalize($fieldValue),
                    self::normalize($compareValue)
                );

            case self::OP_GTE:
                return Math::gte(
                    self::normalize($fieldValue),
                    self::normalize($compareValue)
                );

            case self::OP_LT:
                return Math::lt(
                    self::normalize($fieldValue),
                    self::normalize($compareValue)
                );

            case self::OP_LTE:
                return Math::lte(
                    self::normalize($fieldValue),
                    self::normalize($compareValue)
                );

            case self::OP_IN:
                if (!is_array($compareValue)) {
                    return false;
                }
                return in_array($fieldValue, $compareValue, false);

            case self::OP_NOT_IN:
                if (!is_array($compareValue)) {
                    return true;
                }
                return !in_array($fieldValue, $compareValue, false);

            case self::OP_CONTAINS:
                # Field is array, check if it contains value
                if (is_array($fieldValue)) {
                    return in_array($compareValue, $fieldValue, false);
                }
                # Field is string, check substring
                if (is_string($fieldValue) && is_string($compareValue)) {
                    return str_contains($fieldValue, $compareValue);
                }
                return false;

            case self::OP_BETWEEN:
                if (!is_array($compareValue) || count($compareValue) < 2) {
                    return false;
                }
                $normalized = self::normalize($fieldValue);
                return Math::gte($normalized, self::normalize($compareValue[0]))
                    && Math::lte($normalized, self::normalize($compareValue[1]));

            case self::OP_REGEX:
                if (!is_string($fieldValue) || !is_string($compareValue)) {
                    return false;
                }
                return (bool)preg_match($compareValue, $fieldValue);

            case self::OP_STARTS_WITH:
                if (!is_string($fieldValue) || !is_string($compareValue)) {
                    return false;
                }
                return str_starts_with($fieldValue, $compareValue);

            case self::OP_ENDS_WITH:
                if (!is_string($fieldValue) || !is_string($compareValue)) {
                    return false;
                }
                return str_ends_with($fieldValue, $compareValue);

            case self::OP_IS_NULL:
                return $fieldValue === null;

            case self::OP_IS_NOT_NULL:
                return $fieldValue !== null;

            default:
                # Unknown operator, default to equality
                return $fieldValue == $compareValue;
        }
    }

    /**
     * Apply logic to results
     */
    private static function applyLogic(array $results, string $logic): bool
    {
        if (empty($results)) {
            return true;
        }

        $matches = array_column($results, 'matched');

        if ($logic === self::LOGIC_OR) {
            # Any must match
            return in_array(true, $matches, true);
        }

        # AND: All must match
        return !in_array(false, $matches, true);
    }

    /**
     * Normalize value for BCMath
     */
    private static function normalize($value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $str = trim((string)$value);

        if (!is_numeric($str)) {
            return '0';
        }

        return $str;
    }

    /**
     * Validate rules structure
     */
    public static function validate(array $rules): array
    {
        if (empty($rules)) {
            return ['valid' => true, 'errors' => []];
        }

        $errors = [];
        $normalized = self::normalizeRules($rules);

        foreach ($normalized['predicates'] as $i => $predicate) {
            $var = $predicate['var'] ?? $predicate['field'] ?? null;
            $op = $predicate['op'] ?? $predicate['operator'] ?? null;

            if (empty($var)) {
                $errors[] = "Predicate $i: missing 'var' or 'field'";
            }

            if (empty($op)) {
                $errors[] = "Predicate $i: missing 'op' or 'operator'";
            }

            $validOps = [
                self::OP_EQ, self::OP_NEQ, self::OP_GT, self::OP_GTE,
                self::OP_LT, self::OP_LTE, self::OP_IN, self::OP_NOT_IN,
                self::OP_CONTAINS, self::OP_BETWEEN, self::OP_REGEX,
                self::OP_STARTS_WITH, self::OP_ENDS_WITH,
                self::OP_IS_NULL, self::OP_IS_NOT_NULL,
            ];

            if ($op && !in_array($op, $validOps)) {
                $errors[] = "Predicate $i: invalid operator '$op'";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Build rules from simple key-value pairs (convenience method)
     *
     * @param array $equals Key-value pairs for equality checks
     * @param string $logic Logic mode
     * @return array Rules array
     */
    public static function buildEquals(array $equals, string $logic = self::LOGIC_AND): array
    {
        $predicates = [];
        foreach ($equals as $var => $val) {
            $predicates[] = ['var' => $var, 'op' => self::OP_EQ, 'val' => $val];
        }

        return [
            'mode' => self::MODE_INCLUDE,
            'logic' => $logic,
            'predicates' => $predicates,
        ];
    }
}
