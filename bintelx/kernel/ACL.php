<?php
# bintelx/kernel/ACL.php
namespace bX;

/**
 * ACL - Orquestador central de seguridad/permisos
 *
 * Centraliza TODAS las operaciones de permisos. Graph, endpoints y Profile delegan a ACL.
 * Stateless: todos los métodos reciben parámetros explícitos, sin estado per-request.
 * Seguro en Swoole Channel Server (no necesita CoroutineAware).
 *
 * Internamente delega a:
 * - RoleTemplateService — consulta templates por relation_kind
 * - RolePackageService — expande packages a roles
 * - ModuleCategoryService — resuelve categorías
 * - bX\Cache — lee/escribe cache de roles
 *
 * Uso en endpoints/handlers — sin parámetros, usa el contexto actual:
 *
 *   # OR — ¿tiene ALGUNO de estos roles?
 *   if (ACL::hasAnyRole(['finance.all', 'company.owner'])) {
 *       # ver precios y totales
 *   }
 *
 *   # AND — ¿tiene TODOS estos roles?
 *   if (ACL::hasAllRoles(['finance.all', 'admin.local'])) {
 *       # operación que requiere ambos
 *   }
 *
 *   # Explícito solo cuando consultas otro profile
 *   if (ACL::hasAnyRole(['finance.all'], profileId: 200, scopeEntityId: 105)) {
 *       # ...
 *   }
 *
 * Guard pattern — bloquear un módulo completo (Swoole-safe):
 *
 *   # kpi.endpoint.php
 *   namespace kpi;
 *
 *   use bX\Router;
 *   use bX\Response;
 *   use bX\ACL;
 *
 *   # Guard reutilizable para todo el archivo
 *   function guardFinance(): ?array {
 *       if (!ACL::hasAnyRole(['finance.all', 'company.owner', 'admin.global'])) {
 *           return Response::json(['error' => 'Insufficient permissions'], 403);
 *       }
 *       return null;
 *   }
 *
 *   Router::register(['GET'], 'summary\.json', function() {
 *       if ($deny = guardFinance()) return $deny;
 *       # ... lógica
 *   }, ROUTER_SCOPE_READ);
 *
 *   Router::register(['GET'], 'totals\.json', function() {
 *       if ($deny = guardFinance()) return $deny;
 *       # ... lógica
 *   }, ROUTER_SCOPE_READ);
 *
 * @package bX
 */
class ACL
{
    # =========================================================================
    # ASIGNACIÓN
    # =========================================================================

    /**
     * Apply role templates when creating a relationship
     * Resolves templates → expands packages → inserts individual roles
     * Must be called inside CONN::transaction()
     *
     * @param int $profileId Profile receiving roles
     * @param int $entityId Entity the relationship is with
     * @param string $relationKind Relation type (owner, technician, etc.)
     * @param int $scopeEntityId Tenant scope
     * @param int|null $actorProfileId Who triggered this (for audit)
     * @return array ['applied' => [], 'skipped' => [], 'packages_expanded' => []]
     */
    public static function applyTemplates(
        int $profileId,
        int $entityId,
        string $relationKind,
        int $scopeEntityId,
        ?int $actorProfileId = null
    ): array {
        $actor = $actorProfileId ?? Profile::ctx()->profileId;
        $applied = [];
        $skipped = [];
        $packagesExpanded = [];

        # Obtener templates para este relation_kind
        $params = [':kind' => $relationKind];
        $sql = "SELECT role_code, package_code, scope_entity_id
                FROM role_templates
                WHERE relation_kind = :kind
                  AND is_active = 1";

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);
        $sql .= " ORDER BY " . Tenant::priorityOrderBy('scope_entity_id', $opts) . ", priority DESC";

        $templates = CONN::dml($sql, $params) ?? [];

        if (empty($templates)) {
            return ['applied' => [], 'skipped' => [], 'packages_expanded' => []];
        }

        # Scope-specific override: si hay templates del tenant, skip globals
        $globalIds = Tenant::globalIds();
        $hasSpecificScope = false;
        foreach ($templates as $t) {
            if (!in_array((int)$t['scope_entity_id'], $globalIds, true)) {
                $hasSpecificScope = true;
                break;
            }
        }

        # Recolectar roles a asignar (directos + expandidos de packages)
        $rolesToAssign = []; # ['role_code' => 'source_package_code'|null]

        foreach ($templates as $t) {
            if ($hasSpecificScope && in_array((int)$t['scope_entity_id'], $globalIds, true)) {
                continue;
            }

            # Si tiene package_code → expandir
            if (!empty($t['package_code'])) {
                $expanded = RolePackageService::expand($t['package_code'], $scopeEntityId);
                foreach ($expanded['roles'] as $role) {
                    $rolesToAssign[$role] = $t['package_code'];
                }
                # Synthetic Role Bridge: si el package tiene route_permissions, agregar synthetic role
                if (!empty($expanded['route_permissions'])) {
                    $rolesToAssign['sys.pkg.' . $t['package_code']] = $t['package_code'];
                }
                $packagesExpanded[] = $t['package_code'];
            }

            # Si tiene role_code directo → agregar
            if (!empty($t['role_code'])) {
                if (!isset($rolesToAssign[$t['role_code']])) {
                    $rolesToAssign[$t['role_code']] = null;
                }
            }
        }

        # Insertar roles individuales con dedup
        foreach ($rolesToAssign as $roleCode => $sourcePackage) {
            $result = self::assignRole($profileId, $roleCode, $scopeEntityId, $actor, $sourcePackage);
            if ($result['success']) {
                if ($result['already_exists'] ?? false) {
                    $skipped[] = $roleCode;
                } else {
                    $applied[] = $roleCode;
                }
            }
        }

        if (!empty($applied)) {
            self::invalidateCache($profileId);

            Log::logInfo("ACL: Applied templates for {$relationKind} to profile {$profileId}", [
                'scope' => $scopeEntityId,
                'applied' => $applied,
                'packages' => $packagesExpanded
            ]);
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            'packages_expanded' => $packagesExpanded
        ];
    }

    /**
     * Expand and assign a package to a profile
     *
     * @param int $profileId
     * @param string $packageCode
     * @param int $scopeEntityId
     * @param int|null $actorProfileId
     * @return array ['success' => bool, 'applied' => [], 'skipped' => []]
     */
    public static function applyPackage(
        int $profileId,
        string $packageCode,
        int $scopeEntityId,
        ?int $actorProfileId = null
    ): array {
        $actor = $actorProfileId ?? Profile::ctx()->profileId;
        $expanded = RolePackageService::expand($packageCode, $scopeEntityId);

        if (empty($expanded['roles'])) {
            return ['success' => true, 'applied' => [], 'skipped' => [], 'errors' => []];
        }

        $applied = [];
        $skipped = [];
        $errors = [];

        foreach ($expanded['roles'] as $roleCode) {
            $result = self::assignRole($profileId, $roleCode, $scopeEntityId, $actor, $packageCode);
            if ($result['success']) {
                if ($result['already_exists'] ?? false) {
                    $skipped[] = $roleCode;
                } else {
                    $applied[] = $roleCode;
                }
            } else {
                $errors[] = ['role_code' => $roleCode, 'error' => $result['error'] ?? 'Unknown'];
                Log::logWarning("ACL::applyPackage failed for role {$roleCode}", [
                    'profile_id' => $profileId,
                    'package' => $packageCode,
                    'error' => $result['error'] ?? 'Unknown'
                ]);
            }
        }

        # Synthetic Role Bridge: asignar sys.pkg.{package_code} si el package tiene route_permissions
        if (!empty($expanded['route_permissions'])) {
            $syntheticCode = 'sys.pkg.' . $packageCode;
            $result = self::assignRole($profileId, $syntheticCode, $scopeEntityId, $actor, $packageCode);
            if ($result['success'] && !($result['already_exists'] ?? false)) {
                $applied[] = $syntheticCode;
            }
        }

        $hasErrors = !empty($errors);
        return [
            'success' => !$hasErrors || !empty($applied),
            'applied' => $applied,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * Assign a single role to a profile
     *
     * @param int $profileId
     * @param string $roleCode
     * @param int $scopeEntityId
     * @param int|null $actorProfileId
     * @param string|null $sourcePackage Informational: which package originated this
     * @return array ['success' => bool, 'already_exists' => bool, 'error' => string|null]
     */
    public static function assignRole(
        int $profileId,
        string $roleCode,
        int $scopeEntityId,
        ?int $actorProfileId = null,
        ?string $sourcePackage = null
    ): array {
        $actor = $actorProfileId ?? Profile::ctx()->profileId;

        # Check if already exists (active)
        $exists = CONN::dml(
            "SELECT profile_role_id, status FROM profile_roles
             WHERE profile_id = :pid AND role_code = :role AND scope_entity_id = :scope
             LIMIT 1",
            [':pid' => $profileId, ':role' => $roleCode, ':scope' => $scopeEntityId]
        );

        if (!empty($exists)) {
            $row = $exists[0];
            if ($row['status'] === 'active') {
                return ['success' => true, 'already_exists' => true, 'error' => null];
            }

            # Reactivar si estaba inactivo
            $result = CONN::nodml(
                "UPDATE profile_roles
                 SET status = 'active', source_package_code = :pkg,
                     updated_by_profile_id = :actor
                 WHERE profile_role_id = :prid",
                [':prid' => $row['profile_role_id'], ':pkg' => $sourcePackage, ':actor' => $actor]
            );

            if ($result['success']) {
                self::invalidateCache($profileId);
            }
            return ['success' => $result['success'], 'already_exists' => false, 'error' => $result['error'] ?? null];
        }

        # Insert nuevo
        $result = CONN::nodml(
            "INSERT INTO profile_roles
             (profile_id, role_code, scope_entity_id, source_package_code, status, created_by_profile_id, updated_by_profile_id)
             VALUES (:pid, :role, :scope, :pkg, 'active', :actor, :actor)",
            [
                ':pid' => $profileId,
                ':role' => $roleCode,
                ':scope' => $scopeEntityId,
                ':pkg' => $sourcePackage,
                ':actor' => $actor
            ]
        );

        if ($result['success']) {
            self::invalidateCache($profileId);
        }

        return ['success' => $result['success'], 'already_exists' => false, 'error' => $result['error'] ?? null];
    }

    # =========================================================================
    # REVOCACIÓN
    # =========================================================================

    /**
     * Revoke a single role from a profile
     *
     * @param int $profileId
     * @param string $roleCode
     * @param int $scopeEntityId
     * @param int|null $actorProfileId
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function revokeRole(
        int $profileId,
        string $roleCode,
        int $scopeEntityId,
        ?int $actorProfileId = null
    ): array {
        $actor = $actorProfileId ?? Profile::ctx()->profileId;

        $result = CONN::nodml(
            "UPDATE profile_roles
             SET status = 'inactive', updated_by_profile_id = :actor
             WHERE profile_id = :pid
               AND role_code = :role
               AND scope_entity_id = :scope
               AND status = 'active'",
            [':pid' => $profileId, ':role' => $roleCode, ':scope' => $scopeEntityId, ':actor' => $actor]
        );

        if ($result['success'] && $result['rowCount'] > 0) {
            self::invalidateCache($profileId);
        }

        return ['success' => $result['success'], 'error' => $result['error'] ?? null];
    }

    /**
     * Revoke all roles from a package (by source_package_code)
     *
     * @param int $profileId
     * @param string $packageCode
     * @param int $scopeEntityId
     * @param int|null $actorProfileId
     * @return array ['success' => bool, 'revoked_count' => int]
     */
    public static function revokePackage(
        int $profileId,
        string $packageCode,
        int $scopeEntityId,
        ?int $actorProfileId = null
    ): array {
        $actor = $actorProfileId ?? Profile::ctx()->profileId;

        $result = CONN::nodml(
            "UPDATE profile_roles
             SET status = 'inactive', updated_by_profile_id = :actor
             WHERE profile_id = :pid
               AND source_package_code = :pkg
               AND scope_entity_id = :scope
               AND status = 'active'",
            [':pid' => $profileId, ':pkg' => $packageCode, ':scope' => $scopeEntityId, ':actor' => $actor]
        );

        if ($result['success'] && $result['rowCount'] > 0) {
            self::invalidateCache($profileId);
        }

        return ['success' => $result['success'], 'revoked_count' => $result['rowCount'] ?? 0];
    }

    # =========================================================================
    # CONSULTA
    # =========================================================================

    /**
     * Check if profile has a specific role in a scope
     * Uses Profile's 3-tier cache (memory → bX\Cache → DB)
     *
     * @param int $profileId
     * @param string $roleCode
     * @param int|null $scopeEntityId NULL = any scope
     * @return bool
     */
    public static function hasRole(int $profileId, string $roleCode, ?int $scopeEntityId = null): bool
    {
        # Tier 1: In-memory (if checking current user)
        $ctx = Profile::ctx();
        if ($profileId === $ctx->profileId && !empty($ctx->roles['by_role'])) {
            return self::hasRoleInCache($ctx->roles, $scopeEntityId, $roleCode);
        }

        # Tier 2: bX\Cache
        $cached = Cache::get('global:profile:roles', (string)$profileId);
        if ($cached !== null && !empty($cached['by_role'])) {
            return self::hasRoleInCache($cached, $scopeEntityId, $roleCode);
        }

        # Tier 3: Database
        $params = [':pid' => $profileId, ':role' => $roleCode];
        $sql = "SELECT 1 FROM profile_roles
                WHERE profile_id = :pid
                  AND role_code = :role
                  AND status = 'active'";

        if ($scopeEntityId !== null) {
            $globalIds = Tenant::globalIds();
            if (!empty($globalIds)) {
                $inList = implode(',', array_map('intval', $globalIds));
                $sql .= " AND (scope_entity_id = :scope OR scope_entity_id IN ({$inList}))";
            } else {
                $sql .= " AND scope_entity_id = :scope";
            }
            $params[':scope'] = $scopeEntityId;
        }

        $sql .= " LIMIT 1";
        $rows = CONN::dml($sql, $params);

        return !empty($rows);
    }

    /**
     * Check role in cached roles array
     *
     * @param array $roles Cached roles structure with 'by_role' key
     * @param int|null $scopeEntityId
     * @param string $roleCode
     * @return bool
     */
    private static function hasRoleInCache(array $roles, ?int $scopeEntityId, string $roleCode): bool
    {
        if (empty($roles['by_role'][$roleCode])) {
            return false;
        }

        if ($scopeEntityId === null) {
            return true;
        }

        $globalIds = Tenant::globalIds();
        foreach ($roles['by_role'][$roleCode] as $assignment) {
            $assignmentScope = $assignment['scopeEntityId'] ?? $assignment['scope_entity_id'] ?? null;
            if ($assignmentScope === null
                || (int)$assignmentScope === $scopeEntityId
                || in_array((int)$assignmentScope, $globalIds, true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if profile has ANY of the given roles (OR logic)
     * Uses current profile+scope from context if not specified
     *
     * Usage: ACL::hasAnyRole(['finance.all', 'company.owner', 'admin.global'])
     *
     * @param array $roleCodes
     * @param int|null $profileId NULL = current user
     * @param int|null $scopeEntityId NULL = current scope
     * @return bool
     */
    public static function hasAnyRole(array $roleCodes, ?int $profileId = null, ?int $scopeEntityId = null): bool
    {
        $pid = $profileId ?? Profile::ctx()->profileId;
        $scope = $scopeEntityId ?? (Profile::ctx()->scopeEntityId > 0 ? Profile::ctx()->scopeEntityId : null);

        foreach ($roleCodes as $code) {
            if (self::hasRole($pid, $code, $scope)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if profile has ALL of the given roles (AND logic)
     * Uses current profile+scope from context if not specified
     *
     * Usage: ACL::hasAllRoles(['finance.all', 'admin.local'])
     *
     * @param array $roleCodes
     * @param int|null $profileId NULL = current user
     * @param int|null $scopeEntityId NULL = current scope
     * @return bool
     */
    public static function hasAllRoles(array $roleCodes, ?int $profileId = null, ?int $scopeEntityId = null): bool
    {
        $pid = $profileId ?? Profile::ctx()->profileId;
        $scope = $scopeEntityId ?? (Profile::ctx()->scopeEntityId > 0 ? Profile::ctx()->scopeEntityId : null);

        foreach ($roleCodes as $code) {
            if (!self::hasRole($pid, $code, $scope)) {
                return false;
            }
        }
        return !empty($roleCodes);
    }

    /**
     * Check if profile has access to a module category
     *
     * @param int $profileId
     * @param string $categoryCode
     * @param int|null $scopeEntityId
     * @return bool
     */
    public static function hasCategory(int $profileId, string $categoryCode, ?int $scopeEntityId = null): bool
    {
        # owner y system.admin bypasean categorías
        if (self::hasRole($profileId, 'company.owner', $scopeEntityId)
            || self::hasRole($profileId, 'system.admin')
        ) {
            return true;
        }

        return ModuleCategoryService::hasCategory($profileId, $categoryCode, $scopeEntityId);
    }

    /**
     * Get all effective roles for a profile
     *
     * @param int $profileId
     * @param int|null $scopeEntityId
     * @return array ['roles' => [...], 'by_source' => ['package_code' => [roles]]]
     */
    public static function getEffectiveRoles(int $profileId, ?int $scopeEntityId = null): array
    {
        $params = [':pid' => $profileId];
        $sql = "SELECT pr.role_code, pr.scope_entity_id, pr.source_package_code,
                       r.role_label, r.scope_type
                FROM profile_roles pr
                LEFT JOIN roles r ON r.role_code = pr.role_code
                WHERE pr.profile_id = :pid AND pr.status = 'active'";

        if ($scopeEntityId !== null) {
            $globalIds = Tenant::globalIds();
            if (!empty($globalIds)) {
                $inList = implode(',', array_map('intval', $globalIds));
                $sql .= " AND (pr.scope_entity_id = :scope OR pr.scope_entity_id IN ({$inList}))";
            } else {
                $sql .= " AND pr.scope_entity_id = :scope";
            }
            $params[':scope'] = $scopeEntityId;
        }

        $sql .= " ORDER BY r.role_label";

        $roles = [];
        $bySource = [];
        CONN::dml($sql, $params, function ($row) use (&$roles, &$bySource) {
            $roles[] = $row;
            $source = $row['source_package_code'] ?? '_direct';
            $bySource[$source][] = $row['role_code'];
        });

        return ['roles' => $roles, 'by_source' => $bySource];
    }

    /**
     * Get all effective categories for a profile
     *
     * @param int $profileId
     * @param int|null $scopeEntityId
     * @return string[]
     */
    public static function getEffectiveCategories(int $profileId, ?int $scopeEntityId = null): array
    {
        return ModuleCategoryService::getProfileCategories($profileId, $scopeEntityId);
    }

    /**
     * Get view-level flags for a profile (view.totals, view.detail, etc.)
     *
     * @param int $profileId
     * @param int|null $scopeEntityId
     * @return array ['flag_name' => 'flag_value', ...]
     */
    public static function getFlags(int $profileId, ?int $scopeEntityId = null): array
    {
        # Obtener role_codes del perfil
        $params = [':pid' => $profileId];
        $sql = "SELECT DISTINCT role_code FROM profile_roles
                WHERE profile_id = :pid AND status = 'active'";

        if ($scopeEntityId !== null) {
            $globalIds = Tenant::globalIds();
            if (!empty($globalIds)) {
                $inList = implode(',', array_map('intval', $globalIds));
                $sql .= " AND (scope_entity_id = :scope OR scope_entity_id IN ({$inList}))";
            } else {
                $sql .= " AND scope_entity_id = :scope";
            }
            $params[':scope'] = $scopeEntityId;
        }

        $roleCodes = [];
        CONN::dml($sql, $params, function ($row) use (&$roleCodes) {
            $roleCodes[] = $row['role_code'];
        });

        if (empty($roleCodes)) {
            return [];
        }

        # Obtener flags de esos roles
        $placeholders = [];
        $flagParams = [];
        foreach ($roleCodes as $i => $code) {
            $key = ":rc{$i}";
            $placeholders[] = $key;
            $flagParams[$key] = $code;
        }
        $inSql = implode(',', $placeholders);

        $flagsSql = "SELECT flag_name, flag_value FROM role_flags
                     WHERE role_code IN ({$inSql})";

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $flagsSql = Tenant::applySql($flagsSql, 'scope_entity_id', $opts, $flagParams);

        $flags = [];
        CONN::dml($flagsSql, $flagParams, function ($row) use (&$flags) {
            # Si hay duplicados, el último gana (scope-specific > global)
            $flags[$row['flag_name']] = $row['flag_value'];
        });

        return $flags;
    }

    # =========================================================================
    # RESYNC
    # =========================================================================

    /**
     * Re-sync a package for all profiles in a tenant that have it
     * Use after adding/removing roles from a package definition
     *
     * Algorithm:
     * 1. Find all profiles with source_package_code = $packageCode in this scope
     * 2. Expand the current package definition
     * 3. For each profile: add missing roles, optionally revoke removed roles
     *
     * @param string $packageCode Package to resync
     * @param int $scopeEntityId Tenant scope
     * @param int|null $actorProfileId Who triggered this
     * @param bool $revokeRemoved If true, revoke roles no longer in the package
     * @return array ['profiles_affected' => int, 'added' => [], 'revoked' => [], 'errors' => []]
     */
    public static function resyncTenantPackage(
        string $packageCode,
        int $scopeEntityId,
        ?int $actorProfileId = null,
        bool $revokeRemoved = false
    ): array {
        $actor = $actorProfileId ?? Profile::ctx()->profileId;

        # Expandir definición actual del package
        $expanded = RolePackageService::expand($packageCode, $scopeEntityId);
        $currentRoles = $expanded['roles'] ?? [];

        # Incluir synthetic role si el package tiene route_permissions
        if (!empty($expanded['route_permissions'])) {
            $currentRoles[] = 'sys.pkg.' . $packageCode;
        }

        if (empty($currentRoles) && !$revokeRemoved) {
            return ['profiles_affected' => 0, 'added' => [], 'revoked' => [], 'errors' => []];
        }

        # Encontrar perfiles que tienen este package en este scope
        $profileIds = [];
        CONN::dml(
            "SELECT DISTINCT profile_id FROM profile_roles
             WHERE source_package_code = :pkg
               AND scope_entity_id = :scope
               AND status = 'active'",
            [':pkg' => $packageCode, ':scope' => $scopeEntityId],
            function ($row) use (&$profileIds) {
                $profileIds[] = (int)$row['profile_id'];
            }
        );

        if (empty($profileIds)) {
            return ['profiles_affected' => 0, 'added' => [], 'revoked' => [], 'errors' => []];
        }

        $totalAdded = [];
        $totalRevoked = [];
        $errors = [];

        foreach ($profileIds as $pid) {
            # Roles actuales de este package para este profile
            $existingRoles = [];
            CONN::dml(
                "SELECT role_code FROM profile_roles
                 WHERE profile_id = :pid
                   AND source_package_code = :pkg
                   AND scope_entity_id = :scope
                   AND status = 'active'",
                [':pid' => $pid, ':pkg' => $packageCode, ':scope' => $scopeEntityId],
                function ($row) use (&$existingRoles) {
                    $existingRoles[] = $row['role_code'];
                }
            );

            # Agregar roles que faltan
            $toAdd = array_diff($currentRoles, $existingRoles);
            foreach ($toAdd as $roleCode) {
                $result = self::assignRole($pid, $roleCode, $scopeEntityId, $actor, $packageCode);
                if ($result['success'] && !($result['already_exists'] ?? false)) {
                    $totalAdded[] = "{$pid}:{$roleCode}";
                } elseif (!$result['success']) {
                    $errors[] = "{$pid}:{$roleCode} → " . ($result['error'] ?? 'unknown');
                }
            }

            # Revocar roles que ya no están en el package
            if ($revokeRemoved) {
                $toRevoke = array_diff($existingRoles, $currentRoles);
                foreach ($toRevoke as $roleCode) {
                    $result = self::revokeRole($pid, $roleCode, $scopeEntityId, $actor);
                    if ($result['success']) {
                        $totalRevoked[] = "{$pid}:{$roleCode}";
                    }
                }
            }
        }

        Log::logInfo("ACL::resyncTenantPackage complete", [
            'package' => $packageCode,
            'scope' => $scopeEntityId,
            'profiles' => count($profileIds),
            'added' => count($totalAdded),
            'revoked' => count($totalRevoked)
        ]);

        return [
            'profiles_affected' => count($profileIds),
            'added' => $totalAdded,
            'revoked' => $totalRevoked,
            'errors' => $errors
        ];
    }

    /**
     * Re-init a specific profile: revoke all package roles and re-apply from templates
     * Use when a profile needs a clean restart of its RBAC state
     *
     * @param int $profileId
     * @param int $scopeEntityId
     * @param int|null $actorProfileId
     * @return array ['revoked' => int, 'applied' => [], 'packages' => []]
     */
    public static function reinitProfile(
        int $profileId,
        int $scopeEntityId,
        ?int $actorProfileId = null
    ): array {
        $actor = $actorProfileId ?? Profile::ctx()->profileId;

        # Obtener relation_kind del profile en este scope
        $rels = CONN::dml(
            "SELECT relation_kind FROM entity_relationships
             WHERE profile_id = :pid AND scope_entity_id = :scope AND status = 'active'",
            [':pid' => $profileId, ':scope' => $scopeEntityId]
        );

        if (empty($rels)) {
            return ['revoked' => 0, 'applied' => [], 'packages' => [], 'error' => 'No active relationship found'];
        }

        # Revocar todos los roles con source_package_code en este scope
        $revokeResult = CONN::nodml(
            "UPDATE profile_roles
             SET status = 'inactive', updated_by_profile_id = :actor
             WHERE profile_id = :pid
               AND scope_entity_id = :scope
               AND source_package_code IS NOT NULL
               AND status = 'active'",
            [':pid' => $profileId, ':scope' => $scopeEntityId, ':actor' => $actor]
        );

        $revokedCount = $revokeResult['rowCount'] ?? 0;

        # Re-aplicar templates por cada relación activa
        $allApplied = [];
        $allPackages = [];
        foreach ($rels as $rel) {
            $kind = $rel['relation_kind'];
            $templateResult = self::applyTemplates($profileId, $scopeEntityId, $kind, $scopeEntityId, $actor);
            $allApplied = array_merge($allApplied, $templateResult['applied'] ?? []);
            $allPackages = array_merge($allPackages, $templateResult['packages_expanded'] ?? []);
        }

        self::invalidateCache($profileId);

        return [
            'revoked' => $revokedCount,
            'applied' => $allApplied,
            'packages' => array_unique($allPackages)
        ];
    }

    # =========================================================================
    # GOVERNANCE
    # =========================================================================

    /**
     * Check if actor can manage org chart positions
     * Only owner + admin.local can manage positions
     *
     * @param int $actorProfileId
     * @param int $scopeEntityId
     * @return bool
     */
    public static function canManagePositions(int $actorProfileId, int $scopeEntityId): bool
    {
        return self::hasRole($actorProfileId, 'company.owner', $scopeEntityId)
            || self::hasRole($actorProfileId, 'admin.local', $scopeEntityId);
    }

    # =========================================================================
    # CACHE
    # =========================================================================

    /**
     * Invalidate role cache for a profile (local + Channel)
     *
     * @param int $profileId
     */
    public static function invalidateCache(int $profileId): void
    {
        Cache::delete('global:profile:roles', (string)$profileId);
        Cache::notifyChannel('global:profile:roles', (string)$profileId);
    }
}
