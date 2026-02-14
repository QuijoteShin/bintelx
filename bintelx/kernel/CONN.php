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
    # Pool of database connections
    static array $pool = [];
    # Default/primary database link
    private static ?PDO $link = null;
    # Index of the last used connection from the pool (if switchBack is used)
    private static int $lastIndex = 0;
    # Stores the PDO connection object currently being used for a transaction
    private static ?PDO $transactionConnection = null;
    # Credenciales para reconnect (Swoole long-running)
    private static ?array $primaryCredentials = null;

    # Opciones PDO centralizadas — se aplican a TODA nueva conexión (pool, coroutine, reconnect)
    # MYSQL_ATTR_INIT_COMMAND ejecuta SQL al conectar: collation + timezone en un solo round-trip
    private static function pdoOptions(): array {
        $collation = Config::get('DB_COLLATION', 'utf8mb4_unicode_520_ci');
        $tz = $_SERVER['HTTP_X_USER_TIMEZONE'] ?? Config::get('DEFAULT_TIMEZONE', 'UTC');
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE {$collation}, time_zone = '{$tz}'"
        ];
    }

    /**
     * Establishes the primary database connection.
     * @param string $dsn The Data Source Name.
     * @param string $username The database username.
     * @param string $password The database password.
     * @return PDO The PDO connection object.
     */
    public static function connect(string $dsn, string $username, string $password): PDO {
        # Guardar credenciales para reconnect (Swoole long-running)
        self::$primaryCredentials = compact('dsn', 'username', 'password');

        self::$link = new PDO($dsn, $username, $password, self::pdoOptions());
        return self::$link;
    }

    /**
     * Retrieves the appropriate PDO connection object.
     * If a transaction is active, it returns the connection used for that transaction.
     * Otherwise, it returns a connection from the pool or the primary link.
     * Verifies connection is alive and reconnects if needed (Swoole long-running).
     *
     * SWOOLE COROUTINE SUPPORT:
     * When running inside a Swoole coroutine (Cid > 0), each coroutine gets its own
     * isolated PDO connection stored in Coroutine::getContext(). This prevents race
     * conditions when multiple coroutines share the same worker process.
     *
     * @return PDO The PDO connection object.
     * @throws Exception If no database connection is available.
     */
    private static function getConnection(): PDO {
        # SWOOLE COROUTINE: Conexión y transacción aislada por coroutine
        if (class_exists('Swoole\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            $ctx = \Swoole\Coroutine::getContext();
            if (isset($ctx->transaction_conn)) {
                return $ctx->transaction_conn;
            }
            return self::getCoroutineConnection();
        }

        # PHP-FPM / CLI: Transacción estática tiene prioridad
        if (self::$transactionConnection !== null) {
            return self::$transactionConnection;
        }

        $connection = self::get();
        if ($connection === null) {
            Log::logError("CONN::getConnection - No database connection available (link or pool is empty).");
            throw new Exception("Database connection not available.");
        }

        # MySQL wait_timeout (default 8h): conexión muere si no hay queries
        # ping() detecta "MySQL server has gone away" y reconnect() crea nueva conexión
        if (!self::ping($connection)) {
            Log::logWarning("CONN::getConnection - Connection dead, attempting reconnect...");
            $connection = self::reconnect();
            if ($connection === null) {
                throw new Exception("Database reconnection failed.");
            }
        }

        return $connection;
    }

    /**
     * Obtiene o crea una conexión PDO aislada para la coroutine actual.
     * Cada coroutine tiene su propia conexión, evitando race conditions.
     * La conexión se limpia automáticamente cuando la coroutine termina.
     *
     * @return PDO
     * @throws Exception Si no hay credenciales disponibles
     */
    private static function getCoroutineConnection(): PDO {
        $ctx = \Swoole\Coroutine::getContext();

        # Conexión por coroutine: sobrevive wait_timeout via ping+reconnect
        if (isset($ctx->pdo_conn)) {
            if (self::ping($ctx->pdo_conn)) {
                return $ctx->pdo_conn;
            }
            # Conexión muerta, crear nueva
            Log::logWarning("CONN::getCoroutineConnection - Connection dead in coroutine context, recreating...");
            unset($ctx->pdo_conn);
        }

        # Crear nueva conexión para esta coroutine
        if (self::$primaryCredentials === null) {
            Log::logError("CONN::getCoroutineConnection - No credentials available.");
            throw new Exception("Database credentials not available for coroutine connection.");
        }

        $creds = self::$primaryCredentials;
        $ctx->pdo_conn = new PDO($creds['dsn'], $creds['username'], $creds['password'], self::pdoOptions());

        Log::logDebug("CONN: New coroutine connection created for Cid=" . \Swoole\Coroutine::getCid());

        return $ctx->pdo_conn;
    }

    /**
     * Verifica si la conexión PDO está viva.
     * @param PDO $connection
     * @return bool
     */
    private static function ping(PDO $connection): bool {
        try {
            $connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Reconecta la conexión primaria usando credenciales guardadas.
     * @return PDO|null
     */
    private static function reconnect(): ?PDO {
        if (self::$primaryCredentials === null) {
            Log::logError("CONN::reconnect - No credentials available for reconnection.");
            return null;
        }

        try {
            $creds = self::$primaryCredentials;
            self::$link = new PDO($creds['dsn'], $creds['username'], $creds['password'], self::pdoOptions());
            Log::logInfo("CONN::reconnect - Successfully reconnected to database.");
            return self::$link;
        } catch (PDOException $e) {
            Log::logError("CONN::reconnect - Failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Adds a new database connection to the pool.
     * @param string $dsn
     * @param string $username
     * @param string $password
     * @return PDO The newly added PDO connection object.
     */
    public static function add(string $dsn, string $username, string $password): PDO {
        $pdo = new PDO($dsn, $username, $password, self::pdoOptions());
        self::$pool[] = $pdo;

        # Guardar credenciales para coroutine connections (Swoole)
        if (self::$primaryCredentials === null) {
            self::$primaryCredentials = compact('dsn', 'username', 'password');
        }

        return $pdo;
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
     * Detecta si estamos en contexto coroutine de Swoole.
     */
    private static function inCoroutine(): bool {
        return class_exists('Swoole\Coroutine') && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * Begins a database transaction.
     * En Swoole: almacena la conexión de transacción en el contexto de la coroutine.
     * En FPM/CLI: usa la propiedad estática $transactionConnection.
     * @throws Exception If a transaction is already active or no connection is available.
     */
    public static function begin(): void {
        if (self::inCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            if (isset($ctx->transaction_conn)) {
                throw new Exception("CONN::begin - A transaction is already active in this coroutine.");
            }
            $conn = self::getCoroutineConnection();
            $conn->beginTransaction();
            $ctx->transaction_conn = $conn;
            Log::logDebug("CONN: Transaction started (coroutine Cid=" . \Swoole\Coroutine::getCid() . ").");
            return;
        }

        # FPM/CLI
        if (self::$transactionConnection !== null) {
            throw new Exception("CONN::begin - A transaction is already active on the current connection.");
        }
        self::$transactionConnection = self::get();
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
        if (self::inCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            if (!isset($ctx->transaction_conn)) {
                throw new Exception("CONN::commit - No active transaction in this coroutine.");
            }
            # try/finally: si commit() lanza excepción, limpiar estado para que begin() funcione después
            try {
                $ctx->transaction_conn->commit();
            } finally {
                unset($ctx->transaction_conn);
            }
            Log::logDebug("CONN: Transaction committed (coroutine Cid=" . \Swoole\Coroutine::getCid() . ").");
            return;
        }

        # FPM/CLI
        if (self::$transactionConnection === null) {
            throw new Exception("CONN::commit - No active transaction to commit.");
        }
        try {
            self::$transactionConnection->commit();
        } finally {
            self::$transactionConnection = null;
        }
        Log::logDebug("CONN: Transaction committed.");
    }

    /**
     * Rolls back the current active database transaction.
     */
    public static function rollback(): void {
        if (self::inCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            if (!isset($ctx->transaction_conn)) {
                Log::logWarning("CONN::rollback - No active transaction in this coroutine.");
                return;
            }
            if ($ctx->transaction_conn->inTransaction()) {
                $ctx->transaction_conn->rollBack();
                Log::logDebug("CONN: Transaction rolled back (coroutine Cid=" . \Swoole\Coroutine::getCid() . ").");
            }
            unset($ctx->transaction_conn);
            return;
        }

        # FPM/CLI
        if (self::$transactionConnection === null) {
            Log::logWarning("CONN::rollback - No active transaction to roll back.");
            return;
        }
        if (self::$transactionConnection->inTransaction()) {
            self::$transactionConnection->rollBack();
            Log::logDebug("CONN: Transaction rolled back.");
        } else {
            Log::logWarning("CONN::rollback - PDO reports no active transaction.");
        }
        self::$transactionConnection = null;
    }

    /**
     * Executes a callable within a transaction with auto-commit/rollback.
     * @param callable $fn The function to execute inside the transaction.
     * @return mixed The return value of the callable.
     * @throws \Throwable Re-throws any exception after rollback.
     */
    public static function transaction(callable $fn): mixed {
        self::begin();
        try {
            $result = $fn();
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    /**
     * Checks if a transaction is currently active.
     * @return bool True if a transaction is active, false otherwise.
     */
    public static function isInTransaction(): bool {
        if (self::inCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            return isset($ctx->transaction_conn) && $ctx->transaction_conn->inTransaction();
        }
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
            return ['success' => false, 'last_id' => false, 'rowCount' => 0, 'error' => 'Database operation failed. Check logs for details.'];
        }
    }

    /**
     * Executes a query that returns a result set (e.g., SELECT).
     * @param string $query The SQL query string.
     * @param array $data Parameters to bind to the query.
     * @param callable|null $callback Optional callback function to process each row.
     *                                If callback returns false, iteration stops (early exit).
     *                                If callback returns true or void, iteration continues.
     * @return array|null If no callback, returns all rows as an array of associative arrays.
     *                    If callback is used, returns null.
     */
    public static function dml(string $query, array $data = [], ?callable $callback = null): ?array {
        try {
            $connection = self::getConnection();
            $stmt = $connection->prepare($query);
            $stmt->execute($data);
            if ($callback) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $result = call_user_func($callback, $row);
                    # Early exit: si callback retorna false, detener iteración
                    if ($result === false) {
                        $stmt->closeCursor(); # Liberar recursos del statement
                        break;
                    }
                }
                return null;
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            Log::logError("CONN::dml PDOException: " . $e->getMessage() . " | Query: " . $query . " | Data: " . json_encode($data));
            return null;
        }
    }

    /**
     * Gets the ID of the last inserted row on the current transaction connection or primary link.
     * @return string|false The ID of the last inserted row, or false on failure.
     */
    public static function getLastInsertId(): false|string {
        try {
            $connection = self::getConnection();
            return $connection->lastInsertId();
        } catch (Exception $e) {
            Log::logWarning("CONN::getLastInsertId - " . $e->getMessage());
            return false;
        }
    }
}