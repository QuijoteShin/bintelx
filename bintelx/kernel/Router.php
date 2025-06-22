<?php # bintelx/kernel/Router.php
namespace bX;

// Scope constants define access levels for routes
define('ROUTER_SCOPE_PRIVATE', 'private');       // Requires a valid login (any authenticated user)
define('ROUTER_SCOPE_READ', 'read');             // Requires login + specific read privileges
define('ROUTER_SCOPE_WRITE', 'write');           // Requires login + specific write privileges
define('ROUTER_SCOPE_PUBLIC', 'public');         // No login required, open access
define('ROUTER_SCOPE_PUBLIC_WRITE', 'public-write'); // No login, but allows write actions (use with extreme caution and ensure endpoint self-secures)
// ROUTER_ENDPOINT_PREFIX: A regex defining the base for API endpoints, typically capturing a 'module_id'.
// Example from your setup: define('ROUTER_ENDPOINT_PREFIX', '\/api\/(?P<module_id>\w+)\/');
#define('ROUTER_ENDPOINT_PREFIX', '\/api\/(?P<module_id>\w+)\/');
define('ROUTER_ENDPOINT_PREFIX', '/api/(?P<module_id>\w+)/*');
// This pattern is used in dispatch() to identify the module and the subsequent path for matching.

class Router
{
  // Stores all registered routes, grouped by their module key.
  private static array $routesByModule = [];
  // The processed URI path for the current request (e.g., /api/order/save).
  private static string $URI = '';
  // The HTTP method for the current request (e.g., GET, POST).
  private static string $METHOD = '';
  // Holds context of the route file currently being processed by Router::load().
  // This helps Router::register() determine the module for the routes being defined.
  public static array $CurrentRouteFileContext = [];
  // The determined permission scope of the current user for this request.
  private static string $currentUserScope = ROUTER_SCOPE_PUBLIC;

  /**
   * Router Constructor.
   * Initializes essential properties for handling the current request.
   * Called from the main entry point (e.g., api.php).
   * @param string $uri The request URI path, already processed by parse_url().
   */
  public function __construct(string $uri) {
    self::$METHOD = strtoupper($_SERVER['REQUEST_METHOD']);
    self::$URI = $uri; // Assumes $uri is the path component, e.g., /api/order/save

    self::determineCurrentUserScope();
    // Route files are loaded via Router::load() in api.php after this constructor.
  }

  /**
   * Determines the effective permission scope for the current user.
   * Relies on bX\Profile to be populated (via bX\Auth) with the user's context.
   */
  private static function determineCurrentUserScope(): void {
    // Profile::getEffectiveScope() is expected to return a scope string (e.g., 'public', 'private', 'read', 'write')
    // based on the loaded profile's account_id and associated permissions/roles.
    if (method_exists(Profile::class, 'getEffectiveScope')) {
      self::$currentUserScope = Profile::getEffectiveScope();
    } else {
      // Fallback if Profile::getEffectiveScope is not implemented:
      // Basic check: if logged in (account_id > 0), scope is private, else public.
      if (isset(Profile::$account_id) && Profile::$account_id > 0) {
        self::$currentUserScope = ROUTER_SCOPE_PRIVATE;
        Log::logWarning("Router: Profile::getEffectiveScope() not found. Defaulting authenticated user to 'private' scope.");
      } else {
        self::$currentUserScope = ROUTER_SCOPE_PUBLIC;
      }
    }
    Log::logDebug("Router: Determined currentUserScope: '" . self::$currentUserScope . "' using Profile context (Account ID: " . (Profile::$account_id ?? 'N/A') . ").");
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
    $pattern = $data['pattern'] ?? '{*/,}*{endpoint,controller}.php'; // Default pattern

    $cardex = new Cardex(); // Assumes Cardex class is available
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
      self::$CurrentRouteFileContext = $routeFileContext; // Set for Router::register()

      if ($loaderCallback) {
        $loaderCallback($routeFileContext); // e.g., api.php uses this to filter by module
      } else {
        require_once $routeFileContext["real"];
      }
      self::$CurrentRouteFileContext = []; // Reset after processing
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

    $moduleKey = 'default'; // Fallback
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
      'regex_pattern' => trim($regexPattern, '/'), // Store pattern without leading/trailing slashes
      'callback' => $callback,
      'scope' => $scope,
      'defined_in_file' => self::$CurrentRouteFileContext['real'] ?? 'N/A'
    ];
    Log::logDebug("Router: Registered for module '$moduleKey': [".implode(',', $methods)."] /".$moduleKey."/".trim($regexPattern, '/')." (Scope: $scope)");
  }

  /**
   * Checks if the current user's scope permits access to a route requiring a certain scope.
   * @param string $requiredScope The scope mandated by the route.
   * @param string $currentUserScope The user's determined scope.
   * @return bool True if permitted, false otherwise.
   */
  private static function hasPermission(string $requiredScope, string $currentUserScope): bool {
    if ($requiredScope === ROUTER_SCOPE_PUBLIC || $requiredScope === ROUTER_SCOPE_PUBLIC_WRITE) {
      return true;
    }
    if ($currentUserScope === ROUTER_SCOPE_PUBLIC) { // Unauthenticated user
      return false; // Cannot access any non-public routes
    }
    // User is authenticated (scope is private, read, or write)
    switch ($requiredScope) {
      case ROUTER_SCOPE_PRIVATE: return true; // Any authenticated user has access
      case ROUTER_SCOPE_READ:    return in_array($currentUserScope, [ROUTER_SCOPE_READ, ROUTER_SCOPE_WRITE]);
      case ROUTER_SCOPE_WRITE:   return $currentUserScope === ROUTER_SCOPE_WRITE;
    }
    Log::logWarning("Router::hasPermission - Access check with unknown requiredScope: '$requiredScope'. Denying.");
    return false;
  }

  /**
   * Dispatches the request to the appropriate handler.
   * Called by api.php after router and profile initialization.
   * @param string $requestMethod The current HTTP request method.
   * @param string $requestUri The full URI path of the request.
   */
  public static function dispatch(string $requestMethod, string $requestUri): void {
    $dispatchModuleKey = 'default';
    $uriPathForRouteMatching = $requestUri;
    $prefixCapturedParams = [];

    // Step 1: Identify the module from the URI prefix
    $prefixPattern = ltrim(ROUTER_ENDPOINT_PREFIX, '/');
    $prefixPattern = rtrim($prefixPattern, '/');
    $fullPrefixRegex = '#^' . $prefixPattern . '#i';

    if (preg_match($fullPrefixRegex, ltrim($requestUri, '/'), $matches)) {
      if (isset($matches['module_id'])) {
        $dispatchModuleKey = $matches['module_id'];
        $prefixCapturedParams = \bX\ArrayProcessor::getNamedIndices($matches);
        $uriPathForRouteMatching = '/' . ltrim(substr(ltrim($requestUri, '/'), strlen($matches[0])), '/');
        if ($uriPathForRouteMatching === '//') $uriPathForRouteMatching = '/';
      }
    }

    // Step 2: Get all candidate routes for the identified module
    $routesToSearch = self::$routesByModule[$dispatchModuleKey] ?? [];
    if (empty($routesToSearch) && $dispatchModuleKey !== 'default') {
      $routesToSearch = array_merge($routesToSearch, self::$routesByModule['default'] ?? []);
    }

    // If there are no routes registered for this module at all, return 404 now.
    if (empty($routesToSearch)) {
      http_response_code(404);
      echo json_encode(['status' => 'error', 'message' => "Service or module '$dispatchModuleKey' not found."]);
      return;
    }

    // Step 3: Prepare for the matching loop
    // CORRECTION: Initialize variables before the loop
    $foundMatchingPattern = false;
    $permissionDenied = false;
    // CORRECTION: Remove leading slash from the path to match patterns like '^login$'
    $uriPathForRouteMatching = ltrim($uriPathForRouteMatching, '/');

    // Step 4: Loop through routes to find a match
    foreach ($routesToSearch as $route) {
      // Filter by HTTP method first
      if (!in_array($requestMethod, $route['methods'])) {
        continue;
      }

      // Strict match regex for the route-specific part of the URI
      $fullRoutePattern = '#^' . $route['regex_pattern'] . '$#i';
      $isMatch = preg_match($fullRoutePattern, $uriPathForRouteMatching, $routeSpecificMatches);
      if ($isMatch !== 1) {
        continue;
      }
      // A matching pattern has been found.
      $foundMatchingPattern = true;
      // check permissions
      if (!self::hasPermission($route['scope'], self::$currentUserScope)) {
        $permissionDenied = true; // Mark that we found a match but were denied
        Log::logInfo("Router::dispatch - Permission DENIED (UserScope: '".self::$currentUserScope."', RouteScope: '".$route['scope']."') for URI: '$requestUri'");
        continue; // Continue to see if another route (e.g., with a different scope) might match
      }

      // --- EXECUTION ---
      // If we get here: Method, Pattern, and Permissions all match.
      Log::logInfo("Router::dispatch - Permission GRANTED. Executing " . $route['defined_in_file'] . " for URI '$requestUri'");

      // Clean up captured params from the route pattern
      $routeSpecificMatches = \bX\ArrayProcessor::getNamedIndices($routeSpecificMatches);
      $finalArgs = array_merge($prefixCapturedParams, $routeSpecificMatches);

      try {
        $callback = $route['callback'];
        if (is_string($callback)) {
          $parts = explode('::', $callback);
          if (count($parts) === 2 && class_exists($parts[0]) && method_exists(new $parts[0](), $parts[1])) {
            call_user_func_array([new $parts[0](), $parts[1]], $finalArgs);
          } else { throw new \Exception("Invalid string callback or class/method not found: '$callback'."); }
        } elseif (is_callable($callback)) {
          call_user_func_array($callback, $finalArgs);
        } else { throw new \Exception("Route callback is not executable."); }

        // For "First Match Wins" behavior, we stop everything here.
        // If you want to process ALL matching routes, REMOVE the following 'return;'.
        return;

      } catch (\Exception $e) {
        Log::logError("Router Callback Error for ".$route['defined_in_file'].": " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error during request execution.']);
        return;
      }
    }

    // Step 5: Handle cases where the loop finishes without dispatching a route
    if ($foundMatchingPattern && $permissionDenied) {
      // A route was found, but the user didn't have permission for any of the valid matches.
      http_response_code(403); // Forbidden
      echo json_encode(['status' => 'error', 'message' => 'Access Denied. Insufficient permissions for this resource.']);
    } elseif (!$foundMatchingPattern) {
      // No route pattern matched the request URI at all.
      http_response_code(404); // Not Found
      echo json_encode(['status' => 'error', 'message' => 'The requested endpoint action was not found.']);
    }
    // If foundMatchingPattern is true but permissionDenied is false, it means an error occurred
    // inside a callback but the loop continued (if you removed the 'return'). In that case, you might
    // need a more complex response strategy, but for "first match wins", this logic is sound.
  }
}