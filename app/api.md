
---

**Important Note on `Router.php` and `api.php` interaction:**

As noted in the `Router.md`, the order of operations in your `api.php` is:
1. `new \bX\Router($uri);` (Constructor calls `determineCurrentUserScope()`)
2. `\bX\Router::load(...);`
3. `new \bX\Auth(...)` and `$profile->load(...)`
4. `\bX\Router::dispatch(...);`

The `determineCurrentUserScope()` inside the `Router` constructor will run *before* `Profile` is loaded with the authenticated user's data. This means `Profile::$account_id` will be 0 at that point, and `currentUserScope` in the `Router` will be set to `ROUTER_SCOPE_PUBLIC`.

To fix this, you have a few options:

*   **Option 1 ( Use of `api.php` order - Recommended ):**
    ```php
    // In app/api.php
    // ... (headers, input handling) ...
    require_once '../bintelx/WarmUp.php';
    use \bX\Cardex;
    new \bX\Args();
    \bX\Log::$logToUser = true;

    // --- START: Authentication and Profile Loading FIRST ---
    $authenticatedAccountId = null;
    try {
        $auth = new \bX\Auth("woz.min..", 'XOR_KEY_2o25');
        $token = $_SERVER["HTTP_AUTHORIZATION"] ?? '';
        if(empty($token) && !empty($_COOKIE["authToken"])) $token = $_COOKIE["authToken"];

        if(!empty($token)) {
            $accountId = $auth->verifyToken($token, $_SERVER["REMOTE_ADDR"]);
            if($accountId) {
                $profileInstance = new \bX\Profile(); // Instance
                if ($profileInstance->load(['account_id' => $accountId])) {
                     $authenticatedAccountId = $accountId; // Flag that profile is loaded
                }
            }
        }
    } catch (\Exception $e) { // Catch potential exceptions from Auth/Profile
        \bX\Log::logError("Auth/Profile loading error: " . $e->getMessage(), $e->getTrace());
    }
    // --- END: Authentication and Profile Loading ---

    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];

    // Now instantiate Router, so its constructor's determineCurrentUserScope() uses the loaded Profile
    $routerInstance = new \bX\Router($uri); // Constructor will now see Profile::$account_id

    $module = explode('/', $uri)[2] ?? null; // Get module from URI
    if ($module) { // Only load routes if a module is identified
        \bX\Router::load(
            ["find_str"=> \bX\WarmUp::$BINTELX_HOME . '../custom/', 'pattern'=> '{*/,}{endpoint,controller}.php'],
            function ($routeFileContext) use ($module) {
                // Your existing filter to load only relevant module's endpoints
                if(isset($routeFileContext['real']) && is_file($routeFileContext['real']) && 
                   isset($routeFileContext['module']) && is_array($routeFileContext['module']) &&
                   in_array($module, $routeFileContext['module'])) { // Check if current module is in Cardex path
                    require_once $routeFileContext['real'];
                }
            }
        );
    } else {
        \bX\Log::logWarning("Router: No module identified from URI '$uri' for route loading.");
    }
    
    try {
        \bX\Router::dispatch($method, $uri);
    } catch (\ErrorException $e) { // Changed to ErrorException as per your api.php
        \bX\Log::logError("Dispatch ErrorException: ".$e->getMessage(), $e->getTrace());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error during dispatch.']);
    }
    ```

*   **Option 2 (Modify `Router`):** Make `determineCurrentUserScope()` public static and call it explicitly in `api.php` after `Profile::load()` but before `Router::dispatch()`. Or, have `Router::dispatch()` call `determineCurrentUserScope()` at its beginning. Option 1 is generally cleaner as the Router is then constructed with the correct state.

I've chosen to illustrate Option 1 in the conceptual `api.php` snippet above as it makes the Router's internal state consistent from construction. Remember that `Profile::load()` now populates static properties, so `$profileInstance->load()` will set `Profile::$account_id` etc.