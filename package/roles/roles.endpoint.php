<?php
# package/roles/roles.endpoint.php
# Role management endpoints
#
# Endpoints:
#   GET  /api/roles/list.json              - List visible roles (is_hidden=0)
#   GET  /api/roles/list-all.json          - List ALL roles (admin only, includes hidden)
#   GET  /api/roles/my-roles.json          - Get current user roles
#   POST /api/roles/assign.json            - Assign role to profile (EAV audited)
#   POST /api/roles/revoke.json            - Revoke role from profile (EAV audited)

namespace bX;

use bX\Router;
use bX\Response;
use bX\Profile;
use bX\CONN;
use bX\Log;
use bX\Args;
use bX\RoleTemplateService;
use bX\DataCaptureService;

# ============================================
# EAV variable definitions (idempotent UPSERT)
# ============================================
DataCaptureService::defineCaptureField([
    'unique_name' => 'profile.role_code',
    'label' => 'Rol asignado',
    'data_type' => 'STRING',
    'is_pii' => false
], Profile::ctx()->profileId ?: 0);

DataCaptureService::defineCaptureField([
    'unique_name' => 'profile.role_action',
    'label' => 'Accion sobre rol',
    'data_type' => 'STRING',
    'is_pii' => false
], Profile::ctx()->profileId ?: 0);

DataCaptureService::defineCaptureField([
    'unique_name' => 'profile.role_scope',
    'label' => 'Scope del rol',
    'data_type' => 'DECIMAL',
    'is_pii' => false
], Profile::ctx()->profileId ?: 0);

/**
 * @endpoint   /api/roles/list.json
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    List visible roles (excludes is_hidden=1)
 */
Router::register(['GET'], 'list\.json', function() {
    $roles = [];

    CONN::dml(
        "SELECT role_code, role_label, description, scope_type, role_group, permissions_json, status
         FROM roles
         WHERE status = 'active'
           AND is_hidden = 0
         ORDER BY role_group, scope_type, role_label",
        [],
        function($row) use (&$roles) {
            $roles[] = [
                'role_code' => $row['role_code'],
                'role_label' => $row['role_label'],
                'description' => $row['description'],
                'scope_type' => $row['scope_type'],
                'role_group' => $row['role_group'],
                'permissions' => $row['permissions_json'] ? json_decode($row['permissions_json'], true) : null
            ];
        }
    );

    return Response::json(['success' => true, 'roles' => $roles]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   /api/roles/list-all.json
 * @method     GET
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    List ALL roles including hidden (admin only)
 */
Router::register(['GET'], 'list-all\.json', function() {
    if (!Profile::hasRole(roleCode: 'system.admin')) {
        return Response::json(['success' => false, 'message' => 'Forbidden'], 403);
    }

    $roles = [];

    CONN::dml(
        "SELECT role_code, role_label, description, scope_type, role_group,
                is_hidden, permissions_json, status
         FROM roles
         ORDER BY role_group, scope_type, role_label",
        [],
        function($row) use (&$roles) {
            $roles[] = [
                'role_code' => $row['role_code'],
                'role_label' => $row['role_label'],
                'description' => $row['description'],
                'scope_type' => $row['scope_type'],
                'role_group' => $row['role_group'],
                'is_hidden' => (int)$row['is_hidden'],
                'status' => $row['status'],
                'permissions' => $row['permissions_json'] ? json_decode($row['permissions_json'], true) : null
            ];
        }
    );

    return Response::json(['success' => true, 'roles' => $roles]);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/roles/my-roles.json
 * @method     GET
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Get roles assigned to current user
 */
Router::register(['GET'], 'my-roles\.json', function() {
    $profileId = Profile::ctx()->profileId;

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
 * @purpose    Assign role to a profile (EAV audited)
 * @body       { "profile_id": int, "role_code": string, "scope_entity_id": int|null }
 */
Router::register(['POST'], 'assign\.json', function() {
    $input = Args::ctx()->opt;;

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
           AND scope_entity_id = :scope",
        [':pid' => $targetProfileId, ':role' => $roleCode, ':scope' => $scopeEntityId],
        function($row) use (&$existingId) {
            $existingId = (int)$row['profile_role_id'];
            return false;
        }
    );

    if ($existingId) {
        # Reactivate if inactive
        CONN::nodml(
            "UPDATE profile_roles SET status = 'active', updated_by_profile_id = :actor WHERE profile_role_id = :id",
            [':id' => $existingId, ':actor' => Profile::ctx()->profileId]
        );

        # EAV audit: reactivation
        _auditRoleChange($targetProfileId, $roleCode, $scopeEntityId, 'reactivate');

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
            ':actor' => Profile::ctx()->profileId
        ]
    );

    if (!$result['success']) {
        return Response::json(['success' => false, 'message' => 'Failed to assign role'], 500);
    }

    # EAV audit: assign
    _auditRoleChange($targetProfileId, $roleCode, $scopeEntityId, 'assign');

    Log::logInfo("Role assigned", [
        'target_profile_id' => $targetProfileId,
        'role_code' => $roleCode,
        'scope_entity_id' => $scopeEntityId,
        'assigned_by' => Profile::ctx()->profileId
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
 * @purpose    Revoke role from a profile (EAV audited)
 * @body       { "profile_id": int, "role_code": string, "scope_entity_id": int|null }
 */
Router::register(['POST'], 'revoke\.json', function() {
    $input = Args::ctx()->opt;;

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
           AND scope_entity_id = :scope
           AND status = 'active'",
        [':pid' => $targetProfileId, ':role' => $roleCode, ':scope' => $scopeEntityId, ':actor' => Profile::ctx()->profileId]
    );

    if (($result['rowCount'] ?? 0) === 0) {
        return Response::json(['success' => false, 'message' => 'Role assignment not found'], 404);
    }

    # EAV audit: revoke
    _auditRoleChange($targetProfileId, $roleCode, $scopeEntityId, 'revoke');

    Log::logInfo("Role revoked", [
        'target_profile_id' => $targetProfileId,
        'role_code' => $roleCode,
        'scope_entity_id' => $scopeEntityId,
        'revoked_by' => Profile::ctx()->profileId
    ]);

    return Response::json(['success' => true, 'message' => 'Role revoked successfully']);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/roles/profile/<id>.json
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Get roles for specific profile (includes hidden)
 */
Router::register(['GET'], 'profile/(?P<profileId>\d+)\.json', function($profileId) {
    $profileId = (int)$profileId;

    $roles = [];

    CONN::dml(
        "SELECT pr.profile_role_id, pr.scope_entity_id, pr.role_code,
                r.role_label, r.scope_type, r.role_group, r.is_hidden,
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
                'scope_type' => $row['scope_type'],
                'role_group' => $row['role_group'],
                'is_hidden' => (int)$row['is_hidden']
            ];
        }
    );

    return Response::json(['success' => true, 'profile_id' => $profileId, 'roles' => $roles]);
}, ROUTER_SCOPE_READ);

# ============================================
# ROLE TEMPLATES - Auto-asignacion por relation_kind
# ============================================

/**
 * @endpoint   /api/roles/templates/list.json
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    List role templates (global + scope-specific)
 */
Router::register(['GET'], 'templates/list\.json', function() {
    $scopeId = Profile::ctx()->scopeEntityId ?: null;
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
            'is_global' => in_array((int)$t['scope_entity_id'], Tenant::globalIds(), true)
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
    $input = Args::ctx()->opt;

    $relationKind = $input['relation_kind'] ?? '';
    $roleCode = $input['role_code'] ?? '';
    $isGlobal = !empty($input['global']);
    $priority = (int)($input['priority'] ?? 0);

    if (empty($relationKind) || empty($roleCode)) {
        return Response::json(['success' => false, 'message' => 'relation_kind and role_code required'], 400);
    }

    # Determine scope (global -> GLOBAL_TENANT_ID)
    $scopeId = $isGlobal ? (Tenant::globalIds()[0] ?? null) : (Profile::ctx()->scopeEntityId ?: null);

    # Only system.admin can create global templates
    if ($isGlobal && !Profile::hasRole(roleCode: 'system.admin')) {
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
        'created_by' => Profile::ctx()->profileId
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
    $input = Args::ctx()->opt;

    $relationKind = $input['relation_kind'] ?? '';
    $roleCode = $input['role_code'] ?? '';
    $isGlobal = !empty($input['global']);

    if (empty($relationKind) || empty($roleCode)) {
        return Response::json(['success' => false, 'message' => 'relation_kind and role_code required'], 400);
    }

    $scopeId = $isGlobal ? (Tenant::globalIds()[0] ?? null) : (Profile::ctx()->scopeEntityId ?: null);

    # Only system.admin can delete global templates
    if ($isGlobal && !Profile::hasRole(roleCode: 'system.admin')) {
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
        'deleted_by' => Profile::ctx()->profileId
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
    $scopeId = Profile::ctx()->scopeEntityId ?: null;
    $roles = RoleTemplateService::getTemplateRoles($relationKind, $scopeId);

    return Response::json([
        'success' => true,
        'relation_kind' => $relationKind,
        'scope_entity_id' => $scopeId,
        'roles' => $roles
    ]);
}, ROUTER_SCOPE_READ);

# ============================================
# Internal: EAV audit for role changes
# ============================================

function _auditRoleChange(int $targetProfileId, string $roleCode, ?int $scopeEntityId, string $action): void {
    # Resolve subject entity_id from target profile
    $subjectEntityId = 0;
    CONN::dml(
        "SELECT primary_entity_id FROM profiles WHERE profile_id = :pid LIMIT 1",
        [':pid' => $targetProfileId],
        function($row) use (&$subjectEntityId) {
            $subjectEntityId = (int)$row['primary_entity_id'];
            return false;
        }
    );

    if ($subjectEntityId <= 0) {
        Log::logWarning("_auditRoleChange: no entity found for profile $targetProfileId");
        return;
    }

    DataCaptureService::saveData(
        Profile::ctx()->profileId,
        $subjectEntityId,
        Profile::ctx()->scopeEntityId ?: null,
        [
            'macro_context' => 'profile',
            'event_context' => 'role_assignment',
            'sub_context' => $roleCode
        ],
        [
            ['variable_name' => 'profile.role_code', 'value' => $roleCode],
            ['variable_name' => 'profile.role_action', 'value' => $action],
            ['variable_name' => 'profile.role_scope', 'value' => (string)($scopeEntityId ?? 0)],
        ],
        'role_change'
    );
}
