<?php # custom/profile/profile.endpoint.php
use bX\Router;
use bX\Response;
use bX\Profile;
use bX\CONN;
use bX\Log;
use bX\Args;
use bX\DataCaptureService;

/**
 * Helper to ensure the user is authenticated.
 */
function profile_require_auth(): ?Response {
  if (!Profile::isLoggedIn()) {
    return Response::json(['success' => false, 'message' => 'Authentication required.'], 401);
  }
  return null;
}

/**
 * Fetch current profile data (account + profile + entity)
 */
Router::register(['GET'], '', function() {
  if ($authError = profile_require_auth()) {
    return $authError;
  }

  $accountId = Profile::ctx()->accountId;
  $profileId = Profile::ctx()->profileId;
  $entityId = Profile::ctx()->entityId;

  $row = null;
  $sql = "SELECT
            a.account_id,
            a.username,
            a.email AS account_email,
            a.last_login,
            a.created_at,
            a.status AS account_status,
            p.profile_id,
            p.profile_name,
            p.primary_entity_id,
            e.primary_name
          FROM accounts a
          JOIN profiles p ON p.account_id = a.account_id
          LEFT JOIN entities e ON e.entity_id = p.primary_entity_id
          WHERE a.account_id = :account_id
          LIMIT 1";

  CONN::dml($sql, [':account_id' => $accountId], function($r) use (&$row) {
    $row = $r;
    return false;
  });

  if (!$row) {
    Log::logWarning("Profile GET: No profile for account_id={$accountId}");
    return Response::json(['success' => false, 'message' => 'Perfil no encontrado'], 404);
  }

  $fullName = trim((string)($row['primary_name'] ?? ''));
  $parts = array_values(array_filter(preg_split('/\s+/', $fullName)));
  $firstName = $parts[0] ?? '';
  $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
  $displayName = $row['profile_name'] ?: ($fullName ?: $row['username']);

  $roles = Profile::ctx()->roles['assignments'] ?? [];
  $roleCode = !empty($roles) ? ($roles[0]['role_code'] ?? 'user') : 'user';

  $profileEmail = null;
  $userRoles = [];
  if ($entityId > 0) {
    $hot = DataCaptureService::getHotData($entityId, ['profile.contact_email']);
    if (!empty($hot['success']) && !empty($hot['data']['profile.contact_email']['value'])) {
      $profileEmail = $hot['data']['profile.contact_email']['value'];
    }
  }
  if (!empty(Profile::ctx()->roles['assignments'])) {
    foreach (Profile::ctx()->roles['assignments'] as $assign) {
      if (!empty($assign['roleCode'])) {
        $userRoles[] = $assign['roleCode'];
      }
    }
    $userRoles = array_values(array_unique($userRoles));
  }

  $data = [
    'success' => true,
    'profile_id' => (int)$profileId,
    'account_id' => (int)$accountId,
    'entity_id' => (int)$entityId,
    'first_name' => $firstName,
    'last_name' => $lastName,
    'display_name' => $displayName,
    'email' => $profileEmail ?? $row['account_email'] ?? $row['username'],
    'account_email' => $row['account_email'] ?? $row['username'],
    'profile_email' => $profileEmail ?? $row['account_email'] ?? $row['username'],
    'role' => $roleCode,
    'last_login' => $row['last_login'] ?? 'Nunca',
    'active_sessions' => 1,
    'created_at' => $row['created_at'] ?? null,
    'status' => $row['account_status'] ?? null,
    'roles' => $userRoles,
  ];

  return Response::json($data);
}, ROUTER_SCOPE_PRIVATE);

/**
 * Update password
 */
Router::register(['PUT'], 'password', function() {
  if ($authError = profile_require_auth()) {
    return $authError;
  }

  $current = \bX\Args::ctx()->opt['current_password'] ?? '';
  $next = \bX\Args::ctx()->opt['new_password'] ?? '';

  if (empty($current) || empty($next)) {
    return Response::json(['success' => false, 'message' => 'current_password y new_password son requeridos'], 400);
  }
  if (strlen($next) < 8) {
    return Response::json(['success' => false, 'message' => 'La nueva contraseña debe tener al menos 8 caracteres'], 400);
  }

  $accountId = Profile::ctx()->accountId;
  $row = null;
  CONN::dml("SELECT password_hash FROM accounts WHERE account_id = :id LIMIT 1", [':id' => $accountId], function($r) use (&$row) { $row = $r; return false; });
  if (!$row || empty($row['password_hash'])) {
    return Response::json(['success' => false, 'message' => 'Cuenta no encontrada'], 404);
  }
  if (!password_verify($current, $row['password_hash'])) {
    return Response::json(['success' => false, 'message' => 'Contraseña actual incorrecta'], 401);
  }

  $hash = password_hash($next, PASSWORD_DEFAULT);
  $res = CONN::nodml(
    "UPDATE accounts SET password_hash = :pwd, updated_at = NOW(), updated_by_profile_id = :p WHERE account_id = :id",
    [':pwd' => $hash, ':id' => $accountId, ':p' => Profile::ctx()->profileId]
  );

  if (!$res['success']) {
    Log::logError('Profile password update failed', ['account_id' => $accountId, 'error' => $res['error'] ?? 'unknown']);
    return Response::json(['success' => false, 'message' => 'No se pudo actualizar la contraseña'], 500);
  }

  return Response::json(['success' => true, 'message' => 'Contraseña actualizada']);
}, ROUTER_SCOPE_PRIVATE);

/**
 * Update display/name data
 */
Router::register(['PUT'], 'name', function() {
  if ($authError = profile_require_auth()) {
    return $authError;
  }

  $first = trim((string)(\bX\Args::ctx()->opt['first_name'] ?? ''));
  $last = trim((string)(\bX\Args::ctx()->opt['last_name'] ?? ''));
  $display = trim((string)(\bX\Args::ctx()->opt['display_name'] ?? ''));

  if ($first === '' && $display === '') {
    return Response::json(['success' => false, 'message' => 'Debe enviar display_name o first_name'], 400);
  }

  $accountId = Profile::ctx()->accountId;
  $entityId = Profile::ctx()->entityId;
  $profileId = Profile::ctx()->profileId;

  $primaryName = trim("{$first} {$last}") ?: $display;
  $profileName = $display ?: $primaryName;

  CONN::begin();
  try {
    if ($entityId > 0) {
      $resEntity = CONN::nodml(
        "UPDATE entities
           SET primary_name = :name,
               updated_at = NOW(),
               updated_by_profile_id = :p
         WHERE entity_id = :id",
        [':name' => $primaryName, ':id' => $entityId, ':p' => $profileId]
      );
      if (!$resEntity['success']) {
        throw new \Exception($resEntity['error'] ?? 'Error al actualizar entidad');
      }
    }

    $resProfile = CONN::nodml(
      "UPDATE profiles
          SET profile_name = :pname,
              updated_at = NOW(),
              updated_by_profile_id = :p
        WHERE profile_id = :pid",
      [':pname' => $profileName, ':pid' => $profileId, ':p' => $profileId]
    );
    if (!$resProfile['success']) {
      throw new \Exception($resProfile['error'] ?? 'Error al actualizar perfil');
    }

    CONN::commit();
  } catch (\Throwable $e) {
    CONN::rollback();
    Log::logError('Profile name update failed', ['account_id' => $accountId, 'error' => $e->getMessage()]);
    return Response::json(['success' => false, 'message' => 'No se pudo actualizar el nombre'], 500);
  }

  return Response::json(['success' => true, 'message' => 'Nombre actualizado', 'data' => [
    'display_name' => $profileName,
    'first_name' => $first,
    'last_name' => $last
  ]]);
}, ROUTER_SCOPE_PRIVATE);

/**
 * Update email (username)
 */
Router::register(['PUT'], 'email', function() {
  if ($authError = profile_require_auth()) {
    return $authError;
  }

  $newEmail = trim((string)(\bX\Args::ctx()->opt['new_email'] ?? ''));
  $password = \bX\Args::ctx()->opt['password'] ?? '';

  if ($newEmail === '' || $password === '') {
    return Response::json(['success' => false, 'message' => 'new_email y password son requeridos'], 400);
  }

  $accountId = Profile::ctx()->accountId;
  $current = null;
  CONN::dml("SELECT username, email, password_hash FROM accounts WHERE account_id = :id LIMIT 1", [':id' => $accountId], function($r) use (&$current) { $current = $r; return false; });

  if (!$current) {
    return Response::json(['success' => false, 'message' => 'Cuenta no encontrada'], 404);
  }
  if (!password_verify($password, $current['password_hash'])) {
    return Response::json(['success' => false, 'message' => 'Contraseña incorrecta'], 401);
  }

  $exists = null;
  CONN::dml("SELECT account_id FROM accounts WHERE email = :u AND account_id <> :id LIMIT 1", [':u' => $newEmail, ':id' => $accountId], function($r) use (&$exists) { $exists = $r; return false; });
  if ($exists) {
    return Response::json(['success' => false, 'message' => 'El correo ya está en uso'], 409);
  }

  CONN::begin();
  try {
    $resAcc = CONN::nodml(
      "UPDATE accounts
          SET email = :u,
              updated_at = NOW(),
              updated_by_profile_id = :p
        WHERE account_id = :id",
      [':u' => $newEmail, ':id' => $accountId, ':p' => Profile::ctx()->profileId]
    );
    if (!$resAcc['success']) {
      throw new \Exception($resAcc['error'] ?? 'Error al actualizar correo de cuenta');
    }

    # Guardar correo de perfil en EDC (vertical)
    $fieldDef = [
      'unique_name' => 'profile.contact_email',
      'label' => 'Correo de perfil',
      'data_type' => 'STRING',
      'is_pii' => true,
      'status' => 'active'
    ];
    DataCaptureService::defineCaptureField($fieldDef, Profile::ctx()->profileId);

    $saveRes = DataCaptureService::saveData(
      Profile::ctx()->profileId,
      Profile::ctx()->entityId,
      null,
      ['macro_context' => 'profile', 'event_context' => 'contact', 'sub_context' => 'email_update'],
      [
        ['variable_name' => 'profile.contact_email', 'value' => $newEmail, 'reason' => 'profile-email-update']
      ],
      'profile_email_update',
      'PROFILE_APP'
    );

    if (empty($saveRes['success'])) {
      throw new \Exception($saveRes['message'] ?? 'Error al guardar email de perfil');
    }

    CONN::commit();
  } catch (\Throwable $e) {
    CONN::rollback();
    Log::logError('Profile email update failed', ['account_id' => $accountId, 'error' => $e->getMessage()]);
    return Response::json(['success' => false, 'message' => 'No se pudo actualizar el correo'], 500);
  }

  return Response::json(['success' => true, 'message' => 'Correo actualizado', 'data' => [
    'account_email' => $newEmail,
    'profile_email' => $newEmail
  ]]);
}, ROUTER_SCOPE_PRIVATE);

/**
 * Get allowed scopes for current profile (multi-tenant)
 *
 * Returns scopes with metadata ready for frontend.
 * All SQL logic encapsulated in Profile::getAllowedScopesWithMeta().
 */
Router::register(['GET'], 'scopes\.(json|scon|toon)', function() {
  if ($authError = profile_require_auth()) {
    return $authError;
  }

  # Delegate to Profile (no SQL in endpoint)
  $scopes = Profile::getAllowedScopesWithMeta();

  # Auto-formato: Router detecta extensión y serializa como json/scon/toon
  return ['success' => true, 'scopes' => $scopes];
}, ROUTER_SCOPE_PRIVATE);

/**
 * Switch scope (change tenant/company)
 *
 * This is the ONLY endpoint (besides login) that accepts scope_entity_id from frontend.
 * Validates against ACL and logs fraud attempts.
 */
Router::register(['POST'], 'scope/switch.json', function() {
  if ($authError = profile_require_auth()) {
    return $authError;
  }

  $requestedScope = (int)(Args::ctx()->opt['scope_entity_id'] ?? 0);

  if ($requestedScope <= 0) {
    return Response::json(['success' => false, 'message' => 'scope_entity_id is required'], 400);
  }

  # Validate against ACL + log fraud attempts
  try {
    Profile::assertScope($requestedScope);
  } catch (\RuntimeException $e) {
    # Violation already logged by assertScope()
    return Response::json(['success' => false, 'message' => $e->getMessage()], 403);
  }

  # Preservar device_hash del JWT actual antes de regenerar
  $currentToken = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (str_starts_with($currentToken, 'Bearer ')) {
    $currentToken = substr($currentToken, 7);
  }
  $currentPayload = \bX\JWT::decode($currentToken);
  $deviceHash = $currentPayload[1]['device_hash'] ?? null;

  # Generate new JWT with new scope
  $jwtSecret = \bX\Config::required('JWT_SECRET');
  $jwtXorKey = \bX\Config::required('JWT_XOR_KEY');

  $account = new \bX\Account($jwtSecret, $jwtXorKey);
  $token = $account->generateToken(
    (string)Profile::ctx()->accountId,
    null,
    null,
    Profile::ctx()->profileId,
    $requestedScope,
    $deviceHash
  );

  if (!$token) {
    Log::logError('Failed to generate new token during scope switch', [
      'account_id' => Profile::ctx()->accountId,
      'requested_scope' => $requestedScope
    ]);
    return Response::json(['success' => false, 'message' => 'Failed to generate new token'], 500);
  }

  Log::logInfo('Scope switched successfully', [
    'account_id' => Profile::ctx()->accountId,
    'profile_id' => Profile::ctx()->profileId,
    'old_scope' => Profile::ctx()->scopeEntityId,
    'new_scope' => $requestedScope
  ]);

  return Response::json([
    'success' => true,
    'token' => $token,
    'scope_entity_id' => $requestedScope,
    'message' => 'Scope changed successfully'
  ]);
}, ROUTER_SCOPE_PRIVATE);
