`# bintelx/doc/Profile.md`
---
# `bX\Profile` - User Context Data Container

## Purpose

The `bX\Profile` class is a **request-scoped data container**. Its primary purpose is to be loaded once per API request (after successful authentication) and then hold the current user's essential context in its static properties.

This provides a simple, global access point to user identity information (`Profile::$account_id`, `Profile::$comp_id`, etc.) and, critically, their **assigned roles**. It eliminates the need to pass a user object through every layer of the application.

## Key Features

* **Static Context:** Holds user information globally for the duration of a single request.
* **Role-Based Permission Input:** The `Profile` is responsible for loading a user's roles (e.g., into a `Profile::$roles` array). This array is the primary input used by `app/api.php` to construct the user's final permission map.
* **Loading Mechanism:** The `load()` method populates the static context from the database based on a given `account_id`.

## Static Properties (Available after `load()`)

* `Profile::$isLoggedIn`: (bool) A flag indicating if a profile has been successfully loaded.
* `Profile::$account_id`: (int) The ID of the user's account (login identity).
* `Profile::$profile_id`: (int) The ID of the user's primary profile record.
* `Profile::$entity_id`: (int) The ID of the main entity (person/company) associated with this profile.
* `Profile::$comp_id`: (int) The ID of the current company context.
* `Profile::$roles`: (array) **Crucial for permissions.** An array of strings representing the user's assigned roles (e.g., `['JEFE_BODEGA', 'FINANZAS_USER']`).

## Core Static Methods

### `load(array $criteria = []): bool`
* **Purpose:** The main method to initialize the user context. It queries the database and populates all the static properties of the class, including the user's roles.
* **Usage:** `(new \bX\Profile())->load(['account_id' => $accountId]);`
* **Parameters:**
    * `$criteria`: Must contain `['account_id' => int]`.
* **Returns:** `true` if a profile and its associated data (like roles) are loaded successfully, `false` otherwise.

### `hasPermission(string $permissionKey): bool`
* **Purpose:** Checks if the user has a specific, granular permission key.
* **Note:** This relies on the internal permission-loading logic within the `load()` method and is a good tool for fine-grained checks *inside* a controller method, separate from route-level access control.

### `load(array $criteria = []): bool`
*   **Purpose:** Loads a user's profile information into the static properties of the class. This is the primary method to initialize the user context for the current request.
*   **Parameters:**
    *   `$criteria`: Associative array. Must contain `['account_id' => int]`. Can optionally include `comp_id` and `comp_branch_id` for more specific profile loading scenarios (if one account can have multiple profiles across companies/branches).
*   **Returns:** `true` if a profile is successfully loaded, `false` otherwise.
*   **Side Effects:** Populates `Profile::$account_id`, `Profile::$profile_id`, etc., and calls internal methods to load granular permissions (`loadUserPermissions`).

### `getEffectiveScope(string $moduleContext = null): string`
*   **Purpose:** Determines the broad permission scope of the currently loaded user. Used by `bX\Router`.
*   **Parameters:**
    *   `$moduleContext` (string|null): Optional. The name of the module for which scope is being checked. Can be used for module-specific permission logic.
*   **Returns:** A string constant like `ROUTER_SCOPE_PUBLIC`, `ROUTER_SCOPE_PRIVATE`, `ROUTER_SCOPE_READ`, or `ROUTER_SCOPE_WRITE`.
*   **Note:** The actual logic for 'read'/'write' depends on the implementation of `loadUserPermissions()` and how it populates `Profile::$userPermissions`.

### `hasPermission(string $permissionKey): bool`
*   **Purpose:** Checks if the currently loaded user has a specific granular permission.
*   **Parameters:**
    *   `$permissionKey`: The unique string identifier for the permission (e.g., 'CREATE_ORDER', 'VIEW_REPORTS').
*   **Returns:** `true` if the user has the permission, `false` otherwise.
*   **Note:** This relies on `loadUserPermissions()` having populated `Profile::$userPermissions` correctly.

### `isLoggedIn(): bool`
*   **Purpose:** A simple check to see if a user profile is currently loaded (i.e., `Profile::$account_id > 0`).
*   **Returns:** `true` if a user is considered logged in, `false` otherwise.

### `save(array $profileData): int`
*   **Purpose:** Creates a new profile record or updates an existing one.
*   **Parameters:**
    *   `$profileData`: Associative array of profile data. For updates, must include `profile_id`. For new profiles, `account_id` is essential.
*   **Returns:** The `profile_id` of the created or updated record.
*   **Throws:** `\Exception` on failure.

## Instance Methods

The class also contains instance methods like `read(int $entityId)` and a `model()` definition. The primary interaction for request context is through the static `load()` and subsequent access to static properties. Instance methods might be used for operations on specific profile objects if needed outside the current request's global context.

## Setup & Dependencies

*   Relies on `bX\CONN` for database access.
*   Relies on `bX\Log` for logging.
*   Assumes database tables: `profile`, and potentially tables for roles/permissions (e.g., `account_roles`, `role_permissions`, `permission`) for the `loadUserPermissions()` logic.
*   Typically initialized in `app/api.php` after successful authentication via `bX\Account`.

## Example Usage (in `app/api.php`)

```php
// After bX\Account verifies a token and gets $accountId:
$profile = new \bX\Profile();
if ($profile->load(['account_id' => $accountId])) {
    // Profile::$account_id, Profile::$comp_id etc. are now set.
    // The Router can now use Profile::getEffectiveScope().
    // Application logic can use Profile::hasPermission('SPECIFIC_ACTION').
}
```

## Caveats & Design Philosophy

* **Data Container, Not Logic Engine:** The `Profile` class's main job is to fetch and hold data ("who is this user?" and "what roles do they have?"). The logic for *interpreting* what those roles mean in terms of API access has been moved to `app/api.php`. This is a strong separation of concerns. `Profile` provides the "what", `api.php` decides "so what?".
* **Stateless by Design:** Remember that all static properties are reset on every new API request, ensuring no data leakage between requests.