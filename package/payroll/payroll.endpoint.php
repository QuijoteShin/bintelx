<?php # package/payroll/payroll.endpoint.php
use bX\Router;
use bX\Response;
use bX\Profile;
use bX\Args;
use bX\Payroll\Handler as PayrollHandler;

# Helper para responder en formato json o toon
function payrollResponse($data, $format, $code = 200) {
    return $format === 'toon' ? Response::toon($data, $code) : Response::json($data, $code);
}

/**
 * @endpoint   /api/payroll/calculate.json
 * @method     POST
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Calcula liquidación de nómina para un empleado
 * @body       (JSON) {"employee_id": 123, "country_code": "CL", "period_start": "2025-01-01", "period_end": "2025-01-31", "earnings": [], "inputs": {}}
 * @tag        Payroll
 */
Router::register(['POST'], 'calculate\.(?P<format>json|toon)', function($format = 'json') {
    if (!Profile::isLoggedIn()) {
        return payrollResponse(['success' => false, 'message' => 'Authentication required'], $format, 401);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $data = [
        'employee_id' => (int)($input['employee_id'] ?? 0),
        'earnings' => $input['earnings'] ?? [],
        'inputs' => $input['inputs'] ?? [],
    ];

    $options = [
        'country_code' => strtoupper($input['country_code'] ?? ''),
        'period_start' => $input['period_start'] ?? '',
        'period_end' => $input['period_end'] ?? '',
        'calc_type' => $input['calc_type'] ?? 'MONTHLY',
        'scope_entity_id' => Profile::ctx()->scopeEntityId,
    ];

    if (isset($input['finiquito'])) {
        $options['finiquito'] = $input['finiquito'];
    }

    $result = PayrollHandler::calculate($data, $options);
    $code = $result['success'] ? 200 : 422;
    return payrollResponse($result, $format, $code);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   /api/payroll/params.json
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Obtiene parámetros de nómina vigentes por país y fecha
 * @query      country (string) Código país ISO (default: CL)
 * @query      date (string) Fecha efectiva YYYY-MM-DD (default: hoy)
 * @tag        Payroll
 */
Router::register(['GET'], 'params\.(?P<format>json|toon)', function($format = 'json') {
    if (!Profile::isLoggedIn()) {
        return payrollResponse(['success' => false, 'message' => 'Authentication required'], $format, 401);
    }

    $date = Args::$GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return payrollResponse([
            'success' => false,
            'error_code' => 'INVALID_DATE',
            'message' => 'Formato de fecha inválido. Usar YYYY-MM-DD'
        ], $format, 400);
    }

    $options = [
        'country_code' => Args::$GET['country'] ?? 'CL',
        'date' => $date,
        'scope_entity_id' => Profile::ctx()->scopeEntityId,
    ];

    $result = PayrollHandler::getParams([], $options);
    return payrollResponse($result, $format);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   /api/payroll/concepts.json
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Obtiene conceptos de nómina activos por país
 * @query      country (string) Código país ISO (default: CL)
 * @query      type (string) Tipo de concepto: EARNING, DEDUCTION, EMPLOYER_COST, INFO (opcional)
 * @tag        Payroll
 */
Router::register(['GET'], 'concepts\.(?P<format>json|toon)', function($format = 'json') {
    if (!Profile::isLoggedIn()) {
        return payrollResponse(['success' => false, 'message' => 'Authentication required'], $format, 401);
    }

    $options = [
        'country_code' => Args::$GET['country'] ?? 'CL',
        'type' => Args::$GET['type'] ?? null,
    ];

    $result = PayrollHandler::getConcepts([], $options);
    return payrollResponse($result, $format);
}, ROUTER_SCOPE_READ);
