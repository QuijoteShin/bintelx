<?php # bintelx/kernel/Router.php
/**
 * @namespace bX
 * @class Router
 * Handles all API routing, including matching requests to endpoints
 * and validating permissions based on a dynamic, role-based system.
 */
namespace bX;

# Scope constants define access levels for routes
define('ROUTER_SCOPE_PRIVATE', 'private');       # Any authenticated user.
define('ROUTER_SCOPE_READ', 'read');             # User requires at least 'read' privileges.
define('ROUTER_SCOPE_WRITE', 'write');           # User requires 'write' privileges.
define('ROUTER_SCOPE_PUBLIC', 'public');         # Open to everyone, no authentication needed.
define('ROUTER_SCOPE_PUBLIC_WRITE', 'public-write'); # Publicly writable, use with extreme caution.
# ROUTER_ENDPOINT_PREFIX: A regex defining the base for API endpoints, typically capturing a 'module_id'.
# Example from your setup: define('ROUTER_ENDPOINT_PREFIX', '\/api\/(?P<module_id>\w+)\/');
#define('ROUTER_ENDPOINT_PREFIX', '/api/(?P<module_id>\w+)/*');

class Router
{
  private static array $routesByModule = []; # Stores all registered routes, grouped by their module key.
  private static string $URI = ''; # The processed URI path for the current request (e.g., /api/order/save).
  private static string $METHOD = ''; #  The HTTP method for the current request (e.g., GET, POST).
  public static array $CurrentRouteFileContext = []; # This helps Router::register() determine the module for the routes being defined.

  /**
   * @var array Holds the user's permission map.
   * @usage Set externally by api.php after user login.
   * @example [ 'products/.*' => 'read', 'admin/settings' => 'write', '*' => 'private' ]
   */
  public static array $currentUserPermissions = [];

  /**
   * @var string Stores the API's base path (e.g., '/api', '/v2').
   * This makes route and permission definitions independent of the base URL.
   */
  private static string $apiBasePath = '/';

  /**
   * The Router is initialized by the application's entry point.
   * This is where the app injects its configuration, like the API base path.
   * @param string $uri The full request URI.
   * @param string $apiBasePath The application's base path for the API.
   */
  public function __construct(string $uri, string $apiBasePath = '/api') {
    self::$METHOD = strtoupper($_SERVER['REQUEST_METHOD']);
    self::$URI = $uri;
    self::$apiBasePath = rtrim($apiBasePath, '/');
  }

  /**
   * Helper function to convert scope strings into numbers for easy comparison.
   * This defines the hierarchy: WRITE > READ > PRIVATE.
   * @param string $scope The scope name.
   * @return int The scope's weight.
   */
  private static function getScopeWeight(string $scope): int
  {
    return match ($scope) {
      ROUTER_SCOPE_WRITE => 3,
      ROUTER_SCOPE_READ => 2,
      ROUTER_SCOPE_PRIVATE => 1,
      default => 0, # for 'public' or any other
    };
  }

  /**
   * Finds endpoint definition files using bX\Cardex.
   * @param array $data ['find_str' => string (base path for Cardex search),
   *                     'pattern' => string (glob pattern for Cardex)]
   * @return array List of file details from Cardex.
   */
  public static function find(array $data = []): array {
    if (empty($data["find_str"])) {
      Log::logError('Router::find - "find_str" (directory to search) is required.');
      return [];
    }
    $pattern = $data['pattern'] ?? '{*/,}*{endpoint,controller}.php'; # Default pattern
    # Cardex Search
    $cardex = new Cardex(); # Assumes Cardex class is available
    return $cardex->search($data["find_str"], $pattern);
  }

  /**
   * Loads route definition files.
   * Endpoint files found by Cardex are processed. These files should call Router::register().
   * @param array $data ['find_str' => string (base path), 'pattern' => string (glob pattern)]
   * @param callable|null $loaderCallback Optional callback that receives $routeFileContext.
   *                                      If provided, this callback is responsible for `require_once`.
   *                                      If null, this method directly `require_once`s the file.
   */
  public static function load(array $data = [], callable $loaderCallback = null): void {
    if (empty($data["find_str"])) {
      Log::logError('Router::load - "find_str" (directory to search) is required.');
      return;
    }
    $routeFiles = self::find($data);

    foreach ($routeFiles as $routeFileContext) {
      if (empty($routeFileContext["real"]) || !is_file($routeFileContext["real"])) {
        Log::logWarning("Router::load - Skipping non-existent or invalid file path from Cardex: " . ($routeFileContext["real"] ?? '[path not set]'));
        continue;
      }
      self::$CurrentRouteFileContext = $routeFileContext; # Set for Router::register()

      if ($loaderCallback) {
        $loaderCallback($routeFileContext); # e.g., api.php uses this to filter by module
      } else {
        require_once $routeFileContext["real"];
      }
      self::$CurrentRouteFileContext = []; # Reset after processing
    }
  }

  /**
   * Registers a route. Called from endpoint definition files.
   * @param array|string $methods HTTP method(s) like 'GET' or ['POST', 'PUT'].
   * @param string $regexPattern URI pattern relative to the module base (e.g., 'items\/(?P<id>\d+)').
   * @param string|callable $callback Handler (e.g., 'MyController::action', or a Closure).
   * @param string $scope Required permission scope (e.g., ROUTER_SCOPE_READ).
   */
  public static function register(array|string $methods, string $regexPattern, string|callable $callback, string $scope = ROUTER_SCOPE_PRIVATE): void {
    if (!is_array($methods)) {
      $methods = [$methods];
    }

    $moduleKey = 'default'; # Fallback
    if (!empty(self::$CurrentRouteFileContext) && isset(self::$CurrentRouteFileContext['module']) && is_array(self::$CurrentRouteFileContext['module_parts'])) {
      $moduleKey = self::$CurrentRouteFileContext['module'];
    } else {
      Log::logWarning("Router::register - Module key not determined from CurrentRouteFileContext for regex '$regexPattern'. Using '$moduleKey'. Ensure Cardex returns 'module' array.");
    }

    $validScopes = [ROUTER_SCOPE_PRIVATE, ROUTER_SCOPE_READ, ROUTER_SCOPE_WRITE, ROUTER_SCOPE_PUBLIC, ROUTER_SCOPE_PUBLIC_WRITE];
    if (!in_array($scope, $validScopes)) {
      Log::logError("Router::register - Invalid scope '$scope' for regex '$regexPattern' (module: '$moduleKey'). Defaulting to 'ROUTER_SCOPE_PRIVATE'.");
      $scope = ROUTER_SCOPE_PRIVATE;
    }


    self::$routesByModule[$moduleKey][] = [
      'methods' => array_map('strtoupper', $methods),
      'regex_pattern' => trim($regexPattern, '/'), # Store pattern without leading/trailing slashes
      'callback' => $callback,
      'scope' => $scope,
      'defined_in_file' => self::$CurrentRouteFileContext['real'] ?? 'N/A'
    ];
    Log::logDebug("Router: Registered for module '$moduleKey': [".implode(',', $methods)."] /".$moduleKey."/".trim($regexPattern, '/')." (Scope: $scope)");
  }

  /**
   * Checks if the current user is allowed to access a route.
   * @param string $requiredScope The access level defined for the route.
   * @param string $pathAfterPrefix The request path *after* the apiBasePath (e.g., 'products/123').
   * @return bool
   */
  private static function hasPermission(string $requiredScope, string $pathAfterPrefix): bool
  {
    if ($requiredScope === ROUTER_SCOPE_PUBLIC || $requiredScope === ROUTER_SCOPE_PUBLIC_WRITE) {
      return true;
    }
    $effectiveUserScope = ROUTER_SCOPE_PUBLIC; # Default to the lowest permission.
    foreach (self::$currentUserPermissions as $pathRegex => $permissionScope) {
      $isMatch = ($pathRegex === '*') ? true : @preg_match('#^' . $pathRegex . '$#i', $pathAfterPrefix);
      # If the path matches and this permission is higher than what we've found so far, update it.
      if ($isMatch && self::getScopeWeight($permissionScope) > self::getScopeWeight($effectiveUserScope)) {
        $effectiveUserScope = $permissionScope;
      }
    }
    # user's best permission against what the route requires
    $return = self::getScopeWeight($effectiveUserScope) >= self::getScopeWeight($requiredScope);
    if(!$return) Log::logError("Router::hasPermission - Denying Access `$effectiveUserScope` checked with requiredScope: '$requiredScope'.");
    return $return;
  }

  /**
   * Finds and executes the correct route for the current request.
   * @param string $requestMethod
   * @param string $requestUri
   */
  public static function dispatch(string $requestMethod, string $requestUri): void
  {
    # clean the URI
    $pathForMatching = (str_starts_with($requestUri, self::$apiBasePath))
      ? substr($requestUri, strlen(self::$apiBasePath))
      : $requestUri;
    $pathForMatching = ltrim($pathForMatching, '/');

    # 2. Find the module and the specific route pattern that matches the cleaned path.
    $dispatchModuleKey = 'default';
    $uriPathForRouteMatching  = '';
    $moduleCaptureRegex = '#^(?P<module_id>\w+)/?#i';
    $prefixCapturedParams = [];

    if (preg_match($moduleCaptureRegex, $pathForMatching, $matches)) {
      $dispatchModuleKey = $matches['module_id'];
      $uriPathForRouteMatching = ltrim(substr($pathForMatching, strlen($matches[0])), '/');
    } else {
      $uriPathForRouteMatching = $pathForMatching;
    }

    $routesToSearch = self::$routesByModule[$dispatchModuleKey] ?? [];
    if (empty($routesToSearch) && $dispatchModuleKey !== 'default') {
      $routesToSearch = array_merge($routesToSearch, self::$routesByModule['default'] ?? []);
    }

    # 3. Loop through candidate routes to find a match and check permissions.
    $foundMatchingPattern = false;
    $permissionDenied = false;

    foreach ($routesToSearch as $route) {
      if (!in_array($requestMethod, $route['methods'])) continue;

      $fullRoutePattern = '#^' . $route['regex_pattern'] . '$#i';
      if (preg_match($fullRoutePattern, $uriPathForRouteMatching, $routeSpecificMatches) !== 1) continue;

      $foundMatchingPattern = true;
      if (!self::hasPermission($route['scope'], $pathForMatching)) {
        $permissionDenied = true;
        continue; # Keep checking in case another route definition allows access.
      }

      Log::logInfo("Router::dispatch - Permission GRANTED. Executing for URI '$requestUri'");
      $finalArgs = array_merge($prefixCapturedParams, \bX\ArrayProcessor::getNamedIndices($routeSpecificMatches));

      try {
        $callback = $route['callback'];
        if (is_string($callback) && count($parts = explode('::', $callback)) === 2) {
          call_user_func_array([new $parts[0](), $parts[1]], $finalArgs);
        } elseif (is_callable($callback)) {
          call_user_func_array($callback, $finalArgs);
        } else {
          throw new \Exception("Route callback is not executable.");
        }
        return; // "First Match Wins" behavior. Stop after executing the first valid route.
      } catch (\Exception $e) {
        Log::logError("Router Callback Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error during request execution.']);
        return;
      }
    }

    # return 403 Forbidden or 404 Not Found
    if ($foundMatchingPattern && $permissionDenied) {
      # A route was found, but the user didn't have permission.
      http_response_code(403); // Forbidden
      echo json_encode(['status' => 'error', 'message' => 'Access Denied. Insufficient permissions for this resource.']);
    } elseif (!$foundMatchingPattern) {
      # No route pattern matched the request at all.
      http_response_code(404); // Not Found
      echo json_encode(['status' => 'error', 'message' => 'The requested endpoint action was not found.']);
    }
  }
}