<?php
# bintelx/kernel/RulesEngine.php
# DSL Engine for Payroll Formulas - Secure Math-based
#
# Features:
#   - Secure tokenizer + parser (no eval)
#   - Uses Math:: wrapper for all arithmetic (configurable scale)
#   - Functions: MIN, MAX, ABS, ROUND, FLOOR, CEIL, IF
#   - Country functions: CL_IMPUESTO_UNICO, BR_INSS_PROGRESSIVO, BR_IRRF_PROGRESSIVO
#   - Params: PARAM(), EMP_PARAM(), SUM_GROUP()
#   - Operators: + - * / < > <= >= == != AND OR NOT
#   - Variables prebind with dot notation (earnings.base_salary)
#   - Deterministic evaluation with explain trace
#
# @version 1.1.0

namespace bX;

require_once __DIR__ . '/Math.php';
require_once __DIR__ . '/TierCalculator.php';

class RulesEngine
{
    public const VERSION = '1.1.0';

    # Token types
    private const T_NUMBER = 'NUMBER';
    private const T_STRING = 'STRING';
    private const T_IDENT = 'IDENT';
    private const T_OP = 'OP';
    private const T_LPAREN = 'LPAREN';
    private const T_RPAREN = 'RPAREN';
    private const T_COMMA = 'COMMA';
    private const T_DOT = 'DOT';
    private const T_EOF = 'EOF';

    # Error codes
    public const ERR_SYNTAX = 'SYNTAX_ERROR';
    public const ERR_UNDEFINED_VAR = 'UNDEFINED_VARIABLE';
    public const ERR_UNDEFINED_FUNC = 'UNDEFINED_FUNCTION';
    public const ERR_DIV_ZERO = 'DIVISION_BY_ZERO';
    public const ERR_TYPE_MISMATCH = 'TYPE_MISMATCH';
    public const ERR_PARAM_NOT_FOUND = 'PARAM_NOT_FOUND';

    # Operator precedence (lower = binds tighter)
    private const PRECEDENCE = [
        'OR' => 1,
        'AND' => 2,
        '<' => 3, '>' => 3, '<=' => 3, '>=' => 3, '==' => 3, '!=' => 3,
        '+' => 4, '-' => 4,
        '*' => 5, '/' => 5,
    ];

    # Evaluation context
    private array $variables = [];
    private array $params = [];
    private array $employeeParams = [];
    private array $conceptValues = [];
    private array $groups = [];
    private string $evaluationDate = '';
    private ?int $employeeId = null;
    private int $scale = 10;
    private array $trace = [];
    private array $paramsUsed = [];

    # Callbacks for param resolution
    private $paramResolver = null;
    private $empParamResolver = null;
    private $groupResolver = null;

    /**
     * Evaluate a DSL expression
     *
     * @param string $expression DSL expression
     * @param array $context Evaluation context
     * @param array $options Evaluation options
     * @return array Result with value, success, explain
     */
    public static function evaluate(
        string $expression,
        array $context = [],
        array $options = []
    ): array {
        $engine = new self();
        return $engine->eval($expression, $context, $options);
    }

    /**
     * Validate a DSL expression without evaluating
     *
     * @param string $expression DSL expression
     * @return array Validation result
     */
    public static function validate(string $expression): array
    {
        $engine = new self();
        try {
            $tokens = $engine->tokenize($expression);
            $ast = $engine->parse($tokens);
            return [
                'valid' => true,
                'ast' => $ast,
                'dependencies' => $engine->extractDependencies($ast)
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'error_code' => self::ERR_SYNTAX
            ];
        }
    }

    /**
     * Instance evaluation
     */
    public function eval(string $expression, array $context = [], array $options = []): array
    {
        $this->trace = [];
        $this->paramsUsed = [];
        $this->scale = $options['scale'] ?? 10;
        $this->evaluationDate = $context['date'] ?? date('Y-m-d');
        $this->employeeId = $context['employee_id'] ?? null;
        $this->variables = $context['variables'] ?? [];
        $this->conceptValues = $context['concepts'] ?? [];
        $this->groups = $context['groups'] ?? [];
        $this->paramResolver = $options['param_resolver'] ?? null;
        $this->empParamResolver = $options['emp_param_resolver'] ?? null;
        $this->groupResolver = $options['group_resolver'] ?? null;

        try {
            $tokens = $this->tokenize($expression);
            $ast = $this->parse($tokens);
            $result = $this->evalNode($ast);

            return [
                'success' => true,
                'value' => $result,
                'expression' => $expression,
                'trace' => $this->trace,
                'params_used' => $this->paramsUsed,
                'engine_version' => self::VERSION
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode() ?: self::ERR_SYNTAX,
                'expression' => $expression,
                'trace' => $this->trace
            ];
        }
    }

    # =========================================================================
    # TOKENIZER
    # =========================================================================

    private function tokenize(string $expr): array
    {
        $tokens = [];
        $len = strlen($expr);
        $i = 0;

        while ($i < $len) {
            $ch = $expr[$i];

            # Whitespace
            if (ctype_space($ch)) {
                $i++;
                continue;
            }

            # Number (including decimals)
            if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $len && ctype_digit($expr[$i + 1]))) {
                $num = '';
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i++];
                }
                $tokens[] = [self::T_NUMBER, $num];
                continue;
            }

            # String literal
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $str = '';
                $i++;
                while ($i < $len && $expr[$i] !== $quote) {
                    if ($expr[$i] === '\\' && $i + 1 < $len) {
                        $i++;
                    }
                    $str .= $expr[$i++];
                }
                $i++; # Skip closing quote
                $tokens[] = [self::T_STRING, $str];
                continue;
            }

            # Identifier or keyword
            if (ctype_alpha($ch) || $ch === '_') {
                $ident = '';
                while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                    $ident .= $expr[$i++];
                }
                $tokens[] = [self::T_IDENT, strtoupper($ident)];
                continue;
            }

            # Two-char operators
            if ($i + 1 < $len) {
                $two = $ch . $expr[$i + 1];
                if (in_array($two, ['<=', '>=', '==', '!=', '&&', '||'])) {
                    $op = $two === '&&' ? 'AND' : ($two === '||' ? 'OR' : $two);
                    $tokens[] = [self::T_OP, $op];
                    $i += 2;
                    continue;
                }
            }

            # Single-char tokens
            $singleOps = ['+', '-', '*', '/', '<', '>', '!'];
            if (in_array($ch, $singleOps)) {
                $tokens[] = [self::T_OP, $ch];
                $i++;
                continue;
            }

            if ($ch === '(') { $tokens[] = [self::T_LPAREN, '(']; $i++; continue; }
            if ($ch === ')') { $tokens[] = [self::T_RPAREN, ')']; $i++; continue; }
            if ($ch === ',') { $tokens[] = [self::T_COMMA, ',']; $i++; continue; }
            if ($ch === '.') { $tokens[] = [self::T_DOT, '.']; $i++; continue; }

            throw new \Exception("Unexpected character: $ch at position $i");
        }

        $tokens[] = [self::T_EOF, null];
        return $tokens;
    }

    # =========================================================================
    # PARSER (Recursive Descent)
    # =========================================================================

    private int $pos = 0;
    private array $currentTokens = [];

    private function parse(array $tokens): array
    {
        $this->currentTokens = $tokens;
        $this->pos = 0;
        return $this->parseExpression(0);
    }

    private function parseExpression(int $minPrec): array
    {
        $left = $this->parsePrimary();

        while (true) {
            $token = $this->currentTokens[$this->pos];

            if ($token[0] !== self::T_OP && $token[0] !== self::T_IDENT) {
                break;
            }

            $op = $token[1];

            # Handle AND/OR as operators
            if (!in_array($op, ['AND', 'OR']) && $token[0] === self::T_IDENT) {
                break;
            }

            if (!isset(self::PRECEDENCE[$op])) {
                break;
            }

            $prec = self::PRECEDENCE[$op];
            if ($prec < $minPrec) {
                break;
            }

            $this->pos++;
            $right = $this->parseExpression($prec + 1);
            $left = ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    private function parsePrimary(): array
    {
        $token = $this->currentTokens[$this->pos];

        # Unary NOT
        if ($token[0] === self::T_IDENT && $token[1] === 'NOT') {
            $this->pos++;
            $operand = $this->parsePrimary();
            return ['type' => 'unary', 'op' => 'NOT', 'operand' => $operand];
        }

        # Unary minus
        if ($token[0] === self::T_OP && $token[1] === '-') {
            $this->pos++;
            $operand = $this->parsePrimary();
            return ['type' => 'unary', 'op' => '-', 'operand' => $operand];
        }

        # Parentheses
        if ($token[0] === self::T_LPAREN) {
            $this->pos++;
            $expr = $this->parseExpression(0);
            $this->expect(self::T_RPAREN);
            return $expr;
        }

        # Number
        if ($token[0] === self::T_NUMBER) {
            $this->pos++;
            return ['type' => 'number', 'value' => $token[1]];
        }

        # String
        if ($token[0] === self::T_STRING) {
            $this->pos++;
            return ['type' => 'string', 'value' => $token[1]];
        }

        # Identifier (variable, function, or boolean)
        if ($token[0] === self::T_IDENT) {
            $name = $token[1];
            $this->pos++;

            # Boolean literals
            if ($name === 'TRUE') return ['type' => 'boolean', 'value' => true];
            if ($name === 'FALSE') return ['type' => 'boolean', 'value' => false];

            # Check for dot notation (variable access)
            if ($this->currentTokens[$this->pos][0] === self::T_DOT) {
                $path = [$name];
                while ($this->currentTokens[$this->pos][0] === self::T_DOT) {
                    $this->pos++;
                    $next = $this->currentTokens[$this->pos];
                    if ($next[0] !== self::T_IDENT) {
                        throw new \Exception("Expected identifier after dot");
                    }
                    $path[] = $next[1];
                    $this->pos++;
                }
                return ['type' => 'variable', 'path' => $path];
            }

            # Check for function call
            if ($this->currentTokens[$this->pos][0] === self::T_LPAREN) {
                $this->pos++;
                $args = $this->parseArguments();
                $this->expect(self::T_RPAREN);
                return ['type' => 'call', 'name' => $name, 'args' => $args];
            }

            # Simple variable
            return ['type' => 'variable', 'path' => [$name]];
        }

        throw new \Exception("Unexpected token: " . json_encode($token));
    }

    private function parseArguments(): array
    {
        $args = [];
        if ($this->currentTokens[$this->pos][0] === self::T_RPAREN) {
            return $args;
        }

        $args[] = $this->parseExpression(0);
        while ($this->currentTokens[$this->pos][0] === self::T_COMMA) {
            $this->pos++;
            $args[] = $this->parseExpression(0);
        }
        return $args;
    }

    private function expect(string $type): void
    {
        if ($this->currentTokens[$this->pos][0] !== $type) {
            throw new \Exception("Expected $type, got " . $this->currentTokens[$this->pos][0]);
        }
        $this->pos++;
    }

    # =========================================================================
    # EVALUATOR
    # =========================================================================

    private function evalNode(array $node): string
    {
        switch ($node['type']) {
            case 'number':
                return $node['value'];

            case 'string':
                return $node['value'];

            case 'boolean':
                return $node['value'] ? '1' : '0';

            case 'variable':
                return $this->resolveVariable($node['path']);

            case 'call':
                return $this->evalFunction($node['name'], $node['args']);

            case 'unary':
                return $this->evalUnary($node['op'], $node['operand']);

            case 'binary':
                return $this->evalBinary($node['op'], $node['left'], $node['right']);

            default:
                throw new \Exception("Unknown node type: " . $node['type']);
        }
    }

    private function resolveVariable(array $path): string
    {
        $key = strtolower(implode('.', $path));

        # Check concepts first
        if (isset($this->conceptValues[$key])) {
            $this->trace[] = "VAR[$key] = {$this->conceptValues[$key]}";
            return $this->conceptValues[$key];
        }

        # Then variables
        $current = $this->variables;
        foreach ($path as $part) {
            $part = strtolower($part);
            if (!is_array($current) || !isset($current[$part])) {
                throw new \Exception("Undefined variable: " . implode('.', $path), self::ERR_UNDEFINED_VAR);
            }
            $current = $current[$part];
        }

        $value = is_numeric($current) ? (string)$current : $current;
        $this->trace[] = "VAR[$key] = $value";
        return $value;
    }

    private function evalUnary(string $op, array $operand): string
    {
        $val = $this->evalNode($operand);

        if ($op === '-') {
            return Math::negate($val, $this->scale);
        }

        if ($op === 'NOT') {
            return Math::isZero($val, $this->scale) ? '1' : '0';
        }

        throw new \Exception("Unknown unary operator: $op");
    }

    private function evalBinary(string $op, array $left, array $right): string
    {
        # Short-circuit for AND/OR
        if ($op === 'AND') {
            $l = $this->evalNode($left);
            if (Math::isZero($l, $this->scale)) return '0';
            $r = $this->evalNode($right);
            return !Math::isZero($r, $this->scale) ? '1' : '0';
        }

        if ($op === 'OR') {
            $l = $this->evalNode($left);
            if (!Math::isZero($l, $this->scale)) return '1';
            $r = $this->evalNode($right);
            return !Math::isZero($r, $this->scale) ? '1' : '0';
        }

        $l = $this->evalNode($left);
        $r = $this->evalNode($right);

        switch ($op) {
            case '+': return Math::add($l, $r, $this->scale);
            case '-': return Math::sub($l, $r, $this->scale);
            case '*': return Math::mul($l, $r, $this->scale);
            case '/':
                $result = Math::div($l, $r, $this->scale, true);
                if ($result === null) {
                    throw new \Exception("Division by zero", self::ERR_DIV_ZERO);
                }
                return $result;
            case '<':  return Math::lt($l, $r, $this->scale) ? '1' : '0';
            case '>':  return Math::gt($l, $r, $this->scale) ? '1' : '0';
            case '<=': return Math::lte($l, $r, $this->scale) ? '1' : '0';
            case '>=': return Math::gte($l, $r, $this->scale) ? '1' : '0';
            case '==': return Math::eq($l, $r, $this->scale) ? '1' : '0';
            case '!=': return !Math::eq($l, $r, $this->scale) ? '1' : '0';
            default:
                throw new \Exception("Unknown operator: $op");
        }
    }

    private function evalFunction(string $name, array $args): string
    {
        switch ($name) {
            case 'MIN':
                $vals = array_map(fn($a) => $this->evalNode($a), $args);
                return Math::min(...$vals);

            case 'MAX':
                $vals = array_map(fn($a) => $this->evalNode($a), $args);
                return Math::max(...$vals);

            case 'ABS':
                $this->assertArgCount($name, $args, 1);
                $val = $this->evalNode($args[0]);
                return Math::abs($val, $this->scale);

            case 'ROUND':
                $this->assertArgCount($name, $args, 1, 3);
                $val = $this->evalNode($args[0]);
                $precision = isset($args[1]) ? (int)$this->evalNode($args[1]) : 2;
                $mode = isset($args[2]) ? $this->evalNode($args[2]) : Math::ROUND_HALF_UP;
                return Math::round($val, $precision, $mode);

            case 'FLOOR':
                $this->assertArgCount($name, $args, 1);
                $val = $this->evalNode($args[0]);
                return Math::floor($val);

            case 'CEIL':
                $this->assertArgCount($name, $args, 1);
                $val = $this->evalNode($args[0]);
                return Math::ceil($val);

            case 'TRUNCATE':
                $this->assertArgCount($name, $args, 1, 2);
                $val = $this->evalNode($args[0]);
                $precision = isset($args[1]) ? (int)$this->evalNode($args[1]) : 0;
                return Math::truncate($val, $precision);

            case 'IF':
                $this->assertArgCount($name, $args, 3);
                $cond = $this->evalNode($args[0]);
                if (!Math::isZero($cond, $this->scale)) {
                    return $this->evalNode($args[1]);
                }
                return $this->evalNode($args[2]);

            case 'IIF':
                return $this->evalFunction('IF', $args);

            case 'COALESCE':
                foreach ($args as $arg) {
                    $val = $this->evalNode($arg);
                    if ($val !== '' && $val !== null) {
                        return $val;
                    }
                }
                return '0';

            case 'PARAM':
                $this->assertArgCount($name, $args, 1, 2);
                $key = $this->evalNode($args[0]);
                $date = isset($args[1]) ? $this->evalNode($args[1]) : $this->evaluationDate;
                return $this->resolveParam($key, $date);

            case 'EMP_PARAM':
                $this->assertArgCount($name, $args, 1, 3);
                $key = $this->evalNode($args[0]);
                $empId = isset($args[1]) ? (int)$this->evalNode($args[1]) : $this->employeeId;
                $date = isset($args[2]) ? $this->evalNode($args[2]) : $this->evaluationDate;
                return $this->resolveEmpParam($key, $empId, $date);

            case 'SUM_GROUP':
                $this->assertArgCount($name, $args, 1);
                $groupCode = $this->evalNode($args[0]);
                return $this->resolveGroupSum($groupCode);

            # =========================================================================
            # COUNTRY-SPECIFIC TAX FUNCTIONS (delegate to TierCalculator)
            # =========================================================================

            case 'CL_IMPUESTO_UNICO':
                # Chile: Impuesto Único 2ª Categoría
                # Args: base_tributable, utm_value
                $this->assertArgCount($name, $args, 2);
                $baseTributable = $this->evalNode($args[0]);
                $utm = $this->evalNode($args[1]);
                $result = TierCalculator::chileImpuestoUnico($baseTributable, $utm);
                $this->trace[] = "CL_IMPUESTO_UNICO($baseTributable, UTM=$utm) = {$result['amount']} (rate: {$result['effective_rate']})";
                return $result['amount'];

            case 'BR_INSS_PROGRESSIVO':
                # Brazil: INSS Progressive (marginal brackets since 2020)
                # Args: base_inss
                $this->assertArgCount($name, $args, 1);
                $baseInss = $this->evalNode($args[0]);
                $result = TierCalculator::brazilInssProgressivo($baseInss);
                $this->trace[] = "BR_INSS_PROGRESSIVO($baseInss) = {$result['amount']} (rate: {$result['effective_rate']})";
                return $result['amount'];

            case 'BR_IRRF_PROGRESSIVO':
                # Brazil: IRRF Progressive (with deductions)
                # Args: base_irrf
                $this->assertArgCount($name, $args, 1);
                $baseIrrf = $this->evalNode($args[0]);
                $result = TierCalculator::brazilIrrfProgressivo($baseIrrf);
                $this->trace[] = "BR_IRRF_PROGRESSIVO($baseIrrf) = {$result['amount']} (rate: {$result['effective_rate']})";
                return $result['amount'];

            case 'TIER_CALC':
                # Generic tier calculation for custom brackets
                # Args: base, tiers_json, mode (MARGINAL/FLAT/INTERPOLATED)
                $this->assertArgCount($name, $args, 2, 3);
                $base = $this->evalNode($args[0]);
                $tiersJson = $this->evalNode($args[1]);
                $mode = isset($args[2]) ? $this->evalNode($args[2]) : TierCalculator::MODE_MARGINAL;
                $tiers = json_decode($tiersJson, true);
                if (!is_array($tiers)) {
                    throw new \Exception("TIER_CALC: Invalid tiers JSON");
                }
                $result = TierCalculator::calculate($base, $tiers, $mode);
                $this->trace[] = "TIER_CALC($base, mode=$mode) = {$result['amount']}";
                return $result['amount'];

            default:
                throw new \Exception("Unknown function: $name", self::ERR_UNDEFINED_FUNC);
        }
    }

    # =========================================================================
    # PARAM RESOLUTION
    # =========================================================================

    private function resolveParam(string $key, string $date): string
    {
        if ($this->paramResolver) {
            $result = ($this->paramResolver)($key, $date);
            if ($result !== null) {
                $this->paramsUsed[$key] = ['value' => $result, 'date' => $date, 'scope' => 'GLOBAL'];
                $this->trace[] = "PARAM[$key, $date] = $result";
                return $result;
            }
        }

        if (isset($this->params[$key])) {
            $value = $this->params[$key];
            $this->paramsUsed[$key] = ['value' => $value, 'date' => $date, 'scope' => 'GLOBAL'];
            $this->trace[] = "PARAM[$key] = $value (static)";
            return $value;
        }

        throw new \Exception("Parameter not found: $key for date $date", self::ERR_PARAM_NOT_FOUND);
    }

    private function resolveEmpParam(string $key, int $empId, string $date): string
    {
        if ($this->empParamResolver) {
            $result = ($this->empParamResolver)($key, $empId, $date);
            if ($result !== null) {
                $this->paramsUsed[$key] = ['value' => $result, 'date' => $date, 'scope' => 'EMPLOYEE', 'employee_id' => $empId];
                $this->trace[] = "EMP_PARAM[$key, $empId, $date] = $result";
                return $result;
            }
        }

        if (isset($this->employeeParams[$empId][$key])) {
            $value = $this->employeeParams[$empId][$key];
            $this->paramsUsed[$key] = ['value' => $value, 'date' => $date, 'scope' => 'EMPLOYEE', 'employee_id' => $empId];
            $this->trace[] = "EMP_PARAM[$key, $empId] = $value (static)";
            return $value;
        }

        throw new \Exception("Employee parameter not found: $key for employee $empId, date $date", self::ERR_PARAM_NOT_FOUND);
    }

    private function resolveGroupSum(string $groupCode): string
    {
        if ($this->groupResolver) {
            $result = ($this->groupResolver)($groupCode, $this->conceptValues);
            if ($result !== null) {
                $this->trace[] = "SUM_GROUP[$groupCode] = $result";
                return $result;
            }
        }

        if (!isset($this->groups[$groupCode])) {
            throw new \Exception("Group not found: $groupCode");
        }

        $sum = '0';
        foreach ($this->groups[$groupCode] as $member) {
            $conceptCode = strtolower($member['concept_code']);
            $weight = $member['weight'] ?? '1';
            if (isset($this->conceptValues[$conceptCode])) {
                $memberValue = Math::mul($this->conceptValues[$conceptCode], $weight, $this->scale);
                $sum = Math::add($sum, $memberValue, $this->scale);
            }
        }

        $this->trace[] = "SUM_GROUP[$groupCode] = $sum";
        return $sum;
    }

    # =========================================================================
    # UTILITIES
    # =========================================================================

    private function assertArgCount(string $func, array $args, int $min, int $max = null): void
    {
        $max = $max ?? $min;
        $count = count($args);
        if ($count < $min || $count > $max) {
            throw new \Exception("$func expects $min-$max arguments, got $count");
        }
    }

    private function extractDependencies(array $node): array
    {
        $deps = ['variables' => [], 'params' => [], 'emp_params' => [], 'groups' => [], 'concepts' => []];

        $this->walkAst($node, function($n) use (&$deps) {
            if ($n['type'] === 'variable') {
                $deps['variables'][] = implode('.', $n['path']);
            }
            if ($n['type'] === 'call') {
                if ($n['name'] === 'PARAM' && isset($n['args'][0])) {
                    $deps['params'][] = $n['args'][0]['value'] ?? 'dynamic';
                }
                if ($n['name'] === 'EMP_PARAM' && isset($n['args'][0])) {
                    $deps['emp_params'][] = $n['args'][0]['value'] ?? 'dynamic';
                }
                if ($n['name'] === 'SUM_GROUP' && isset($n['args'][0])) {
                    $deps['groups'][] = $n['args'][0]['value'] ?? 'dynamic';
                }
            }
        });

        return $deps;
    }

    private function walkAst(array $node, callable $visitor): void
    {
        $visitor($node);

        if (isset($node['left'])) $this->walkAst($node['left'], $visitor);
        if (isset($node['right'])) $this->walkAst($node['right'], $visitor);
        if (isset($node['operand'])) $this->walkAst($node['operand'], $visitor);
        if (isset($node['args'])) {
            foreach ($node['args'] as $arg) {
                $this->walkAst($arg, $visitor);
            }
        }
    }

    /**
     * Set static params for evaluation (no resolver)
     */
    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    /**
     * Set static employee params for evaluation (no resolver)
     */
    public function setEmployeeParams(int $employeeId, array $params): self
    {
        $this->employeeParams[$employeeId] = $params;
        return $this;
    }
}
