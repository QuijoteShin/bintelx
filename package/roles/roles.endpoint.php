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
use bX\ACL;
use bX\RoleTemplateService;
use bX\RolePackageService;
use bX\ModuleCategoryService;
use bX\TenantPositionService;
use bX\DataCaptureService;
use bX\Cache;

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
 * @purpose    Assign role to a profile via ACL (EAV audited)
 * @body       { "profile_id": int, "role_code": string, "scope_entity_id": int }
 */
Router::register(['POST'], 'assign\.json', function() {
    $input = Args::ctx()->opt;

    $targetProfileId = (int)($input['profile_id'] ?? 0);
    $roleCode = $input['role_code'] ?? '';
    $scopeEntityId = (int)($input['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);

    if ($targetProfileId <= 0 || empty($roleCode)) {
        return Response::json(['success' => false, 'message' => 'profile_id and role_code required'], 400);
    }

    if ($scopeEntityId <= 0) {
        return Response::json(['success' => false, 'message' => 'scope_entity_id required (cannot be NULL)'], 400);
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

    # Delegar a ACL (maneja dedup, reactivación, cache invalidation)
    $sourcePackage = $input['source_package_code'] ?? null;
    $result = ACL::assignRole($targetProfileId, $roleCode, $scopeEntityId, null, $sourcePackage);

    if (!$result['success']) {
        return Response::json(['success' => false, 'message' => $result['error'] ?? 'Failed to assign role'], 500);
    }

    # EAV audit
    $action = ($result['already_exists'] ?? false) ? 'reactivate' : 'assign';
    _auditRoleChange($targetProfileId, $roleCode, $scopeEntityId, $action);

    Log::logInfo("Role assigned via ACL", [
        'target_profile_id' => $targetProfileId,
        'role_code' => $roleCode,
        'scope_entity_id' => $scopeEntityId,
        'already_existed' => $result['already_exists'] ?? false,
        'assigned_by' => Profile::ctx()->profileId
    ]);

    return Response::json([
        'success' => true,
        'message' => ($result['already_exists'] ?? false) ? 'Role reactivated' : 'Role assigned',
        'already_exists' => $result['already_exists'] ?? false
    ], ($result['already_exists'] ?? false) ? 200 : 201);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/roles/revoke.json
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Revoke role from a profile via ACL (EAV audited)
 * @body       { "profile_id": int, "role_code": string, "scope_entity_id": int }
 */
Router::register(['POST'], 'revoke\.json', function() {
    $input = Args::ctx()->opt;

    $targetProfileId = (int)($input['profile_id'] ?? 0);
    $roleCode = $input['role_code'] ?? '';
    $scopeEntityId = (int)($input['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);

    if ($targetProfileId <= 0 || empty($roleCode)) {
        return Response::json(['success' => false, 'message' => 'profile_id and role_code required'], 400);
    }

    if ($scopeEntityId <= 0) {
        return Response::json(['success' => false, 'message' => 'scope_entity_id required (cannot be NULL)'], 400);
    }

    # Delegar a ACL (maneja cache invalidation)
    $result = ACL::revokeRole($targetProfileId, $roleCode, $scopeEntityId);

    if (!$result['success']) {
        return Response::json(['success' => false, 'message' => $result['error'] ?? 'Failed to revoke role'], 500);
    }

    # EAV audit: revoke
    _auditRoleChange($targetProfileId, $roleCode, $scopeEntityId, 'revoke');

    Log::logInfo("Role revoked via ACL", [
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
# Cache invalidation — invalidar roles de un perfil en Channel Server
# ============================================

/**
 * @endpoint   POST /api/roles/invalidate-cache
 * @scope      ROUTER_SCOPE_WRITE (migrar a SYSTEM después de testing)
 * @purpose    Forzar invalidación de cache de roles para un perfil
 */
Router::register(['POST'], 'invalidate-cache', function() {
    $profileId = (int)(Args::ctx()->opt['profile_id'] ?? 0);
    if ($profileId <= 0) {
        return Response::json(['success' => false, 'message' => 'profile_id required'], 400);
    }
    Cache::delete('global:profile:roles', (string)$profileId);
    Cache::notifyChannel('global:profile:roles', (string)$profileId);
    return Response::json(['success' => true, 'invalidated' => $profileId]);
}, ROUTER_SCOPE_WRITE);

# ============================================
# RBAC: Package endpoints
# ============================================

/**
 * @endpoint   GET /api/roles/packages/list.json
 * @scope      ROUTER_SCOPE_READ
 * @purpose    List all role packages for current scope
 */
Router::register(['GET'], 'packages/list\.json', function() {
    $packages = RolePackageService::list(Profile::ctx()->scopeEntityId ?: null);
    return Response::json(['success' => true, 'data' => $packages]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   POST /api/roles/packages/create.json
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Create a new role package
 */
Router::register(['POST'], 'packages/create\.json', function() {
    $opt = Args::ctx()->opt;
    $result = RolePackageService::create($opt);
    return Response::json($result, $result['success'] ? 200 : 400);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   POST /api/roles/packages/assign.json
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Assign a package to a profile (expands to individual roles)
 */
Router::register(['POST'], 'packages/assign\.json', function() {
    $opt = Args::ctx()->opt;
    $profileId = (int)($opt['profile_id'] ?? 0);
    $packageCode = $opt['package_code'] ?? '';
    $scopeEntityId = (int)($opt['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);

    if ($profileId <= 0 || empty($packageCode) || $scopeEntityId <= 0) {
        return Response::json(['success' => false, 'message' => 'profile_id, package_code, scope_entity_id required'], 400);
    }

    $result = ACL::applyPackage($profileId, $packageCode, $scopeEntityId);
    return Response::json($result);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   POST /api/roles/packages/revoke.json
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Revoke all roles from a package for a profile
 */
Router::register(['POST'], 'packages/revoke\.json', function() {
    $opt = Args::ctx()->opt;
    $profileId = (int)($opt['profile_id'] ?? 0);
    $packageCode = $opt['package_code'] ?? '';
    $scopeEntityId = (int)($opt['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);

    if ($profileId <= 0 || empty($packageCode) || $scopeEntityId <= 0) {
        return Response::json(['success' => false, 'message' => 'profile_id, package_code, scope_entity_id required'], 400);
    }

    $result = ACL::revokePackage($profileId, $packageCode, $scopeEntityId);
    return Response::json($result);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   GET /api/roles/packages/diff.json
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Preview diff between current profile roles and package definition
 */
Router::register(['GET'], 'packages/diff\.json', function() {
    $profileId = (int)($_GET['profile_id'] ?? 0);
    $packageCode = $_GET['package_code'] ?? '';
    $scopeEntityId = (int)($_GET['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);

    if ($profileId <= 0 || empty($packageCode)) {
        return Response::json(['success' => false, 'message' => 'profile_id, package_code required'], 400);
    }

    $diff = RolePackageService::diffPackageVsProfile($profileId, $packageCode, $scopeEntityId);
    return Response::json(['success' => true, 'data' => $diff]);
}, ROUTER_SCOPE_READ);

# ============================================
# RBAC: Package Route Permissions
# ============================================

/**
 * @endpoint   GET /api/roles/packages/route-permissions.json?package_code=X
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Get route permissions defined for a package
 */
Router::register(['GET'], 'packages/route-permissions\.json', function() {
    $packageCode = $_GET['package_code'] ?? '';
    if (empty($packageCode)) {
        return Response::json(['success' => false, 'message' => 'package_code required'], 400);
    }

    $scopeEntityId = (int)($_GET['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);
    $permissions = RolePackageService::getPackageRoutePermissions($packageCode, $scopeEntityId ?: null);

    return Response::json(['success' => true, 'data' => $permissions]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   POST /api/roles/packages/route-permissions.json
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Set route permissions for a package (replace all) + sync synthetic role
 */
Router::register(['POST'], 'packages/route-permissions\.json', function() {
    $opt = Args::ctx()->opt;
    $packageCode = $opt['package_code'] ?? '';
    $permissions = $opt['route_permissions'] ?? [];

    if (empty($packageCode)) {
        return Response::json(['success' => false, 'message' => 'package_code required'], 400);
    }

    $scopeEntityId = (int)($opt['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);
    $package = RolePackageService::resolvePackage($packageCode, $scopeEntityId ?: null);

    if (!$package) {
        return Response::json(['success' => false, 'message' => 'Package not found'], 404);
    }

    $result = RolePackageService::setPackageRoutePermissions(
        (int)$package['package_id'],
        $permissions,
        $packageCode
    );

    return Response::json($result);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   POST /api/roles/packages/roles.json
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Set roles for a package (replace all)
 */
Router::register(['POST'], 'packages/roles\.json', function() {
    $opt = Args::ctx()->opt;
    $packageCode = $opt['package_code'] ?? '';
    $roleCodes = $opt['roles'] ?? [];

    if (empty($packageCode)) {
        return Response::json(['success' => false, 'message' => 'package_code required'], 400);
    }

    $scopeEntityId = (int)($opt['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);
    $package = RolePackageService::resolvePackage($packageCode, $scopeEntityId ?: null);

    if (!$package) {
        return Response::json(['success' => false, 'message' => 'Package not found'], 404);
    }

    $result = RolePackageService::setPackageRoles(
        (int)$package['package_id'],
        $roleCodes,
        (int)$package['scope_entity_id']
    );

    return Response::json($result);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   POST /api/roles/packages/categories.json
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Set categories for a package (replace all)
 */
Router::register(['POST'], 'packages/categories\.json', function() {
    $opt = Args::ctx()->opt;
    $packageCode = $opt['package_code'] ?? '';
    $categoryCodes = $opt['categories'] ?? [];

    if (empty($packageCode)) {
        return Response::json(['success' => false, 'message' => 'package_code required'], 400);
    }

    $scopeEntityId = (int)($opt['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);
    $package = RolePackageService::resolvePackage($packageCode, $scopeEntityId ?: null);

    if (!$package) {
        return Response::json(['success' => false, 'message' => 'Package not found'], 404);
    }

    $result = RolePackageService::setPackageCategories(
        (int)$package['package_id'],
        $categoryCodes,
        (int)$package['scope_entity_id']
    );

    return Response::json($result);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   GET /api/roles/packages/detail.json?package_code=X
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Get full package detail (roles + categories + route_permissions)
 */
Router::register(['GET'], 'packages/detail\.json', function() {
    $packageCode = $_GET['package_code'] ?? '';
    if (empty($packageCode)) {
        return Response::json(['success' => false, 'message' => 'package_code required'], 400);
    }

    $scopeEntityId = (int)($_GET['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);
    $expanded = RolePackageService::expand($packageCode, $scopeEntityId ?: null);

    if (empty($expanded['roles']) && empty($expanded['categories']) && empty($expanded['route_permissions'])) {
        $package = RolePackageService::resolvePackage($packageCode, $scopeEntityId ?: null);
        if (!$package) {
            return Response::json(['success' => false, 'message' => 'Package not found'], 404);
        }
    }

    return Response::json(['success' => true, 'data' => $expanded]);
}, ROUTER_SCOPE_READ);

# ============================================
# RBAC: Category endpoints
# ============================================

/**
 * @endpoint   GET /api/roles/categories/list.json
 * @scope      ROUTER_SCOPE_READ
 * @purpose    List all module categories for current scope
 */
Router::register(['GET'], 'categories/list\.json', function() {
    $categories = ModuleCategoryService::listCategories(Profile::ctx()->scopeEntityId ?: null);
    return Response::json(['success' => true, 'data' => $categories]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   POST /api/roles/categories/create.json
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Create a new module category (tenant-specific)
 */
Router::register(['POST'], 'categories/create\.json', function() {
    $opt = Args::ctx()->opt;
    $result = ModuleCategoryService::create($opt);
    return Response::json($result, $result['success'] ? 200 : 400);
}, ROUTER_SCOPE_WRITE);

# ============================================
# RBAC: ACL query endpoints
# ============================================

/**
 * @endpoint   GET /api/roles/effective.json
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Get all effective roles for a profile (with package origin)
 */
Router::register(['GET'], 'effective\.json', function() {
    $profileId = (int)($_GET['profile_id'] ?? Profile::ctx()->profileId);
    $scopeEntityId = (int)($_GET['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);

    $roles = ACL::getEffectiveRoles($profileId, $scopeEntityId ?: null);
    $categories = ACL::getEffectiveCategories($profileId, $scopeEntityId ?: null);
    $flags = ACL::getFlags($profileId, $scopeEntityId ?: null);

    return Response::json([
        'success' => true,
        'data' => [
            'roles' => $roles['roles'],
            'by_source' => $roles['by_source'],
            'categories' => $categories,
            'flags' => $flags
        ]
    ]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   GET /api/roles/flags.json
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Get view-level flags for current user (frontend queries this)
 */
Router::register(['GET'], 'flags\.json', function() {
    $flags = ACL::getFlags(Profile::ctx()->profileId, Profile::ctx()->scopeEntityId ?: null);
    return Response::json(['success' => true, 'data' => $flags]);
}, ROUTER_SCOPE_READ);

# ============================================
# RBAC: Tenant Positions (Org Chart)
# ============================================

/**
 * @endpoint   GET /api/roles/positions/list.json
 * @scope      ROUTER_SCOPE_READ
 * @purpose    List tenant positions for current scope
 */
Router::register(['GET'], 'positions/list\.json', function() {
    $scopeEntityId = (int)($_GET['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);
    if ($scopeEntityId <= 0) {
        return Response::json(['success' => false, 'message' => 'scope_entity_id required'], 400);
    }

    # Tenant isolation: solo puede leer posiciones de su scope
    Profile::assertScope($scopeEntityId);

    $positions = TenantPositionService::list($scopeEntityId);
    return Response::json(['success' => true, 'data' => $positions]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   GET /api/roles/positions/tree.json
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Get hierarchical position tree for current scope
 */
Router::register(['GET'], 'positions/tree\.json', function() {
    $scopeEntityId = (int)($_GET['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);
    if ($scopeEntityId <= 0) {
        return Response::json(['success' => false, 'message' => 'scope_entity_id required'], 400);
    }

    # Tenant isolation
    Profile::assertScope($scopeEntityId);

    $tree = TenantPositionService::getTree($scopeEntityId);
    return Response::json(['success' => true, 'data' => $tree]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   POST /api/roles/positions/create.json
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Create a new tenant position (governance: owner/admin.local only)
 * @body       { scope_entity_id, position_code, position_label, relation_kind?, package_code?, parent_position_id?, department?, sort_order? }
 */
Router::register(['POST'], 'positions/create\.json', function() {
    $opt = Args::ctx()->opt;

    # scope_entity_id defaults to current scope
    if (empty($opt['scope_entity_id'])) {
        $opt['scope_entity_id'] = Profile::ctx()->scopeEntityId;
    }

    $result = TenantPositionService::create($opt);
    return Response::json($result, $result['success'] ? 201 : 400);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   PUT /api/roles/positions/update.json
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Update a tenant position (governance: owner/admin.local only)
 * @body       { position_id, position_label?, relation_kind?, package_code?, parent_position_id?, department?, sort_order?, is_active? }
 */
Router::register(['PUT', 'POST'], 'positions/update\.json', function() {
    $opt = Args::ctx()->opt;
    $positionId = (int)($opt['position_id'] ?? 0);

    if ($positionId <= 0) {
        return Response::json(['success' => false, 'message' => 'position_id required'], 400);
    }

    # Remove position_id from update data
    unset($opt['position_id']);

    $result = TenantPositionService::update($positionId, $opt);
    return Response::json($result, $result['success'] ? 200 : 400);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   POST /api/roles/positions/delete.json
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Deactivate a tenant position (governance: owner/admin.local only)
 * @body       { position_id }
 */
Router::register(['POST'], 'positions/delete\.json', function() {
    $positionId = (int)(Args::ctx()->opt['position_id'] ?? 0);

    if ($positionId <= 0) {
        return Response::json(['success' => false, 'message' => 'position_id required'], 400);
    }

    $result = TenantPositionService::delete($positionId);
    return Response::json($result, $result['success'] ? 200 : 400);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   GET /api/roles/positions/resolve/<positionId>
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Preview what relation_kind + package would be applied for a position
 */
Router::register(['GET'], 'positions/resolve/(?P<positionId>\d+)', function($positionId) {
    $positionId = (int)$positionId;
    $resolved = TenantPositionService::resolveForOnboarding($positionId);

    if (!$resolved) {
        return Response::json(['success' => false, 'message' => 'Position not found or inactive'], 404);
    }

    # Expand package for preview
    $expanded = null;
    if (!empty($resolved['package_code'])) {
        $expanded = RolePackageService::expand($resolved['package_code'], Profile::ctx()->scopeEntityId);
    }

    return Response::json([
        'success' => true,
        'data' => [
            'position' => $resolved,
            'package_expanded' => $expanded
        ]
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
