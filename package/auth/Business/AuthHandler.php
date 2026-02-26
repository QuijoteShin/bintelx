<?php
# package/auth/Business/AuthHandler.php
namespace auth;

/**
 * Handles authentication-related business logic.
 * This class serves as a controller for authentication endpoints,
 * keeping the endpoint definition file clean and focused on routing.
 */
class AuthHandler
{
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
          $profileId = \bX\Profile::ctx()->profileId;
          $entityId = \bX\Profile::ctx()->entityId;

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

          # Regenerate token with complete payload (preservar device_hash del primer token)
          $deviceHash = $payload[1]['device_hash'] ?? ($inputData['device_hash'] ?? null);
          $token = $accountService->generateToken(
            (string)$accountId,
            null,
            null,
            $profileId,
            $scopeEntityId,
            $deviceHash
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
   */
  public static function validateToken(): array
  {
    # Check if user is already authenticated via header/cookie (handled by api.php)
    if (\bX\Profile::isLoggedIn()) {
      return self::buildValidationPayload('Token is valid.');
    }

    # If not authenticated via header/cookie, check for token in POST body
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty(\bX\Args::ctx()->opt['token'])) {
      $token = \bX\Args::ctx()->opt['token'];

      try {
        $ping = \bX\CONN::dml('SELECT 1 AS ok');
        if (empty($ping)) {
          \bX\Log::logWarning("ValidateToken: DB ping failed in POST branch");
        }
        $dbPingOk = !empty($ping[0]['ok']);
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
          return ['success' => false, 'message' => 'Invalid or expired token.'];
        }
      } catch (\Exception $e) {
        \bX\Log::logError("Token validation exception: " . $e->getMessage());
        return ['success' => false, 'message' => 'Token validation failed.'];
      }
    }

    return ['success' => false, 'message' => 'Authentication required.'];
  }

  /**
   * Registers a new account with automatic profile and entity creation.
   * Delegates to Account service which internally uses Entity::save().
   */
  public static function register(array $inputData): array
  {
    try {
      if (empty($inputData['username']) || empty($inputData['password'])) {
        return ['success' => false, 'message' => 'Username and password are required.'];
      }

      $jwtSecret = \bX\Config::get('JWT_SECRET');
      $accountService = new \bX\Account($jwtSecret);
      $accountData = $accountService->createAccount(
        $inputData['username'],
        $inputData['password'],
        true,
        [
            'country_code' => $inputData['country_code'] ?? null,
            'national_id' => $inputData['national_id'] ?? null,
            'national_id_type' => $inputData['national_id_type'] ?? null,
        ]
      );

      if ($accountData === false) {
        return ['success' => false, 'message' => 'Failed to create account. Username may already exist.'];
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
      return ['success' => false, 'message' => 'An internal error occurred during registration.'];
    }
  }

  /**
   * Creates or updates a profile for an account with entity.
   * Uses Entity::save() (identity_hash, checksum, EAV) and Profile::save().
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
   */
  public static function createProfile(array $inputData): array
  {
    try {
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

      # Verificar que la account existe
      $accountExists = \bX\CONN::dml(
        "SELECT account_id FROM accounts WHERE account_id = :id",
        [':id' => $accountId]
      );

      if (empty($accountExists)) {
        http_response_code(404);
        return ['success' => false, 'message' => 'Account not found.'];
      }

      # Accounts can have multiple profiles (multiple "hats") — no check for existing profiles

      return \bX\CONN::transaction(function () use ($isUpdate, $accountId, $profileId, $entityId, $entityType, $entityName, $nationalId, $nationalIsocode, $inputData) {

        if ($isUpdate) {
          # UPDATE MODE: usa Entity::save() con entity_id → recalcula identity_hash
          \bX\Log::logInfo("Updating existing profile: profile_id=$profileId, entity_id=$entityId");

          \bX\Entity::save([
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'primary_name' => $entityName,
            'national_id' => $nationalId,
            'national_isocode' => $nationalIsocode
          ]);

          $profileName = $inputData['profileName'] ?? "Profile for $entityName";
          \bX\Profile::save([
            'profile_id' => $profileId,
            'profile_name' => $profileName
          ]);

          \bX\Log::logInfo("Profile updated successfully: profile_id=$profileId, account_id=$accountId, entity_id=$entityId");

          return [
            'success' => true,
            'message' => 'Profile and entity updated successfully.',
            'data' => [
              'profileId' => $profileId, 'accountId' => $accountId, 'primaryEntityId' => $entityId,
              'entityType' => $entityType, 'entityName' => $entityName
            ]
          ];

        } else {
          # CREATE MODE: Entity::save() genera identity_hash + checksum + EAV automáticamente
          \bX\Log::logInfo("Creating new profile and entity for account_id=$accountId");

          $entityId = \bX\Entity::save([
            'entity_type' => $entityType,
            'primary_name' => $entityName,
            'national_id' => $nationalId,
            'national_isocode' => $nationalIsocode,
            'status' => 'active'
          ]);

          $profileName = $inputData['profileName'] ?? "Profile for $entityName";
          $profileId = \bX\Profile::save([
            'account_id' => $accountId,
            'primary_entity_id' => $entityId,
            'profile_name' => $profileName,
            'status' => 'active',
            'actor_profile_id' => null # bootstrap — no actor yet
          ]);

          \bX\Log::logInfo("Profile created successfully: profile_id=$profileId, account_id=$accountId, entity_id=$entityId");

          return [
            'success' => true,
            'message' => 'Profile and entity created successfully.',
            'data' => [
              'profileId' => $profileId, 'accountId' => $accountId, 'primaryEntityId' => $entityId,
              'entityType' => $entityType, 'entityName' => $entityName
            ]
          ];
        }
      });

    } catch (\RuntimeException $e) {
      # CONN::transaction() ya hizo rollback, solo reportar
      \bX\Log::logError("Create Profile error: " . $e->getMessage());
      http_response_code(500);
      return ['success' => false, 'message' => $e->getMessage()];
    } catch (\Exception $e) {
      # Error inesperado — CONN::transaction() ya hizo rollback
      \bX\Log::logError("Create Profile Exception in AuthHandler: " . $e->getMessage());
      http_response_code(500);
      return ['success' => false, 'message' => 'An internal error occurred.'];
    }
  }

  /**
   * Create entity_relationship (profile → entity) to grant scope/role.
   * Delegates to kernel Entity\Graph class.
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
   * Registers a company account with 2 entities: person (user) + organization (company).
   * Uses Entity::save() for both, Graph::create() for relationship, Profile::save() for profile.
   */
  public static function registerCompany(array $inputData): array
  {
    try {
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

      # Verificar si username ya existe (antes de transacción — es solo lectura)
      $existing = \bX\CONN::dml(
        "SELECT account_id FROM accounts WHERE username = :u LIMIT 1",
        [':u' => $inputData['username']]
      );
      if (!empty($existing)) {
        return ['success' => false, 'message' => 'Username already exists.'];
      }

      $jwtSecret = \bX\Config::get('JWT_SECRET');
      $accountService = new \bX\Account($jwtSecret);

      $txResult = \bX\CONN::transaction(function () use ($inputData, $nationalIsocode) {

        # 1. Crear Account
        $hashedPassword = password_hash($inputData['password'], PASSWORD_DEFAULT);
        $accountResult = \bX\CONN::nodml(
          "INSERT INTO accounts (username, password_hash, is_active, status) VALUES (:u, :p, 1, 'active')",
          [':u' => $inputData['username'], ':p' => $hashedPassword]
        );
        if (!$accountResult['success']) throw new \RuntimeException('Failed to create account.');
        $accountId = (int)$accountResult['last_id'];

        # 2. Crear Entity "person" (el usuario/dueño)
        $personEntityId = \bX\Entity::save([
          'entity_type' => 'person',
          'primary_name' => $inputData['personName'],
          'national_id' => $inputData['personNationalId'] ?? null,
          'national_isocode' => $nationalIsocode,
          'status' => 'active'
        ]);
        if (!$personEntityId) throw new \RuntimeException('Failed to create person entity.');

        # 3. Crear Entity "organization" (la empresa)
        $companyEntityId = \bX\Entity::save([
          'entity_type' => 'organization',
          'primary_name' => $inputData['companyName'],
          'national_id' => $inputData['companyNationalId'] ?? null,
          'national_isocode' => $nationalIsocode,
          'status' => 'active'
        ]);
        if (!$companyEntityId) throw new \RuntimeException('Failed to create company entity.');

        # 4. Crear Profile vinculado a Account + Person Entity
        $profileId = \bX\Profile::save([
          'account_id' => $accountId,
          'primary_entity_id' => $personEntityId,
          'profile_name' => "Profile - " . $inputData['personName'],
          'status' => 'active',
          'actor_profile_id' => null # bootstrap — no actor yet
        ]);
        if (!$profileId) throw new \RuntimeException('Failed to create profile.');

        # 5. Crear Relationship: Profile → Company (owner)
        $relResult = \bX\Entity\Graph::create([
          'profile_id' => $profileId,
          'entity_id' => $companyEntityId,
          'relation_kind' => 'owner',
          'relationship_label' => $inputData['companyName']
        ], ['scope_entity_id' => $companyEntityId]);
        if (!$relResult['success']) throw new \RuntimeException('Failed to create relationship.');

        return [
          'accountId' => $accountId,
          'profileId' => $profileId,
          'personEntityId' => $personEntityId,
          'companyEntityId' => $companyEntityId
        ];
      });

      # 6. Generar token con scope de la empresa (fuera de transacción — no es DB write)
      $token = $accountService->generateToken(
        (string)$txResult['accountId'],
        null,
        null,
        $txResult['profileId'],
        $txResult['companyEntityId'],
        $inputData['device_hash'] ?? null
      );

      \bX\Log::logInfo("Company registered: account_id={$txResult['accountId']}, person_entity={$txResult['personEntityId']}, company_entity={$txResult['companyEntityId']}, profile={$txResult['profileId']}");

      return [
        'success' => true,
        'message' => 'Company account created successfully.',
        'token' => $token,
        'data' => [
          'accountId' => $txResult['accountId'],
          'profileId' => $txResult['profileId'],
          'personEntityId' => $txResult['personEntityId'],
          'companyEntityId' => $txResult['companyEntityId'],
          'scopeEntityId' => $txResult['companyEntityId'],
          'username' => $inputData['username']
        ]
      ];

    } catch (\RuntimeException $e) {
      # CONN::transaction() ya hizo rollback
      \bX\Log::logError("RegisterCompany error: " . $e->getMessage());
      return ['success' => false, 'message' => $e->getMessage()];
    } catch (\Exception $e) {
      # Error inesperado — CONN::transaction() ya hizo rollback
      \bX\Log::logError("RegisterCompany Exception: " . $e->getMessage());
      return ['success' => false, 'message' => 'An internal error occurred during registration.'];
    }
  }

  /**
   * Provides a generic public report or status.
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
        'accountId' => \bX\Profile::ctx()->accountId,
        'profileId' => \bX\Profile::ctx()->profileId,
        'primaryEntityId' => \bX\Profile::ctx()->entityId,
        'permissions' => \bX\Profile::ctx()->permissions
      ]
    ];
  }
}
