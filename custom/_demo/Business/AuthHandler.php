<?php
# custom/_demo/Business/AuthHandler.php
namespace _demo;

/**
 * Handles authentication-related business logic.
 * This class serves as a controller for authentication endpoints,
 * keeping the endpoint definition file clean and focused on routing.
 */
class AuthHandler
{
  /**
   * Handles a user login attempt.
   * Verifies credentials against the database via bX\Auth and returns a JWT on success.
   *
   * @param array $inputData Associative array expected to contain 'username' and 'password'.
   * @return array The result of the operation, including a JWT on success.
   */
  /**
   * Handles a user login attempt by delegating to the Account service.
   * @param array $inputData Associative array expected to contain 'username' and 'password'.
   * @return array The result of the operation from the Account service.
   */
  public static function login(array $inputData): array
  {
    try {
      // Instantiate the Bintelx Account service
      $jwtSecret = \bX\Config::get('JWT_SECRET');
      $accountService = new \bX\Account($jwtSecret);
      $loginResult = $accountService->login($inputData);

      if ($loginResult['success']) {
        http_response_code(200); // OK
        return [
          'success' => true,
          'message' => 'Login successful.',
          'token' => $loginResult['token']
        ];
      } else {
        http_response_code(401); // Unauthorized
        return ['success' => false, 'message' => $loginResult['message']];
      }
    } catch (\Exception $e) {
      \bX\Log::logError("Login Exception in AuthHandler: " . $e->getMessage());
      http_response_code(500); // Internal Server Error
      return ['success' => false, 'message' => 'An internal error occurred during login.'];
    }
  }

  /**
   * Validates the current user's token.
   * Can validate tokens from:
   * 1. Authorization header or cookie (already validated by api.php)
   * 2. POST body JSON with {"token": "..."} (validated here)
   *
   * @return array Simple validation result: {"success": true|false}
   */
  public static function validateToken(): array
  {
    // Check if user is already authenticated via header/cookie (handled by api.php)
    if (\bX\Profile::isLoggedIn()) {
      http_response_code(200); // OK
      return ['success' => true];
    }

    // If not authenticated via header/cookie, check for token in POST body
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty(\bX\Args::$OPT['token'])) {
      $token = \bX\Args::$OPT['token'];

      try {
        // Get JWT configuration from environment
        $jwtSecret = \bX\Config::get('JWT_SECRET');
        $jwtXorKey = \bX\Config::get('JWT_XOR_KEY');

        $account = new \bX\Account($jwtSecret, $jwtXorKey);
        $account_id = $account->verifyToken($token, $_SERVER["REMOTE_ADDR"] ?? '');

        if ($account_id) {
          // Token is valid
          http_response_code(200); // OK
          return ['success' => true];
        } else {
          // Token verification failed
          http_response_code(401); // Unauthorized
          return ['success' => false];
        }
      } catch (\Exception $e) {
        \bX\Log::logError("Token validation exception: " . $e->getMessage());
        http_response_code(401); // Unauthorized
        return ['success' => false];
      }
    }

    // No valid authentication found
    http_response_code(401); // Unauthorized
    return ['success' => false];
  }

  /**
   * Registers a new account with automatic profile and entity creation
   * Creates a complete account setup: credentials + personal entity + profile
   *
   * @param array $inputData Expected: ['username' => string, 'password' => string]
   * @return array
   */
  public static function register(array $inputData): array
  {
    try {
      // Validate input
      if (empty($inputData['username']) || empty($inputData['password'])) {
        http_response_code(400); // Bad Request
        return [
          'success' => false,
          'message' => 'Username and password are required.'
        ];
      }

      // Create account using Account service (now includes entity + profile)
      $jwtSecret = \bX\Config::get('JWT_SECRET');
      $accountService = new \bX\Account($jwtSecret);
      $accountData = $accountService->createAccount(
        $inputData['username'],
        $inputData['password'],
        true  // is_active
      );

      if ($accountData === false) {
        http_response_code(400); // Bad Request
        return [
          'success' => false,
          'message' => 'Failed to create account. Username may already exist.'
        ];
      }

      \bX\Log::logInfo("Account registered successfully: account_id={$accountData['account_id']}, username={$inputData['username']}, profile_id={$accountData['profile_id']}");

      http_response_code(201); // Created
      return [
        'success' => true,
        'message' => 'Account created successfully with profile and entity.',
        'data' => [
          'accountId' => (int)$accountData['account_id'],
          'profileId' => (int)$accountData['profile_id'],
          'entityId' => (int)$accountData['entity_id'],
          'username' => $inputData['username']
        ]
      ];

    } catch (\Exception $e) {
      \bX\Log::logError("Registration Exception in AuthHandler: " . $e->getMessage());
      http_response_code(500); // Internal Server Error
      return [
        'success' => false,
        'message' => 'An internal error occurred during registration.'
      ];
    }
  }

  /**
   * Creates or updates a profile for an account with entity
   * - If profileId and entityId are provided: updates existing profile/entity
   * - If not provided: creates new profile and entity (multiple "hats" for different contexts)
   *
   * @param array $inputData Expected:
   *   - accountId: int (required)
   *   - profileId: int (optional - for updating existing profile)
   *   - entityId: int (optional - for updating existing entity)
   *   - entityType: string (default: 'person')
   *   - entityName: string (required)
   *   - nationalId: string (optional - RUT, DNI, etc.)
   *   - nationalIsocode: string (default: 'CL')
   *   - profileName: string (optional)
   * @return array
   */
  public static function createProfile(array $inputData): array
  {
    try {
      // Validate required fields
      if (empty($inputData['accountId'])) {
        http_response_code(400);
        return ['success' => false, 'message' => 'accountId is required.'];
      }

      if (empty($inputData['entityName'])) {
        http_response_code(400);
        return ['success' => false, 'message' => 'entityName is required.'];
      }

      $accountId = (int)$inputData['accountId'];
      $profileId = isset($inputData['profileId']) ? (int)$inputData['profileId'] : null;
      $entityId = isset($inputData['entityId']) ? (int)$inputData['entityId'] : null;
      $entityType = $inputData['entityType'] ?? 'person';
      $entityName = $inputData['entityName'];
      $nationalId = $inputData['nationalId'] ?? null;
      $nationalIsocode = $inputData['nationalIsocode'] ?? 'CL';
      $isUpdate = ($profileId && $entityId);

      // Check if account exists
      $accountExists = \bX\CONN::dml(
        "SELECT account_id FROM accounts WHERE account_id = :id",
        [':id' => $accountId]
      );

      if (empty($accountExists)) {
        http_response_code(404);
        return ['success' => false, 'message' => 'Account not found.'];
      }

      // Note: Accounts can have multiple profiles (multiple "hats")
      // No need to check for existing profiles - this is intentional

      \bX\CONN::begin();

      if ($isUpdate) {
        // UPDATE MODE: Update existing entity and profile
        \bX\Log::logInfo("Updating existing profile: profile_id=$profileId, entity_id=$entityId");

        // 1. Update entity
        $entitySql = "UPDATE entities
                      SET entity_type = :type,
                          primary_name = :name,
                          national_id = :nid,
                          national_isocode = :iso,
                          updated_at = NOW()
                      WHERE entity_id = :entity_id";

        $entityResult = \bX\CONN::nodml($entitySql, [
          ':entity_id' => $entityId,
          ':type' => $entityType,
          ':name' => $entityName,
          ':nid' => $nationalId,
          ':iso' => $nationalIsocode
        ]);

        if (!$entityResult['success']) {
          \bX\CONN::rollback();
          http_response_code(500);
          return ['success' => false, 'message' => 'Failed to update entity.'];
        }

        // 2. Update profile
        $profileName = $inputData['profileName'] ?? "Profile for $entityName";

        $profileSql = "UPDATE profiles
                       SET profile_name = :profile_name,
                           updated_at = NOW()
                       WHERE profile_id = :profile_id";

        $profileResult = \bX\CONN::nodml($profileSql, [
          ':profile_id' => $profileId,
          ':profile_name' => $profileName
        ]);

        if (!$profileResult['success']) {
          \bX\CONN::rollback();
          http_response_code(500);
          return ['success' => false, 'message' => 'Failed to update profile.'];
        }

        \bX\CONN::commit();

        \bX\Log::logInfo("Profile updated successfully: profile_id=$profileId, account_id=$accountId, entity_id=$entityId");

        http_response_code(200); // OK
        return [
          'success' => true,
          'message' => 'Profile and entity updated successfully.',
          'data' => [
            'profileId' => $profileId,
            'accountId' => $accountId,
            'primaryEntityId' => $entityId,
            'entityType' => $entityType,
            'entityName' => $entityName
          ]
        ];

      } else {
        // CREATE MODE: Create new entity and profile
        \bX\Log::logInfo("Creating new profile and entity for account_id=$accountId");

        // 1. Create entity first
        $entitySql = "INSERT INTO entities (entity_type, primary_name, national_id, national_isocode, status)
                      VALUES (:type, :name, :nid, :iso, 'active')";

        $entityResult = \bX\CONN::nodml($entitySql, [
          ':type' => $entityType,
          ':name' => $entityName,
          ':nid' => $nationalId,
          ':iso' => $nationalIsocode
        ]);

        if (!$entityResult['success']) {
          \bX\CONN::rollback();
          http_response_code(500);
          return ['success' => false, 'message' => 'Failed to create entity.'];
        }

        $entityId = (int)$entityResult['last_id'];

        // 2. Create profile linked to entity
        $profileName = $inputData['profileName'] ?? "Profile for $entityName";

        $profileSql = "INSERT INTO profiles
                       (account_id, primary_entity_id, profile_name, status)
                       VALUES (:account_id, :entity_id, :profile_name, 'active')";

        $profileResult = \bX\CONN::nodml($profileSql, [
          ':account_id' => $accountId,
          ':entity_id' => $entityId,
          ':profile_name' => $profileName
        ]);

        if (!$profileResult['success']) {
          \bX\CONN::rollback();
          http_response_code(500);
          return ['success' => false, 'message' => 'Failed to create profile.'];
        }

        $profileId = (int)$profileResult['last_id'];

        \bX\CONN::commit();

        \bX\Log::logInfo("Profile created successfully: profile_id=$profileId, account_id=$accountId, entity_id=$entityId");

        http_response_code(201); // Created
        return [
          'success' => true,
          'message' => 'Profile and entity created successfully.',
          'data' => [
            'profileId' => $profileId,
            'accountId' => $accountId,
            'primaryEntityId' => $entityId,
            'entityType' => $entityType,
            'entityName' => $entityName
          ]
        ];
      }

    } catch (\Exception $e) {
      if (\bX\CONN::isInTransaction()) {
        \bX\CONN::rollback();
      }
      \bX\Log::logError("Create Profile Exception in AuthHandler: " . $e->getMessage());
      http_response_code(500);
      return ['success' => false, 'message' => 'An internal error occurred.'];
    }
  }

  /**
   * Provides a generic public report or status.
   * This is an example of a public, non-authenticated endpoint.
   *
   * @return array A public status report.
   */
  public static function DevToolsReport(): array
  {
    http_response_code(200); // OK
    return [
      'success' => true,
      'message' => 'Public report fetched successfully.',
      'data' => [
        'serviceStatus' => 'OK',
        'serverTime' => date('c')
      ]
    ];
  }
}