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
        $pattern = $data['pattern'] ?? '{*/,}{endpoint,controller}.php'; // Default pattern

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
        if (!empty(self::$CurrentRouteFileContext) && isset(self::$CurrentRouteFileContext['module']) && is_array(self::$CurrentRouteFileContext['module'])) {
            $moduleParts = array_filter(self::$CurrentRouteFileContext['module']); // Remove empty segments
            if (!empty($moduleParts)) {
                $moduleKey = end($moduleParts); // Use the most specific directory name as module key
            }
        } else {
            Log::logWarning("Router::register - Module key not determined from CurrentRouteFileContext for regex '$regexPattern'. Using '$moduleKey'. Ensure Cardex returns 'module' array.");
        }

        $validScopes = [ROUTER_SCOPE_PRIVATE, ROUTER_SCOPE_READ, ROUTER_SCOPE_WRITE, ROUTER_SCOPE_PUBLIC, ROUTER_SCOPE_PUBLIC_WRITE];
        if (!in_array($scope, $validScopes)) {
            Log::logError("Router::register - Invalid scope '$scope' for regex '$regexPattern' (module: '$moduleKey'). Defaulting to '$ROUTER_SCOPE_PRIVATE'.");
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
        $uriPathForRouteMatching = $requestUri; // Path part for individual route regexes
        $prefixCapturedParams = [];

        // Define the regex for the global API prefix to extract module_id
        // Example: ROUTER_ENDPOINT_PREFIX = '\/api\/(?P<module_id>\w+)\/'
        // Ensure it's treated as regex content, not a full /.../ pattern yet
        $prefixPattern = ltrim(ROUTER_ENDPOINT_PREFIX, '/'); // Remove leading slash if present in define
        $prefixPattern = rtrim($prefixPattern, '/');       // Remove trailing slash if present
        $fullPrefixRegex = '#^' . $prefixPattern . '#i'; // Add delimiters and anchor

        if (preg_match($fullPrefixRegex, ltrim($requestUri, '/'), $matches)) {
            if (isset($matches['module_id'])) {
                $dispatchModuleKey = $matches['module_id'];
                $prefixCapturedParams = \bX\ArrayProcessor::getNamedIndices($matches);
                // Path for route regexes is what's after the matched prefix
                $uriPathForRouteMatching = '/' . ltrim(substr(ltrim($requestUri, '/'), strlen($matches[0])), '/');
                if ($uriPathForRouteMatching === '//') $uriPathForRouteMatching = '/';
            } else {
                Log::logWarning("Router::dispatch - ROUTER_ENDPOINT_PREFIX matched '$requestUri', but 'module_id' not captured. Check prefix regex captures. Matches: ".json_encode($matches));
                // Fallback or specific logic if module_id is not in prefix
            }
        } else {
            Log::logDebug("Router::dispatch - ROUTER_ENDPOINT_PREFIX did not match URI '$requestUri'. Trying 'default' module with full URI.");
            // If prefix doesn't match, routes in 'default' module will try to match the full $requestUri.
        }

        Log::logDebug("Router::dispatch - Effective module: '$dispatchModuleKey', Path for route matching: '$uriPathForRouteMatching', Method: '$requestMethod'");

        $routesToSearch = self::$routesByModule[$dispatchModuleKey] ?? [];
        if (empty($routesToSearch) && $dispatchModuleKey !== 'default') {
            $routesToSearch = array_merge($routesToSearch, self::$routesByModule['default'] ?? []);
        }

        if (empty($routesToSearch)) {
            Log::logInfo("Router::dispatch - No routes found for module '$dispatchModuleKey' or 'default' for URI: '$requestUri'.");
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => "Service or module '$dispatchModuleKey' not found."]);
            return;
        }

        $foundMatchingPattern = false;
        $permissionDeniedOverall = false;

        foreach ($routesToSearch as $route) {
            if (!in_array($requestMethod, $route['methods'])) {
                continue;
            }

            // $route['regex_pattern'] is relative, e.g., 'item\/(?P<id>\d+)' or empty for module root
            // Match it against $uriPathForRouteMatching
            $patternForUrlMatcher = '^' . $route['regex_pattern'] . '$'; // Anchor for exact match of the segment

            $routeSpecificMatches = null;
            if ($route['regex_pattern'] === '' && $uriPathForRouteMatching === '/') { // Root of module
                $routeSpecificMatches = []; // No further params from this part
            } else if ($route['regex_pattern'] !== '') {
                // UrlMatcher adds its own delimiters internally
                $routeSpecificMatches = \bX\UrlMatcher::match($uriPathForRouteMatching, $patternForUrlMatcher);
            }


            if ($routeSpecificMatches === false || $routeSpecificMatches === null) {
                continue; // This route's regex_pattern doesn't match $uriPathForRouteMatching
            }

            $foundMatchingPattern = true;
            $finalArgs = array_merge($prefixCapturedParams, $routeSpecificMatches); // Combine prefix and route captures

            if (!self::hasPermission($route['scope'], self::$currentUserScope)) {
                $permissionDeniedOverall = true; // At least one matched route was denied
                Log::logInfo("Router::dispatch - Permission DENIED (UserScope: '".self::$currentUserScope."', RouteScope: '".$route['scope']."') for ". $route['defined_in_file'] ." URI: '$requestUri'");
                // Continue to check other routes only if multiple routes can handle one request (uncommon for first-match wins)
                // For "first permitted match wins", we would continue here.
                // If "any permission denial on a matched route means 403", then this logic is more complex.
                // Let's assume "first *permitted* match wins".
                continue;
            }

            // Route Matched, Method Matched, Permission GRANTED
            Log::logInfo("Router::dispatch - Permission GRANTED. Executing " . $route['defined_in_file'] . " for URI '$requestUri'");

            try {
                $callback = $route['callback'];
                if (is_string($callback)) {
                    $parts = explode('::', $callback);
                    if (count($parts) === 2 && class_exists($parts[0]) && method_exists(new $parts[0](), $parts[1])) {
                        call_user_func_array([new $parts[0](), $parts[1]], $finalArgs);
                    } else { throw new Exception("Invalid string callback or class/method not found: '$callback'."); }
                } elseif (is_callable($callback)) {
                    call_user_func_array($callback, $finalArgs);
                } else { throw new Exception("Route callback is not executable."); }
                return; // Successfully dispatched and executed.
            } catch (Exception $e) {
                Log::logError("Router Callback Error for ".$route['defined_in_file'].": " . $e->getMessage(), $e->getTrace());
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Server error during request execution.']);
                return;
            }
        }

        // After checking all routes:
        if ($foundMatchingPattern && $permissionDeniedOverall) {
            // A route pattern matched, but user lacked permissions for all such matches.
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access Denied. Insufficient permissions for this resource or action.']);
        } elseif (!$foundMatchingPattern) {
            // No route pattern matched the URI for the method and module.
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'The requested endpoint action was not found within the service module.']);
        }
        // If $foundMatchingPattern = true AND $permissionDeniedOverall = false, means a callback error occurred (already handled with 500).
    }
}