<?php
# bintelx/kernel/Entity/Graph.php
namespace bX\Entity;

use bX\ACL;
use bX\CONN;
use bX\Profile;
use bX\Tenant;
use bX\TenantPositionService;

/**
 * Graph - Entity graph edges (connections between entities/profiles)
 *
 * Manages the entity_relationships table as a graph structure:
 * - Nodes: entities, profiles
 * - Edges: relationships with kind (owner, collaborator, customer, member, supplier)
 *
 * All methods follow Bintelx signature: (array $data, array $options, ?callable $callback)
 * Multi-tenant: uses Tenant helper for scope filtering
 *
 * @package bX\Entity
 */
class Graph
{
    # Relation kinds — los 5 tipos válidos de relación entity↔profile
    public const KIND_OWNER = 'owner';
    public const KIND_COLLABORATOR = 'collaborator';
    public const KIND_CUSTOMER = 'customer';
    public const KIND_MEMBER = 'member';
    public const KIND_SUPPLIER = 'supplier';

    # Whitelist para validación
    public const VALID_KINDS = [
        self::KIND_OWNER,
        self::KIND_COLLABORATOR,
        self::KIND_CUSTOMER,
        self::KIND_MEMBER,
        self::KIND_SUPPLIER,
    ];

    # Status constants
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    /**
     * Create a new entity relationship
     *
     * @param array $data [
     *   'profile_id' => int (required),
     *   'entity_id' => int (required),
     *   'relation_kind' => string (default: 'member', must be one of VALID_KINDS),
     *   'position_id' => int|null (if set, overrides relation_kind + applies position's package),
     *   'grant_mode' => string|null,
     *   'relationship_label' => string|null
     * ]
     * @param array $options ['scope_entity_id' => int]
     * @param callable|null $callback (unused)
     * @return array ['success' => bool, 'relationship_id' => int|null, 'message' => string]
     */
    public static function create(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $profileId = (int)($data['profile_id'] ?? 0);
        $entityId = (int)($data['entity_id'] ?? 0);
        $kind = $data['relation_kind'] ?? self::KIND_MEMBER;
        $positionId = !empty($data['position_id']) ? (int)$data['position_id'] : null;
        $grantMode = $data['grant_mode'] ?? null;
        $label = $data['relationship_label'] ?? null;

        if ($profileId <= 0 || $entityId <= 0) {
            return ['success' => false, 'message' => 'profile_id and entity_id are required'];
        }

        $bootstrap = !empty($options['bootstrap']);

        # Si position_id, resolver la posición y usar su relation_kind + package
        $positionPackageCode = null;
        if ($positionId !== null) {
            $resolved = TenantPositionService::resolveForOnboarding($positionId);
            if (!$resolved) {
                return ['success' => false, 'message' => 'Position not found or inactive'];
            }
            $kind = $resolved['relation_kind'];
            $positionPackageCode = $resolved['package_code'];
        }

        # Validar relation_kind contra whitelist (después de posible override por position)
        if (!in_array($kind, self::VALID_KINDS, true)) {
            return ['success' => false, 'message' => "Invalid relation_kind: {$kind}"];
        }

        # Bootstrap: scope explícito sin Tenant::validateForWrite (Account creation, sin ctx aún)
        if (!$bootstrap) {
            $tenant = Tenant::validateForWrite($options);
            if (!$tenant['valid']) {
                return ['success' => false, 'message' => $tenant['error']];
            }
            $scope = $tenant['scope'];
        } else {
            $scope = (int)($options['scope_entity_id'] ?? 0);
            if ($scope <= 0) {
                return ['success' => false, 'message' => 'bootstrap requires valid scope_entity_id > 0'];
            }
        }

        $sql = "INSERT INTO entity_relationships
                (profile_id, entity_id, scope_entity_id, relation_kind, grant_mode, relationship_label, status, created_by_profile_id)
                VALUES
                (:profile_id, :entity_id, :scope_entity_id, :kind, :grant_mode, :label, 'active', :created_by)";

        $params = [
            ':profile_id' => $profileId,
            ':entity_id' => $entityId,
            ':scope_entity_id' => $scope,
            ':kind' => $kind,
            ':grant_mode' => $grantMode,
            ':label' => $label,
            ':created_by' => $data['created_by'] ?? (Profile::ctx()->profileId ?: null)
        ];

        # Bootstrap: INSERT directo sin transaction ni ACL (Account creation flow)
        if ($bootstrap) {
            try {
                $result = CONN::nodml($sql, $params);
                if (!$result['success']) {
                    throw new \RuntimeException('Failed to create relationship: ' . ($result['error'] ?? 'Unknown'));
                }
                return [
                    'success' => true,
                    'relationship_id' => (int)$result['last_id'],
                    'message' => 'Relationship created (bootstrap)',
                    'roles_applied' => [], 'roles_skipped' => [], 'packages_expanded' => []
                ];
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        # Wrap relationship + role assignment in transaction (race condition prevention)
        try {
            return CONN::transaction(function () use ($sql, $params, $profileId, $entityId, $kind, $tenant, $positionPackageCode) {
                $result = CONN::nodml($sql, $params);

                if (!$result['success']) {
                    throw new \RuntimeException('Failed to create relationship: ' . ($result['error'] ?? 'Unknown error'));
                }

                $relationshipId = (int)$result['last_id'];
                $actor = Profile::ctx()->profileId ?: null;

                # Auto-apply role templates via ACL orchestrator
                $templateResult = ACL::applyTemplates(
                    $profileId,
                    $entityId,
                    $kind,
                    $tenant['scope'],
                    $actor
                );

                # Si la posición tiene un package explícito, aplicarlo además de los templates
                # (cubre el caso donde el template no tiene package pero la posición sí)
                $positionPackageResult = null;
                if ($positionPackageCode !== null
                    && !in_array($positionPackageCode, $templateResult['packages_expanded'], true)
                ) {
                    $positionPackageResult = ACL::applyPackage(
                        $profileId,
                        $positionPackageCode,
                        $tenant['scope'],
                        $actor
                    );
                }

                return [
                    'success' => true,
                    'relationship_id' => $relationshipId,
                    'message' => 'Relationship created',
                    'roles_applied' => array_merge(
                        $templateResult['applied'],
                        $positionPackageResult ? $positionPackageResult['applied'] : []
                    ),
                    'roles_skipped' => array_merge(
                        $templateResult['skipped'],
                        $positionPackageResult ? $positionPackageResult['skipped'] : []
                    ),
                    'packages_expanded' => array_merge(
                        $templateResult['packages_expanded'],
                        $positionPackageCode ? [$positionPackageCode] : []
                    )
                ];
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Check if a relationship exists
     *
     * @param array $data ['profile_id' => int, 'entity_id' => int, 'relation_kind' => string|null]
     * @param array $options ['scope_entity_id' => int, 'active_only' => bool]
     * @param callable|null $callback Optional callback
     * @return bool
     */
    public static function exists(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): bool {
        $profileId = (int)($data['profile_id'] ?? 0);
        $entityId = (int)($data['entity_id'] ?? 0);
        $kind = $data['relation_kind'] ?? null;
        $activeOnly = $options['active_only'] ?? true;

        $sql = "SELECT 1 FROM entity_relationships
                WHERE profile_id = :profile_id
                  AND entity_id = :entity_id";
        $params = [
            ':profile_id' => $profileId,
            ':entity_id' => $entityId
        ];

        # Apply tenant filter
        $sql .= Tenant::whereClause('scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        if ($kind !== null) {
            $sql .= " AND relation_kind = :kind";
            $params[':kind'] = $kind;
        }

        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }

        $sql .= " LIMIT 1";

        $found = false;
        CONN::dml($sql, $params, $callback ?? function() use (&$found) {
            $found = true;
            return false;
        });

        return $found;
    }

    /**
     * Find relationship by ID (scoped)
     *
     * @param array $data ['relationship_id' => int]
     * @param array $options ['scope_entity_id' => int]
     * @param callable|null $callback Optional callback
     * @return array|null
     */
    public static function findById(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): ?array {
        $relationshipId = (int)($data['relationship_id'] ?? 0);

        if ($relationshipId <= 0) {
            return null;
        }

        $sql = "SELECT * FROM entity_relationships WHERE relationship_id = :id";
        $params = [':id' => $relationshipId];

        # Apply tenant filter
        $sql .= Tenant::whereClause('scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        $row = null;
        CONN::dml($sql, $params, $callback ?? function($r) use (&$row) {
            $row = $r;
            return false;
        });

        return $row;
    }

    /**
     * Find relationships by profile (scoped)
     *
     * @param array $data ['profile_id' => int, 'relation_kind' => string|null]
     * @param array $options ['scope_entity_id' => int, 'active_only' => bool]
     * @param callable|null $callback Optional callback
     * @return array
     */
    public static function findByProfile(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $profileId = (int)($data['profile_id'] ?? 0);
        $kind = $data['relation_kind'] ?? null;
        $activeOnly = $options['active_only'] ?? true;

        $sql = "SELECT er.*, e.primary_name AS entity_name, e.entity_type
                FROM entity_relationships er
                LEFT JOIN entities e ON e.entity_id = er.entity_id
                WHERE er.profile_id = :profile_id";
        $params = [':profile_id' => $profileId];

        # Apply tenant filter
        $sql .= Tenant::whereClause('er.scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        if ($kind !== null) {
            $sql .= " AND er.relation_kind = :kind";
            $params[':kind'] = $kind;
        }

        if ($activeOnly) {
            $sql .= " AND er.status = 'active'";
        }

        $sql .= " ORDER BY e.primary_name ASC";

        if ($callback !== null) {
            CONN::dml($sql, $params, $callback);
            return [];
        }

        return CONN::dml($sql, $params) ?? [];
    }

    /**
     * Find relationships by entity (scoped)
     *
     * @param array $data ['entity_id' => int, 'relation_kind' => string|null]
     * @param array $options ['scope_entity_id' => int, 'active_only' => bool]
     * @param callable|null $callback Optional callback
     * @return array
     */
    public static function findByEntity(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $entityId = (int)($data['entity_id'] ?? 0);
        $kind = $data['relation_kind'] ?? null;
        $activeOnly = $options['active_only'] ?? true;

        $sql = "SELECT er.*, p.profile_name, p.account_id
                FROM entity_relationships er
                LEFT JOIN profiles p ON p.profile_id = er.profile_id
                WHERE er.entity_id = :entity_id";
        $params = [':entity_id' => $entityId];

        # Apply tenant filter
        $sql .= Tenant::whereClause('er.scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        if ($kind !== null) {
            $sql .= " AND er.relation_kind = :kind";
            $params[':kind'] = $kind;
        }

        if ($activeOnly) {
            $sql .= " AND er.status = 'active' AND p.status = 'active'";
        }

        $sql .= " ORDER BY p.profile_name ASC";

        if ($callback !== null) {
            CONN::dml($sql, $params, $callback);
            return [];
        }

        return CONN::dml($sql, $params) ?? [];
    }

    /**
     * Find relationships by kind within scope
     *
     * @param array $data ['relation_kind' => string]
     * @param array $options ['scope_entity_id' => int, 'active_only' => bool]
     * @param callable|null $callback Optional callback
     * @return array
     */
    public static function findByKind(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $kind = $data['relation_kind'] ?? '';
        $activeOnly = $options['active_only'] ?? true;
        $scope = Tenant::resolve($options);

        if ($scope === null && !Tenant::isAdmin()) {
            return [];
        }

        $sql = "SELECT er.*, p.profile_name, p.account_id
                FROM entity_relationships er
                JOIN profiles p ON p.profile_id = er.profile_id
                WHERE er.relation_kind = :kind";
        $params = [':kind' => $kind];

        # For findByKind, entity_id = scope (collaborators OF a company)
        if ($scope !== null) {
            $sql .= " AND er.entity_id = :scope_id";
            $params[':scope_id'] = $scope;
        }

        # Apply tenant filter
        $sql .= Tenant::whereClause('er.scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        if ($activeOnly) {
            $sql .= " AND er.status = 'active' AND p.status = 'active'";
        }

        $sql .= " ORDER BY p.profile_name ASC";

        if ($callback !== null) {
            CONN::dml($sql, $params, $callback);
            return [];
        }

        return CONN::dml($sql, $params) ?? [];
    }

    /**
     * Update a relationship (scoped)
     *
     * @param array $data ['relationship_id' => int, ...fields to update]
     * @param array $options ['scope_entity_id' => int]
     * @param callable|null $callback (unused)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function update(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $relationshipId = (int)($data['relationship_id'] ?? 0);

        if ($relationshipId <= 0) {
            return ['success' => false, 'message' => 'relationship_id is required'];
        }

        # Validate tenant for write
        $tenant = Tenant::validateForWrite($options);
        if (!$tenant['valid']) {
            return ['success' => false, 'message' => $tenant['error']];
        }

        $allowedFields = ['relation_kind', 'grant_mode', 'relationship_label', 'status'];
        $sets = [];
        $params = [':id' => $relationshipId];

        # Validar relation_kind si viene en la data
        if (isset($data['relation_kind']) && !in_array($data['relation_kind'], self::VALID_KINDS, true)) {
            return ['success' => false, 'message' => "Invalid relation_kind: {$data['relation_kind']}"];
        }

        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
                $sets[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }
        }

        if (empty($sets)) {
            return ['success' => false, 'message' => 'No valid fields to update'];
        }

        $sets[] = "updated_at = NOW()";
        $sets[] = "updated_by_profile_id = :updated_by";
        $params[':updated_by'] = Profile::ctx()->profileId ?: null;

        $sql = "UPDATE entity_relationships SET " . implode(', ', $sets) . " WHERE relationship_id = :id";

        # Apply tenant filter (security: can only update own tenant's data)
        $sql .= Tenant::whereClause('scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        $result = CONN::nodml($sql, $params);

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Relationship updated' : 'Failed to update relationship'
        ];
    }

    /**
     * Deactivate a relationship (scoped)
     *
     * @param array $data ['relationship_id' => int]
     * @param array $options ['scope_entity_id' => int]
     * @param callable|null $callback (unused)
     * @return array
     */
    public static function deactivate(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $relationshipId = (int)($data['relationship_id'] ?? 0);
        return self::update(
            ['relationship_id' => $relationshipId, 'status' => self::STATUS_INACTIVE],
            $options
        );
    }

    /**
     * Reactivate a relationship (scoped)
     *
     * @param array $data ['relationship_id' => int]
     * @param array $options ['scope_entity_id' => int]
     * @param callable|null $callback (unused)
     * @return array
     */
    public static function activate(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $relationshipId = (int)($data['relationship_id'] ?? 0);
        return self::update(
            ['relationship_id' => $relationshipId, 'status' => self::STATUS_ACTIVE],
            $options
        );
    }

    /**
     * Delete a relationship permanently (scoped)
     *
     * @param array $data ['relationship_id' => int]
     * @param array $options ['scope_entity_id' => int]
     * @param callable|null $callback (unused)
     * @return array
     */
    public static function delete(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $relationshipId = (int)($data['relationship_id'] ?? 0);

        if ($relationshipId <= 0) {
            return ['success' => false, 'message' => 'relationship_id is required'];
        }

        # Validate tenant for write
        $tenant = Tenant::validateForWrite($options);
        if (!$tenant['valid']) {
            return ['success' => false, 'message' => $tenant['error']];
        }

        $sql = "DELETE FROM entity_relationships WHERE relationship_id = :id";
        $params = [':id' => $relationshipId];

        # Apply tenant filter
        $sql .= Tenant::whereClause('scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        $result = CONN::nodml($sql, $params);

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'Relationship deleted' : 'Failed to delete relationship'
        ];
    }

    /**
     * Create relationship if it doesn't exist (scoped)
     *
     * @param array $data Same as create()
     * @param array $options ['scope_entity_id' => int]
     * @param callable|null $callback Optional callback
     * @return array ['success' => bool, 'relationship_id' => int|null, 'existed' => bool]
     */
    public static function createIfNotExists(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $profileId = (int)($data['profile_id'] ?? 0);
        $entityId = (int)($data['entity_id'] ?? 0);
        $kind = $data['relation_kind'] ?? self::KIND_MEMBER;

        if ($profileId <= 0 || $entityId <= 0) {
            return ['success' => false, 'message' => 'profile_id and entity_id are required'];
        }

        # Validate tenant for write
        $tenant = Tenant::validateForWrite($options);
        if (!$tenant['valid']) {
            return ['success' => false, 'message' => $tenant['error']];
        }

        # Check if exists
        $sql = "SELECT relationship_id FROM entity_relationships
                WHERE profile_id = :profile_id
                  AND entity_id = :entity_id
                  AND relation_kind = :kind
                  AND status = 'active'";
        $params = [
            ':profile_id' => $profileId,
            ':entity_id' => $entityId,
            ':kind' => $kind
        ];

        # Apply tenant filter
        $sql .= Tenant::whereClause('scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        $existingId = null;
        CONN::dml($sql, $params, $callback ?? function($row) use (&$existingId) {
            $existingId = (int)$row['relationship_id'];
            return false;
        });

        if ($existingId !== null) {
            return [
                'success' => true,
                'relationship_id' => $existingId,
                'existed' => true,
                'message' => 'Relationship already exists'
            ];
        }

        $result = self::create($data, $options);
        $result['existed'] = false;
        return $result;
    }

    /**
     * Get all profiles with a specific relation (scoped)
     * Returns formatted array suitable for dropdowns
     *
     * @param array $data ['relation_kind' => string]
     * @param array $options ['scope_entity_id' => int, 'active_only' => bool]
     * @param callable|null $callback Optional callback
     * @return array [['profile_id' => int, 'name' => string], ...]
     */
    public static function getProfilesByRelation(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $kind = $data['relation_kind'] ?? '';

        $rows = self::findByKind(
            ['relation_kind' => $kind],
            $options,
            $callback
        );

        # If callback was used, return empty
        if ($callback !== null) {
            return [];
        }

        return array_map(fn($r) => [
            'profile_id' => (int)$r['profile_id'],
            'name' => $r['relationship_label'] ?: $r['profile_name']
        ], $rows);
    }
}
