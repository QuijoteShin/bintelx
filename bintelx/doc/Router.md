`# bintelx/doc/Router.md`
---
# `bX\Router` - HTTP Request Routing Engine

## Purpose

The `bX\Router` class is the core engine for handling incoming HTTP requests. Its primary responsibilities are to match a request's URI and method against registered route definitions, and then validate access by checking against a dynamic, role-based permission map.

It is designed to be a pure and reusable routing engine. It does **not** determine user permissions itself; instead, it receives a pre-built permission map from the application's entry point (e.g., `app/api.php`), making it highly configurable and decoupled from business logic.

## Key Features

* **Configurable API Base Path:** The main API prefix (e.g., `/api`) is injected during initialization, making all route and permission definitions independent of the base URL.
* **Module-Based Routing:** Routes are grouped by "module," typically inferred from the file system, allowing for organized code.
* **Regex-Based Route Matching:** Uses regular expressions for powerful and flexible URI pattern definitions.
* **Dynamic, Role-Based Authorization:**
    * The router's permission decisions are driven by the `Router::$currentUserPermissions` static property.
    * This property is an array (`['path_regex' => 'scope']`) that is built and set externally by the application.
    * This allows for a granular, centralized permission system where a user's multiple roles are resolved into a final set of access rights for the current request.
* **Hierarchical Scopes:** Enforces a clear permission hierarchy (`WRITE` > `READ` > `PRIVATE`) when comparing the user's effective scope against the scope required by a route.

## Core Static Methods

### `__construct(string $uri, string $apiBasePath)`
* **Purpose:** Initializes the router for the current request, injecting application-specific configuration.
* **Usage:** `new \bX\Router($requestUri, '/api');`
* **Parameters:**
    * `$uri`: The full request URI path.
    * `$apiBasePath`: The application's base path for the API (e.g., `/api`, `/v2`). This is stored internally and used to correctly parse URIs for matching.

### `register(array|string $methods, string $regexPattern, callable $callback, string $scope)`
* **Purpose:** Called from endpoint files to define a specific route.
* **Parameters:**
    * `$methods`: HTTP method(s) (e.g., `['POST', 'PUT']`).
    * `$regexPattern`: A regex pattern for the URI part *after* the module name. **It must not include the API base path.** (e.g., `item\/(?P<id>\d+)`).
    * `$callback`: The handler to execute (e.g., `MyController::class, 'action'`).
    * `$scope`: The minimum permission scope required for this route (e.g., `ROUTER_SCOPE_READ`).

### `dispatch(string $requestMethod, string $requestUri): void`
* **Purpose:** The main method that orchestrates the entire routing process for a request.
* **Behavior:**
    1.  **Cleans URI:** Removes the configured `$apiBasePath` from the request URI to get a relative path for matching.
    2.  **Finds Module:** Determines the target module from the start of the relative path.
    3.  **Matches Route:** Finds a registered route that matches the HTTP method and the remaining URI pattern.
    4.  **Checks Permissions:** Calls the internal `hasPermission()` method, passing it the route's required scope and the cleaned, relative request path.
    5.  **Executes Callback:** If permission is granted, it executes the route's callback.
    6.  **Responds:** Sends an appropriate HTTP response (e.g., 404 Not Found, 403 Forbidden) if no route is successfully dispatched.

## Caveats & Design Philosophy

* **Separation of Concerns:** A key insight from our chat is that the `Router` is intentionally "unaware" of *how* user permissions are determined. It doesn't know about roles or special user IDs. Its only job is to evaluate a pre-built permission map (`$currentUserPermissions`) against a requested URI. This makes it a clean, reusable engine.
* **Configuration over Convention:** The API base path is now an injected configuration parameter in the constructor, rather than a hardcoded convention, making the system more flexible for future changes.
* **Centralized Permission Logic:** The responsibility of building the permissions map now lies in the application's entry point (`app/api.php`). This is where you should translate your application-specific roles and rules into the generic format the `Router` understands.