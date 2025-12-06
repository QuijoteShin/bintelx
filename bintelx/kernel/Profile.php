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
    public static bool $isLoggedIn = false;
    public static array $roles = [];
    public static array $userPermissions = [];

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

        $assignments = self::fetchProfileRelationships(self::$profile_id);
        self::hydrateRoleCaches($assignments);

        $roleCount = count(self::$roles['assignments'] ?? []);
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
    }

    /**
     * Checks whether a profile has the requested role for a given entity.
     */
    public static function hasRole(int $profileId, ?int $entityId, string $roleCode, bool $includePassive = true): bool
    {
        $roleCode = trim($roleCode);
        if ($roleCode === '') {
            return false;
        }

        if ($profileId === self::$profile_id && !empty(self::$roles['by_role'])) {
            return self::hasRoleInCache($entityId, $roleCode, $includePassive);
        }

        $sql = "SELECT COUNT(*) AS total
                FROM entity_relationships
                WHERE profile_id = :profile_id
                  AND status = 'active'
                  AND role_code = :role_code";
        $params = [
            ':profile_id' => $profileId,
            ':role_code' => $roleCode
        ];

        if ($entityId !== null) {
            $sql .= " AND entity_id = :entity_id";
            $params[':entity_id'] = $entityId;
        }

        if (!$includePassive) {
            $sql .= " AND (grant_mode IS NULL OR grant_mode <> 'passive')";
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
     * Fetches active relationships for a profile from the database.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function fetchProfileRelationships(int $profileId): array
    {
        $permissionsField = self::rolesTableHasPermissionsColumn()
            ? ", r.permissions_json"
            : ", NULL AS permissions_json";

        $sql = "SELECT
                    er.relationship_id,
                    er.profile_id,
                    er.entity_id,
                    er.relation_kind,
                    er.role_code,
                    er.grant_mode,
                    er.relationship_label,
                    er.status,
                    e.primary_name AS entity_name,
                    e.entity_type,
                    r.role_label,
                    r.scope_type,
                    r.status AS role_status
                    {$permissionsField}
                FROM entity_relationships er
                LEFT JOIN entities e ON e.entity_id = er.entity_id
                LEFT JOIN roles r ON r.role_code = er.role_code
                WHERE er.profile_id = :profile_id
                  AND er.status = 'active'
                  AND (r.role_code IS NULL OR r.status = 'active')
                ORDER BY e.primary_name ASC, er.role_code ASC";

        $rows = CONN::dml($sql, [':profile_id' => $profileId]);
        return $rows ?? [];
    }

    /**
     * Builds the cached structures for roles and router permissions.
     *
     * @param array<int,array<string,mixed>> $relationships
     */
    private static function hydrateRoleCaches(array $relationships): void
    {
        $assignments = [];
        $byRole = [];
        $byEntity = [];
        $ownership = [];
        $routeMap = ['*' => 'private'];

        foreach ($relationships as $row) {
            $assignment = [
                'relationshipId' => (int)($row['relationship_id'] ?? 0),
                'profileId' => (int)($row['profile_id'] ?? 0),
                'entityId' => isset($row['entity_id']) ? (int)$row['entity_id'] : null,
                'entityName' => $row['entity_name'] ?? null,
                'entityType' => $row['entity_type'] ?? null,
                'relationKind' => $row['relation_kind'] ?? null,
                'roleCode' => $row['role_code'] ?? null,
                'roleLabel' => $row['role_label'] ?? null,
                'scopeType' => $row['scope_type'] ?? null,
                'grantMode' => $row['grant_mode'] ?? null,
                'relationshipLabel' => $row['relationship_label'] ?? null,
                'permissions' => self::decodePermissionsJson($row['permissions_json'] ?? null),
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

            if (!empty($assignment['roleCode'])) {
                if ($assignment['roleCode'] === 'system.admin') {
                    $routeMap['*'] = 'write'; # sysadmin acceso total
                }
                $byEntity[$entityKey]['roles'][$assignment['roleCode']] = $assignment;
                $byRole[$assignment['roleCode']][] = $assignment;
                if (!empty($assignment['permissions'])) {
                    self::mergeRoutePermissions($routeMap, $assignment['permissions']);
                }
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
            'roles' => $assignments
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
    private static function hasRoleInCache(?int $entityId, string $roleCode, bool $includePassive): bool
    {
        if (empty(self::$roles['by_role'][$roleCode])) {
            return false;
        }

        foreach (self::$roles['by_role'][$roleCode] as $assignment) {
            if (!$includePassive && ($assignment['grantMode'] ?? null) === 'passive') {
                continue;
            }
            if ($entityId === null || (int)($assignment['entityId'] ?? 0) === (int)$entityId) {
                return true;
            }
        }

        return false;
    }
}
