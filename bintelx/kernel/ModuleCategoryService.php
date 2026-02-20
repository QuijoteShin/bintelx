<?php
# bintelx/kernel/ModuleCategoryService.php
namespace bX;

/**
 * ModuleCategoryService - Macro-filtro de módulos por categoría
 *
 * Las categorías agrupan rutas en bloques funcionales (operational, administrative, vision).
 * Antes del pattern match fino de permisos, Router verifica si el usuario tiene
 * acceso a la categoría de la ruta solicitada.
 *
 * Tenant override: misma lógica que role_templates (scope-specific > global).
 * Tenants pueden crear categorías custom (laboratorio, calidad, etc.)
 *
 * Cache: global:rbac:categories, TTL 3600s
 *
 * @package bX
 */
class ModuleCategoryService
{
    private const CACHE_NS = 'global:rbac:categories';
    private const CACHE_TTL = 3600;

    /**
     * Get the category for a route path
     * Returns null if route has no category (backward compatible: no restriction)
     *
     * @param string $routePath e.g. 'engagement/list.json'
     * @param int|null $scopeEntityId For tenant overrides
     * @return string|null Category code or null
     */
    public static function getRouteCategory(string $routePath, ?int $scopeEntityId = null): ?string
    {
        $routeMap = self::getRouteCategoryMap($scopeEntityId);

        foreach ($routeMap as $pattern => $categoryCode) {
            # Convertir glob pattern a regex de forma segura
            # Primero escapar metacaracteres regex, luego reemplazar glob * por .*
            $escaped = preg_quote($pattern, '#');
            $regex = str_replace('\\*', '.*', $escaped);
            if (preg_match('#^' . $regex . '$#i', $routePath)) {
                return $categoryCode;
            }
        }

        return null;
    }

    /**
     * Get full route→category map (cached)
     *
     * @param int|null $scopeEntityId
     * @return array ['engagement/*' => 'operational', 'workspace/*' => 'administrative', ...]
     */
    public static function getRouteCategoryMap(?int $scopeEntityId = null): array
    {
        $scope = $scopeEntityId ?? Profile::ctx()->scopeEntityId;
        $cacheKey = 'route-map:' . ($scope ?: 0);

        $cached = Cache::get(self::CACHE_NS, $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $params = [];

        $sql = "SELECT mcr.route_pattern, mc.category_code, mcr.scope_entity_id
                FROM module_category_routes mcr
                JOIN module_categories mc ON mc.category_id = mcr.category_id AND mc.is_active = 1
                WHERE 1=1";

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scope > 0) $opts['scope_entity_id'] = $scope;

        $sql = Tenant::applySql($sql, 'mcr.scope_entity_id', $opts, $params);
        # Tenant-specific overrides last → ganan sobre globals
        $sql .= " ORDER BY " . Tenant::priorityOrderBy('mcr.scope_entity_id', $opts);

        $map = [];
        CONN::dml($sql, $params, function ($row) use (&$map) {
            # Tenant-specific override gana (viene después en ORDER BY)
            $map[$row['route_pattern']] = $row['category_code'];
        });

        Cache::set(self::CACHE_NS, $cacheKey, $map, self::CACHE_TTL);

        return $map;
    }

    /**
     * Get effective categories for a profile
     * Resuelve vía packages asignados (source_package_code en profile_roles)
     *
     * @param int $profileId
     * @param int|null $scopeEntityId
     * @return string[] Array of category codes
     */
    public static function getProfileCategories(int $profileId, ?int $scopeEntityId = null): array
    {
        $categories = [];

        # Obtener todos los source_package_code distintos del perfil
        $params = [':pid' => $profileId];
        $sql = "SELECT DISTINCT source_package_code
                FROM profile_roles
                WHERE profile_id = :pid
                  AND source_package_code IS NOT NULL
                  AND status = 'active'";

        if ($scopeEntityId > 0) {
            $sql .= " AND scope_entity_id = :scope";
            $params[':scope'] = $scopeEntityId;
        }

        $packages = [];
        CONN::dml($sql, $params, function ($row) use (&$packages) {
            $packages[] = $row['source_package_code'];
        });

        # Expandir cada package para obtener sus categorías
        foreach ($packages as $pkgCode) {
            $expanded = RolePackageService::expand($pkgCode, $scopeEntityId);
            foreach ($expanded['categories'] as $cat) {
                $categories[$cat] = true;
            }
        }

        # owner siempre tiene todas las categorías
        if (!empty($scopeEntityId)) {
            $isOwner = CONN::dml(
                "SELECT 1 FROM entity_relationships
                 WHERE profile_id = :pid AND scope_entity_id = :scope
                   AND relation_kind = 'owner' AND status = 'active' LIMIT 1",
                [':pid' => $profileId, ':scope' => $scopeEntityId]
            );
            if (!empty($isOwner)) {
                $allCats = self::listCategories($scopeEntityId);
                foreach ($allCats as $cat) {
                    $categories[$cat['category_code']] = true;
                }
            }
        }

        return array_keys($categories);
    }

    /**
     * Check if profile has access to a category
     *
     * @param int $profileId
     * @param string $categoryCode
     * @param int|null $scopeEntityId
     * @return bool
     */
    public static function hasCategory(int $profileId, string $categoryCode, ?int $scopeEntityId = null): bool
    {
        $cats = self::getProfileCategories($profileId, $scopeEntityId);
        return in_array($categoryCode, $cats, true);
    }

    /**
     * List all categories for a scope
     *
     * @param int|null $scopeEntityId
     * @return array
     */
    public static function listCategories(?int $scopeEntityId = null): array
    {
        $params = [];

        $sql = "SELECT category_id, category_code, category_label, description, scope_entity_id, sort_order
                FROM module_categories
                WHERE is_active = 1";

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);
        $sql .= " ORDER BY sort_order, category_label";

        return CONN::dml($sql, $params) ?? [];
    }

    /**
     * Create a new category
     *
     * @param array $data ['category_code', 'category_label', 'description', 'scope_entity_id']
     * @return array ['success' => bool, 'category_id' => int|null, 'error' => string|null]
     */
    public static function create(array $data): array
    {
        $code = $data['category_code'] ?? '';
        $label = $data['category_label'] ?? '';
        $scope = (int)($data['scope_entity_id'] ?? Profile::ctx()->scopeEntityId);

        if (empty($code) || empty($label) || $scope <= 0) {
            return ['success' => false, 'category_id' => null, 'error' => 'category_code, category_label, scope_entity_id required'];
        }

        $result = CONN::nodml(
            "INSERT INTO module_categories (category_code, category_label, description, scope_entity_id, created_by_profile_id)
             VALUES (:code, :label, :desc, :scope, :actor)
             ON DUPLICATE KEY UPDATE
                category_label = VALUES(category_label),
                description = VALUES(description),
                is_active = 1",
            [
                ':code' => $code,
                ':label' => $label,
                ':desc' => $data['description'] ?? null,
                ':scope' => $scope,
                ':actor' => Profile::ctx()->profileId
            ]
        );

        if (!$result['success']) {
            return ['success' => false, 'category_id' => null, 'error' => $result['error'] ?? 'Database error'];
        }

        self::invalidateCache();

        return ['success' => true, 'category_id' => $result['last_id'] ?: null, 'error' => null];
    }

    /**
     * Set routes for a category (replace all)
     *
     * @param int $categoryId
     * @param array $routePatterns Array of route pattern strings
     * @param int $scopeEntityId For denormalization
     * @return array ['success' => bool, 'count' => int]
     */
    public static function setCategoryRoutes(int $categoryId, array $routePatterns, int $scopeEntityId): array
    {
        CONN::nodml("DELETE FROM module_category_routes WHERE category_id = :cid", [':cid' => $categoryId]);

        $count = 0;
        foreach ($routePatterns as $pattern) {
            $result = CONN::nodml(
                "INSERT IGNORE INTO module_category_routes (category_id, route_pattern, scope_entity_id)
                 VALUES (:cid, :pattern, :scope)",
                [':cid' => $categoryId, ':pattern' => $pattern, ':scope' => $scopeEntityId]
            );
            if ($result['success']) $count++;
        }

        self::invalidateCache();

        return ['success' => true, 'count' => $count];
    }

    /**
     * Invalidate category cache (local + Channel)
     */
    public static function invalidateCache(): void
    {
        Cache::flush(self::CACHE_NS);
        Cache::notifyChannel(self::CACHE_NS);
    }
}
