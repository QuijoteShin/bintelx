<?php
# bintelx/kernel/Profile.php
namespace bX;

// use bX\Entity\Entity; // Not directly used in this file for static properties
// use bX\Entity\Correlation;
// use bX\Entity\Model;

class Profile {
    // Processed data from the loaded profile row
    private array $data = [];
    // Raw data from the database for the loaded profile row
    private array $data_raw = [];

    // Static properties to hold the current user's context for the request
    public static int $profile_id = 0;
    public static int $account_id = 0;
    public static int $entity_id = 0; // The main entity linked to this profile
    public static int $comp_id = 0;   // Current company context
    public static int $comp_branch_id = 0; // Current branch context (0 if not specific)

    public static bool $isLoggedIn = false;
    public static array $roles = []; # plain assoc array ['ROLE_NAME', 'ANOTHER']
    public static array $superCowPermissions = []; // Could store granular permissions/roles


    /**
     * Constructor.
     * Initializes an instance. Static properties are populated by load().
     * @param array $data Optional data (not typically used as load() is preferred for populating statics).
     */
    public function __construct(array $data = [])
    {
        // Constructor is instance-specific. Static properties are global for the request.
    }

    /**
     * Defines the data model for the `profile` table.
     * Used for formatting and potentially validation.
     * @return array Model definition.
     */
    public function model(): array
    {
        return [
            'profile_id'         => ['type' => 'int',     'auto_increment' => true, 'not null' => true],
            'comp_id'            => ['type' => 'int',     'default' => 0],
            'comp_branch_id'     => ['type' => 'int',     'default' => 0],
            'entity_id'          => ['type' => 'int',     'default' => null], // Can be null if account not linked to a specific entity yet
            'account_id'         => ['type' => 'int',     'default' => 0, 'not null' => true], // Should be not null if it's a profile for an account
            'profile_created_at' => ['type' => 'datetime','default' => 'CURRENT_TIMESTAMP'],
            'profile_created_by' => ['type' => 'int',     'not null' => true], // User ID who created this profile record
            'profile_updated_at' => ['type' => 'datetime','default' => 'CURRENT_TIMESTAMP', 'on_update' => 'CURRENT_TIMESTAMP'],
            'profile_updated_by' => ['type' => 'int',     'not null' => true], // User ID who last updated this
        ];
    }

    /**
     * Creates or updates a profile record in the database.
     * @param array $profileData Data for the profile. Must include identifying keys for update,
     *                        or necessary fields for creation.
     * @return int The profile_id of the saved record.
     * @throws \Exception If saving fails.
     */
    public static function save(array $profileData): int {
        $existingId = $profileData['profile_id'] ?? 0;

        // Use current context if specific IDs are not provided in $profileData
        $compId = $profileData['comp_id'] ?? self::$comp_id;
        $compBranchId = $profileData['comp_branch_id'] ?? self::$comp_branch_id;
        // created_by and updated_by should ideally be the current active user's ID
        $actorUserId = self::$account_id ?: ($profileData['profile_created_by'] ?? 1); // Fallback to 1 if no active user

        if ($existingId > 0) {
            // Update existing record
            $query = "UPDATE `profile`
                SET 
                    comp_id = :comp_id,
                    comp_branch_id = :comp_branch_id,
                    entity_id = :entity_id,
                    account_id = :account_id,
                    -- profile_created_at = :profile_created_at, -- Usually not updated
                    -- profile_created_by = :profile_created_by, -- Usually not updated
                    profile_updated_by = :profile_updated_by,
                    profile_updated_at = NOW()
                WHERE profile_id = :profile_id";
            $params = [
                ':profile_id'         => $existingId,
                ':comp_id'            => $compId,
                ':comp_branch_id'     => $compBranchId,
                ':entity_id'          => $profileData['entity_id'] ?? null, // Allow null if unlinking
                ':account_id'         => $profileData['account_id'] ?? 0,
                // ':profile_created_at' => $profileData['profile_created_at'] ?? null, // Handled by DB or on insert
                // ':profile_created_by' => $profileData['profile_created_by'] ?? $actorUserId,
                ':profile_updated_by' => $actorUserId,
            ];
            $result = \bX\CONN::nodml($query, $params);
            if (!$result['success']) {
                throw new \Exception("Failed to update profile for ID: $existingId.");
            }
            return $existingId;
        } else {
            // Insert new record
            $query = "INSERT INTO `profile`
                (comp_id, comp_branch_id, entity_id, account_id, 
                 profile_created_by, profile_updated_by, profile_created_at, profile_updated_at)
                VALUES
                (:comp_id, :comp_branch_id, :entity_id, :account_id, 
                 :profile_created_by, :profile_updated_by, NOW(), NOW())";

            $params = [
                ':comp_id'            => $compId,
                ':comp_branch_id'     => $compBranchId,
                ':entity_id'          => $profileData['entity_id'] ?? null,
                ':account_id'         => $profileData['account_id'] ?? 0, // Must be provided for a new profile
                ':profile_created_by' => $actorUserId,
                ':profile_updated_by' => $actorUserId,
            ];
            if (empty($params[':account_id'])) {
                throw new \Exception("account_id is required to create a new profile.");
            }
            $result = \bX\CONN::nodml($query, $params);

            if (!$result['success'] || empty($result['last_id'])) {
                throw new \Exception("Failed to insert new profile.");
            }
            return (int)$result['last_id'];
        }
    }

    /**
     * Loads profile data based on account_id and optionally comp_id/comp_branch_id.
     * Populates the static properties of this class with the loaded profile context.
     *
     * @param array $criteria Associative array for lookup. Typically ['account_id' => int].
     *                        Can include 'comp_id', 'comp_branch_id' for more specific loading if needed.
     * @return bool True if profile is successfully loaded, false otherwise.
     */
    public function load(array $criteria = []): bool
    {
        if (empty($criteria['account_id'])) {
            Log::logError("Profile::load - account_id is required for loading.");
            return false;
        }

        // Reset static properties before loading a new profile
        self::resetStaticProfileData();

        $query = "SELECT * FROM `profile` WHERE account_id = :account_id";
        $params = [ ':account_id' => (int)$criteria['account_id'] ];

        // If comp_id is part of criteria, and you have multiple profiles per account for different companies
        if (isset($criteria['comp_id'])) {
            $query .= " AND comp_id = :comp_id";
            $params[':comp_id'] = (int)$criteria['comp_id'];
            // If comp_branch_id is also relevant for uniqueness of a profile
            if (isset($criteria['comp_branch_id'])) {
                $query .= " AND comp_branch_id = :comp_branch_id";
                $params[':comp_branch_id'] = (int)$criteria['comp_branch_id'];
            }
        }
        $query .= " LIMIT 1"; // Assuming one primary profile per account (or per account/comp)

        $profileRows = \bX\CONN::dml($query, $params);

        if (!empty($profileRows[0])) {
            $this->data_raw = $profileRows[0]; // Store raw data in instance
            $this->data = $this->formatData($this->data_raw); // Store formatted data in instance

            self::$account_id = $this->data['account_id'];
            self::$profile_id = $this->data['profile_id'] ?? 0; # profile_id can be null
            self::$entity_id = $this->data['entity_id'] ?? 0; # entity_id can be null
            self::$comp_id = $this->data['comp_id'];
            self::$comp_branch_id = $this->data['comp_branch_id'] ?? 0; # comp_branch_id may be null

            // using profile data, fetch granular permissions
            self::loadUserPermissions(self::$account_id, self::$comp_id); // Or self::$profile_id

            Log::logDebug("Profile loaded: account_id=" . self::$account_id . ", profile_id=" . self::$profile_id . ", entity_id=" . self::$entity_id);
            return true;
        }
        Log::logWarning("Profile::load - No profile found for criteria: " . json_encode($criteria));
        return false;
    }

    /**
     * Resets static profile data. Called before loading a new profile.
     */
    private static function resetStaticProfileData(): void {
        self::$account_id = 0;
        self::$profile_id = 0;
        self::$entity_id = 0;
        self::$comp_id = 0;
        self::$comp_branch_id = 0;
        self::$superCowPermissions = [];
    }

    /**
     * (Conceptual) Loads granular user permissions/roles.
     * This method would query your permissions/roles tables.
     * @param int $accountId The account ID of the user.
     * @param int $companyId The current company context.
     */
    private static function loadUserPermissions(int $accountId, int $companyId): void {
        // Example: Query a `account_roles` and `role_permissions` table
        // For now, let's hardcode for demonstration. Replace with actual DB queries.
        // self::$superCowPermissions = []; // Reset
        // $sql = "SELECT p.permission_key
        //         FROM account_x_role axr
        //         JOIN role_x_permission rxp ON axr.role_id = rxp.role_id
        //         JOIN permission p ON rxp.permission_id = p.permission_id
        //         WHERE axr.account_id = :account_id AND (axr.comp_id = :comp_id OR axr.comp_id IS NULL)";
        // $permissionsData = \bX\CONN::dml($sql, [':account_id' => $accountId, ':comp_id' => $companyId]);
        // foreach($permissionsData as $row) {
        //    self::$superCowPermissions[] = $row['permission_key']; // e.g., 'ORDER_READ', 'ORDER_WRITE', 'USER_ADMIN'
        // }

        // Simplified example: if account_id is 1, grant 'write', else 'read' if logged in
        if ($accountId === 1) { // Superadmin example
            self::$superCowPermissions = ['ROLE_ADMIN', 'CAN_WRITE_ALL', 'CAN_READ_ALL'];
            self::$roles = ['ROLE_USER'];
        } elseif ($accountId > 0) { // Other logged-in users
            self::$roles = ['ROLE_USER']; // Example permission
        }
        Log::logDebug("User permissions loaded for account_id $accountId: " . json_encode(self::$superCowPermissions));
    }

    /**
     * Gets the effective permission scope for the currently loaded profile.
     * This is used by the Router to determine access level.
     * @param string|null $moduleContext Optional module name for context-specific scope. (Not used in this simple version)
     * @return string One of ROUTER_SCOPE_* constants.
     */
    public static function getEffectiveScope(string $moduleContext = null): string {
        if (self::$account_id <= 0) {
            return ROUTER_SCOPE_PUBLIC;
        }

        // Check for granular permissions (this part needs to be tailored to your permission system)
        // Example: if user has a 'CAN_WRITE_ALL' permission or a specific 'WRITE_moduleContext' permission
        if (!empty(self::$superCowPermissions)) {
            if (in_array('CAN_WRITE_ALL', self::$superCowPermissions) ||
                ($moduleContext && in_array('WRITE_' . strtoupper($moduleContext), self::$superCowPermissions))) {
                return ROUTER_SCOPE_WRITE;
            }
            if (in_array('CAN_READ_ALL', self::$superCowPermissions) ||
                ($moduleContext && in_array('READ_' . strtoupper($moduleContext), self::$superCowPermissions))) {
                return ROUTER_SCOPE_READ;
            }
        }

        // Default for any authenticated user if no specific read/write scopes are found
        return ROUTER_SCOPE_PRIVATE;
    }

    /**
     * Checks if the current profile has a specific permission.
     * @param string $permissionKey The permission key to check (e.g., 'ORDER_CREATE').
     * @return bool True if the user has the permission, false otherwise.
     */
    public static function hasPermission(string $permissionKey): bool {
        if (self::$account_id <= 0) return false; // Not logged in
        // Superadmin (account_id 1 in example) has all permissions implicitly
        if (self::$account_id === 1 && (in_array('ROLE_ADMIN', self::$superCowPermissions) || in_array('CAN_WRITE_ALL', self::$superCowPermissions)) ) {
            return true;
        }
        return in_array($permissionKey, self::$superCowPermissions);
    }

    /**
     * Checks if a user is currently logged in (i.e., profile loaded).
     * @return bool True if logged in, false otherwise.
     */
    public static function isLoggedIn(): bool {
        return self::$account_id > 0;
    }


    /**
     * Formats raw database data according to the model's type definitions.
     * @param array $rawData Raw data row from the database.
     * @return array Formatted data.
     */
    private function formatData(array $rawData): array {
        // Assuming bX\DataUtils::formatData exists and works as expected.
        // If DataUtils is not available or its model definition differs, implement here:
        $formatted = [];
        $model = $this->model(); // Get model from instance method
        foreach ($model as $field => $rules) {
            $val = $rawData[$field] ?? $rules['default'] ?? null; // Use default if not set
            switch ($rules['type']) {
                case 'int':
                    $formatted[$field] = ($val !== null) ? (int)$val : null;
                    if (($rules['not null'] ?? false) && $formatted[$field] === null) {
                        // Or throw exception, or assign 0 based on your rules
                        $formatted[$field] = 0; // Example: default not-null int to 0
                    }
                    break;
                case 'string':
                case 'datetime': // Store datetimes as strings
                case 'enum':
                    $formatted[$field] = ($val !== null) ? (string)$val : null;
                    if (($rules['not null'] ?? false) && $formatted[$field] === null) {
                        $formatted[$field] = ''; // Example: default not-null string to empty
                    }
                    break;
                case 'bigint':
                    $formatted[$field] = ($val !== null) ? (string)$val : null; // Handle as string to avoid PHP int limits
                    break;
                default:
                    $formatted[$field] = $val;
                    break;
            }
        }
        return $formatted;
        // return \bX\DataUtils::formatData($rawData, $this->model());
    }

    // --- Other instance methods from your original Profile class ---
    // (read, getRelations, getPrimaryContactData, delete - these operate on an instance ($this->data))
    // They might need adjustments if they should also use static Profile context or be fully static.
    // For consistency, if Profile is mainly a static context holder for the request,
    // methods like read() that populate $this->data might be less used than load() populating statics.

    /**
     * Reads a profile based on entity_id and current static comp_id/comp_branch_id.
     * This loads data into the INSTANCE, not the static properties.
     * Consider if this is needed alongside the static load().
     * @param int $entityId
     * @return bool
     */
    public function read(int $entityId): bool // Operates on instance $this
    {
        if ($entityId <= 0 || self::$comp_id <= 0) { // Uses static comp_id
            Log::logError("Profile instance read: Invalid entityId or comp_id not set in static context.");
            return false;
        }

        $query = "SELECT * FROM `profile` 
                  WHERE entity_id = :entity_id 
                  AND comp_id = :comp_id";
        $params = [
            ':entity_id' => $entityId,
            ':comp_id' => self::$comp_id // Uses static context
        ];
        if (self::$comp_branch_id > 0) {
            $query .= " AND comp_branch_id = :comp_branch_id";
            $params[':comp_branch_id'] = self::$comp_branch_id;
        }
        $query .= " LIMIT 1";

        $this->data_raw = []; // Reset instance data
        $this->data = [];
        $profileRows = \bX\CONN::dml($query, $params);

        if (!empty($profileRows[0])) {
            $this->data_raw = $profileRows[0];
            $this->data = $this->formatData($this->data_raw);
            // Note: This does NOT update the static self::$profile_id etc.
            return true;
        }
        Log::logWarning("Profile instance read: No profile found for entity_id $entityId with current static comp context.");
        return false;
    }
}