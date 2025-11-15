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
      $accountService = new \bX\Account("woz.min.."); // Provide your JWT secret
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
   * Validates the current user's token and returns their profile information.
   * This endpoint is private, so the framework (api.php) has already
   * verified the token and loaded the user's profile before this method is called.
   *
   * @return array An array containing the loaded profile data.
   */
  public static function validateToken(): array
  {
    if (\bX\Profile::isLoggedIn()) {
      http_response_code(200); // OK
      return [
        'success' => true,
        'message' => 'Token is valid.',
        'data' => [
          'accountId' => \bX\Profile::$account_id,
          'profileId' => \bX\Profile::$profile_id,
          'primaryEntityId' => \bX\Profile::$entity_id,
          'permissions' => \bX\Profile::$userPermissions ?? []
        ]
      ];
    } else {
      // This case should technically not be reachable if the router scope is working correctly.
      http_response_code(401); // Unauthorized
      return ['success' => false, 'message' => 'No valid session found.'];
    }
  }

  /**
   * Registers a new account (without profile or entity)
   * An account is just credentials - profile and entity are created separately
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

      // Create account using Account service
      $accountService = new \bX\Account("woz.min..");
      $accountId = $accountService->createAccount(
        $inputData['username'],
        $inputData['password'],
        true  // is_active
      );

      if ($accountId === false) {
        http_response_code(400); // Bad Request
        return [
          'success' => false,
          'message' => 'Failed to create account. Username may already exist.'
        ];
      }

      \bX\Log::logInfo("Account registered successfully: account_id=$accountId, username={$inputData['username']}");

      http_response_code(201); // Created
      return [
        'success' => true,
        'message' => 'Account created successfully.',
        'data' => [
          'accountId' => (int)$accountId,
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
   * Creates a profile for an account and automatically creates the first entity
   *
   * @param array $inputData Expected:
   *   - accountId: int (required)
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
      $entityType = $inputData['entityType'] ?? 'person';
      $entityName = $inputData['entityName'];
      $nationalId = $inputData['nationalId'] ?? null;
      $nationalIsocode = $inputData['nationalIsocode'] ?? 'CL';

      // Check if account exists
      $accountExists = \bX\CONN::dml(
        "SELECT account_id FROM accounts WHERE account_id = :id",
        [':id' => $accountId]
      );

      if (empty($accountExists)) {
        http_response_code(404);
        return ['success' => false, 'message' => 'Account not found.'];
      }

      // Check if profile already exists for this account
      $existingProfile = \bX\CONN::dml(
        "SELECT profile_id FROM profiles WHERE account_id = :id",
        [':id' => $accountId]
      );

      if (!empty($existingProfile)) {
        http_response_code(400);
        return [
          'success' => false,
          'message' => 'Profile already exists for this account.',
          'data' => ['profileId' => $existingProfile[0]['profile_id']]
        ];
      }

      \bX\CONN::begin();

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