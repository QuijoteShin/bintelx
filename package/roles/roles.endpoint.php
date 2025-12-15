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
 * @body       { "profile_id": int, "role_code": string, "entity_id": int|null }
 */
Router::register(['POST'], 'assign\.json', function() {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $targetProfileId = (int)($input['profile_id'] ?? 0);
    $roleCode = $input['role_code'] ?? '';
    $entityId = isset($input['entity_id']) ? (int)$input['entity_id'] : null;
    $relationKind = $input['relation_kind'] ?? 'role_assignment';

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

    # Check if already assigned
    $existingId = null;
    CONN::dml(
        "SELECT relationship_id FROM entity_relationships
         WHERE profile_id = :pid AND role_code = :role
           AND (entity_id = :eid OR (entity_id IS NULL AND :eid IS NULL))",
        [':pid' => $targetProfileId, ':role' => $roleCode, ':eid' => $entityId],
        function($row) use (&$existingId) {
            $existingId = (int)$row['relationship_id'];
            return false;
        }
    );

    if ($existingId) {
        # Reactivate if inactive
        CONN::nodml(
            "UPDATE entity_relationships SET status = 'active' WHERE relationship_id = :id",
            [':id' => $existingId]
        );

        return Response::json([
            'success' => true,
            'message' => 'Role already assigned, reactivated',
            'relationship_id' => $existingId
        ]);
    }

    # Create new assignment
    $result = CONN::nodml(
        "INSERT INTO entity_relationships (profile_id, entity_id, role_code, relation_kind, status)
         VALUES (:pid, :eid, :role, :kind, 'active')",
        [
            ':pid' => $targetProfileId,
            ':eid' => $entityId,
            ':role' => $roleCode,
            ':kind' => $relationKind
        ]
    );

    if (!$result['success']) {
        return Response::json(['success' => false, 'message' => 'Failed to assign role'], 500);
    }

    Log::logInfo("Role assigned", [
        'target_profile_id' => $targetProfileId,
        'role_code' => $roleCode,
        'entity_id' => $entityId,
        'assigned_by' => Profile::$profile_id
    ]);

    return Response::json([
        'success' => true,
        'message' => 'Role assigned successfully',
        'relationship_id' => (int)$result['last_id']
    ], 201);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/roles/revoke.json
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Revoke role from a profile
 * @body       { "profile_id": int, "role_code": string, "entity_id": int|null }
 */
Router::register(['POST'], 'revoke\.json', function() {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $targetProfileId = (int)($input['profile_id'] ?? 0);
    $roleCode = $input['role_code'] ?? '';
    $entityId = isset($input['entity_id']) ? (int)$input['entity_id'] : null;

    if ($targetProfileId <= 0 || empty($roleCode)) {
        return Response::json(['success' => false, 'message' => 'profile_id and role_code required'], 400);
    }

    $result = CONN::nodml(
        "UPDATE entity_relationships
         SET status = 'inactive'
         WHERE profile_id = :pid AND role_code = :role
           AND (entity_id = :eid OR (entity_id IS NULL AND :eid IS NULL))
           AND status = 'active'",
        [':pid' => $targetProfileId, ':role' => $roleCode, ':eid' => $entityId]
    );

    if ($result['affected_rows'] === 0) {
        return Response::json(['success' => false, 'message' => 'Role assignment not found'], 404);
    }

    Log::logInfo("Role revoked", [
        'target_profile_id' => $targetProfileId,
        'role_code' => $roleCode,
        'entity_id' => $entityId,
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
        "SELECT er.relationship_id, er.entity_id, er.role_code, er.relation_kind,
                r.role_label, r.scope_type,
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
                'relation_kind' => $row['relation_kind']
            ];
        }
    );

    return Response::json(['success' => true, 'profile_id' => $profileId, 'roles' => $roles]);
}, ROUTER_SCOPE_READ);
