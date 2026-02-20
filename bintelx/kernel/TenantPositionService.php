<?php
# bintelx/kernel/TenantPositionService.php
namespace bX;

/**
 * TenantPositionService - CRUD posiciones por tenant (organigramas)
 *
 * Una posición es un cargo organizacional dentro de un tenant específico.
 * Combina: nombre del cargo + relation_kind + package auto-asignado.
 *
 * Al agregar un colaborador con position_id:
 *   1. Se busca la posición → obtiene relation_kind + package_code
 *   2. Graph::create() crea relación con ese relation_kind
 *   3. ACL::applyTemplates() expande el package
 *
 * Governance: solo owner + admin.local pueden CRUD posiciones.
 * Stateless: seguro en Swoole Channel Server.
 *
 * Cache: global:rbac:positions, TTL 3600s
 *
 * @package bX
 */
class TenantPositionService
{
    private const CACHE_NS = 'global:rbac:positions';
    private const CACHE_TTL = 3600;

    /**
     * List positions for a tenant scope
     * Returns scope-specific + global defaults (if no override)
     *
     * @param int $scopeEntityId Tenant scope
     * @param bool $includeInactive Include deactivated positions
     * @return array
     */
    public static function list(int $scopeEntityId, bool $includeInactive = false): array
    {
        $params = [];

        $sql = "SELECT tp.*, rp.package_label,
                       tp2.position_label AS parent_label
                FROM tenant_positions tp
                LEFT JOIN role_packages rp ON rp.package_code = tp.package_code
                    AND rp.is_active = 1
                    AND rp.scope_entity_id = tp.scope_entity_id
                LEFT JOIN tenant_positions tp2 ON tp2.position_id = tp.parent_position_id";

        if (!$includeInactive) {
            $sql .= " WHERE tp.is_active = 1";
        } else {
            $sql .= " WHERE 1=1";
        }

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $sql = Tenant::applySql($sql, 'tp.scope_entity_id', $opts, $params);
        $sql .= " ORDER BY tp.department, tp.sort_order, tp.position_label";

        return CONN::dml($sql, $params) ?? [];
    }

    /**
     * Get a single position by ID (scoped to current tenant or explicit scope)
     *
     * @param int $positionId
     * @param int|null $scopeEntityId If null, uses Tenant::applySql for isolation
     * @return array|null
     */
    public static function get(int $positionId, ?int $scopeEntityId = null): ?array
    {
        $params = [':id' => $positionId];

        $sql = "SELECT tp.*, rp.package_label,
                       tp2.position_label AS parent_label
                FROM tenant_positions tp
                LEFT JOIN role_packages rp ON rp.package_code = tp.package_code
                    AND rp.is_active = 1
                    AND rp.scope_entity_id = tp.scope_entity_id
                LEFT JOIN tenant_positions tp2 ON tp2.position_id = tp.parent_position_id
                WHERE tp.position_id = :id";

        # Scope isolation: restringir al tenant actual o al explícito
        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) {
            $opts['scope_entity_id'] = $scopeEntityId;
        }
        $sql = Tenant::applySql($sql, 'tp.scope_entity_id', $opts, $params);
        $sql .= " LIMIT 1";

        $rows = CONN::dml($sql, $params);

        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Create a new position
     *
     * @param array $data Required: scope_entity_id, position_code, position_label
     *                    Optional: relation_kind, package_code, parent_position_id, department, sort_order
     * @return array ['success' => bool, 'position_id' => int|null, 'error' => string|null]
     */
    public static function create(array $data): array
    {
        $scope = (int)($data['scope_entity_id'] ?? 0);
        $code = trim($data['position_code'] ?? '');
        $label = trim($data['position_label'] ?? '');

        if ($scope <= 0 || empty($code) || empty($label)) {
            return ['success' => false, 'position_id' => null, 'error' => 'scope_entity_id, position_code, position_label required'];
        }

        # Governance: solo owner/admin.local
        if (!ACL::canManagePositions(Profile::ctx()->profileId, $scope)) {
            return ['success' => false, 'position_id' => null, 'error' => 'Insufficient permissions to manage positions'];
        }

        # Validar package_code si se proporciona
        $packageCode = $data['package_code'] ?? null;
        if (!empty($packageCode)) {
            $pkg = RolePackageService::resolvePackage($packageCode, $scope);
            if (!$pkg) {
                return ['success' => false, 'position_id' => null, 'error' => "Package '{$packageCode}' not found"];
            }
        }

        # Validar parent_position_id si se proporciona
        $parentId = !empty($data['parent_position_id']) ? (int)$data['parent_position_id'] : null;
        if ($parentId !== null) {
            $parent = self::get($parentId, $scope);
            if (!$parent || (int)$parent['scope_entity_id'] !== $scope) {
                return ['success' => false, 'position_id' => null, 'error' => 'Parent position not found in this scope'];
            }
        }

        $result = CONN::nodml(
            "INSERT INTO tenant_positions
             (scope_entity_id, position_code, position_label, relation_kind, package_code,
              parent_position_id, department, sort_order, created_by_profile_id)
             VALUES (:scope, :code, :label, :kind, :pkg, :parent, :dept, :sort, :actor)
             ON DUPLICATE KEY UPDATE
                position_label = VALUES(position_label),
                relation_kind = VALUES(relation_kind),
                package_code = VALUES(package_code),
                parent_position_id = VALUES(parent_position_id),
                department = VALUES(department),
                sort_order = VALUES(sort_order),
                is_active = 1",
            [
                ':scope' => $scope,
                ':code' => $code,
                ':label' => $label,
                ':kind' => $data['relation_kind'] ?? 'collaborator',
                ':pkg' => $packageCode,
                ':parent' => $parentId,
                ':dept' => $data['department'] ?? null,
                ':sort' => (int)($data['sort_order'] ?? 0),
                ':actor' => Profile::ctx()->profileId
            ]
        );

        if (!$result['success']) {
            return ['success' => false, 'position_id' => null, 'error' => $result['error'] ?? 'Database error'];
        }

        self::invalidateCache($scope);

        return ['success' => true, 'position_id' => $result['last_id'] ?: null, 'error' => null];
    }

    /**
     * Update an existing position
     *
     * @param int $positionId
     * @param array $data Fields to update
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function update(int $positionId, array $data): array
    {
        $position = self::get($positionId);
        if (!$position) {
            return ['success' => false, 'error' => 'Position not found'];
        }

        $scope = (int)$position['scope_entity_id'];

        # Governance
        if (!ACL::canManagePositions(Profile::ctx()->profileId, $scope)) {
            return ['success' => false, 'error' => 'Insufficient permissions to manage positions'];
        }

        # Construir SET dinámico con campos permitidos
        $allowed = ['position_label', 'relation_kind', 'package_code', 'parent_position_id', 'department', 'sort_order', 'is_active'];
        $sets = [];
        $params = [':id' => $positionId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $paramKey = ':' . $field;
                $sets[] = "{$field} = {$paramKey}";
                $params[$paramKey] = $data[$field];
            }
        }

        if (empty($sets)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }

        # Validar parent no sea circular
        if (isset($data['parent_position_id']) && $data['parent_position_id'] !== null) {
            $parentId = (int)$data['parent_position_id'];
            if ($parentId === $positionId) {
                return ['success' => false, 'error' => 'Position cannot be its own parent'];
            }
            # Verificar que el padre exista en el mismo scope
            $parent = self::get($parentId, $scope);
            if (!$parent || (int)$parent['scope_entity_id'] !== $scope) {
                return ['success' => false, 'error' => 'Parent position not found in this scope'];
            }
            # Detectar ciclos recorriendo ancestros hasta la raíz (max 50 niveles)
            $visited = [$positionId => true];
            $current = $parentId;
            $depth = 0;
            while ($current > 0 && $depth < 50) {
                if (isset($visited[$current])) {
                    return ['success' => false, 'error' => 'Circular parent reference detected'];
                }
                $visited[$current] = true;
                $ancestor = self::get($current, $scope);
                $current = (int)($ancestor['parent_position_id'] ?? 0);
                $depth++;
            }
        }

        # Validar package_code si cambia
        if (!empty($data['package_code'])) {
            $pkg = RolePackageService::resolvePackage($data['package_code'], $scope);
            if (!$pkg) {
                return ['success' => false, 'error' => "Package '{$data['package_code']}' not found"];
            }
        }

        $setClause = implode(', ', $sets);
        $result = CONN::nodml(
            "UPDATE tenant_positions SET {$setClause} WHERE position_id = :id",
            $params
        );

        if ($result['success']) {
            self::invalidateCache($scope);
        }

        return ['success' => $result['success'], 'error' => $result['error'] ?? null];
    }

    /**
     * Deactivate a position (soft delete)
     *
     * @param int $positionId
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function delete(int $positionId): array
    {
        return self::update($positionId, ['is_active' => 0]);
    }

    /**
     * Get position tree for a scope (hierarchical)
     *
     * @param int $scopeEntityId
     * @return array Nested tree with 'children' arrays
     */
    public static function getTree(int $scopeEntityId): array
    {
        $cacheKey = 'tree:' . $scopeEntityId;
        $cached = Cache::get(self::CACHE_NS, $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $flat = self::list($scopeEntityId);

        # Construir árbol
        $byId = [];
        foreach ($flat as &$pos) {
            $pos['children'] = [];
            $byId[(int)$pos['position_id']] = &$pos;
        }
        unset($pos);

        $tree = [];
        foreach ($byId as &$pos) {
            $parentId = (int)($pos['parent_position_id'] ?? 0);
            if ($parentId > 0 && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$pos;
            } else {
                $tree[] = &$pos;
            }
        }
        unset($pos);

        Cache::set(self::CACHE_NS, $cacheKey, $tree, self::CACHE_TTL);

        return $tree;
    }

    /**
     * Resolve position for onboarding: returns relation_kind + package_code
     * Used by Graph::create() when a position_id is provided
     *
     * @param int $positionId
     * @return array|null ['relation_kind' => string, 'package_code' => string|null, 'position_label' => string]
     */
    public static function resolveForOnboarding(int $positionId): ?array
    {
        $pos = self::get($positionId);
        if (!$pos || !(int)$pos['is_active']) {
            return null;
        }

        return [
            'relation_kind' => $pos['relation_kind'],
            'package_code' => $pos['package_code'] ?: null,
            'position_label' => $pos['position_label']
        ];
    }

    /**
     * Invalidate positions cache for a scope
     *
     * @param int $scopeEntityId
     */
    public static function invalidateCache(int $scopeEntityId): void
    {
        Cache::flush(self::CACHE_NS);
        Cache::notifyChannel(self::CACHE_NS);
    }
}
