<?php
# custom/roles/roles.endpoint.php
# Role management endpoints
#
# Endpoints:
#   GET  /api/roles/list.json         - List all roles
#   GET  /api/roles/my-roles.json     - Get current user roles
#   POST /api/roles/assign.json       - Assign role to profile
#   POST /api/roles/revoke.json       - Revoke role from profile

namespace bX;

use bX\Router;
use bX\Response;
use bX\Profile;
use bX\CONN;
use bX\Log;
use bX\Args;
use bX\RoleTemplateService;

/**
 * @endpoint   /api/roles/list.json
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    List all available roles
 */
Router::register(['GET'], 'list\.json', function() {
    $roles = [];

    CONN::dml(
        "SELECT role_code, role_label, description, scope_type, permissions_json, status
         FROM roles
         WHERE status = 'active'
         ORDER BY scope_type, role_label",
        [],
        function($row) use (&$roles) {
            $roles[] = [
                'role_code' => $row['role_code'],
                'role_label' => $row['role_label'],
                'description' => $row['description'],
                'scope_type' => $row['scope_type'],
                'permissions' => $row['permissions_json'] ? json_decode($row['permissions_json'], true) : null
            ];
        }
    );

    return Response::json(['success' => true, 'roles' => $roles]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   /api/roles/my-roles.json
 * @method     GET
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Get roles assigned to current user
 */
Router::register(['GET'], 'my-roles\.json', function() {
    $profileId = Profile::$profile_id;

    if ($profileId <= 0) {
        return Response::json(['success' => false, 'message' => 'Not authenticated'], 401);
    }

    $roles = [];

    CONN::dml(
        "SELECT er.relationship_id, er.entity_id, er.role_code, er.relation_kind, er.status,
                r.role_label, r.scope_type, r.permissions_json,
                e.primary_name as entity_name
         FROM entity_relationships er
         JOIN roles r ON r.role_code = er.role_code
         LEFT JOIN entities e ON e.entity_id = er.entity_id
         WHERE er.profile_id = :pid
           AND er.status = 'active'
           AND r.status = 'active'",
        [':pid' => $profileId],
        function($row) use (&$roles) {
            $roles[] = [
                'relationship_id' => (int)$row['relationship_id'],
                'entity_id' => $row['entity_id'] ? (int)$row['entity_id'] : null,
                'entity_name' => $row['entity_name'],
                'role_code' => $row['role_code'],
                'role_label' => $row['role_label'],
                'scope_type' => $row['scope_type'],
                'relation_kind' => $row['relation_kind'],
                'permissions' => $row['permissions_json'] ? json_decode($row['permissions_json'], true) : null
            ];
        }
    );

    return Response::json([
        'success' => true,
        'profile_id' => $profileId,
        'roles' => $roles,
        'can_force_status' => array_reduce($roles, function($carry, $r) {
            return $carry || !empty($r['permissions']['can_force_status']);
        }, false)
    ]);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/roles/assign.json
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Assign role to a profile
 * @body       { "profile_id": int, "role_code": string, "scope_entity_id": int|null }
 */
Router::register(['POST'], 'assign\.json', function() {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $targetProfileId = (int)($input['profile_id'] ?? 0);
    $roleCode = $input['role_code'] ?? '';
    $scopeEntityId = isset($input['scope_entity_id']) ? (int)$input['scope_entity_id'] : null;

    if ($targetProfileId <= 0 || empty($roleCode)) {
        return Response::json(['success' => false, 'message' => 'profile_id and role_code required'], 400);
    }

    # Verify role exists
    $roleExists = false;
    CONN::dml(
        "SELECT role_code FROM roles WHERE role_code = :code AND status = 'active'",
        [':code' => $roleCode],
        function() use (&$roleExists) {
            $roleExists = true;
            return false;
        }
    );

    if (!$roleExists) {
        return Response::json(['success' => false, 'message' => 'Role not found: ' . $roleCode], 404);
    }

    # Check if already assigned in profile_roles
    $existingId = null;
    CONN::dml(
        "SELECT profile_role_id FROM profile_roles
         WHERE profile_id = :pid AND role_code = :role
           AND (scope_entity_id = :scope OR (scope_entity_id IS NULL AND :scope2 IS NULL))",
        [':pid' => $targetProfileId, ':role' => $roleCode, ':scope' => $scopeEntityId, ':scope2' => $scopeEntityId],
        function($row) use (&$existingId) {
            $existingId = (int)$row['profile_role_id'];
            return false;
        }
    );

    if ($existingId) {
        # Reactivate if inactive
        CONN::nodml(
            "UPDATE profile_roles SET status = 'active', updated_by_profile_id = :actor WHERE profile_role_id = :id",
            [':id' => $existingId, ':actor' => Profile::$profile_id]
        );

        return Response::json([
            'success' => true,
            'message' => 'Role already assigned, reactivated',
            'profile_role_id' => $existingId
        ]);
    }

    # Create new assignment in profile_roles
    $result = CONN::nodml(
        "INSERT INTO profile_roles (profile_id, role_code, scope_entity_id, status, created_by_profile_id)
         VALUES (:pid, :role, :scope, 'active', :actor)",
        [
            ':pid' => $targetProfileId,
            ':role' => $roleCode,
            ':scope' => $scopeEntityId,
            ':actor' => Profile::$profile_id
        ]
    );

    if (!$result['success']) {
        return Response::json(['success' => false, 'message' => 'Failed to assign role'], 500);
    }

    Log::logInfo("Role assigned", [
        'target_profile_id' => $targetProfileId,
        'role_code' => $roleCode,
        'scope_entity_id' => $scopeEntityId,
        'assigned_by' => Profile::$profile_id
    ]);

    return Response::json([
        'success' => true,
        'message' => 'Role assigned successfully',
        'profile_role_id' => (int)$result['last_id']
    ], 201);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/roles/revoke.json
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Revoke role from a profile
 * @body       { "profile_id": int, "role_code": string, "scope_entity_id": int|null }
 */
Router::register(['POST'], 'revoke\.json', function() {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $targetProfileId = (int)($input['profile_id'] ?? 0);
    $roleCode = $input['role_code'] ?? '';
    $scopeEntityId = isset($input['scope_entity_id']) ? (int)$input['scope_entity_id'] : null;

    if ($targetProfileId <= 0 || empty($roleCode)) {
        return Response::json(['success' => false, 'message' => 'profile_id and role_code required'], 400);
    }

    $result = CONN::nodml(
        "UPDATE profile_roles
         SET status = 'inactive', updated_by_profile_id = :actor
         WHERE profile_id = :pid AND role_code = :role
           AND (scope_entity_id = :scope OR (scope_entity_id IS NULL AND :scope2 IS NULL))
           AND status = 'active'",
        [':pid' => $targetProfileId, ':role' => $roleCode, ':scope' => $scopeEntityId, ':scope2' => $scopeEntityId, ':actor' => Profile::$profile_id]
    );

    if (($result['rowCount'] ?? 0) === 0) {
        return Response::json(['success' => false, 'message' => 'Role assignment not found'], 404);
    }

    Log::logInfo("Role revoked", [
        'target_profile_id' => $targetProfileId,
        'role_code' => $roleCode,
        'scope_entity_id' => $scopeEntityId,
        'revoked_by' => Profile::$profile_id
    ]);

    return Response::json(['success' => true, 'message' => 'Role revoked successfully']);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/roles/profile/<id>.json
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Get roles for specific profile
 */
Router::register(['GET'], 'profile/(?P<profileId>\d+)\.json', function($profileId) {
    $profileId = (int)$profileId;

    $roles = [];

    CONN::dml(
        "SELECT pr.profile_role_id, pr.scope_entity_id, pr.role_code,
                r.role_label, r.scope_type,
                e.primary_name as scope_name
         FROM profile_roles pr
         JOIN roles r ON r.role_code = pr.role_code
         LEFT JOIN entities e ON e.entity_id = pr.scope_entity_id
         WHERE pr.profile_id = :pid
           AND pr.status = 'active'
           AND r.status = 'active'",
        [':pid' => $profileId],
        function($row) use (&$roles) {
            $roles[] = [
                'profile_role_id' => (int)$row['profile_role_id'],
                'scope_entity_id' => $row['scope_entity_id'] ? (int)$row['scope_entity_id'] : null,
                'scope_name' => $row['scope_name'],
                'role_code' => $row['role_code'],
                'role_label' => $row['role_label'],
                'scope_type' => $row['scope_type']
            ];
        }
    );

    return Response::json(['success' => true, 'profile_id' => $profileId, 'roles' => $roles]);
}, ROUTER_SCOPE_READ);

# ============================================
# ROLE TEMPLATES - Auto-asignación por relation_kind
# ============================================

/**
 * @endpoint   /api/roles/templates/list.json
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    List role templates (global + scope-specific)
 */
Router::register(['GET'], 'templates/list\.json', function() {
    $scopeId = Profile::$scope_entity_id ?: null;
    $templates = RoleTemplateService::listTemplates($scopeId);

    # Group by relation_kind for easier UI consumption
    $grouped = [];
    foreach ($templates as $t) {
        $kind = $t['relation_kind'];
        if (!isset($grouped[$kind])) {
            $grouped[$kind] = [
                'relation_kind' => $kind,
                'roles' => []
            ];
        }
        $grouped[$kind]['roles'][] = [
            'template_id' => (int)$t['template_id'],
            'role_code' => $t['role_code'],
            'role_label' => $t['role_label'],
            'scope_entity_id' => $t['scope_entity_id'],
            'priority' => (int)$t['priority'],
            'is_global' => $t['scope_entity_id'] === null
        ];
    }

    return Response::json([
        'success' => true,
        'templates' => array_values($grouped),
        'relation_kinds' => RoleTemplateService::getRelationKinds()
    ]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   /api/roles/templates/create.json
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Create a role template
 * @body       { "relation_kind": string, "role_code": string, "global": bool, "priority": int }
 */
Router::register(['POST'], 'templates/create\.json', function() {
    $input = Args::$OPT;

    $relationKind = $input['relation_kind'] ?? '';
    $roleCode = $input['role_code'] ?? '';
    $isGlobal = !empty($input['global']);
    $priority = (int)($input['priority'] ?? 0);

    if (empty($relationKind) || empty($roleCode)) {
        return Response::json(['success' => false, 'message' => 'relation_kind and role_code required'], 400);
    }

    # Determine scope
    $scopeId = $isGlobal ? null : (Profile::$scope_entity_id ?: null);

    # Only system.admin can create global templates
    if ($isGlobal && !isSysAdmin()) {
        return Response::json(['success' => false, 'message' => 'Only system admin can create global templates'], 403);
    }

    $result = RoleTemplateService::createTemplate($relationKind, $roleCode, $scopeId, $priority);

    if (!$result['success']) {
        return Response::json(['success' => false, 'message' => $result['error']], 400);
    }

    Log::logInfo("Role template created", [
        'relation_kind' => $relationKind,
        'role_code' => $roleCode,
        'scope_entity_id' => $scopeId,
        'created_by' => Profile::$profile_id
    ]);

    return Response::json([
        'success' => true,
        'message' => 'Template created',
        'template_id' => $result['template_id']
    ], 201);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/roles/templates/delete.json
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Delete (deactivate) a role template
 * @body       { "relation_kind": string, "role_code": string, "global": bool }
 */
Router::register(['POST'], 'templates/delete\.json', function() {
    $input = Args::$OPT;

    $relationKind = $input['relation_kind'] ?? '';
    $roleCode = $input['role_code'] ?? '';
    $isGlobal = !empty($input['global']);

    if (empty($relationKind) || empty($roleCode)) {
        return Response::json(['success' => false, 'message' => 'relation_kind and role_code required'], 400);
    }

    $scopeId = $isGlobal ? null : (Profile::$scope_entity_id ?: null);

    # Only system.admin can delete global templates
    if ($isGlobal && !isSysAdmin()) {
        return Response::json(['success' => false, 'message' => 'Only system admin can delete global templates'], 403);
    }

    $result = RoleTemplateService::deleteTemplate($relationKind, $roleCode, $scopeId);

    if (!$result['success']) {
        return Response::json(['success' => false, 'message' => $result['error'] ?? 'Delete failed'], 400);
    }

    Log::logInfo("Role template deleted", [
        'relation_kind' => $relationKind,
        'role_code' => $roleCode,
        'scope_entity_id' => $scopeId,
        'deleted_by' => Profile::$profile_id
    ]);

    return Response::json(['success' => true, 'message' => 'Template deleted']);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/roles/templates/preview/<relation_kind>
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Preview what roles would be assigned for a relation_kind
 */
Router::register(['GET'], 'templates/preview/(?P<relationKind>[a-z_]+)', function($relationKind) {
    $scopeId = Profile::$scope_entity_id ?: null;
    $roles = RoleTemplateService::getTemplateRoles($relationKind, $scopeId);

    return Response::json([
        'success' => true,
        'relation_kind' => $relationKind,
        'scope_entity_id' => $scopeId,
        'roles' => $roles
    ]);
}, ROUTER_SCOPE_READ);

# Helper function
function isSysAdmin(): bool {
    foreach (Profile::$roles['assignments'] ?? [] as $assign) {
        if (($assign['roleCode'] ?? null) === 'system.admin') return true;
    }
    return false;
}
