<?php
# bintelx/kernel/PayrollEngine.php
# Payroll Calculation Engine - Enterprise Agnóstico
#
# Features:
#   - Formula-driven calculation (uses RulesEngine)
#   - Parameter resolution with effective dating (uses ParamStore)
#   - Dependency graph execution order
#   - Rounding per concept with configurable precision
#   - Rounding bucket for net reconciliation
#   - Full explain trace (ALCOA+ compliance)
#   - Deterministic (same input → same output)
#   - Country pack agnostic (formulas/params in DB)
#
# @version 1.0.6 - CL todo HALF_UP (Previred 2026 confirmado)
# @version 1.0.4 - Rounding policy: constantes explícitas por tipo de concepto
# @version 1.0.3 - Input validation: employee_id, dates, condition warnings
# @version 1.0.2 - Fixed M2: Rounding bucket now rounds to payroll precision
# @version 1.0.1 - Fixed C3: Formula errors now tracked; strict_mode makes them fatal
# @version 1.0.0

namespace bX;

require_once __DIR__ . '/Math.php';
require_once __DIR__ . '/RulesEngine.php';
require_once __DIR__ . '/ParamStore.php';

class PayrollEngine
{
    public const VERSION = '1.0.6';

    # =========================================================================
    # ROUNDING POLICY (por país)
    # =========================================================================
    # Documentado en: .claude/PAYROLL_ENGINE_STATUS.md (Sección 10)
    #
    # Principio: Redondear por línea/concepto en cada etapa "informable"
    # Invariante auditoría: totals == sum(lines.amount_clp)
    #
    # Configuración por país (precision, mode):
    #   - CL: (0, HALF_UP todo) → Previred 2026 usa HALF_UP
    #   - BR: (2, HALF_UP) → centavos
    #   - US: (2, HALF_UP) → cents
    # =========================================================================
    public const ROUNDING_POLICY = [
        'CL' => [
            'precision' => 0,
            'line' => Math::ROUND_HALF_UP,        # Haberes/deducciones
            'contribution' => Math::ROUND_HALF_UP, # AFP, Salud, AFC (Previred HALF_UP)
            'tax' => Math::ROUND_HALF_UP,          # Impuesto único
        ],
        'BR' => [
            'precision' => 2,
            'line' => Math::ROUND_HALF_UP,
            'contribution' => Math::ROUND_HALF_UP,
            'tax' => Math::ROUND_HALF_UP,
        ],
        'DEFAULT' => [
            'precision' => 2,
            'line' => Math::ROUND_HALF_UP,
            'contribution' => Math::ROUND_HALF_UP,
            'tax' => Math::ROUND_HALF_UP,
        ],
    ];

    # Error codes
    public const ERR_NO_FORMULAS = 'NO_FORMULAS_LOADED';
    public const ERR_CIRCULAR_DEP = 'CIRCULAR_DEPENDENCY';
    public const ERR_FORMULA_ERROR = 'FORMULA_EVALUATION_ERROR';
    public const ERR_MISSING_INPUT = 'MISSING_REQUIRED_INPUT';
    public const ERR_MISSING_COUNTRY = 'MISSING_COUNTRY_CODE';

    # Standard concept suffixes (country-agnostic)
    private const SUFFIX_GROSS = '_E_GROSS';
    private const SUFFIX_NET_BEFORE_TAX = '_O_NET_BEFORE_TAX';
    private const SUFFIX_NET_PAID = '_O_NET_PAID';
    private const SUFFIX_NET_SOCIAL = '_O_NET_SOCIAL';

    # Aggregation types for groups
    public const AGG_SUM = 'SUM';
    public const AGG_AVG = 'AVG';
    public const AGG_MIN = 'MIN';
    public const AGG_MAX = 'MAX';

    # Calculation context
    private string $countryCode;
    private ?int $scopeEntityId;
    private string $periodStart;
    private string $periodEnd;
    private int $employeeId;
    private array $formulas = [];
    private array $concepts = [];
    private array $groups = [];
    private ParamStore $paramStore;
    private array $calculatedValues = [];
    private array $lineDetails = [];
    private array $trace = [];
    private array $warnings = [];
    private int $defaultPrecision = 2;
    private string $defaultRoundingMode = Math::ROUND_HALF_UP;
    private bool $strictMode = false;
    private int $formulaErrorCount = 0;
    private array $inputEmployeeParams = []; # Employee params from input (override DB)

    /**
     * Calculate payroll for an employee
     *
     * @param array $input Employee input data (earnings, etc.)
     * @param array $config Calculation configuration
     * @return array Calculation result
     */
    public static function calculate(array $input, array $config): array
    {
        $engine = new self();
        return $engine->run($input, $config);
    }

    /**
     * Validate formulas without calculation
     */
    public static function validateFormulas(string $countryCode, string $date): array
    {
        $engine = new self();
        $engine->countryCode = $countryCode;
        $engine->loadFormulas($date);

        $errors = [];
        foreach ($engine->formulas as $formula) {
            $validation = RulesEngine::validate($formula['dsl_expression']);
            if (!$validation['valid']) {
                $errors[] = [
                    'formula_code' => $formula['formula_code'],
                    'target_concept' => $formula['target_concept_code'],
                    'error' => $validation['error']
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'formula_count' => count($engine->formulas),
            'errors' => $errors
        ];
    }

    /**
     * Run calculation
     */
    public function run(array $input, array $config): array
    {
        $startTime = microtime(true);

        try {
            # Validate required config
            if (empty($config['country_code'])) {
                return $this->errorResult(self::ERR_MISSING_COUNTRY, 'country_code is required in config');
            }

            # G6 FIX: Validate employee_id
            $employeeId = (int)($input['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                return $this->errorResult(self::ERR_MISSING_INPUT, 'employee_id must be a positive integer');
            }

            # G6 FIX: Validate date formats and range
            $periodStart = $config['period_start'] ?? date('Y-m-01');
            $periodEnd = $config['period_end'] ?? date('Y-m-t');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
                return $this->errorResult(self::ERR_MISSING_INPUT, 'period_start and period_end must be YYYY-MM-DD format');
            }
            if ($periodStart > $periodEnd) {
                return $this->errorResult(self::ERR_MISSING_INPUT, 'period_start must be <= period_end');
            }

            # Initialize context (using validated values)
            $this->countryCode = strtoupper($config['country_code']);
            $this->scopeEntityId = $config['scope_entity_id'] ?? null;
            $this->periodStart = $periodStart;
            $this->periodEnd = $periodEnd;
            $this->employeeId = $employeeId;
            $this->defaultPrecision = $config['precision'] ?? 2;
            $this->defaultRoundingMode = $config['rounding_mode'] ?? Math::ROUND_HALF_UP;
            # FIX C3: strict_mode makes formula errors fatal
            $this->strictMode = (bool)($config['strict_mode'] ?? false);
            $this->formulaErrorCount = 0;
            $this->inputEmployeeParams = $input['employee_params'] ?? [];

            # Initialize param store
            # DEBUG
            if (!empty($config['debug'])) echo "[PE] ParamStore init...\n";
            $this->paramStore = new ParamStore(
                $this->countryCode,
                $this->scopeEntityId,
                $this->periodEnd
            );

            # Load formulas and concepts
            if (!empty($config['debug'])) echo "[PE] Loading formulas...\n";
            $this->loadFormulas($this->periodEnd);
            if (!empty($config['debug'])) echo "[PE] Loading concepts...\n";
            $this->loadConcepts($this->periodEnd);
            if (!empty($config['debug'])) echo "[PE] Loading groups...\n";
            $this->loadGroups($this->periodEnd);

            if (empty($this->formulas)) {
                return $this->errorResult(self::ERR_NO_FORMULAS, 'No formulas loaded for country');
            }

            # Validate required inputs
            if (!empty($config['debug'])) echo "[PE] Validating inputs...\n";
            $inputValidation = $this->validateInputs($input);
            if (!$inputValidation['valid']) {
                return $this->errorResult(self::ERR_MISSING_INPUT, $inputValidation['message'], $inputValidation);
            }

            # Sort formulas by dependency order
            if (!empty($config['debug'])) echo "[PE] Sorting dependencies...\n";
            $sortedFormulas = $this->sortByDependencies();

            # Initialize calculated values with inputs
            if (!empty($config['debug'])) echo "[PE] Init from input...\n";
            $this->initializeFromInput($input);

            # Execute formulas in order
            if (!empty($config['debug'])) echo "[PE] Executing " . count($sortedFormulas) . " formulas...\n";
            foreach ($sortedFormulas as $i => $formula) {
                if (!empty($config['debug'])) echo "[PE] F" . ($i+1) . ": {$formula['formula_code']}\n";
                $this->executeFormula($formula, $input);
            }

            # Apply rounding bucket to net
            $this->applyRoundingBucket();

            # Build result
            $endTime = microtime(true);

            # FIX C3: Report formula errors in result
            $hasFormulaErrors = $this->formulaErrorCount > 0;

            return [
                'success' => true,
                'has_formula_errors' => $hasFormulaErrors,
                'formula_error_count' => $this->formulaErrorCount,
                'employee_id' => $this->employeeId,
                'period' => [
                    'start' => $this->periodStart,
                    'end' => $this->periodEnd
                ],
                'totals' => $this->buildTotals(),
                'lines' => $this->lineDetails,
                'calculated_values' => $this->calculatedValues,
                'params_used' => $this->paramStore->getAccessedParams(),
                'trace' => $this->trace,
                'warnings' => $this->warnings,
                'meta' => [
                    'engine_version' => self::VERSION,
                    'country_code' => $this->countryCode,
                    'precision' => $this->defaultPrecision,
                    'rounding_mode' => $this->defaultRoundingMode,
                    'strict_mode' => $this->strictMode,
                    'formula_count' => count($this->formulas),
                    'formulas_executed' => count($this->formulas) - $this->formulaErrorCount,
                    'calculation_ms' => round(($endTime - $startTime) * 1000, 2)
                ]
            ];

        } catch (\Exception $e) {
            return $this->errorResult(
                self::ERR_FORMULA_ERROR,
                $e->getMessage(),
                ['trace' => $this->trace]
            );
        }
    }

    # =========================================================================
    # DATA LOADING
    # =========================================================================

    private function loadFormulas(string $date): void
    {
        $sql = "SELECT
                    f.formula_code,
                    f.target_concept_code,
                    f.execution_order,
                    fv.formula_version_id,
                    fv.dsl_expression,
                    fv.precision_override,
                    fv.rounding_mode_override,
                    fv.dependencies_json,
                    fv.conditions_json,
                    fv.formula_hash
                FROM pay_formula f
                JOIN pay_formula_version fv ON f.formula_id = fv.formula_id
                WHERE f.country_code = :country
                  AND f.is_active = 1
                  AND :date BETWEEN fv.effective_from AND fv.effective_to
                ORDER BY f.execution_order";

        CONN::dml($sql, [
            ':country' => $this->countryCode,
            ':date' => $date
        ], function($row) {
            $this->formulas[$row['formula_code']] = [
                'formula_code' => $row['formula_code'],
                'target_concept_code' => $row['target_concept_code'],
                'execution_order' => (int)$row['execution_order'],
                'formula_version_id' => (int)$row['formula_version_id'],
                'dsl_expression' => $row['dsl_expression'],
                'precision' => $row['precision_override'] !== null ? (int)$row['precision_override'] : null,
                'rounding_mode' => $row['rounding_mode_override'],
                'dependencies' => json_decode($row['dependencies_json'] ?? '[]', true),
                'conditions' => json_decode($row['conditions_json'] ?? '[]', true),
                'formula_hash' => $row['formula_hash']
            ];
        });
    }

    private function loadConcepts(string $date): void
    {
        $sql = "SELECT
                    c.concept_code,
                    c.type_code,
                    c.is_input,
                    COALESCE(cc.precision_override, c.default_precision) AS precision_digits,
                    COALESCE(cc.rounding_mode_override, c.default_rounding_mode) AS rounding_mode,
                    cc.local_name
                FROM pay_concept c
                LEFT JOIN pay_concept_catalog cc ON c.concept_id = cc.concept_id
                    AND cc.country_code = :country
                    AND cc.is_active = 1
                    AND :date BETWEEN cc.valid_from AND cc.valid_to
                WHERE c.concept_code IN (
                    SELECT DISTINCT target_concept_code FROM pay_formula
                    WHERE country_code = :country AND is_active = 1
                )";

        CONN::dml($sql, [
            ':country' => $this->countryCode,
            ':date' => $date
        ], function($row) {
            $this->concepts[$row['concept_code']] = [
                'concept_code' => $row['concept_code'],
                'type_code' => $row['type_code'],
                'is_input' => (bool)$row['is_input'],
                'precision' => (int)$row['precision_digits'],
                'rounding_mode' => $row['rounding_mode'],
                'local_name' => $row['local_name']
            ];
        });
    }

    private function loadGroups(string $date): void
    {
        $sql = "SELECT
                    g.group_code,
                    g.aggregation_type,
                    gm.concept_code,
                    gm.inclusion_type,
                    gm.weight
                FROM pay_concept_group g
                JOIN pay_concept_group_member gm ON g.group_id = gm.group_id
                WHERE g.country_code = :country
                  AND :date BETWEEN g.valid_from AND g.valid_to
                  AND :date BETWEEN gm.valid_from AND gm.valid_to
                ORDER BY g.group_code";

        CONN::dml($sql, [
            ':country' => $this->countryCode,
            ':date' => $date
        ], function($row) {
            $groupCode = $row['group_code'];
            if (!isset($this->groups[$groupCode])) {
                $this->groups[$groupCode] = [
                    'aggregation_type' => $row['aggregation_type'],
                    'members' => []
                ];
            }
            $this->groups[$groupCode]['members'][] = [
                'concept_code' => $row['concept_code'],
                'inclusion_type' => $row['inclusion_type'],
                'weight' => $row['weight']
            ];
        });
    }

    # =========================================================================
    # DEPENDENCY SORTING
    # =========================================================================

    private function sortByDependencies(): array
    {
        $sorted = [];
        $visited = [];
        $inProgress = [];

        $visit = function($formulaCode) use (&$visit, &$sorted, &$visited, &$inProgress) {
            if (isset($visited[$formulaCode])) {
                return;
            }

            if (isset($inProgress[$formulaCode])) {
                throw new \Exception("Circular dependency detected: $formulaCode", self::ERR_CIRCULAR_DEP);
            }

            if (!isset($this->formulas[$formulaCode])) {
                return;
            }

            $inProgress[$formulaCode] = true;

            $formula = $this->formulas[$formulaCode];
            foreach ($formula['dependencies'] ?? [] as $dep) {
                # Check if dependency is another formula's target (case-insensitive)
                $depLower = strtolower($dep);
                foreach ($this->formulas as $f) {
                    if (strtolower($f['target_concept_code']) === $depLower) {
                        $visit($f['formula_code']);
                    }
                }
            }

            unset($inProgress[$formulaCode]);
            $visited[$formulaCode] = true;
            $sorted[] = $this->formulas[$formulaCode];
        };

        foreach (array_keys($this->formulas) as $formulaCode) {
            $visit($formulaCode);
        }

        return $sorted;
    }

    # =========================================================================
    # CALCULATION
    # =========================================================================

    private function validateInputs(array $input): array
    {
        $missing = [];

        foreach ($this->concepts as $concept) {
            if ($concept['is_input']) {
                $conceptCode = $concept['concept_code'];
                $found = false;

                # Generate possible input keys (country-agnostic)
                $possibleKeys = $this->getInputKeyVariants($conceptCode);

                # Check in earnings, deductions, inputs sections
                foreach (['earnings', 'deductions', 'inputs'] as $section) {
                    if (isset($input[$section])) {
                        foreach ($input[$section] as $inputKey => $v) {
                            $inputKeyLower = strtolower($inputKey);
                            foreach ($possibleKeys as $possibleKey) {
                                if ($inputKeyLower === $possibleKey) {
                                    $found = true;
                                    break 3;
                                }
                            }
                        }
                    }
                }

                # Check direct input
                if (!$found) {
                    foreach ($possibleKeys as $possibleKey) {
                        if (isset($input[$possibleKey])) {
                            $found = true;
                            break;
                        }
                    }
                }

                if (!$found) {
                    $missing[] = $conceptCode;
                }
            }
        }

        if (!empty($missing)) {
            return [
                'valid' => false,
                'message' => 'Missing required inputs: ' . implode(', ', $missing),
                'missing' => $missing
            ];
        }

        return ['valid' => true];
    }

    /**
     * Get possible input key variants for a concept (country-agnostic)
     */
    private function getInputKeyVariants(string $conceptCode): array
    {
        $lower = strtolower($conceptCode);
        $variants = [$lower];

        # Strip country prefix if present (XX_X_name → name)
        if (preg_match('/^[a-z]{2}_[a-z]_(.+)$/', $lower, $m)) {
            $variants[] = $m[1];
        }

        # Also try without any prefix
        $parts = explode('_', $lower);
        if (count($parts) > 2) {
            $variants[] = implode('_', array_slice($parts, 2));
        }

        return array_unique($variants);
    }

    private function initializeFromInput(array $input): void
    {
        # Flatten earnings into calculated values
        if (isset($input['earnings'])) {
            foreach ($input['earnings'] as $key => $value) {
                $normalizedKey = strtolower($key);
                $this->calculatedValues[$normalizedKey] = Math::normalize($value);
                $this->calculatedValues["earnings.{$normalizedKey}"] = Math::normalize($value);
            }
        }

        # Flatten other inputs
        foreach (['deductions', 'inputs', 'context'] as $section) {
            if (isset($input[$section])) {
                foreach ($input[$section] as $key => $value) {
                    $normalizedKey = strtolower($key);
                    $this->calculatedValues[$normalizedKey] = is_numeric($value) ? Math::normalize($value) : $value;
                    $this->calculatedValues["{$section}.{$normalizedKey}"] = is_numeric($value) ? Math::normalize($value) : $value;
                }
            }
        }
    }

    private function executeFormula(array $formula, array $input): void
    {
        $targetConcept = $formula['target_concept_code'];
        $conceptKey = strtolower($targetConcept);

        # Check conditions
        if (!empty($formula['conditions'])) {
            if (!$this->evaluateConditions($formula['conditions'], $input)) {
                $this->trace[] = "SKIP {$targetConcept}: conditions not met";
                return;
            }
        }

        # Get precision and rounding mode
        $precision = $formula['precision']
            ?? ($this->concepts[$targetConcept]['precision'] ?? $this->defaultPrecision);
        $roundingMode = $formula['rounding_mode']
            ?? ($this->concepts[$targetConcept]['rounding_mode'] ?? $this->defaultRoundingMode);

        # Build context for RulesEngine
        $context = [
            'date' => $this->periodEnd,
            'employee_id' => $this->employeeId,
            'variables' => $input,
            'concepts' => $this->calculatedValues,
            'groups' => $this->prepareGroupsForEngine()
        ];

        $options = [
            'scale' => 10,
            'param_resolver' => $this->paramStore->createParamResolver(),
            'emp_param_resolver' => $this->createCombinedEmpParamResolver(),
            'group_resolver' => fn($code, $concepts) => $this->resolveGroup($code, $concepts)
        ];

        # Evaluate formula
        $result = RulesEngine::evaluate($formula['dsl_expression'], $context, $options);

        if (!$result['success']) {
            $this->formulaErrorCount++;
            $this->warnings[] = [
                'formula' => $formula['formula_code'],
                'concept' => $targetConcept,
                'error' => $result['error'],
                'is_formula_error' => true
            ];
            $this->trace[] = "ERROR {$targetConcept}: {$result['error']}";

            # FIX C3: In strict_mode, throw exception on formula error
            if ($this->strictMode) {
                throw new \Exception(
                    "Formula error in {$targetConcept}: {$result['error']}",
                    (int)self::ERR_FORMULA_ERROR
                );
            }
            return;
        }

        # Round result
        $rawValue = $result['value'];
        $roundedValue = Math::round($rawValue, $precision, $roundingMode);

        # Store calculated value
        $this->calculatedValues[$conceptKey] = $roundedValue;

        # Create line detail
        $conceptMeta = $this->concepts[$targetConcept] ?? ['type_code' => 'INFO'];
        $this->lineDetails[] = [
            'concept_code' => $targetConcept,
            'type_code' => $conceptMeta['type_code'],
            'amount_raw' => $rawValue,
            'amount' => $roundedValue,
            'precision_used' => $precision,
            'rounding_mode_used' => $roundingMode,
            'formula_version_id' => $formula['formula_version_id'],
            'formula_hash' => $formula['formula_hash'],
            'params_used' => $result['params_used'] ?? [],
            'calculation_explain' => $formula['dsl_expression'],
            'trace' => $result['trace'] ?? []
        ];

        $this->trace[] = "{$targetConcept} = {$roundedValue} (raw: {$rawValue})";
    }

    private function evaluateConditions(array $conditions, array $input): bool
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? 'eq';
            $value = $condition['value'] ?? '';

            $fieldValue = $this->calculatedValues[strtolower($field)]
                ?? $input['context'][$field]
                ?? null;

            if ($fieldValue === null) {
                # G4 FIX: Warn when condition field is missing (potential typo)
                $this->warnings[] = [
                    'type' => 'CONDITION_FIELD_MISSING',
                    'field' => $field,
                    'message' => "Condition field '{$field}' not found in calculated values or context"
                ];
                $this->trace[] = "CONDITION_SKIP: Field '{$field}' not found";
                return false;
            }

            switch ($operator) {
                case 'eq':
                    if ($fieldValue != $value) return false;
                    break;
                case 'neq':
                    if ($fieldValue == $value) return false;
                    break;
                case 'gt':
                    if (!Math::gt($fieldValue, $value)) return false;
                    break;
                case 'gte':
                    if (!Math::gte($fieldValue, $value)) return false;
                    break;
                case 'lt':
                    if (!Math::lt($fieldValue, $value)) return false;
                    break;
                case 'lte':
                    if (!Math::lte($fieldValue, $value)) return false;
                    break;
                case 'has_flag':
                    $flags = $input['context']['flags'] ?? [];
                    if (!in_array($value, $flags)) return false;
                    break;
            }
        }

        return true;
    }

    /**
     * Create employee param resolver that checks input first, then DB
     */
    private function createCombinedEmpParamResolver(): callable
    {
        $dbResolver = $this->paramStore->createEmpParamResolver();
        $inputParams = $this->inputEmployeeParams;

        return function(string $key, int $employeeId, string $date) use ($dbResolver, $inputParams): ?string {
            # First check input params
            if (isset($inputParams[$key])) {
                return (string)$inputParams[$key];
            }
            # Then check DB
            return $dbResolver($key, $employeeId, $date);
        };
    }

    private function prepareGroupsForEngine(): array
    {
        $prepared = [];
        foreach ($this->groups as $groupCode => $group) {
            $prepared[$groupCode] = $group['members'];
        }
        return $prepared;
    }

    private function resolveGroup(string $groupCode, array $concepts): ?string
    {
        if (!isset($this->groups[$groupCode])) {
            return null;
        }

        $group = $this->groups[$groupCode];
        $aggregationType = $group['aggregation_type'] ?? self::AGG_SUM;
        $values = [];

        foreach ($group['members'] as $member) {
            $conceptKey = strtolower($member['concept_code']);
            $weight = $member['weight'] ?? '1';

            if (isset($concepts[$conceptKey])) {
                $contribution = Math::mul($concepts[$conceptKey], $weight);
                if ($member['inclusion_type'] === 'EXCLUDE') {
                    $contribution = Math::negate($contribution);
                }
                $values[] = $contribution;
            }
        }

        if (empty($values)) {
            return '0';
        }

        # Apply aggregation type
        switch ($aggregationType) {
            case self::AGG_SUM:
                return Math::sum($values);

            case self::AGG_AVG:
                return Math::avg($values);

            case self::AGG_MIN:
                return Math::min(...$values);

            case self::AGG_MAX:
                return Math::max(...$values);

            default:
                $this->warnings[] = [
                    'group' => $groupCode,
                    'error' => "Unknown aggregation type: {$aggregationType}, using SUM"
                ];
                return Math::sum($values);
        }
    }

    private function applyRoundingBucket(): void
    {
        # Sum all line amounts (earnings - deductions)
        $totalFromLines = '0';
        foreach ($this->lineDetails as $line) {
            if (in_array($line['type_code'], ['EARNING', 'DEDUCTION'])) {
                $sign = $line['type_code'] === 'DEDUCTION' ? '-1' : '1';
                $totalFromLines = Math::add($totalFromLines, Math::mul($line['amount'], $sign));
            }
        }

        # Find net_paid concept by suffix (country-agnostic)
        $calculatedNet = $this->findConceptValueBySuffix(self::SUFFIX_NET_PAID);

        if ($calculatedNet === null) {
            # No net_paid concept found, skip rounding bucket
            return;
        }

        # Calculate rounding adjustment
        $adjustmentRaw = Math::sub($calculatedNet, $totalFromLines);

        # FIX M2: Round adjustment to payroll precision
        $adjustment = Math::round($adjustmentRaw, $this->defaultPrecision, $this->defaultRoundingMode);

        if (!Math::isZero($adjustment)) {
            $this->calculatedValues['rounding_adjustment'] = $adjustment;
            $this->lineDetails[] = [
                'concept_code' => 'ROUNDING_ADJUSTMENT',
                'type_code' => 'INFO',
                'amount_raw' => $adjustmentRaw,
                'amount' => $adjustment,
                'precision_used' => $this->defaultPrecision,
                'rounding_mode_used' => $this->defaultRoundingMode,
                'formula_version_id' => null,
                'formula_hash' => null,
                'params_used' => [],
                'calculation_explain' => 'Rounding bucket adjustment',
                'trace' => []
            ];

            $this->trace[] = "ROUNDING_ADJUSTMENT = {$adjustment}";
        }
    }

    /**
     * Find calculated value by concept suffix (country-agnostic)
     */
    private function findConceptValueBySuffix(string $suffix): ?string
    {
        $suffixLower = strtolower($suffix);

        foreach ($this->calculatedValues as $key => $value) {
            if (str_ends_with($key, $suffixLower)) {
                return $value;
            }
        }

        return null;
    }

    private function buildTotals(): array
    {
        $gross = '0';
        $deductions = '0';
        $employerCost = '0';

        # Sum from calculated lines by type_code
        foreach ($this->lineDetails as $line) {
            switch ($line['type_code']) {
                case 'EARNING':
                    $gross = Math::add($gross, $line['amount']);
                    break;
                case 'DEDUCTION':
                    $deductions = Math::add($deductions, $line['amount']);
                    break;
                case 'EMPLOYER_COST':
                    $employerCost = Math::add($employerCost, $line['amount']);
                    break;
            }
        }

        # If gross is 0 from lines, try to get from calculated GROSS concept
        if (Math::isZero($gross)) {
            $calculatedGross = $this->findConceptValueBySuffix(self::SUFFIX_GROSS);
            if ($calculatedGross !== null) {
                $gross = $calculatedGross;
            }
        }

        # Get net values by suffix (country-agnostic)
        $netBeforeTax = $this->findConceptValueBySuffix(self::SUFFIX_NET_BEFORE_TAX)
            ?? Math::sub($gross, $deductions);
        $netPaid = $this->findConceptValueBySuffix(self::SUFFIX_NET_PAID)
            ?? $netBeforeTax;
        $netSocial = $this->findConceptValueBySuffix(self::SUFFIX_NET_SOCIAL);

        return [
            'gross' => Math::round($gross, $this->defaultPrecision),
            'total_deductions' => Math::round($deductions, $this->defaultPrecision),
            'net_before_tax' => Math::round($netBeforeTax, $this->defaultPrecision),
            'net_paid' => Math::round($netPaid, $this->defaultPrecision),
            'net_social' => $netSocial !== null ? Math::round($netSocial, $this->defaultPrecision) : null,
            'employer_cost' => Math::round($employerCost, $this->defaultPrecision),
            'rounding_adjustment' => $this->calculatedValues['rounding_adjustment'] ?? '0'
        ];
    }

    private function errorResult(string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'success' => false,
            'error_code' => $code,
            'error_message' => $message
        ], $extra);
    }
}
