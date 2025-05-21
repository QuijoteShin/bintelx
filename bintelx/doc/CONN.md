# `bX\CONN` - Database Connection Manager

**File:** `bintelx/kernel/CONN.php`

## Purpose

The `bX\CONN` class provides a static interface for managing database connections and executing SQL queries within the Bintelx framework. It supports a primary connection, an optional connection pool for load distribution (primarily for read queries), and explicit transaction management.

## Key Features

*   **Primary Connection:** Manages a main database link established via `CONN::connect()`.
*   **Connection Pooling (Optional):** Can manage a pool of additional connections via `CONN::add()`. `CONN::get()` can randomly pick from this pool if transactions are not active.
*   **Explicit Transactions:** Provides `CONN::begin()`, `CONN::commit()`, and `CONN::rollback()` for managing database transactions. All queries within a transaction use the same dedicated connection.
*   **Simplified Query Execution:**
    *   `CONN::dml()`: For SELECT queries or queries returning data. Supports an optional callback for row-by-row processing.
    *   `CONN::nodml()`: For INSERT, UPDATE, DELETE queries. Returns success status, last insert ID, and row count.
*   **Error Handling:** Catches `PDOException`s and logs them using `bX\Log`.

## Core Static Methods

### `connect(string $dsn, string $username, string $password): PDO`
*   **Purpose:** Establishes the primary (default) database connection.
*   **Parameters:** Standard PDO connection parameters.
*   **Returns:** The `PDO` object for the primary connection.

### `add(string $dsn, string $username, string $password): PDO`
*   **Purpose:** Adds a new database connection to an internal pool.
*   **Parameters:** Standard PDO connection parameters.
*   **Returns:** The `PDO` object for the newly added connection.

### `begin(): void`
*   **Purpose:** Starts a new database transaction. It acquires a connection (either from the pool or the primary link) and dedicates it to this transaction.
*   **Throws:** `\Exception` if a transaction is already active or no connection is available.

### `commit(): void`
*   **Purpose:** Commits the currently active transaction.
*   **Throws:** `\Exception` if no transaction is active.

### `rollback(): void`
*   **Purpose:** Rolls back the currently active transaction. Logs a warning if no transaction was active.

### `isInTransaction(): bool`
*   **Purpose:** Checks if there is currently an active transaction being managed by `CONN`.
*   **Returns:** `true` if a transaction is active, `false` otherwise.

### `dml(string $query, array $data = [], callable $callback = null): ?array`
*   **Purpose:** Executes a SQL query expected to return data (e.g., `SELECT`).
*   **Parameters:**
    *   `$query`: The SQL query string.
    *   `$data`: Associative array of parameters to bind to the query.
    *   `$callback` (optional): A function to call for each row fetched. If provided, the method processes rows one by one.
*   **Returns:** If `$callback` is null, returns an array of all fetched rows (associative arrays). If `$callback` is provided, returns `null`. Returns `null` on PDOException.

### `nodml(string $query, array $data = []): array`
*   **Purpose:** Executes a SQL query not expected to return a data set (e.g., `INSERT`, `UPDATE`, `DELETE`).
*   **Parameters:**
    *   `$query`: The SQL query string.
    *   `$data`: Associative array of parameters to bind to the query.
*   **Returns:** An associative array:
    *   `['success' => bool, 'last_id' => string|false, 'rowCount' => int, 'error' => string (optional)]`

### `getLastInsertId(): string|false`
*   **Purpose:** Retrieves the ID of the last inserted row using the connection that was active (either transaction connection or primary link).
*   **Returns:** The last insert ID or `false` on failure/ambiguity.

## Usage Example

```php
// Establish primary connection (typically in WarmUp.php or bootstrap)
\bX\CONN::connect('mysql:host=localhost;dbname=main_db', 'user', 'pass');

// Add to pool (optional)
// \bX\CONN::add('mysql:host=replica_db;dbname=main_db', 'user_ro', 'pass_ro');

// Start a transaction
try {
    \bX\CONN::begin();

    $insertResult = \bX\CONN::nodml("INSERT INTO my_table (name) VALUES (:name)", [':name' => 'Test']);
    if (!$insertResult['success']) {
        throw new \Exception("Insert failed: " . ($insertResult['error'] ?? 'Unknown DB error'));
    }
    $newId = $insertResult['last_id'];

    \bX\CONN::nodml("UPDATE my_table SET status = 'processed' WHERE id = :id", [':id' => $newId]);

    \bX\CONN::commit();
    echo "Transaction successful. New ID: $newId";

} catch (\Exception $e) {
    if (\bX\CONN::isInTransaction()) {
        \bX\CONN::rollback();
    }
    \bX\Log::logError("Database operation failed: " . $e->getMessage());
    echo "An error occurred.";
}

// Select data
$users = \bX\CONN::dml("SELECT id, username FROM users WHERE status = :status", [':status' => 'active']);
if ($users !== null) {
    foreach ($users as $user) {
        // Process $user
    }
}