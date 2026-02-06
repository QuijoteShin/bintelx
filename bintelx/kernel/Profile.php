<?php
# bintelx/kernel/Profile.php
namespace bX;

/**
 * Profile - User Profile Management
 *
 * Manages user profiles in the target schema (bintelx_core).
 * Uses CONN directly with real field names (no DatabaseAdapter).
 *
 * @package bX
 * @version 3.0 - Simplified for target schema only
 */
class Profile {
    // Processed data from the loaded profile row
    private array $data = [];
    // Raw data from the database for the loaded profile row
    private array $data_raw = [];
    private static bool $rolePermissionsColumnChecked = false;
    private static bool $rolePermissionsColumnExists = false;
    private const ROUTER_SCOPE_WEIGHTS = [
        'write' => 4,
        'read' => 3,
        'private' => 2,
        'public-write' => 1,
        'public' => 0,
    ];

    // Static properties to hold the current user's context for the request
    public static int $profile_id = 0;
    public static int $account_id = 0;
    public static int $entity_id = 0; // The main entity linked to this profile (primary_entity_id in DB)
    public static int $scope_entity_id = 0; // Current tenant/scope from JWT (multi-tenant)
    public static bool $isLoggedIn = false;
    public static array $roles = [];
    public static array $userPermissions = [];

    // Cache for ACL (allowed scopes per request)
    private static ?array $cachedAllowedScopes = null;

    /**
     * Constructor
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Checks if a user is currently logged in
     * @return bool
     */
    public static function isLoggedIn(): bool {
        return self::$isLoggedIn && self::$account_id > 0 && self::$profile_id > 0;
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
        // Allow override for actor_profile_id (useful for first account creation)
        $actorProfileId = $profileData['actor_profile_id'] ?? (self::$profile_id ?: null);

        if ($existingId > 0) {
            // Update existing record
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
            // Create new record
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
     * Loads a profile by account_id and populates static properties
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

        // Reset static properties before loading a new profile
        self::resetStaticProfileData();

        $query = "SELECT * FROM profiles WHERE account_id = :account_id LIMIT 1";
        $params = [':account_id' => (int)$criteria['account_id']];


        $profileData = null;

        // Use callback to get first row efficiently without loading full array
        CONN::dml($query, $params, function($row) use (&$profileData) {
            $profileData = $row;
            return false; // Stop after first row
        });

        self::$isLoggedIn = true;
        self::$account_id = (int)$criteria['account_id'];
        if ($profileData !== null) {
            $this->data_raw = $profileData;
            $this->data = $profileData;

            // Populate static properties
            self::$profile_id = $profileData['profile_id'] ?? 0;
            self::$entity_id = $profileData['primary_entity_id'] ?? 0;

            // Load user permissions
            self::loadUserPermissions(self::$account_id);

            Log::logInfo("Profile loaded: account_id=" . self::$account_id . ", profile_id=" . self::$profile_id . ", entity_id=" . self::$entity_id);
            return true;
        }

        Log::logWarning("Profile::load - No profile found for criteria: " . json_encode($criteria));
        return false;
    }

    /**
     * Loads user permissions/roles for the given account
     *
     * @param int $accountId
     * @return void
     */
    private static function loadUserPermissions(int $accountId): void
    {
        self::$roles = [];
        self::$userPermissions = [
            'routes' => ['*' => 'private'],
            'roles' => []
        ];

        if (self::$profile_id <= 0) {
            Log::logWarning("Profile::loadUserPermissions - profile not initialized for account_id={$accountId}");
            return;
        }

        $relationships = self::fetchProfileRelationships(self::$profile_id);
        $roleAssignments = self::fetchProfileRoles(self::$profile_id);
        self::hydrateRoleCaches($relationships, $roleAssignments);

        $roleCount = count(self::$roles['by_role'] ?? []);
        $routeCount = count(self::$userPermissions['routes'] ?? []);
        Log::logDebug("Permissions loaded for profile_id=" . self::$profile_id . " (roles={$roleCount}, route_rules={$routeCount})");
    }

    /**
     * Resets all static profile data (used before loading a new profile or on logout)
     */
    public static function resetStaticProfileData(): void
    {
        self::$profile_id = 0;
        self::$account_id = 0;
        self::$entity_id = 0;
        self::$isLoggedIn = false;
        self::$roles = [];
        self::$userPermissions = [
            'routes' => ['*' => 'public'],
            'roles' => []
        ];

        # Reset Tenant cache to prevent stale admin bypass between requests
        Tenant::resetCache();
    }

    /**
     * Checks whether a profile has the requested role for a given scope entity.
     */
    public static function hasRole(int $profileId, ?int $scopeEntityId, string $roleCode, bool $includePassive = true): bool
    {
        $roleCode = trim($roleCode);
        if ($roleCode === '') {
            return false;
        }

        if ($profileId === self::$profile_id && !empty(self::$roles['by_role'])) {
            return self::hasRoleInCache($scopeEntityId, $roleCode, $includePassive);
        }

        $sql = "SELECT COUNT(*) AS total
                FROM profile_roles
                WHERE profile_id = :profile_id
                  AND status = 'active'
                  AND role_code = :role_code";
        $params = [
            ':profile_id' => $profileId,
            ':role_code' => $roleCode
        ];

        if ($scopeEntityId !== null) {
            # Check for specific scope OR global (NULL scope)
            $sql .= " AND (scope_entity_id = :scope_id OR scope_entity_id IS NULL)";
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
        if ($profileId === self::$profile_id && !empty(self::$roles['ownership'])) {
            return isset(self::$roles['ownership'][$entityId]);
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
        return self::$userPermissions['routes'] ?? ['*' => 'private'];
    }

    /**
     * Gets profile data for a specific entity
     *
     * @param int $entityId
     * @return bool
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

        // Use callback to get first row efficiently
        CONN::dml($query, $params, function($row) use (&$profileData) {
            $profileData = $row;
            return false; // Stop after first row
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
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Gets the raw data for this profile instance
     * @return array
     */
    public function getRawData(): array
    {
        return $this->data_raw;
    }

    /**
     * Formats raw database data (placeholder for future use)
     * @param array $rawData
     * @return array
     */
    private function formatData(array $rawData): array
    {
        // For now, just return as-is
        // In the future, could do transformations here
        return $rawData;
    }

    /**
     * Gets the profile_id from static context
     * @return int
     */
    public static function getProfileId(): int
    {
        return self::$profile_id;
    }

    /**
     * Gets the account_id from static context
     * @return int
     */
    public static function getAccountId(): int
    {
        return self::$account_id;
    }

    /**
     * Gets the entity_id from static context
     * @return int
     */
    public static function getEntityId(): int
    {
        return self::$entity_id;
    }

    /**
     * Fetches active business relationships for a profile (owner, supplier, customer, etc.)
     * Role assignments are now in profile_roles table
     *
     * @return array<int,array<string,mixed>>
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
     *
     * @return array<int,array<string,mixed>>
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
                  AND r.status = 'active'
                ORDER BY r.role_label ASC";

        $rows = CONN::dml($sql, [':profile_id' => $profileId]);
        return $rows ?? [];
    }

    /**
     * Builds the cached structures for roles and router permissions.
     *
     * @param array<int,array<string,mixed>> $relationships Business relationships (owner, supplier, etc.)
     * @param array<int,array<string,mixed>> $roleAssignments Role assignments from profile_roles
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

            $roleAssignment = [
                'profileRoleId' => (int)($row['profile_role_id'] ?? 0),
                'profileId' => (int)($row['profile_id'] ?? 0),
                'roleCode' => $roleCode,
                'roleLabel' => $row['role_label'] ?? null,
                'scopeType' => $row['scope_type'] ?? null,
                'scopeEntityId' => $scopeEntityId,
                'scopeName' => $row['scope_name'] ?? null,
                'permissions' => self::decodePermissionsJson($row['permissions_json'] ?? null),
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

        self::$roles = [
            'assignments' => $assignments,
            'by_role' => $byRole,
            'by_entity' => $byEntity,
            'ownership' => $ownership
        ];

        self::$userPermissions = [
            'routes' => $routeMap,
            'roles' => array_keys($byRole)
        ];
    }

    /**
     * Determines if the roles table exposes the permissions_json column.
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
     * Decodes permissions JSON safely.
     *
     * @return array<string,mixed>|null
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
        if (empty(self::$roles['by_role'][$roleCode])) {
            return false;
        }

        foreach (self::$roles['by_role'][$roleCode] as $assignment) {
            $assignmentScope = $assignment['scopeEntityId'] ?? null;
            # Match if: no scope requested, or scope matches, or assignment is global (null scope)
            if ($scopeEntityId === null || $assignmentScope === null || $assignmentScope === $scopeEntityId) {
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
        if (!self::$isLoggedIn || !self::$profile_id) {
            return [];
        }

        if (self::$cachedAllowedScopes !== null) {
            return self::$cachedAllowedScopes;
        }

        # Super admin wildcard
        if (self::$account_id === 1) {
            self::$cachedAllowedScopes = ['*'];
            return self::$cachedAllowedScopes;
        }

        $scopes = [];

        # Query ACL from entity_relationships
        # Get DISTINCT scope_entity_id values (tenant IDs) for this profile
        # Excludes NULL scopes - those are internal/system relationships
        CONN::dml(
            "SELECT DISTINCT scope_entity_id
             FROM entity_relationships
             WHERE profile_id = :profile_id
               AND status = 'active'
               AND scope_entity_id IS NOT NULL",
            [':profile_id' => self::$profile_id],
            function(array $row) use (&$scopes) {
                $scopes[] = (int)$row['scope_entity_id'];
            }
        );

        # Fallback: if no scopes found, use profile's own entity_id
        if (empty($scopes) && self::$entity_id > 0) {
            $scopes[] = self::$entity_id;
        }

        self::$cachedAllowedScopes = array_values(array_unique($scopes));
        return self::$cachedAllowedScopes;
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
        $personalEntityId = self::$entity_id;
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
                 WHERE entity_type IN ('company', 'crm_company')
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
            return $result; # Return at least personal scope
        }

        # Build IN clause (exclude personal entity)
        $placeholders = [];
        $params = [];
        foreach ($scopes as $index => $scopeId) {
            if ($scopeId === $personalEntityId) continue; # Skip personal
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
     *
     * @param int $scopeEntityId Scope to check
     * @return bool True if allowed
     */
    public static function canAccessScope(int $scopeEntityId): bool
    {
        # Personal scope is always allowed
        if ($scopeEntityId === self::$entity_id && self::$entity_id > 0) {
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
     *
     * @param int $scopeEntityId Requested scope
     * @throws \RuntimeException If scope not allowed
     */
    public static function assertScope(int $scopeEntityId): void
    {
        if (!self::canAccessScope($scopeEntityId)) {
            # LOG SECURITY VIOLATION
            Log::logError('SECURITY: SCOPE_VIOLATION', [
                'account_id' => self::$account_id,
                'profile_id' => self::$profile_id,
                'entity_id' => self::$entity_id,
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
     *
     * @param int $profileId The ID of the profile to fetch.
     * @param array $options [
     *   'includeAccount' => bool,
     *   'onlyActive' => bool (default: true)
     * ]
     * @return array|null Profile data array or null if not found.
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
