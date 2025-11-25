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
        // TODO: Implement role/permission loading from roles table
        // For now, set empty permissions
        self::$roles = [];
        self::$userPermissions = [];

        // Example: Load from roles table when implemented
        /*
        $query = "SELECT r.role_name, r.permissions_json
                  FROM account_roles ar
                  JOIN roles r ON ar.role_id = r.role_id
                  WHERE ar.account_id = :account_id AND ar.is_active = 1";

        $roleRows = CONN::dml($query, [':account_id' => $accountId]);

        foreach ($roleRows as $role) {
            self::$roles[] = $role['role_name'];
            $permissions = json_decode($role['permissions_json'], true);
            self::$userPermissions = array_merge(self::$userPermissions, $permissions ?? []);
        }
        */

        Log::logDebug("Permissions loaded for account_id=$accountId: " . count(self::$userPermissions) . " permissions");
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
        self::$userPermissions = [];
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
}
