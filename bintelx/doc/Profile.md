# `bX\Profile` - User Context Management

**File:** `bintelx/kernel/Profile.php`

## Purpose

The `bX\Profile` class is central to managing the **current user's context** within a single stateless API request in Bintelx. After successful authentication (typically handled by `bX\Auth`), an instance of `Profile` is loaded, and its static properties are populated with the authenticated user's details like `account_id`, `profile_id`, `entity_id`, `comp_id`, and `comp_branch_id`.

This class acts as a request-scoped global access point for user context, eliminating the need to pass user identity objects through every layer of the application. It also includes logic for determining the user's effective permission scope for routing and a helper to check for granular permissions.

## Key Features

*   **Stateless Context:** Holds user information for the duration of a single request.
*   **Static Properties:** Provides easy global access to key identifiers (`Profile::$account_id`, etc.).
*   **Permission Scoping:** Includes `Profile::getEffectiveScope()` used by `bX\Router` to determine basic access levels (`public`, `private`, `read`, `write`).
*   **Granular Permissions:** Supports checking for specific permission keys via `Profile::hasPermission()`.
*   **Loading Mechanism:** `load()` method populates static context from the database based on `account_id`.

## Static Properties (Request-Scoped Context)

Once `Profile->load(['account_id' => $accountId])` is successfully called:

*   `Profile::$profile_id`: (int) The ID of the user's primary profile record.
*   `Profile::$account_id`: (int) The ID of the user's account (login identity).
*   `Profile::$entity_id`: (int) The ID of the main entity associated with this profile (can be 0 if not linked).
*   `Profile::$comp_id`: (int) The ID of the current company context for the user.
*   `Profile::$comp_branch_id`: (int) The ID of the current company branch context (0 if not branch-specific).

## Core Static Methods

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
*   Typically initialized in `app/api.php` after successful authentication via `bX\Auth`.

## Example Usage (in `app/api.php`)

```php
// After bX\Auth verifies a token and gets $accountId:
$profile = new \bX\Profile();
if ($profile->load(['account_id' => $accountId])) {
    // Profile::$account_id, Profile::$comp_id etc. are now set.
    // The Router can now use Profile::getEffectiveScope().
    // Application logic can use Profile::hasPermission('SPECIFIC_ACTION').
}