<?php
/**
 * EDC Endpoints - API REST for form operations
 *
 * Consumes generic bX\EDC class from kernel
 *
 * @package edc
 */

namespace edc;

use bX\Router;
use bX\EDC;
use bX\Profile;
use bX\Args;

// ==================== TEST ENDPOINTS ====================

/**
 * @endpoint   /api/edc/test
 * @method     GET
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Simple test endpoint to verify EDC module loads
 * @tag        Test
 */
Router::register(['GET'], 'test', function() {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'EDC module loaded successfully!',
        'timestamp' => date('c')
    ]);
}, ROUTER_SCOPE_PUBLIC);

// ==================== FORM DEFINITIONS ====================

/**
 * @endpoint   /api/edc/v1/forms/{formName}
 * @method     POST
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Creates or updates a form definition
 * @body       (JSON) {"title": "...", "description": "...", "schema": {...}}
 * @tag        Forms
 */
Router::register(['POST'], 'v1/forms/(?P<formName>[^/]+)', function($formName) {
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $actorId = Profile::$account_id;
    $scopeId = Profile::$scope_entity_id ?? null;

    if (!$actorId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        return;
    }

    $result = EDC::defineForm(
        $formName,
        $data,
        $actorId,
        $scopeId
    );

    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($result);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/edc/v1/forms/{formName}
 * @method     GET
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Gets active form definition
 * @tag        Forms
 */
Router::register(['GET'], 'v1/forms/(?P<formName>[^/]+)', function($formName) {
    header('Content-Type: application/json');

    $scopeId = Profile::$scope_entity_id ?? null;
    $formDef = EDC::getFormDefinition($formName, $scopeId, true);

    if ($formDef) {
        http_response_code(200);
        echo json_encode(['success' => true, 'data' => $formDef]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Form not found']);
    }
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/edc/v1/forms
 * @method     GET
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Lists all active forms
 * @param      status string Filter by status (optional)
 * @tag        Forms
 */
Router::register(['GET'], 'v1/forms', function() {
    header('Content-Type: application/json');

    $scopeId = Profile::$scope_entity_id ?? null;
    $status = Args::$OPT['status'] ?? null;

    $result = EDC::listForms($scopeId, true, $status);

    http_response_code($result['success'] ? 200 : 500);
    echo json_encode($result);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/edc/v1/forms/{formDefId}/publish
 * @method     PUT
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Publishes a form (draft → published)
 * @tag        Forms
 */
Router::register(['PUT'], 'v1/forms/(?P<formDefId>\d+)/publish', function($formDefId) {
    header('Content-Type: application/json');

    $actorId = Profile::$account_id;
    if (!$actorId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        return;
    }

    $result = EDC::publishForm((int)$formDefId, $actorId);

    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($result);
}, ROUTER_SCOPE_PRIVATE);

// ==================== FORM RESPONSES ====================

/**
 * @endpoint   /api/edc/v1/responses
 * @method     POST
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Creates a new form response
 * @body       (JSON) {"form_name": "...", "metadata": {...}}
 * @tag        Responses
 */
Router::register(['POST'], 'v1/responses', function() {
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $respondentId = Profile::$account_id;
    $scopeId = Profile::$scope_entity_id ?? null;

    if (!$respondentId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        return;
    }

    if (empty($data['form_name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'form_name is required']);
        return;
    }

    $result = EDC::createResponse(
        $data['form_name'],
        $respondentId,
        $scopeId,
        $data['metadata'] ?? []
    );

    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($result);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/edc/v1/responses/{responseId}/data
 * @method     POST
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Saves form response data (versioned via DataCaptureService)
 * @body       (JSON) {"fields": {"field_id": "value", ...}, "reason": "...", "new_status": "..."}
 * @tag        Responses
 */
Router::register(['POST'], 'v1/responses/(?P<responseId>\d+)/data', function($responseId) {
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($data['fields'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'fields is required']);
        return;
    }

    $result = EDC::saveResponseData(
        (int)$responseId,
        $data['fields'],
        $data['reason'] ?? 'Data entry',
        $data['new_status'] ?? null
    );

    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($result);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/edc/v1/responses/{responseId}
 * @method     GET
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Gets form response data
 * @param      fields string[] Specific fields to retrieve (optional)
 * @tag        Responses
 */
Router::register(['GET'], 'v1/responses/(?P<responseId>\d+)', function($responseId) {
    header('Content-Type: application/json');

    // Obtener field_ids específicos si se proporcionan
    $fieldIds = !empty(Args::$OPT['fields']) ? explode(',', Args::$OPT['fields']) : null;

    $result = EDC::getResponseData((int)$responseId, $fieldIds);

    http_response_code($result['success'] ? 200 : 404);
    echo json_encode($result);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/edc/v1/responses/{responseId}/lock
 * @method     PUT
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Locks a response (no more edits allowed)
 * @tag        Responses
 */
Router::register(['PUT'], 'v1/responses/(?P<responseId>\d+)/lock', function($responseId) {
    header('Content-Type: application/json');

    $actorId = Profile::$account_id;
    if (!$actorId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        return;
    }

    $result = EDC::lockResponse((int)$responseId, $actorId);

    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($result);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/edc/v1/responses
 * @method     GET
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Lists form responses with filters
 * @param      form_name string Filter by form name (optional)
 * @param      status string Filter by status (optional)
 * @tag        Responses
 */
Router::register(['GET'], 'v1/responses', function() {
    header('Content-Type: application/json');

    $filters = [
        'form_name' => Args::$OPT['form_name'] ?? null,
        'respondent_profile_id' => Args::$OPT['respondent_profile_id'] ?? null,
        'status' => Args::$OPT['status'] ?? null,
        'scope_entity_id' => Profile::$scope_entity_id ?? null
    ];

    // Remover filtros null
    $filters = array_filter($filters, fn($v) => $v !== null);

    $result = EDC::listResponses($filters);

    http_response_code($result['success'] ? 200 : 500);
    echo json_encode($result);
}, ROUTER_SCOPE_PRIVATE);

// ==================== AUDIT TRAIL ====================

/**
 * @endpoint   /api/edc/v1/responses/{responseId}/audit/{fieldId}
 * @method     GET
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Gets audit trail for a specific field (ALCOA+ compliance)
 * @tag        Audit
 */
Router::register(['GET'], 'v1/responses/(?P<responseId>\d+)/audit/(?P<fieldId>[^/]+)', function($responseId, $fieldId) {
    header('Content-Type: application/json');

    $result = EDC::getFieldAuditTrail((int)$responseId, $fieldId);

    http_response_code($result['success'] ? 200 : 404);
    echo json_encode($result);
}, ROUTER_SCOPE_PRIVATE);

// ==================== TEST ENDPOINT (END) ====================

/**
 * @endpoint   /api/edc/ping
 * @method     GET
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Test endpoint at end of file
 * @tag        Test
 */
Router::register(['GET'], 'ping', function() {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'EDC ping successful - file loaded completely',
        'endpoints_registered' => 'All endpoints should be loaded',
        'timestamp' => date('c')
    ]);
}, ROUTER_SCOPE_PUBLIC);
