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
          # 'accountId' => \bX\Profile::$account_id,
          # 'profileId' => \bX\Profile::$profile_id,
          # 'entityId' => \bX\Profile::$entity_id,
          'companyId' => \bX\Profile::$comp_id,
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