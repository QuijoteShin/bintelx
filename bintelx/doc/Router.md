# `bX\Router` - HTTP Request Routing

**File:** `bintelx/kernel/Router.php`

## Purpose

The `bX\Router` class is responsible for handling incoming HTTP requests, matching them against registered route definitions, checking user permissions (scopes), and dispatching them to the appropriate callback handlers (controller methods or closures). It is a core component for directing traffic within Bintelx applications.

It works in conjunction with `bX\Profile` to determine user authentication status and permission scope.

## Key Features

*   **Module-Based Routing:** Routes can be grouped by "module," typically inferred from the directory structure of endpoint files.
*   **Regex-Based Matching:** Uses regular expressions to define URI patterns for routes.
*   **HTTP Method Matching:** Routes are specific to HTTP methods (GET, POST, PUT, DELETE, etc.).
*   **Scope-Based Authorization:** Integrates with `bX\Profile::getEffectiveScope()` to enforce basic access levels:
    *   `ROUTER_SCOPE_PUBLIC`: Open to all.
    *   `ROUTER_SCOPE_PUBLIC_WRITE`: Open to all, for write actions (use with extreme care).
    *   `ROUTER_SCOPE_PRIVATE`: Requires user to be authenticated.
    *   `ROUTER_SCOPE_READ`: Requires authenticated user with at least 'read' privileges (as determined by `Profile`).
    *   `ROUTER_SCOPE_WRITE`: Requires authenticated user with 'write' privileges (as determined by `Profile`).
*   **Dynamic Route Loading:** Uses `bX\Cardex` via `Router::load()` to discover and process endpoint definition files.
*   **Parameter Extraction:** Captures named groups from URI regexes and passes them as arguments to callback handlers.

## Global Constants

*   `ROUTER_ENDPOINT_PREFIX`: A regex string defining the common base path for API endpoints, typically capturing a `module_id` (e.g., `'/api/(?P<module_id>\w+)/'`).
*   `ROUTER_SCOPE_*`: Constants defining the permission scopes.

## Core Static Methods

### `__construct(string $uri)` (Called as `new \bX\Router($uri)`)
*   **Purpose:** Initializes the router for the current request. Sets the request URI and method, and determines the current user's scope via `determineCurrentUserScope()`.
*   **Parameters:**
    *   `$uri`: The processed URI path (e.g., `/api/module/action/param`) from the entry script.

### `load(array $data = [], callable $loaderCallback = null): void`
*   **Purpose:** Discovers and loads route definition files (e.g., `endpoint.php` files). These files are expected to call `Router::register()` to define their routes.
*   **Parameters:**
    *   `$data`: `['find_str' => string (base path for Cardex search), 'pattern' => string (glob pattern)]`.
    *   `$loaderCallback` (optional): A function that receives the context of each found file and is responsible for its inclusion (e.g., `require_once`). Used in `api.php` to filter loading by module.
*   **Side Effects:** Populates `Router::$CurrentRouteFileContext` during the processing of each file.

### `register(array|string $methods, string $regexPattern, string|callable $callback, string $scope = ROUTER_SCOPE_PRIVATE): void`
*   **Purpose:** Called from within endpoint files to define a specific route.
*   **Parameters:**
    *   `$methods`: HTTP method(s) (e.g., 'GET', `['POST', 'PUT']`).
    *   `$regexPattern`: The regex pattern for the URI part *after* the `ROUTER_ENDPOINT_PREFIX` and module segment (e.g., `item\/(?P<id>\d+)`, or an empty string for the module root).
    *   `$callback`: The handler (e.g., `'Namespace\MyController::actionMethod'`, or a `Closure`).
    *   `$scope` (optional): The required permission scope (e.g., `ROUTER_SCOPE_READ`). Defaults to `ROUTER_SCOPE_PRIVATE`.

### `dispatch(string $requestMethod, string $requestUri): void`
*   **Purpose:** The main method called by the entry script (e.g., `api.php`) to handle the current request. It finds a matching route, checks permissions, and executes the callback.
*   **Parameters:**
    *   `$requestMethod`: The current HTTP request method.
    *   `$requestUri`: The full URI path of the request.
*   **Behavior:**
    1.  Determines the target module from `$requestUri` using `ROUTER_ENDPOINT_PREFIX`.
    2.  Calculates the URI path relative to the module base for matching against registered `regex_pattern`s.
    3.  Iterates through routes for the determined module (and a 'default' module as fallback).
    4.  For the first route that matches the method, relative URI path, and user scope:
        *   Executes the callback, passing captured regex parameters (from both prefix and route pattern).
    5.  Sends appropriate HTTP responses (404 Not Found, 403 Forbidden, 500 Internal Server Error).

## Setup & Workflow (Typical in `app/api.php`)

1.  **Bootstrap Bintelx (`WarmUp.php`).**
2.  **Handle Headers & Input.**
3.  **Instantiate Router:** `$router = new \bX\Router($processedUriPath);`
    *   This sets `$router::$URI`, `$router::$METHOD`, and calls `determineCurrentUserScope()`.
4.  **Load Route Definitions:**
    ```php
    $moduleFromUri = explode('/', $processedUriPath)[1]; // Or a more robust way to get current module
    \bX\Router::load(
        ["find_str"=> \bX\WarmUp::$BINTELX_HOME . '../custom/', 'pattern'=> '{*/,}{endpoint,controller}.php'],
        function ($routeFileContext) use ($moduleFromUri) {
            // Conditionally load file if it belongs to $moduleFromUri
            if(is_file($routeFileContext['real']) && strpos($routeFileContext['real'], "/$moduleFromUri/") !== false) {
                require_once $routeFileContext['real'];
            }
        }
    );
    ```
5.  **Authenticate User & Load Profile:**
    ```php
    $auth = new \bX\Auth(...);
    // ... token verification ...
    if ($accountId) {
        $profile = new \bX\Profile();
        $profile->load(['account_id' => $accountId]);
        // Router::$currentUserScope will be re-evaluated if needed, or ensure Profile load influences it.
        // The current Router constructor calls determineCurrentUserScope once. If Profile is loaded *after*
        // Router instantiation, Router's initial scope determination might be outdated.
        // SOLUTION: Router constructor should be called *after* Profile is loaded, or Router
        // needs a method to re-evaluate scope. (Current implementation in Router constructor is fine if Profile is loaded before dispatch)
        // In your api.php, Profile is loaded *after* Router instantiation but *before* dispatch.
        // Router::determineCurrentUserScope() should ideally be called just before dispatch, OR
        // Profile loading must happen before Router instantiation for the constructor's call to be effective.
        // The current Router implementation calls determineCurrentUserScope in the constructor. If Auth/Profile loading
        // happens after that, the scope won't reflect the logged-in user unless determineCurrentUserScope is called again
        // or Router is instantiated after Profile is loaded.
        // Your api.php structure: new Router -> load routes -> Auth/Profile -> dispatch.
        // This means Router::determineCurrentUserScope() (in constructor) runs *before* Profile is loaded.
        // It should be: Auth/Profile -> new Router -> load routes -> dispatch.
        // OR Router::determineCurrentUserScope() must be callable from dispatch or just before it.
        // The provided Router code calls determineCurrentUserScope() in its constructor. This needs to align with api.php flow.
        // For now, assuming api.php calls `$auth` and `$profile->load()` *before* `new \bX\Router()`.
        // If not, `Router::determineCurrentUserScope()` needs to be called again before `dispatch()`.
        // Let's adjust api.php's order or Router.
    }
    ```
    *(Self-correction on above note: `api.php` does `new \bX\Router()`, then `\bX\Router::load()`, then `new \bX\Auth()` and `$profile->load()`, then `\bX\Router::dispatch()`. The `determineCurrentUserScope()` in the Router constructor will use a non-authenticated state. It *must* be called again or implicitly used by `hasPermission` just before dispatch, or the `Profile` loading must happen before `new Router`. The current `Router`'s `hasPermission` directly uses the `self::$currentUserScope` which was set in constructor. This is a mismatch. `determineCurrentUserScope` should be called in `dispatch` or `hasPermission` should get it fresh.)*

6.  **Dispatch Request:** `\bX\Router::dispatch($requestMethod, $processedUriPath);`

## Dependencies
*   `bX\Profile` (for `getEffectiveScope()`).
*   `bX\Log` (for logging).
*   `bX\Cardex` (for `load()`).
*   `bX\ArrayProcessor` (utility).
*   `bX\UrlMatcher` (for matching regex patterns against URI segments).

---
