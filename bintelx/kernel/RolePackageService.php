<?php
# bintelx/kernel/RolePackageService.php
namespace bX;

/**
 * RolePackageService - CRUD de role packages + expand a roles individuales
 *
 * Un package es un bundle de roles asignables como unidad (snapshot).
 * Al asignar un package se expanden a roles individuales en profile_roles.
 * Cambiar el package después NO afecta perfiles existentes.
 *
 * Tenant override: Si existe un package con mismo code pero scope_entity_id del tenant,
 * se usa ese. Si no, se usa el global (GLOBAL_TENANT_ID).
 *
 * Cache: global:rbac:packages, TTL 3600s
 *
 * @package bX
 */
class RolePackageService
{
    private const CACHE_NS = 'global:rbac:packages';
    private const CACHE_TTL = 3600;

    /**
     * Expand a package to its constituent roles and categories
     * Resolves tenant override: scope-specific > global
     *
     * @param string $packageCode Package code to expand
     * @param int|null $scopeEntityId Tenant scope for override resolution
     * @return array ['roles' => string[], 'categories' => string[], 'source_package' => string]
     */
    public static function expand(string $packageCode, ?int $scopeEntityId = null): array
    {
        $cacheKey = $packageCode . ':' . ($scopeEntityId ?? 0);
        $cached = Cache::get(self::CACHE_NS, $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        # Buscar package con tenant override
        $package = self::resolvePackage($packageCode, $scopeEntityId);
        if (!$package) {
            return ['roles' => [], 'categories' => [], 'source_package' => $packageCode];
        }

        $packageId = (int)$package['package_id'];

        # Obtener roles del package
        $roles = [];
        CONN::dml(
            "SELECT role_code FROM role_package_items WHERE package_id = :pid",
            [':pid' => $packageId],
            function ($row) use (&$roles) {
                $roles[] = $row['role_code'];
            }
        );

        # Obtener categorías del package
        $categories = [];
        CONN::dml(
            "SELECT category_code FROM role_package_categories WHERE package_id = :pid",
            [':pid' => $packageId],
            function ($row) use (&$categories) {
                $categories[] = $row['category_code'];
            }
        );

        # Obtener route permissions del package
        $routePermissions = [];
        CONN::dml(
            "SELECT route_pattern, scope_level FROM role_package_route_permissions WHERE package_id = :pid",
            [':pid' => $packageId],
            function ($row) use (&$routePermissions) {
                $routePermissions[] = ['route_pattern' => $row['route_pattern'], 'scope_level' => $row['scope_level']];
            }
        );

        $result = [
            'roles' => $roles,
            'categories' => $categories,
            'route_permissions' => $routePermissions,
            'source_package' => $packageCode
        ];

        Cache::set(self::CACHE_NS, $cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Resolve package with tenant override
     * Si existe package con scope del tenant, usa ese. Si no, usa global.
     *
     * @param string $packageCode
     * @param int|null $scopeEntityId
     * @return array|null Package row or null if not found
     */
    public static function resolvePackage(string $packageCode, ?int $scopeEntityId = null): ?array
    {
        $params = [':code' => $packageCode];

        $sql = "SELECT package_id, package_code, package_label, scope_entity_id
                FROM role_packages
                WHERE package_code = :code
                  AND is_active = 1";

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);
        $sql .= " ORDER BY " . Tenant::priorityOrderBy('scope_entity_id', $opts) . " LIMIT 1";

        $rows = CONN::dml($sql, $params);

        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * List all packages for a scope
     *
     * @param int|null $scopeEntityId
     * @return array
     */
    public static function list(?int $scopeEntityId = null): array
    {
        $params = [];

        $sql = "SELECT p.*, GROUP_CONCAT(DISTINCT i.role_code ORDER BY i.role_code) AS role_codes,
                       GROUP_CONCAT(DISTINCT c.category_code ORDER BY c.category_code) AS category_codes,
                       GROUP_CONCAT(DISTINCT CONCAT(rp.route_pattern, ':', rp.scope_level) ORDER BY rp.route_pattern) AS route_permissions_raw
                FROM role_packages p
                LEFT JOIN role_package_items i ON i.package_id = p.package_id
                LEFT JOIN role_package_categories c ON c.package_id = p.package_id
                LEFT JOIN role_package_route_permissions rp ON rp.package_id = p.package_id
                WHERE p.is_active = 1";

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $sql = Tenant::applySql($sql, 'p.scope_entity_id', $opts, $params);
        $sql .= " GROUP BY p.package_id ORDER BY p.package_label";

        return CONN::dml($sql, $params) ?? [];
    }

    /**
     * Create a new package
     *
     * @param array $data ['package_code', 'package_label', 'description', 'scope_entity_id']
     * @return array ['success' => bool, 'package_id' => int|null, 'error' => string|null]
     */
    public static function create(array $data): array
    {
        $code = $data['package_code'] ?? '';
        $label = $data['package_label'] ?? '';
        $scope = (int)($data['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);

        if (empty($code) || empty($label) || $scope <= 0) {
            return ['success' => false, 'package_id' => null, 'error' => 'package_code, package_label, scope_entity_id required'];
        }

        $result = CONN::nodml(
            "INSERT INTO role_packages (package_code, package_label, description, scope_entity_id, created_by_profile_id)
             VALUES (:code, :label, :desc, :scope, :actor)
             ON DUPLICATE KEY UPDATE
                package_label = VALUES(package_label),
                description = VALUES(description),
                is_active = 1,
                updated_at = CURRENT_TIMESTAMP",
            [
                ':code' => $code,
                ':label' => $label,
                ':desc' => $data['description'] ?? null,
                ':scope' => $scope,
                ':actor' => Profile::ctx()->profileId
            ]
        );

        if (!$result['success']) {
            return ['success' => false, 'package_id' => null, 'error' => $result['error'] ?? 'Database error'];
        }

        self::invalidateCache();

        return ['success' => true, 'package_id' => $result['last_id'] ?: null, 'error' => null];
    }

    /**
     * Set roles for a package (replace all)
     *
     * @param int $packageId
     * @param array $roleCodes Array of role_code strings
     * @param int $scopeEntityId For denormalization
     * @return array ['success' => bool, 'count' => int]
     */
    public static function setPackageRoles(int $packageId, array $roleCodes, int $scopeEntityId): array
    {
        # Delete existing
        CONN::nodml("DELETE FROM role_package_items WHERE package_id = :pid", [':pid' => $packageId]);

        $count = 0;
        foreach ($roleCodes as $roleCode) {
            $result = CONN::nodml(
                "INSERT IGNORE INTO role_package_items (package_id, role_code, scope_entity_id)
                 VALUES (:pid, :role, :scope)",
                [':pid' => $packageId, ':role' => $roleCode, ':scope' => $scopeEntityId]
            );
            if ($result['success']) $count++;
        }

        self::invalidateCache();

        return ['success' => true, 'count' => $count];
    }

    /**
     * Set categories for a package (replace all)
     *
     * @param int $packageId
     * @param array $categoryCodes Array of category_code strings
     * @param int $scopeEntityId For denormalization
     * @return array ['success' => bool, 'count' => int]
     */
    public static function setPackageCategories(int $packageId, array $categoryCodes, int $scopeEntityId): array
    {
        # Delete existing
        CONN::nodml("DELETE FROM role_package_categories WHERE package_id = :pid", [':pid' => $packageId]);

        $count = 0;
        foreach ($categoryCodes as $catCode) {
            $result = CONN::nodml(
                "INSERT IGNORE INTO role_package_categories (package_id, category_code, scope_entity_id)
                 VALUES (:pid, :cat, :scope)",
                [':pid' => $packageId, ':cat' => $catCode, ':scope' => $scopeEntityId]
            );
            if ($result['success']) $count++;
        }

        self::invalidateCache();

        return ['success' => true, 'count' => $count];
    }

    /**
     * Get diff between current profile roles and package roles
     * Para preview de resync
     *
     * @param int $profileId
     * @param string $packageCode
     * @param int $scopeEntityId
     * @return array ['to_add' => [], 'to_remove' => [], 'unchanged' => []]
     */
    public static function diffPackageVsProfile(int $profileId, string $packageCode, int $scopeEntityId): array
    {
        $expanded = self::expand($packageCode, $scopeEntityId);
        $packageRoles = $expanded['roles'];

        # Roles actuales del perfil que vinieron de este package
        $currentRoles = [];
        CONN::dml(
            "SELECT role_code FROM profile_roles
             WHERE profile_id = :pid
               AND scope_entity_id = :scope
               AND source_package_code = :pkg
               AND status = 'active'",
            [':pid' => $profileId, ':scope' => $scopeEntityId, ':pkg' => $packageCode],
            function ($row) use (&$currentRoles) {
                $currentRoles[] = $row['role_code'];
            }
        );

        $toAdd = array_diff($packageRoles, $currentRoles);
        $toRemove = array_diff($currentRoles, $packageRoles);
        $unchanged = array_intersect($packageRoles, $currentRoles);

        return [
            'to_add' => array_values($toAdd),
            'to_remove' => array_values($toRemove),
            'unchanged' => array_values($unchanged)
        ];
    }

    /**
     * Get route permissions for a package
     *
     * @param string $packageCode
     * @param int|null $scopeEntityId
     * @return array [['route_pattern' => string, 'scope_level' => string], ...]
     */
    public static function getPackageRoutePermissions(string $packageCode, ?int $scopeEntityId = null): array
    {
        $package = self::resolvePackage($packageCode, $scopeEntityId);
        if (!$package) return [];

        $permissions = [];
        CONN::dml(
            "SELECT route_pattern, scope_level FROM role_package_route_permissions WHERE package_id = :pid ORDER BY route_pattern",
            [':pid' => (int)$package['package_id']],
            function ($row) use (&$permissions) {
                $permissions[] = ['route_pattern' => $row['route_pattern'], 'scope_level' => $row['scope_level']];
            }
        );

        return $permissions;
    }

    /**
     * Set route permissions for a package (replace all) + sync synthetic role
     * Patrón DELETE+INSERT como setPackageCategories()
     *
     * @param int $packageId
     * @param array $permissions [['route_pattern' => string, 'scope_level' => string], ...]
     * @param string $packageCode Para sync synthetic role
     * @return array ['success' => bool, 'count' => int]
     */
    public static function setPackageRoutePermissions(int $packageId, array $permissions, string $packageCode): array
    {
        # Delete existing
        CONN::nodml("DELETE FROM role_package_route_permissions WHERE package_id = :pid", [':pid' => $packageId]);

        $count = 0;
        foreach ($permissions as $perm) {
            $pattern = $perm['route_pattern'] ?? '';
            $level = $perm['scope_level'] ?? 'read';
            if (empty($pattern)) continue;

            $result = CONN::nodml(
                "INSERT IGNORE INTO role_package_route_permissions (package_id, route_pattern, scope_level)
                 VALUES (:pid, :pattern, :level)",
                [':pid' => $packageId, ':pattern' => $pattern, ':level' => $level]
            );
            if ($result['success']) $count++;
        }

        # Sincronizar synthetic role
        self::syncSyntheticRole($packageCode, $permissions);

        self::invalidateCache();

        return ['success' => true, 'count' => $count];
    }

    /**
     * Sync synthetic role for a package
     * Crea/actualiza el rol sys.pkg.{code} en `roles` y sincroniza `role_route_permissions`
     *
     * @param string $packageCode
     * @param array $permissions Route permissions del package
     */
    public static function syncSyntheticRole(string $packageCode, array $permissions): void
    {
        $syntheticCode = 'sys.pkg.' . $packageCode;
        $globalScope = 2000000000; # GLOBAL_TENANT_ID

        # Asegurar que el rol sintético existe en el catálogo
        CONN::nodml(
            "INSERT IGNORE INTO roles (role_code, role_label, scope_type, is_hidden, role_group)
             VALUES (:code, :label, 'global', 1, 'system')",
            [':code' => $syntheticCode, ':label' => 'Package: ' . $packageCode]
        );

        # Sincronizar route permissions (DELETE + INSERT)
        CONN::nodml(
            "DELETE FROM role_route_permissions WHERE role_code = :code AND scope_entity_id = :scope",
            [':code' => $syntheticCode, ':scope' => $globalScope]
        );

        foreach ($permissions as $perm) {
            $pattern = $perm['route_pattern'] ?? '';
            $level = $perm['scope_level'] ?? 'read';
            if (empty($pattern)) continue;

            # Convertir glob a regex: engagement/* → engagement/.* (Router evalúa como regex)
            $regexPattern = self::globToRegex($pattern);

            CONN::nodml(
                "INSERT IGNORE INTO role_route_permissions (role_code, route_pattern, scope_level, scope_entity_id)
                 VALUES (:code, :pattern, :level, :scope)",
                [':code' => $syntheticCode, ':pattern' => $regexPattern, ':level' => $level, ':scope' => $globalScope]
            );
        }
    }

    /**
     * Convert glob pattern to regex for Router compatibility
     * Router uses preg_match with patterns, so * must become .*
     * Special case: standalone * stays as * (Router handles it specially)
     */
    private static function globToRegex(string $pattern): string
    {
        if ($pattern === '*') return '*';
        # engagement/* → engagement/.*
        return str_replace('/*', '/.*', $pattern);
    }

    /**
     * Invalidate package cache (local + Channel)
     */
    public static function invalidateCache(): void
    {
        Cache::flush(self::CACHE_NS);
        Cache::notifyChannel(self::CACHE_NS);
    }
}
