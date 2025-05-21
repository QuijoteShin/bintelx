<?php
# bintelx/kernel/CONN.php
namespace bX;
use PDO;
use PDOException; // Added for explicit type hinting in catch
use Exception;    // Added for explicit type hinting in catch

/*
*
This is a PHP class that provides a simple interface for database operations using PDO (PHP Data Objects).
The connect method is used to establish a connection to the database using the provided DSN (data source name), username, and password.
The add method is used to add additional connections to the connection pool.
The get method is used to retrieve a database connection from the connection pool by index.
The switchBack method is used to switch back to the previously used database connection.
The noDML method is used to execute a database query that does not return any data (such as an INSERT or DELETE statement).
The dml method is used to execute a database query that returns data (such as a SELECT statement) and, if provided, passes the returned rows to a callback function.

/ Conexión principal
CONN::connect('mysql:host=localhost;dbname=mydatabase', 'user', 'password');

// Uso de dml
$groups = [];
$query = "SELECT id, title FROM field_groups WHERE parent_id = :parentId";
$data = [':parentId' => 1];
CONN::dml($query, $data, function ($row) use (&$groups) {
    $groups[$row['id']] = [
        'id' => $row['id'],
        'title' => $row['title'],
    ];
});
print_r($groups);

// CONN::add('mysql:host=localhost;dbname=otherdatabase', 'user', 'password'); // Agregar una conexión al pool
// CONN::get(1); forzar el uso de una conexión del pool con la interfaz estática.
// CONN::dml(...); o CONN::nodml(...);
// CONN::switchBack(); // Vuelve a la conexión anterior si es necesario
// CONN::nodml("UPDATE table SET column = 'value' WHERE id = :id", [':id' => 1]);

*/

class CONN {
    // Pool of database connections
    static array $pool = [];
    // Default/primary database link
    private static ?PDO $link = null; // Type hinted
    // Index of the last used connection from the pool (if switchBack is used)
    private static int $lastIndex = 0;
    // Stores the PDO connection object currently being used for a transaction
    private static ?PDO $transactionConnection = null; // Type hinted

    /**
     * Establishes the primary database connection.
     * @param string $dsn The Data Source Name.
     * @param string $username The database username.
     * @param string $password The database password.
     * @return PDO The PDO connection object.
     */
    public static function connect(string $dsn, string $username, string $password): PDO {
        self::$link = new PDO($dsn, $username, $password);
        self::$link->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Optional: self::$link->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); for better security with some drivers
        // Optional: self::$link->setAttribute(PDO::MYSQL_ATTR_INIT_COMMAND, 'SET NAMES utf8mb4'); for MySQL
        return self::$link;
    }

    /**
     * Retrieves the appropriate PDO connection object.
     * If a transaction is active, it returns the connection used for that transaction.
     * Otherwise, it returns a connection from the pool or the primary link.
     * @return PDO The PDO connection object.
     * @throws Exception If no database connection is available.
     */
    private static function getConnection(): PDO {
        if (self::$transactionConnection !== null) {
            return self::$transactionConnection;
        }
        $connection = self::get(); // Gets from pool or primary link
        if ($connection === null) {
            Log::logError("CONN::getConnection - No database connection available (link or pool is empty).");
            throw new Exception("Database connection not available.");
        }
        return $connection;
    }

    /**
     * Adds a new database connection to the pool.
     * @param string $dsn
     * @param string $username
     * @param string $password
     * @return PDO The newly added PDO connection object.
     */
    public static function add(string $dsn, string $username, string $password): PDO {
        $pdo = new PDO($dsn, $username, $password, [
            // PDO::ATTR_TIMEOUT => 30, // This is driver-specific, not a general PDO attribute
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        // The setAttribute after new PDO is redundant if passed in options array
        // $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pool[] = $pdo;
        return $pdo; // Return the connection that was just added
    }

    /**
     * Retrieves a database connection.
     * If a pool exists, a random connection from the pool is returned.
     * Otherwise, the primary link is returned.
     * @return PDO|null The PDO connection object or null if none available.
     */
    private static function get(): ?PDO {
        if (!empty(self::$pool)) {
            $randomKey = array_rand(self::$pool);
            return self::$pool[$randomKey];
        }
        return self::$link; // Can be null if connect() was never called
    }

    /**
     * (Currently not well-defined) Switches back to a previously used connection from the pool.
     * The logic for $lastIndex needs to be managed carefully if this is used.
     * @return PDO|null The PDO object or null.
     */
    public static function switchBack(): ?PDO {
        // This logic is a bit fragile as $lastIndex is not clearly set elsewhere.
        // It assumes $lastIndex was set by a previous call to a specific 'get($index)' method
        // which is not present in the current version.
        if (self::$lastIndex > 0 && isset(self::$pool[self::$lastIndex - 1])) {
            return self::$pool[self::$lastIndex - 1];
        }
        Log::logWarning("CONN::switchBack - lastIndex not valid or pool empty.");
        return null;
    }

    /**
     * Begins a database transaction.
     * @throws Exception If a transaction is already active or no connection is available.
     */
    public static function begin(): void {
        if (self::$transactionConnection !== null) {
            throw new Exception("CONN::begin - A transaction is already active on the current connection.");
        }
        self::$transactionConnection = self::get(); // Get a connection (pool or primary)
        if (self::$transactionConnection === null) {
            throw new Exception("CONN::begin - Cannot start transaction, no database connection available.");
        }
        self::$transactionConnection->beginTransaction();
        Log::logDebug("CONN: Transaction started.");
    }

    /**
     * Commits the current active database transaction.
     * @throws Exception If no transaction is active.
     */
    public static function commit(): void {
        if (self::$transactionConnection === null) {
            throw new Exception("CONN::commit - No active transaction to commit.");
        }
        self::$transactionConnection->commit();
        self::$transactionConnection = null; // Release the dedicated transaction connection
        Log::logDebug("CONN: Transaction committed.");
    }

    /**
     * Rolls back the current active database transaction.
     * @throws Exception If no transaction is active.
     */
    public static function rollback(): void {
        if (self::$transactionConnection === null) {
            // It's possible a transaction was attempted but failed before full begin, or already rolled back.
            // Avoid throwing an exception if there's nothing to roll back, to simplify catch blocks.
            Log::logWarning("CONN::rollback - No active transaction to roll back, or already rolled back.");
            return;
        }
        if (self::$transactionConnection->inTransaction()) { // Check if PDO object itself thinks it's in a transaction
            self::$transactionConnection->rollBack();
            Log::logDebug("CONN: Transaction rolled back.");
        } else {
            Log::logWarning("CONN::rollback - PDO object reports no active transaction, though transactionConnection was set.");
        }
        self::$transactionConnection = null; // Release the dedicated transaction connection
    }

    /**
     * Checks if a transaction is currently active on the dedicated transaction connection.
     * @return bool True if a transaction is active, false otherwise.
     */
    public static function isInTransaction(): bool {
        if (self::$transactionConnection instanceof PDO) {
            return self::$transactionConnection->inTransaction();
        }
        return false;
    }

    /**
     * Executes a query that does not return a result set (e.g., INSERT, UPDATE, DELETE).
     * @param string $query The SQL query string.
     * @param array $data Parameters to bind to the query.
     * @return array ['success' => bool, 'last_id' => string|false, 'rowCount' => int]
     *               'last_id' is the ID of the last inserted row (if applicable).
     *               'rowCount' is the number of affected rows.
     */
    public static function nodml(string $query, array $data = []): array {
        $connection = null; // Initialize to null
        try {
            $connection = self::getConnection();
            $stmt = $connection->prepare($query);
            $success = $stmt->execute($data);
            $lastId = $connection->lastInsertId(); // Get lastInsertId from the actual connection used
            $rowCount = $stmt->rowCount();
            return ['success' => $success, 'last_id' => $lastId, 'rowCount' => $rowCount];
        } catch (PDOException $e) {
            Log::logError("CONN::nodml PDOException: " . $e->getMessage() . " | Query: " . $query . " | Data: " . json_encode($data));
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
                // Avoid echoing directly in a library class, prefer logging or throwing
                // Consider re-throwing a custom exception or returning a detailed error array
            }
            return ['success' => false, 'last_id' => false, 'rowCount' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Executes a query that returns a result set (e.g., SELECT).
     * @param string $query The SQL query string.
     * @param array $data Parameters to bind to the query.
     * @param callable|null $callback Optional callback function to process each row.
     *                                If provided, rows are passed one by one to the callback.
     * @return array|null If no callback, returns all rows as an array of associative arrays.
     *                    If callback is used, returns null (or void, implicitly).
     */
    public static function dml(string $query, array $data = [], callable $callback = null): ?array {
        try {
            $connection = self::getConnection();
            $stmt = $connection->prepare($query);
            $stmt->execute($data);
            if ($callback) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    call_user_func($callback, $row);
                }
                return null; // Callback handled output
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            Log::logError("CONN::dml PDOException: " . $e->getMessage() . " | Query: " . $query . " | Data: " . json_encode($data));
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
                // Avoid echoing
            }
            return null; // Indicate error or empty result on exception
        }
    }

    /**
     * Gets the ID of the last inserted row on the current transaction connection or primary link.
     * @return string|false The ID of the last inserted row, or false on failure.
     */
    public static function getLastInsertId() {
        // This should ideally use the same connection that performed the last insert.
        // If a transaction is active, it's self::$transactionConnection.
        // Otherwise, it's ambiguous if there's a pool and no transaction.
        $connectionToQuery = self::$transactionConnection ?? self::$link;
        if ($connectionToQuery) {
            return $connectionToQuery->lastInsertId();
        }
        Log::logWarning("CONN::getLastInsertId - No specific connection determined for lastInsertId call.");
        return false;
    }
}