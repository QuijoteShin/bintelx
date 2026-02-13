<?php
namespace bX;

use Exception;

class Account {
    private string $jwtSecret;
    private string $xorKey; // For potential other non-JWT crypto operations
    private int $tokenExpiration = 3600; // Default 1 hour for generated tokens

    public function __construct(string $jwtSecret, string $xorKey = '') {
        $this->jwtSecret = $jwtSecret;
        $this->xorKey = $xorKey;
    }

  /**
   * Authenticates a user with username and password, and returns a JWT on success.
   * This is the primary method for handling a login request.
   *
   * @param array $credentials Associative array with 'username' and 'password'.
   * @return array An array indicating success, a message, and the token on success.
   * e.g., ['success' => bool, 'message' => string, 'token' => ?string]
   */
  public function login(array $credentials): array
  {
    if (empty($credentials['username']) || empty($credentials['password'])) {
      return ['success' => false, 'message' => 'Username and password are required.'];
    }

    try {
      # Direct query using target schema field names
      $sql = "SELECT account_id, password_hash, is_active
              FROM accounts
              WHERE username = :username LIMIT 1";

      $user = null;

      // Use callback to get first row efficiently
      CONN::dml($sql, [':username' => $credentials['username']], function($row) use (&$user) {
        $user = $row;
        return false; // Stop after first row
      });

      if ($user === null) {
        Log::logWarning('Login attempt for non-existent user: ' . $credentials['username']);
        return ['success' => false, 'message' => 'Invalid credentials.'];
      }

      if (!$user['is_active']) {
        Log::logWarning('Login attempt for inactive account: ' . $user['account_id']);
        return ['success' => false, 'message' => 'Account is inactive.'];
      }

      # NOTA CHANNEL: password_verify es CPU-heavy (~50-100ms con bcrypt)
      # Bloquea el worker del Channel. Si se usa desde Channel frecuentemente, mover a TaskWorker.
      if (password_verify($credentials['password'], $user['password_hash'])) {
        # Get profile_id and personal entity_id for this account
        $profileData = null;
        CONN::dml(
          "SELECT profile_id, primary_entity_id FROM profiles WHERE account_id = :aid LIMIT 1",
          [':aid' => $user['account_id']],
          function($row) use (&$profileData) { $profileData = $row; return false; }
        );

        $profileId = $profileData ? (int)$profileData['profile_id'] : null;
        $personalScope = $profileData ? (int)$profileData['primary_entity_id'] : null;

        # Generate token with profile_id and personal scope (default at login)
        $deviceHash = $credentials['device_hash'] ?? null;
        $token = $this->generateToken(
          (string)$user['account_id'],
          null,                    # metadata (auto timestamp)
          null,                    # expiresIn (default)
          $profileId,              # profile_id
          $personalScope,          # scope_entity_id (área personal por defecto)
          $deviceHash              # device fingerprint (opcional, xxh128)
        );

        if (!$token) {
          return ['success' => false, 'message' => 'Failed to generate session token.'];
        }

        Log::logInfo('Successful login for account_id: ' . $user['account_id'] . ' with personal scope: ' . $personalScope);
        return ['success' => true, 'message' => 'Login successful.', 'token' => $token];
      } else {
        Log::logWarning('Failed login attempt (wrong password) for account_id: ' . $user['account_id']);
        return ['success' => false, 'message' => 'Invalid credentials.'];
      }

    } catch (Exception $e) {
      Log::logError("Account::login - Exception: " . $e->getMessage());
      return ['success' => false, 'message' => 'An internal server error occurred.'];
    }
  }

    /**
     * Creates a new account in the database.
     * Automatically creates a personal Profile with an empty Entity.
     *
     * @param string $username
     * @param string $plainPassword The plain text password to be hashed.
     * @param bool $isActive Initial status of the account (default true).
     * @param array $otherAccountDetails Associative array for additional fields (reserved for future use).
     * @return array|false Array with account_id, entity_id, and profile_id on success, false on failure.
     */
    public function createAccount(string $username, string $plainPassword, bool $isActive = true, array $otherAccountDetails = []): array|false {
        Log::logDebug("Account::createAccount - Attempting to create account for username: " . $username);

        if (empty(trim($username)) || empty(trim($plainPassword))) {
            Log::logError("Account::createAccount - Username and password cannot be empty.");
            return false;
        }

        $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
        if (!$hashedPassword) {
            Log::logError("Account::createAccount - Failed to hash password.");
            return false;
        }

        $status = $isActive ? 'active' : 'inactive';

        $sql = "INSERT INTO accounts (username, password_hash, is_active, status)
                VALUES (:username, :password_hash, :is_active, :status)";

        $dataToInsert = [
            ':username' => $username,
            ':password_hash' => $hashedPassword,
            ':is_active' => (int)$isActive,
            ':status' => $status
        ];

        try {
            // Check if username already exists
            $checkSql = "SELECT account_id FROM accounts WHERE username = :username LIMIT 1";
            $existingUser = null;

            // Use callback to check existence efficiently
            CONN::dml($checkSql, [':username' => $username], function($row) use (&$existingUser) {
                $existingUser = $row;
                return false; // Stop after first row
            });

            if ($existingUser !== null) {
                Log::logWarning("Account::createAccount - Username '$username' already exists.");
                return false;
            }

            // Start transaction for Account + Entity + Profile creation
            CONN::begin();

            try {
                // 1. Create Account
                $result = CONN::nodml($sql, $dataToInsert);

                if (!$result['success'] || !$result['last_id']) {
                    throw new Exception("Failed to insert account: " . ($result['error'] ?? 'Unknown DB error'));
                }

                $newAccountId = (string)$result['last_id'];
                Log::logInfo("Account::createAccount - Account created: username=$username, account_id=$newAccountId");

                // 2. Create empty Entity for this account
                $entityId = Entity::save([
                    'entity_type' => 'personal',
                    'entity_name' => $username,
                    'comp_id' => 0,
                    'comp_branch_id' => 0
                ]);

                if (!$entityId) {
                    throw new Exception("Failed to create entity for account $newAccountId");
                }

                Log::logInfo("Account::createAccount - Entity created: entity_id=$entityId for account_id=$newAccountId");

                // 3. Create Profile linked to Account and Entity
                $profileId = Profile::save([
                    'account_id' => (int)$newAccountId,
                    'primary_entity_id' => $entityId,
                    'profile_name' => "Personal Profile - $username",
                    'status' => 'active',
                    'actor_profile_id' => null // First account, no actor yet
                ]);

                if (!$profileId) {
                    throw new Exception("Failed to create profile for account $newAccountId");
                }

                Log::logInfo("Account::createAccount - Profile created: profile_id=$profileId, entity_id=$entityId for account_id=$newAccountId");

                // Commit transaction
                CONN::commit();

                Log::logInfo("Account::createAccount - Complete setup: account_id=$newAccountId, entity_id=$entityId, profile_id=$profileId");

                return [
                    'account_id' => $newAccountId,
                    'entity_id' => $entityId,
                    'profile_id' => $profileId
                ];

            } catch (Exception $e) {
                CONN::rollback();
                throw $e; // Re-throw to outer catch
            }

        } catch (Exception $e) {
            Log::logError("Account::createAccount - Exception for username $username: " . $e->getMessage());
            error_log("Account::createAccount EXCEPTION: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            return false;
        }
    }


    /**
     * Generates a JWT token with the specific payload structure: [METADATA, {"id": ACCOUNT_ID, "profile_id": PROFILE_ID, "scope_entity_id": SCOPE_ENTITY_ID}].
     *
     * @param string $accountId The account ID.
     * @param mixed $metadataElement Optional metadata to include in the first element of the payload array (e.g., date("mdhs")).
     * If not provided, a timestamp will be used.
     * @param ?int $expiresIn Seconds until token expiration. Defaults to $this->tokenExpiration.
     * @param ?int $profileId Profile ID (optional)
     * @param ?int $scopeEntityId Scope/tenant ID (optional, for multi-tenant)
     * @return string|false The generated JWT token or false on failure.
     */
    public function generateToken(string $accountId, $metadataElement = null, ?int $expiresIn = null, ?int $profileId = null, ?int $scopeEntityId = null, ?string $deviceHash = null): string|false {
        if (empty(trim($accountId))) {
            Log::logError("Account::generateToken - Account ID cannot be empty.");
            return false;
        }

        $actualMetadata = $metadataElement ?? time(); // Default metadata to current timestamp

        // Construct the specific payload structure: array([METADATA], ["id" => ACCOUNT_ID, "profile_id" => PROFILE_ID, "scope_entity_id" => SCOPE_ENTITY_ID])
        $userPayload = ["id" => $accountId];

        # Include profile_id if provided
        if ($profileId !== null) {
            $userPayload["profile_id"] = $profileId;
        }

        # Include scope_entity_id if provided (multi-tenant)
        if ($scopeEntityId !== null) {
            $userPayload["scope_entity_id"] = $scopeEntityId;
        }

        # Device fingerprint hash (xxh128) — identifica el dispositivo en Channel Server
        if ($deviceHash !== null) {
            $userPayload["device_hash"] = $deviceHash;
        }

        $payloadStructure = [
            $actualMetadata,
            $userPayload
        ];

        // Standard JWT claims like 'exp', 'iat', 'sub' are not part of this custom structure's top level.
        // If expiration needs to be part of the token and verifiable by `verifyToken` using standard means,
        // the payload structure or JWT class would need to accommodate it.
        // For now, adhering strictly to the user's example for setPayload.
        // Expiration can be handled by `verifyToken` if it's part of `metadataElement` or if the JWT class adds it.

        // The `bX\JWT` class should be initialized with the payload for standard claims if they are needed.
        // The user's example `setPayload($payload)` overrides any previous payload.
        // Let's assume standard claims like 'exp' are NOT part of this specific structure unless manually added to $metadataElement or the second array.
        // However, a common practice is to include 'exp'. If your JWT class does not add it automatically based on $expiresIn,
        // you might need to embed it within your custom payload structure, e.g., in $metadataElement.
        // For simplicity here, following user's direct example for payload content.

        try {
            $jwt = new JWT($this->jwtSecret);
            $jwt->setHeader(['alg' => 'HS256', 'typ' => 'JWT']);
            $jwt->setPayload($payloadStructure); // Sets the payload to the custom structure

            // Note: If token expiration is desired *within the JWT standard claims*,
            // the payload needs to be an associative array with an 'exp' key.
            // E.g., $payloadForStdClaims = ['exp' => time() + ($expiresIn ?? $this->tokenExpiration), 'data' => $payloadStructure];
            // $jwt->setPayload($payloadForStdClaims);
            // But sticking to user example for now: $jwt->setPayload($payloadStructure);

            return $jwt->generateJWT();
        } catch (Exception $e) {
            Log::logError("Account::generateToken - Exception: " . $e->getMessage(), ['account_id' => $accountId]);
            return false;
        }
    }

    /**
     * Verifies a JWT token that has the payload structure: [METADATA, {"id": ACCOUNT_ID, "profile_id": PROFILE_ID (optional)}].
     *
     * @param string $token The JWT token string (may include "Bearer " prefix).
     * @return string|false The account_id from payload[1]['id'] if valid, false otherwise.
     */
    public function verifyToken(string $token, $REMOTE_ADDR = ''): string|false {
        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        if (empty($token)) {
            Log::logWarning("Account::verifyToken - Token is empty.");
            return false;
        }

        try {
            $jwt = new JWT($this->jwtSecret, $token);
            // The validateSignature method in your JWT class should verify the signature based on
            // its current header and payload.
            if (!$jwt->validateSignature()) {
                Log::logWarning("Account::verifyToken - Invalid JWT signature.");
                return false;
            }

            $payload = $jwt->getPayload(); // Expecting: [ 0 => "somedate", 1 => ["id" => $accountId] ]
            // Validate the structure and extract account_id
            if (( is_array($payload) && count($payload) >= 2 ) &&
                ( is_array($payload[1]) ) &&
                isset($payload[1]['id']) && !empty(trim((string)$payload[1]['id']))) {

                $accountIdFromToken = (string)$payload[1]['id'];

                // Note: Expiration check. If 'exp' is not a standard top-level claim due to the custom payload,
                // you'd need to have embedded it within your payload structure (e.g., in $payload[0] or $payload[1])
                // and check it manually here. For example:
                // if (isset($payload[0]['exp']) && time() > $payload[0]['exp']) {
                //     Log::logWarning("Account::verifyToken - Token expired based on custom payload data.");
                //     return false;
                // }

                Log::logInfo("Account::verifyToken - Token verified successfully. Account ID: " . $accountIdFromToken);
                return $accountIdFromToken;
            } else {
                Log::logWarning("Account::verifyToken - Payload structure is not as expected or 'id' is missing/empty.", ['payload_received' => $payload]);
                return false;
            }

        } catch (Exception $e) {
            Log::logError("Account::verifyToken - Exception: " . $e->getMessage(), ['token_prefix' => substr($token, 0, 10)]);
            return false;
        }
    }
}