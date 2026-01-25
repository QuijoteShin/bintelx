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
      $jwtSecret = \bX\Config::get('JWT_SECRET');
      $jwtXorKey = \bX\Config::get('JWT_XOR_KEY', '');
      $accountService = new \bX\Account($jwtSecret, $jwtXorKey);
      $loginResult = $accountService->login($inputData);

      if (!$loginResult['success']) {
        return ['success' => false, 'message' => $loginResult['message'] ?? 'Invalid credentials.'];
      }

      $token = $loginResult['token'] ?? null;
      if (!$token) {
        return ['success' => false, 'message' => 'Token generation failed.'];
      }

      $payload = \bX\JWT::decode($token);
      $accountId = (int)($payload[1]['id'] ?? 0);

      # Load profile and regenerate token with profile_id + scope_entity_id
      $profileId = null;
      $entityId = null;
      $scopeEntityId = null;

      if ($accountId) {
        $profile = new \bX\Profile();
        if ($profile->load(['account_id' => $accountId])) {
          $profileId = \bX\Profile::$profile_id;
          $entityId = \bX\Profile::$entity_id;

          # Determine scope_entity_id
          $allowedScopes = \bX\Profile::getAllowedScopes();

          if ($allowedScopes === ['*']) {
            # Admin: use entity as scope
            $scopeEntityId = $entityId;
          } elseif (!empty($allowedScopes)) {
            # Use first allowed scope
            $scopeEntityId = $allowedScopes[0];
          } else {
            # No scopes in ACL: use own entity as scope (single-user company)
            $scopeEntityId = $entityId;
          }

          # Regenerate token with complete payload
          $token = $accountService->generateToken(
            (string)$accountId,
            null,
            null,
            $profileId,
            $scopeEntityId
          );
        }
      }

      return [
        'success' => true,
        'message' => 'Login successful.',
        'token' => $token,
        'data' => [
          'accountId' => $accountId,
          'profileId' => $profileId,
          'entityId' => $entityId,
          'scopeEntityId' => $scopeEntityId
        ]
      ];
    } catch (\Exception $e) {
      \bX\Log::logError("Login Exception in AuthHandler: " . $e->getMessage());
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
      return self::buildValidationPayload('Token is valid.');
    }

    // If not authenticated via header/cookie, check for token in POST body
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty(\bX\Args::$OPT['token'])) {
      $token = \bX\Args::$OPT['token'];

      try {
        $ping = \bX\CONN::dml('SELECT 1 AS ok');
        if (empty($ping)) {
          \bX\Log::logWarning("ValidateToken: DB ping failed in POST branch");
        }
        $dbPingOk = !empty($ping[0]['ok']);
        // Get JWT configuration from environment
        $jwtSecret = \bX\Config::get('JWT_SECRET');
        $jwtXorKey = \bX\Config::get('JWT_XOR_KEY');

        $account = new \bX\Account($jwtSecret, $jwtXorKey);
        $account_id = $account->verifyToken($token, $_SERVER["REMOTE_ADDR"] ?? '');

        if ($account_id) {
          $profile = new \bX\Profile();
          if ($profile->load(['account_id' => $account_id])) {
            $response = self::buildValidationPayload('Token is valid.');
            $response['data']['dbPing'] = $dbPingOk;
            return $response;
          }
          return ['success' => false, 'message' => 'Profile not found for token.'];
        } else {
          // Token verification failed
          
          return ['success' => false, 'message' => 'Invalid or expired token.'];
        }
      } catch (\Exception $e) {
        \bX\Log::logError("Token validation exception: " . $e->getMessage());
        
        return ['success' => false, 'message' => 'Token validation failed.'];
      }
    }

    // No valid authentication found
    
    return ['success' => false, 'message' => 'Authentication required.'];
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
        
        return [
          'success' => false,
          'message' => 'Failed to create account. Username may already exist.'
        ];
      }

      \bX\Log::logInfo("Account registered successfully: account_id={$accountData['account_id']}, username={$inputData['username']}, profile_id={$accountData['profile_id']}");

      
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
   * Create entity_relationship (profile → entity) to grant scope/role.
   * Delegates to kernel Entity\Graph class.
   *
   * Expected:
   *   - profileId (int)
   *   - entityId (int)
   *   - relationKind (string, default 'owner')
   *   - roleCode (string|null)
   */
  public static function createRelationship(array $inputData): array
  {
    $profileId = (int)($inputData['profileId'] ?? 0);
    $entityId = (int)($inputData['entityId'] ?? 0);
    $kind = $inputData['relationKind'] ?? 'owner';
    $roleCode = $inputData['roleCode'] ?? null;

    if ($profileId <= 0 || $entityId <= 0) {
      http_response_code(400);
      return ['success' => false, 'message' => 'profileId y entityId son requeridos'];
    }

    # Delegate to kernel Entity\Graph
    $result = \bX\Entity\Graph::create([
      'profile_id' => $profileId,
      'entity_id' => $entityId,
      'relation_kind' => $kind,
      'role_code' => $roleCode
    ]);

    if (!$result['success']) {
      http_response_code(500);
      return ['success' => false, 'message' => 'No se pudo crear la relación'];
    }

    return [
      'success' => true,
      'relationship_id' => $result['relationship_id'],
      'profile_id' => $profileId,
      'entity_id' => $entityId,
      'relation_kind' => $kind,
      'role_code' => $roleCode
    ];
  }

  /**
   * Registers a company account with 2 entities: person (user) + organization (company)
   * Creates: Account → Profile → Entity(person) + Entity(organization) + Relationship
   *
   * @param array $inputData Expected:
   *   - username: string (required)
   *   - password: string (required)
   *   - personName: string (required) - Nombre del usuario/dueño
   *   - companyName: string (required) - Nombre de la empresa
   *   - personNationalId: string (optional) - RUT/DNI del usuario
   *   - companyNationalId: string (optional) - RUT de la empresa
   *   - nationalIsocode: string (default: 'CL')
   * @return array
   */
  public static function registerCompany(array $inputData): array
  {
    try {
      # Validar campos requeridos
      if (empty($inputData['username']) || empty($inputData['password'])) {
        return ['success' => false, 'message' => 'Username and password are required.'];
      }
      if (empty($inputData['personName'])) {
        return ['success' => false, 'message' => 'personName is required.'];
      }
      if (empty($inputData['companyName'])) {
        return ['success' => false, 'message' => 'companyName is required.'];
      }

      $nationalIsocode = $inputData['nationalIsocode'] ?? 'CL';

      \bX\CONN::begin();

      # 1. Crear Account
      $jwtSecret = \bX\Config::get('JWT_SECRET');
      $accountService = new \bX\Account($jwtSecret);

      # Verificar si username ya existe
      $existing = \bX\CONN::dml(
        "SELECT account_id FROM accounts WHERE username = :u LIMIT 1",
        [':u' => $inputData['username']]
      );
      if (!empty($existing)) {
        \bX\CONN::rollback();
        return ['success' => false, 'message' => 'Username already exists.'];
      }

      # Crear account (sin profile/entity automático - lo hacemos manual)
      $hashedPassword = password_hash($inputData['password'], PASSWORD_DEFAULT);
      $accountResult = \bX\CONN::nodml(
        "INSERT INTO accounts (username, password_hash, is_active, status) VALUES (:u, :p, 1, 'active')",
        [':u' => $inputData['username'], ':p' => $hashedPassword]
      );

      if (!$accountResult['success']) {
        \bX\CONN::rollback();
        return ['success' => false, 'message' => 'Failed to create account.'];
      }

      $accountId = (int)$accountResult['last_id'];

      # 2. Crear Entity "person" (el usuario/dueño)
      $personEntityId = \bX\Entity::save([
        'entity_type' => 'person',
        'primary_name' => $inputData['personName'],
        'national_id' => $inputData['personNationalId'] ?? null,
        'national_isocode' => $nationalIsocode,
        'status' => 'active'
      ]);

      if (!$personEntityId) {
        \bX\CONN::rollback();
        return ['success' => false, 'message' => 'Failed to create person entity.'];
      }

      # 3. Crear Entity "organization" (la empresa)
      $companyEntityId = \bX\Entity::save([
        'entity_type' => 'organization',
        'primary_name' => $inputData['companyName'],
        'national_id' => $inputData['companyNationalId'] ?? null,
        'national_isocode' => $nationalIsocode,
        'status' => 'active'
      ]);

      if (!$companyEntityId) {
        \bX\CONN::rollback();
        return ['success' => false, 'message' => 'Failed to create company entity.'];
      }

      # 4. Crear Profile vinculado a Account + Person Entity
      $profileResult = \bX\CONN::nodml(
        "INSERT INTO profiles (account_id, primary_entity_id, profile_name, status)
         VALUES (:account_id, :entity_id, :profile_name, 'active')",
        [
          ':account_id' => $accountId,
          ':entity_id' => $personEntityId,
          ':profile_name' => "Profile - " . $inputData['personName']
        ]
      );

      if (!$profileResult['success']) {
        \bX\CONN::rollback();
        return ['success' => false, 'message' => 'Failed to create profile.'];
      }

      $profileId = (int)$profileResult['last_id'];

      # 5. Crear Relationship: Profile → Company (owner)
      # Pasamos scope_entity_id = companyEntityId porque es el tenant del nuevo usuario
      $relResult = \bX\Entity\Graph::create([
        'profile_id' => $profileId,
        'entity_id' => $companyEntityId,
        'relation_kind' => 'owner',
        'relationship_label' => $inputData['companyName']
      ], ['scope_entity_id' => $companyEntityId]);

      if (!$relResult['success']) {
        \bX\CONN::rollback();
        return ['success' => false, 'message' => 'Failed to create relationship.'];
      }

      \bX\CONN::commit();

      # 6. Generar token con scope de la empresa
      $token = $accountService->generateToken(
        (string)$accountId,
        null,
        null,
        $profileId,
        $companyEntityId # scope_entity_id = la empresa
      );

      \bX\Log::logInfo("Company registered: account_id=$accountId, person_entity=$personEntityId, company_entity=$companyEntityId, profile=$profileId");

      return [
        'success' => true,
        'message' => 'Company account created successfully.',
        'token' => $token,
        'data' => [
          'accountId' => $accountId,
          'profileId' => $profileId,
          'personEntityId' => $personEntityId,
          'companyEntityId' => $companyEntityId,
          'scopeEntityId' => $companyEntityId,
          'username' => $inputData['username']
        ]
      ];

    } catch (\Exception $e) {
      if (\bX\CONN::isInTransaction()) {
        \bX\CONN::rollback();
      }
      \bX\Log::logError("RegisterCompany Exception: " . $e->getMessage());
      return ['success' => false, 'message' => 'An internal error occurred during registration.'];
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
    
    return [
      'success' => true,
      'message' => 'Public report fetched successfully.',
      'data' => [
        'serviceStatus' => 'OK',
        'serverTime' => date('c')
      ]
    ];
  }

  /**
   * Builds a standardized response payload for token validation.
   */
  private static function buildValidationPayload(string $message): array
  {
    return [
      'success' => true,
      'message' => $message,
      'data' => [
        'accountId' => \bX\Profile::$account_id,
        'profileId' => \bX\Profile::$profile_id,
        'primaryEntityId' => \bX\Profile::$entity_id,
        'permissions' => \bX\Profile::$userPermissions
      ]
    ];
  }
}
