`# app/api.md`
---

# `app/api.php` - Application Entry Point & Permission Orchestrator

## Purpose

The `app/api.php` script is the single entry point for all API requests in the Bintelx application. Its primary responsibilities are to bootstrap the framework, handle the request lifecycle, and, most importantly, act as the **permission orchestrator**. It translates application-specific user roles into a concrete permission map that it then provides to the `bX\Router`.

## Execution Flow

The script follows a strict and logical order of operations:

1.  **Bootstrap:** Includes `bintelx/WarmUp.php` to initialize the environment, constants, and autoloading.
2.  **Handle Pre-flight & Headers:** Manages CORS headers and OPTIONS requests.
3.  **Authentication & Profile Loading:**
    * It retrieves the authentication token from the request.
    * It uses `bX\Account` to verify the token and get a valid `account_id`.
    * If authentication is successful, it uses `bX\Profile` to load the full user context, including their assigned roles (e.g., from `Profile::$roles`).
4.  **Permission Map Construction (Core Logic):**
    * This is the new, critical responsibility of `api.php`.
    * It initializes an empty `$userPermissions` array.
    * It checks for special cases, like a "Super Admin" `account_id`, and can grant universal access immediately.
    * It iterates through the user's roles (from `Profile::$roles`) and, using a centrally defined `ROLE_PERMISSIONS_MAP`, builds the final permission map for the current user. This map resolves any overlaps by granting the most permissive scope.
    * Finally, it assigns the completed map to the `Router`: `\bX\Router::$currentUserPermissions = $userPermissions;`.
5.  **Router Initialization:**
    * It instantiates the `Router`, **injecting** the application's configuration, such as the `apiBasePath`.
    * Example: `$router = new \bX\Router($requestUri, '/api');`
6.  **Route Loading & Dispatching:**
    * It calls `\bX\Router::load()` to include the endpoint definition files for the relevant module.
    * It calls `\bX\Router::dispatch()` to trigger the final routing and permission evaluation process.

## Example: Building the Permission Map

This conceptual code block illustrates how `api.php` acts as the orchestrator.

```php
// In app/api.php, after Profile is loaded...

$userPermissions = [];

// Rule 1: Check for Super Admin by ID
if (isset(\bX\Profile::$account_id) && \bX\Profile::$account_id == 1) {
    $userPermissions['*'] = ROUTER_SCOPE_WRITE; // Grant access to everything
} else if (\bX\Profile::$isLoggedIn) {
    // Rule 2: Build permissions from user roles
    
    // This map should be defined in a central config file
    $rolePermissionsMap = [
        'JEFE_BODEGA' => [
            'bodega/.*' => ROUTER_SCOPE_WRITE,
            'reports/inventory' => ROUTER_SCOPE_READ
        ],
        'BODEGUERO' => [
            'bodega/items/.*' => ROUTER_SCOPE_READ,
        ]
    ];

    // Default permission for authenticated users
    $userPermissions['*'] = ROUTER_SCOPE_PRIVATE;

    // Layer permissions from roles, letting the most permissive scope win
    foreach (\bX\Profile::$roles as $userRole) {
        $permissionsForRole = $rolePermissionsMap[$userRole] ?? [];
        foreach ($permissionsForRole as $pathRegex => $scope) {
            // ... logic to merge permissions and apply highest scope ...
            $userPermissions[$pathRegex] = $scope;
        }
    }
}

// Finally, provide the built map to the Router
\bX\Router::$currentUserPermissions = $userPermissions;
```

## Caveats & Design Philosophy
Orchestrator, Not Implementer: `api.php` is the "glue". It orchestrates the process but relies on dedicated classes (`Account`, `Profile`, `Router`) to do the heavy lifting.
Centralized Rules: All business rules for permissions (e.g., "who is a super admin?", "what can a `JEFE_BODEGA` role do?") are located here, not scattered throughout the framework. This makes the system easier to understand and maintain.

---