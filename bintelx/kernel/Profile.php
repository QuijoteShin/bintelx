<?php
# bintelx/kernel/Profile.php
namespace bX;

/**
 * Profile - User Profile Management
 *
 * Manages user profiles in the target schema (bintelx_core).
 * Uses CONN directly with real field names (no DatabaseAdapter).
 *
 * Per-request state lives in coroutine context via CoroutineAware trait.
 * Static methods remain static (backward compatible) — internally use self::ctx()->prop.
 *
 * @package bX
 * @version 4.0 - Coroutine-safe via CoroutineAware
 */
class Profile {
    use CoroutineAware;

    # Instance data for individual profile reads (not per-request context)
    private array $data = [];
    private array $data_raw = [];

    # Global-immutable schema cache (safe as static in Swoole)
    private static bool $rolePermissionsColumnChecked = false;
    private static bool $rolePermissionsColumnExists = false;

    private const ROUTER_SCOPE_WEIGHTS = [
        'write' => 4,
        'read' => 3,
        'private' => 2,
        'public-write' => 1,
        'public' => 0,
    ];

    # Per-request state (coroutine-isolated via ctx())
    public int $profileId = 0;
    public int $accountId = 0;
    public int $entityId = 0;
    public int $scopeEntityId = 0;
    public bool $isLoggedIn = false;
    public array $roles = [];
    public array $permissions = [
        'routes' => ['*' => 'public'],
        'roles' => []
    ];
    public array $categories = []; # RBAC: module category codes del usuario
    public ?array $cachedAllowedScopes = null;

    /**
     * Constructor
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Checks if a user is currently logged in
     */
    public static function isLoggedIn(): bool {
        return self::ctx()->isLoggedIn && self::ctx()->accountId > 0 && self::ctx()->profileId > 0;
    }

    /**
     * Creates or updates a profile record in the database
     *
     * @param array $profileData Data for the profile
     * @return int The profile_id of the saved record
     * @throws \Exception If saving fails
     */
    public static function save(array $profileData): int {
        $existingId = $profileData['profile_id'] ?? 0;

        $accountId = $profileData['account_id'] ?? 0;
        $primaryEntityId = $profileData['primary_entity_id'] ?? null;
        $profileName = $profileData['profile_name'] ?? "Profile #{$existingId}";
        $status = $profileData['status'] ?? 'active';
        # Allow override for actor_profile_id (useful for first account creation)
        $actorProfileId = $profileData['actor_profile_id'] ?? (self::ctx()->profileId ?: null);

        if ($existingId > 0) {
            $query = "UPDATE profiles
                SET
                    primary_entity_id = :primary_entity_id,
                    profile_name = :profile_name,
                    status = :status,
                    updated_at = NOW(),
                    updated_by_profile_id = :updated_by
                WHERE profile_id = :profile_id";

            $params = [
                ':profile_id' => $existingId,
                ':primary_entity_id' => $primaryEntityId,
                ':profile_name' => $profileName,
                ':status' => $status,
                ':updated_by' => $actorProfileId,
            ];

            $result = CONN::nodml($query, $params);

            if (!$result['success']) {
                throw new \Exception("Failed to update profile ID $existingId: " . ($result['error'] ?? 'Unknown DB error'));
            }

            Log::logInfo("Profile updated: profile_id=$existingId");
            return $existingId;

        } else {
            $query = "INSERT INTO profiles
                (account_id, primary_entity_id, profile_name, status, created_by_profile_id, updated_by_profile_id)
                VALUES
                (:account_id, :primary_entity_id, :profile_name, :status, :created_by, :updated_by)";

            $params = [
                ':account_id' => $accountId,
                ':primary_entity_id' => $primaryEntityId,
                ':profile_name' => $profileName,
                ':status' => $status,
                ':created_by' => $actorProfileId,
                ':updated_by' => $actorProfileId,
            ];

            $result = CONN::nodml($query, $params);

            if (!$result['success'] || !$result['last_id']) {
                throw new \Exception("Failed to create profile: " . ($result['error'] ?? 'Unknown DB error'));
            }

            $newProfileId = (int)$result['last_id'];
            Log::logInfo("Profile created: profile_id=$newProfileId, account_id=$accountId");
            return $newProfileId;
        }
    }

    /**
     * Loads a profile by account_id and populates per-request context
     * This is typically called after successful login to set up the user's session context
     *
     * @param array $criteria Associative array for lookup, typically ['account_id' => int]
     * @return bool True if profile loaded successfully, false otherwise
     */
    public function load(array $criteria): bool
    {
        if (empty($criteria['account_id'])) {
            Log::logError("Profile::load - account_id is required in criteria.");
            return false;
        }

        # Reset context before loading a new profile
        self::resetStaticProfileData();

        $query = "SELECT * FROM profiles WHERE account_id = :account_id LIMIT 1";
        $params = [':account_id' => (int)$criteria['account_id']];

        $profileData = null;

        CONN::dml($query, $params, function($row) use (&$profileData) {
            $profileData = $row;
            return false;
        });

        self::ctx()->isLoggedIn = true;
        self::ctx()->accountId = (int)$criteria['account_id'];
        if ($profileData !== null) {
            $this->data_raw = $profileData;
            $this->data = $profileData;

            self::ctx()->profileId = $profileData['profile_id'] ?? 0;
            self::ctx()->entityId = $profileData['primary_entity_id'] ?? 0;

            # Load user permissions
            self::loadUserPermissions(self::ctx()->accountId);

            Log::logInfo("Profile loaded: account_id=" . self::ctx()->accountId . ", profile_id=" . self::ctx()->profileId . ", entity_id=" . self::ctx()->entityId);
            return true;
        }

        Log::logWarning("Profile::load - No profile found for criteria: " . json_encode($criteria));
        return false;
    }

    /**
     * Loads user permissions/roles for the given account
     */
    private static function loadUserPermissions(int $accountId): void
    {
        self::ctx()->roles = [];
        self::ctx()->permissions = [
            'routes' => ['*' => 'private'],
            'roles' => []
        ];

        if (self::ctx()->profileId <= 0) {
            Log::logWarning("Profile::loadUserPermissions - profile not initialized for account_id={$accountId}");
            return;
        }

        $relationships = self::fetchProfileRelationships(self::ctx()->profileId);
        $roleAssignments = self::fetchProfileRoles(self::ctx()->profileId);
        self::hydrateRoleCaches($relationships, $roleAssignments);

        # Persistir roles en Cache compartido (Swoole\Table) — TTL 300s con invalidación inmediata
        if (!empty(self::ctx()->roles['by_role'])) {
            Cache::set('global:profile:roles', (string)self::ctx()->profileId, self::ctx()->roles, 300);
        }

        $roleCount = count(self::ctx()->roles['by_role'] ?? []);
        $routeCount = count(self::ctx()->permissions['routes'] ?? []);
        Log::logDebug("Permissions loaded for profile_id=" . self::ctx()->profileId . " (roles={$roleCount}, route_rules={$routeCount})");
    }

    /**
     * Resets all per-request profile data.
     * Call at start AND finally of each request boundary.
     */
    public static function resetStaticProfileData(): void
    {
        self::resetCtx();

        # Reset Tenant cache to prevent stale admin bypass between requests
        Tenant::resetCache();

        # Reset Entity context
        Entity::resetCtx();
    }

    /**
     * Checks whether a profile has the requested role(s) for a given scope entity.
     * All params optional — defaults to current user context.
     * Accepts single role or array (OR logic: true if ANY matches).
     *
     * Usage:
     *   Profile::hasRole(roleCode: 'finance.all')
     *   Profile::hasRole(roleCode: ['finance.all', 'finance.summary.all'])
     */
    public static function hasRole(?int $profileId = null, ?int $scopeEntityId = null, string|array $roleCode = '', bool $includePassive = true): bool
    {
        $profileId = $profileId ?? self::ctx()->profileId;
        $scopeEntityId = $scopeEntityId ?? (self::ctx()->scopeEntityId > 0 ? self::ctx()->scopeEntityId : null);

        # Normalize to array
        $roleCodes = is_array($roleCode) ? $roleCode : [$roleCode];
        $roleCodes = array_filter(array_map('trim', $roleCodes));
        if (empty($roleCodes)) {
            return false;
        }

        # Cache path: check each role (ANY match = true)
        if ($profileId === self::ctx()->profileId && !empty(self::ctx()->roles['by_role'])) {
            foreach ($roleCodes as $code) {
                if (self::hasRoleInCache($scopeEntityId, $code, $includePassive)) {
                    return true;
                }
            }
            return false;
        }

        # Cache path (shared): check bX\Cache before DB (global por profile, filtro scope en memoria)
        $cached = Cache::get('global:profile:roles', (string)$profileId);
        if ($cached !== null && !empty($cached['by_role'])) {
            foreach ($roleCodes as $code) {
                if (self::hasRoleInCacheFrom($cached, $scopeEntityId, $code, $includePassive)) {
                    return true;
                }
            }
            return false;
        }

        # DB path: use IN() for efficient single query
        $placeholders = [];
        $params = [':profile_id' => $profileId];
        foreach (array_values($roleCodes) as $i => $code) {
            $key = ":role_{$i}";
            $placeholders[] = $key;
            $params[$key] = $code;
        }
        $inClause = implode(',', $placeholders);

        $sql = "SELECT COUNT(*) AS total
                FROM profile_roles
                WHERE profile_id = :profile_id
                  AND status = 'active'
                  AND role_code IN ({$inClause})";

        if ($scopeEntityId !== null) {
            # Check for specific scope OR global tenant roles
            $globalIn = implode(',', Tenant::globalIds());
            if ($globalIn !== '') {
                $sql .= " AND (scope_entity_id = :scope_id OR scope_entity_id IN ({$globalIn}))";
            } else {
                $sql .= " AND scope_entity_id = :scope_id";
            }
            $params[':scope_id'] = $scopeEntityId;
        }

        $result = CONN::dml($sql, $params);
        return !empty($result) && (int)($result[0]['total'] ?? 0) > 0;
    }

    /**
     * Checks if the given profile owns an entity.
     */
    public static function hasOwnership(int $profileId, int $entityId): bool
    {
        if ($profileId === self::ctx()->profileId && !empty(self::ctx()->roles['ownership'])) {
            return isset(self::ctx()->roles['ownership'][$entityId]);
        }

        $sql = "SELECT COUNT(*) AS total
                FROM entity_relationships
                WHERE profile_id = :profile_id
                  AND entity_id = :entity_id
                  AND relation_kind = 'owner'
                  AND status = 'active'";
        $result = CONN::dml($sql, [
            ':profile_id' => $profileId,
            ':entity_id' => $entityId
        ]);

        return !empty($result) && (int)($result[0]['total'] ?? 0) > 0;
    }

    /**
     * Returns the aggregated Router permission map for the currently loaded profile.
     */
    public static function getRoutePermissions(): array
    {
        return self::ctx()->permissions['routes'] ?? ['*' => 'private'];
    }

    /**
     * Gets profile data for a specific entity
     */
    public function read(int $entityId): bool
    {
        if ($entityId <= 0) {
            Log::logError("Profile::read - Invalid entityId.");
            return false;
        }

        $query = "SELECT * FROM profiles WHERE primary_entity_id = :entity_id LIMIT 1";
        $params = [':entity_id' => $entityId];

        $profileData = null;

        CONN::dml($query, $params, function($row) use (&$profileData) {
            $profileData = $row;
            return false;
        });

        if ($profileData !== null) {
            $this->data_raw = $profileData;
            $this->data = $profileData;

            Log::logDebug("Profile instance read successful for entity_id $entityId");
            return true;
        }

        Log::logWarning("Profile instance read: No profile found for entity_id $entityId");
        return false;
    }

    /**
     * Gets the processed data for this profile instance
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Gets the raw data for this profile instance
     */
    public function getRawData(): array
    {
        return $this->data_raw;
    }

    /**
     * Formats raw database data (placeholder for future use)
     */
    private function formatData(array $rawData): array
    {
        return $rawData;
    }

    /**
     * Gets the profile_id from context
     */
    public static function getProfileId(): int
    {
        return self::ctx()->profileId;
    }

    /**
     * Gets the account_id from context
     */
    public static function getAccountId(): int
    {
        return self::ctx()->accountId;
    }

    /**
     * Gets the entity_id from context
     */
    public static function getEntityId(): int
    {
        return self::ctx()->entityId;
    }

    /**
     * Fetches active business relationships for a profile (owner, supplier, customer, etc.)
     */
    private static function fetchProfileRelationships(int $profileId): array
    {
        $sql = "SELECT
                    er.relationship_id,
                    er.profile_id,
                    er.entity_id,
                    er.relation_kind,
                    er.grant_mode,
                    er.relationship_label,
                    er.status,
                    e.primary_name AS entity_name,
                    e.entity_type
                FROM entity_relationships er
                LEFT JOIN entities e ON e.entity_id = er.entity_id
                WHERE er.profile_id = :profile_id
                  AND er.status = 'active'
                  AND er.relation_kind != 'role_assignment'
                ORDER BY e.primary_name ASC";

        $rows = CONN::dml($sql, [':profile_id' => $profileId]);
        return $rows ?? [];
    }

    /**
     * Fetches role assignments from profile_roles table
     */
    private static function fetchProfileRoles(int $profileId): array
    {
        $permissionsField = self::rolesTableHasPermissionsColumn()
            ? ", r.permissions_json"
            : ", NULL AS permissions_json";

        $sql = "SELECT
                    pr.profile_role_id,
                    pr.profile_id,
                    pr.role_code,
                    pr.scope_entity_id,
                    pr.status,
                    r.role_label,
                    r.scope_type,
                    r.status AS role_status,
                    e.primary_name AS scope_name,
                    e.entity_type AS scope_type_entity
                    {$permissionsField}
                FROM profile_roles pr
                LEFT JOIN roles r ON r.role_code = pr.role_code
                LEFT JOIN entities e ON e.entity_id = pr.scope_entity_id
                WHERE pr.profile_id = :profile_id
                  AND pr.status = 'active'
                  AND (pr.expires_at IS NULL OR pr.expires_at > NOW())
                  AND r.status = 'active'
                ORDER BY r.role_label ASC";

        $rows = CONN::dml($sql, [':profile_id' => $profileId]);
        return $rows ?? [];
    }

    /**
     * Builds the cached structures for roles and router permissions.
     */
    private static function hydrateRoleCaches(array $relationships, array $roleAssignments = []): void
    {
        $assignments = [];
        $byRole = [];
        $byEntity = [];
        $ownership = [];
        $routeMap = ['*' => 'private'];

        # Process business relationships (owner, supplier, customer, etc.)
        foreach ($relationships as $row) {
            $assignment = [
                'relationshipId' => (int)($row['relationship_id'] ?? 0),
                'profileId' => (int)($row['profile_id'] ?? 0),
                'entityId' => isset($row['entity_id']) ? (int)$row['entity_id'] : null,
                'entityName' => $row['entity_name'] ?? null,
                'entityType' => $row['entity_type'] ?? null,
                'relationKind' => $row['relation_kind'] ?? null,
                'roleCode' => null,
                'roleLabel' => null,
                'scopeType' => null,
                'grantMode' => $row['grant_mode'] ?? null,
                'relationshipLabel' => $row['relationship_label'] ?? null,
                'permissions' => null,
            ];

            $assignments[] = $assignment;

            $entityKey = $assignment['entityId'] ?? 'global';
            if (!isset($byEntity[$entityKey])) {
                $byEntity[$entityKey] = [
                    'entityId' => $assignment['entityId'],
                    'entityName' => $assignment['entityName'],
                    'entityType' => $assignment['entityType'],
                    'ownership' => false,
                    'roles' => []
                ];
            }

            if ($assignment['relationKind'] === 'owner' && $assignment['entityId'] !== null) {
                $byEntity[$entityKey]['ownership'] = true;
                $ownership[$assignment['entityId']] = true;
                $routeMap['*'] = self::selectHigherScope($routeMap['*'] ?? 'private', 'write');
            }
        }

        # Process role assignments from profile_roles table
        foreach ($roleAssignments as $row) {
            $roleCode = $row['role_code'] ?? null;
            if (empty($roleCode)) continue;

            $scopeEntityId = isset($row['scope_entity_id']) ? (int)$row['scope_entity_id'] : null;

            # RBAC: try role_route_permissions table first, fallback to permissions_json
            $rolePerms = self::getRoutePermissionsForRole($roleCode, $scopeEntityId);
            if ($rolePerms === null) {
                $rolePerms = self::decodePermissionsJson($row['permissions_json'] ?? null);
            }

            $roleAssignment = [
                'profileRoleId' => (int)($row['profile_role_id'] ?? 0),
                'profileId' => (int)($row['profile_id'] ?? 0),
                'roleCode' => $roleCode,
                'roleLabel' => $row['role_label'] ?? null,
                'scopeType' => $row['scope_type'] ?? null,
                'scopeEntityId' => $scopeEntityId,
                'scopeName' => $row['scope_name'] ?? null,
                'permissions' => $rolePerms,
            ];

            # System admin gets full access
            if ($roleCode === 'system.admin') {
                $routeMap['*'] = 'write';
            }

            # Index by role code
            $byRole[$roleCode][] = $roleAssignment;

            # Index by scope entity (for scoped permissions)
            $scopeKey = $scopeEntityId ?? 'global';
            if (!isset($byEntity[$scopeKey])) {
                $byEntity[$scopeKey] = [
                    'entityId' => $scopeEntityId,
                    'entityName' => $row['scope_name'] ?? null,
                    'entityType' => $row['scope_type_entity'] ?? null,
                    'ownership' => false,
                    'roles' => []
                ];
            }
            $byEntity[$scopeKey]['roles'][$roleCode] = $roleAssignment;

            # Merge route permissions from role
            if (!empty($roleAssignment['permissions'])) {
                self::mergeRoutePermissions($routeMap, $roleAssignment['permissions']);
            }
        }

        self::ctx()->roles = [
            'assignments' => $assignments,
            'by_role' => $byRole,
            'by_entity' => $byEntity,
            'ownership' => $ownership
        ];

        self::ctx()->permissions = [
            'routes' => $routeMap,
            'roles' => array_keys($byRole)
        ];

        # RBAC: extract categorySet from user's packages
        self::ctx()->categories = ModuleCategoryService::getProfileCategories(
            self::ctx()->profileId,
            self::ctx()->scopeEntityId > 0 ? self::ctx()->scopeEntityId : null
        );
    }

    /**
     * Determines if the roles table exposes the permissions_json column.
     * Global-immutable schema check — safe as static in Swoole.
     */
    private static function rolesTableHasPermissionsColumn(): bool
    {
        if (self::$rolePermissionsColumnChecked) {
            return self::$rolePermissionsColumnExists;
        }

        $columns = CONN::dml("SHOW COLUMNS FROM roles LIKE 'permissions_json'");
        self::$rolePermissionsColumnExists = !empty($columns);
        self::$rolePermissionsColumnChecked = true;
        return self::$rolePermissionsColumnExists;
    }

    /**
     * Get route permissions from role_route_permissions table for a role.
     * Returns null if no records found (caller should fallback to permissions_json).
     */
    private static function getRoutePermissionsForRole(string $roleCode, ?int $scopeEntityId = null): ?array
    {
        $params = [':role' => $roleCode];
        $sql = "SELECT route_pattern, scope_level FROM role_route_permissions
                WHERE role_code = :role";

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);

        $routes = [];
        CONN::dml($sql, $params, function ($row) use (&$routes) {
            $routes[$row['route_pattern']] = $row['scope_level'];
        });

        return !empty($routes) ? ['routes' => $routes] : null;
    }

    /**
     * Decodes permissions JSON safely.
     */
    private static function decodePermissionsJson(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            Log::logWarning("Profile::decodePermissionsJson - Invalid JSON: " . json_last_error_msg());
            return null;
        }

        return $decoded;
    }

    /**
     * Merges role based route permissions into the aggregated Router map.
     */
    private static function mergeRoutePermissions(array &$routeMap, array $permissions): void
    {
        $routes = $permissions['routes'] ?? $permissions;
        if (!is_array($routes)) {
            return;
        }

        foreach ($routes as $pattern => $scope) {
            if (!is_string($pattern)) {
                continue;
            }
            $normalizedScope = self::normalizeScope($scope);
            if ($normalizedScope === null) {
                continue;
            }
            $current = $routeMap[$pattern] ?? 'public';
            $routeMap[$pattern] = self::selectHigherScope($current, $normalizedScope);
        }
    }

    /**
     * Normalizes incoming scope strings to known Router scopes.
     */
    private static function normalizeScope(mixed $scope): ?string
    {
        if (!is_string($scope) || $scope === '') {
            return null;
        }
        $scope = strtolower($scope);
        return isset(self::ROUTER_SCOPE_WEIGHTS[$scope]) ? $scope : null;
    }

    /**
     * Selects the scope with the higher weight according to ROUTER_SCOPE_WEIGHTS.
     */
    private static function selectHigherScope(string $current, string $candidate): string
    {
        $currentWeight = self::ROUTER_SCOPE_WEIGHTS[$current] ?? 0;
        $candidateWeight = self::ROUTER_SCOPE_WEIGHTS[$candidate] ?? 0;
        return ($candidateWeight > $currentWeight) ? $candidate : $current;
    }

    /**
     * Checks the cached roles for a match.
     */
    private static function hasRoleInCache(?int $scopeEntityId, string $roleCode, bool $includePassive): bool
    {
        return self::hasRoleInCacheFrom(self::ctx()->roles, $scopeEntityId, $roleCode, $includePassive);
    }

    # Busca rol en un array de roles (ctx o cached) — no modifica estado global
    private static function hasRoleInCacheFrom(array $roles, ?int $scopeEntityId, string $roleCode, bool $includePassive): bool
    {
        if (empty($roles['by_role'][$roleCode])) {
            return false;
        }

        $globalIds = Tenant::globalIds();
        foreach ($roles['by_role'][$roleCode] as $assignment) {
            $assignmentScope = $assignment['scopeEntityId'] ?? null;
            # Match if: no scope requested, scope matches, or assignment is global
            if ($scopeEntityId === null || $assignmentScope === $scopeEntityId || in_array($assignmentScope, $globalIds, true)) {
                return true;
            }
        }

        return false;
    }

    // ========================================================================
    // MULTI-TENANT ACL METHODS
    // ========================================================================

    /**
     * Get all scope_entity_ids this profile can access
     *
     * Reads from entity_relationships where relationship_type = 'profile_can_access_scope'.
     * Cached per request for performance.
     *
     * @return array Array of entity_ids (companies/branches) or ['*'] for admin
     */
    public static function getAllowedScopes(): array
    {
        if (!self::ctx()->isLoggedIn || !self::ctx()->profileId) {
            return [];
        }

        if (self::ctx()->cachedAllowedScopes !== null) {
            return self::ctx()->cachedAllowedScopes;
        }

        # Super admin wildcard
        if (self::ctx()->accountId === 1) {
            self::ctx()->cachedAllowedScopes = ['*'];
            return self::ctx()->cachedAllowedScopes;
        }

        $scopes = [];

        # Query ACL from entity_relationships
        # Get DISTINCT scope_entity_id values (tenant IDs) for this profile
        # Excludes NULL scopes (internal/system) and GLOBAL_TENANT (not switchable)
        $globalIn = implode(',', Tenant::globalIds());
        $excludeClause = "AND scope_entity_id IS NOT NULL";
        if ($globalIn !== '') {
            $excludeClause .= " AND scope_entity_id NOT IN ({$globalIn})";
        }

        CONN::dml(
            "SELECT DISTINCT scope_entity_id
             FROM entity_relationships
             WHERE profile_id = :profile_id
               AND status = 'active'
               {$excludeClause}",
            [':profile_id' => self::ctx()->profileId],
            function(array $row) use (&$scopes) {
                $scopes[] = (int)$row['scope_entity_id'];
            }
        );

        # Global tenant visible for system.admin
        $globalIds = Tenant::globalIds();
        if (!empty($globalIds) && self::hasRole(null, null, 'system.admin')) {
            foreach ($globalIds as $gid) {
                $scopes[] = $gid;
            }
        }

        # Fallback: if no scopes found, use profile's own entity_id
        if (empty($scopes) && self::ctx()->entityId > 0) {
            $scopes[] = self::ctx()->entityId;
        }

        self::ctx()->cachedAllowedScopes = array_values(array_unique($scopes));
        return self::ctx()->cachedAllowedScopes;
    }

    /**
     * Get allowed scopes with metadata (entity_id + name)
     *
     * Returns scopes ready for frontend display.
     * Encapsulates SQL logic (no queries in endpoints).
     *
     * @return array [['id' => int, 'name' => string], ...]
     */
    public static function getAllowedScopesWithMeta(): array
    {
        $scopes = self::getAllowedScopes();
        $result = [];

        # Always include personal scope first (user's own entity)
        $personalEntityId = self::ctx()->entityId;
        if ($personalEntityId > 0) {
            CONN::dml(
                "SELECT entity_id, primary_name FROM entities WHERE entity_id = :id",
                [':id' => $personalEntityId],
                function(array $row) use (&$result) {
                    $result[] = [
                        'id' => (int)$row['entity_id'],
                        'name' => $row['primary_name'],
                        'is_personal' => true
                    ];
                }
            );
        }

        # Admin wildcard: return all companies
        if ($scopes === ['*']) {
            CONN::dml(
                "SELECT entity_id, primary_name
                 FROM entities
                 WHERE entity_type IN ('company')
                   AND status = 'active'",
                [],
                function(array $row) use (&$result, $personalEntityId) {
                    # Skip personal entity (already added)
                    if ((int)$row['entity_id'] === $personalEntityId) return;
                    $result[] = [
                        'id' => (int)$row['entity_id'],
                        'name' => $row['primary_name'],
                        'is_personal' => false
                    ];
                }
            );
            return $result;
        }

        # Specific scopes: fetch metadata in single query
        if (empty($scopes)) {
            return $result;
        }

        # Build IN clause (exclude personal entity)
        $placeholders = [];
        $params = [];
        foreach ($scopes as $index => $scopeId) {
            if ($scopeId === $personalEntityId) continue;
            $key = ":scope{$index}";
            $placeholders[] = $key;
            $params[$key] = $scopeId;
        }

        if (!empty($placeholders)) {
            CONN::dml(
                "SELECT entity_id, primary_name
                 FROM entities
                 WHERE entity_id IN (" . implode(',', $placeholders) . ")",
                $params,
                function(array $row) use (&$result) {
                    $result[] = [
                        'id' => (int)$row['entity_id'],
                        'name' => $row['primary_name'],
                        'is_personal' => false
                    ];
                }
            );
        }

        return $result;
    }

    /**
     * Check if profile can access a specific scope
     */
    public static function canAccessScope(int $scopeEntityId): bool
    {
        # Personal scope is always allowed
        if ($scopeEntityId === self::ctx()->entityId && self::ctx()->entityId > 0) {
            return true;
        }

        $allowedScopes = self::getAllowedScopes();

        # Admin wildcard
        if (in_array('*', $allowedScopes, true)) {
            return true;
        }

        return in_array($scopeEntityId, $allowedScopes, true);
    }

    /**
     * Asserts scope access or throw exception
     *
     * Validates scope and logs security violation if invalid.
     * Use ONLY in login and scope switch endpoints.
     */
    public static function assertScope(int $scopeEntityId): void
    {
        if (!self::canAccessScope($scopeEntityId)) {
            Log::logError('SECURITY: SCOPE_VIOLATION', [
                'account_id' => self::ctx()->accountId,
                'profile_id' => self::ctx()->profileId,
                'entity_id' => self::ctx()->entityId,
                'requested_scope' => $scopeEntityId,
                'allowed_scopes' => self::getAllowedScopes(),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            throw new \RuntimeException('Forbidden: You do not have access to this scope');
        }
    }

    /**
     * Gets comprehensive profile information including entity and account details.
     * Use this to retrieve "author" names or user profile cards.
     */
    public static function getProfileInfo(int $profileId, array $options = []): ?array
    {
        if ($profileId <= 0) {
            return null;
        }

        $onlyActive = $options['onlyActive'] ?? true;
        $includeAccount = $options['includeAccount'] ?? false;

        $sql = "SELECT p.*,
                       e.primary_name AS entity_name,
                       e.entity_type,
                       e.national_id,
                       e.national_isocode";

        if ($includeAccount) {
            $sql .= ", a.username, a.is_active";
        }

        $sql .= " FROM profiles p
                  LEFT JOIN entities e ON e.entity_id = p.primary_entity_id";

        if ($includeAccount) {
            $sql .= " LEFT JOIN accounts a ON a.account_id = p.account_id";
        }

        $sql .= " WHERE p.profile_id = :pid";

        if ($onlyActive) {
            $sql .= " AND p.status = 'active' AND e.status = 'active'";
            if ($includeAccount) {
                $sql .= " AND a.is_active = 1";
            }
        }

        $sql .= " LIMIT 1";

        $profileInfo = null;
        CONN::dml($sql, [':pid' => $profileId], function ($row) use (&$profileInfo) {
            $profileInfo = $row;
            return false;
        });

        return $profileInfo;
    }
}
