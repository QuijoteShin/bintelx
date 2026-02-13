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
use bX\Response;
use bX\EDC;
use bX\Profile;
use bX\Args;

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
    

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $actorId = Profile::$account_id;
    $scopeId = Profile::$scope_entity_id ?? null;

    if (!$actorId) {
        
        return Response::json(['success' => false, 'message' => 'Not authenticated']);
        return;
    }

    $result = EDC::defineForm(
        $formName,
        $data,
        $actorId,
        $scopeId
    );

    return Response::json($result);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/edc/v1/forms/{formName}
 * @method     GET
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Gets active form definition
 * @tag        Forms
 */
Router::register(['GET'], 'v1/forms/(?P<formName>[^/]+)', function($formName) {
    

    $scopeId = Profile::$scope_entity_id ?? null;
    $formDef = EDC::getFormDefinition($formName, $scopeId, true);

    if ($formDef) {
        
        return Response::json(['success' => true, 'data' => $formDef]);
    } else {
        
        return Response::json(['success' => false, 'message' => 'Form not found']);
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
    

    $scopeId = Profile::$scope_entity_id ?? null;
    $status = Args::$OPT['status'] ?? null;

    $result = EDC::listForms($scopeId, true, $status);

    return Response::json($result);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/edc/v1/forms/{formDefId}/publish
 * @method     PUT
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Publishes a form (draft → published)
 * @tag        Forms
 */
Router::register(['PUT'], 'v1/forms/(?P<formDefId>\d+)/publish', function($formDefId) {
    

    $actorId = Profile::$account_id;
    if (!$actorId) {
        
        return Response::json(['success' => false, 'message' => 'Not authenticated']);
        return;
    }

    $result = EDC::publishForm((int)$formDefId, $actorId);

    return Response::json($result);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/edc/v1/forms/{formName}/responses
 * @method     GET
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Lists responses for a specific form (semantic URL)
 * @tag        Responses
 */
Router::register(['GET'], 'v1/forms/(?P<formName>[a-zA-Z0-9_-]+)/responses', function($formName) {

    $filters = [
        'form_name' => $formName,
    ];
    # scope_entity_id se pasa siempre (incluyendo null) para que listResponses
    # filtre IS NULL cuando no hay tenant — evita devolver responses de otros scopes
    if (Profile::$scope_entity_id !== null) {
        $filters['scope_entity_id'] = Profile::$scope_entity_id;
    } else {
        $filters['scope_entity_id'] = null;
    }

    return Response::json(EDC::listResponses($filters));
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
    

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $respondentId = Profile::$account_id;
    $scopeId = Profile::$scope_entity_id ?? null;

    if (!$respondentId) {
        
        return Response::json(['success' => false, 'message' => 'Not authenticated']);
        return;
    }

    if (empty($data['form_name'])) {
        
        return Response::json(['success' => false, 'message' => 'form_name is required']);
        return;
    }

    $result = EDC::createResponse(
        $data['form_name'],
        $respondentId,
        $scopeId,
        $data['metadata'] ?? []
    );

    return Response::json($result);
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
    

    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($data['fields'])) {
        
        return Response::json(['success' => false, 'message' => 'fields is required']);
        return;
    }

    $result = EDC::saveResponseData(
        (int)$responseId,
        $data['fields'],
        $data['reason'] ?? 'Data entry',
        $data['new_status'] ?? null,
        $data['contextPayload'] ?? [],
        $data['source_system'] ?? null,
        $data['device_id'] ?? null
    );

    return Response::json($result);
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


    // Obtener field_ids específicos si se proporcionan
    $fieldIds = !empty(Args::$OPT['fields']) ? explode(',', Args::$OPT['fields']) : null;

    $result = EDC::getResponseData((int)$responseId, $fieldIds);

    return Response::json($result);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/edc/v1/responses/{responseId}/snapshot
 * @method     POST
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Gets current hot data snapshot for specific fields
 * @body       (JSON) {"field_ids": ["product_name", "product_serial", ...]}
 * @tag        Responses
 */
Router::register(['POST'], 'v1/responses/(?P<responseId>\d+)/snapshot', function($responseId) {

    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $fieldIds = $data['field_ids'] ?? null;
    if ($fieldIds !== null && !is_array($fieldIds)) {
        return Response::json(['success' => false, 'message' => 'field_ids must be an array']);
    }

    $result = EDC::getResponseData((int)$responseId, $fieldIds);

    return Response::json($result);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/edc/v1/responses/{responseId}/lock
 * @method     PUT
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Locks a response (no more edits allowed)
 * @tag        Responses
 */
Router::register(['PUT'], 'v1/responses/(?P<responseId>\d+)/lock', function($responseId) {
    

    $actorId = Profile::$account_id;
    if (!$actorId) {
        
        return Response::json(['success' => false, 'message' => 'Not authenticated']);
        return;
    }

    $result = EDC::lockResponse((int)$responseId, $actorId);

    return Response::json($result);
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
    

    $filters = [];
    if (!empty(Args::$OPT['form_name'])) {
        $filters['form_name'] = Args::$OPT['form_name'];
    }
    if (!empty(Args::$OPT['respondent_profile_id'])) {
        $filters['respondent_profile_id'] = Args::$OPT['respondent_profile_id'];
    }
    if (!empty(Args::$OPT['status'])) {
        $filters['status'] = Args::$OPT['status'];
    }
    # scope_entity_id siempre presente (null = IS NULL) para aislar por tenant
    $filters['scope_entity_id'] = Profile::$scope_entity_id ?? null;

    $result = EDC::listResponses($filters);

    return Response::json($result);
}, ROUTER_SCOPE_PRIVATE);

// ==================== AUDIT TRAIL ====================

/**
 * @endpoint   /api/edc/v1/responses/{responseId}/audit/{fieldId}
 * @method     POST
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Gets audit trail for a specific field (ALCOA+ compliance)
 * @tag        Audit
 */
Router::register(['POST'], 'v1/responses/(?P<responseId>\d+)/audit/(?P<fieldId>[^/]+)', function($responseId, $fieldId) {
    

    $payload = json_decode(file_get_contents('php://input'), true) ?? [];

    // Contexto opcional
    $macro = $payload['macro_context'] ?? null;
    $event = $payload['event_context'] ?? null;

    $result = EDC::getFieldAuditTrail((int)$responseId, $fieldId, $macro, $event);

    return Response::json($result);
}, ROUTER_SCOPE_PRIVATE);
