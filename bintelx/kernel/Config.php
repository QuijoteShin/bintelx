<?php
namespace bX;

/**
 * Configuration Manager
 * Loads environment variables from .env file and provides type-safe access
 *
 * Usage:
 *   Config::load('/path/to/.env');
 *   $dbHost = Config::get('DB_HOST', '127.0.0.1');
 *   $debug = Config::getBool('APP_DEBUG', false);
 */
class Config
{
    private static array $config = [];
    private static bool $loaded = false;

    /**
     * Load environment variables from .env file
     *
     * @param string $path Path to .env file
     * @return bool Success status
     */
    public static function load(string $path): bool
    {
        if (self::$loaded) {
            return true;
        }

        if (!file_exists($path)) {
            error_log("Config: .env file not found at: {$path}");
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse key=value
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes if present
                if (preg_match('/^(["\'])(.*)\1$/', $value, $matches)) {
                    $value = $matches[2];
                }

                // Store in both $_ENV and internal array
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
                self::$config[$key] = $value;
            }
        }

        self::$loaded = true;
        return true;
    }

    /**
     * Get configuration value as string
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        // Check internal array first (loaded from .env)
        if (isset(self::$config[$key])) {
            return self::$config[$key];
        }

        // Check $_ENV (system environment variables)
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        // Check getenv() as fallback
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * Get configuration value as boolean
     *
     * @param string $key Configuration key
     * @param bool $default Default value
     * @return bool
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        $value = strtolower(trim($value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Get configuration value as integer
     *
     * @param string $key Configuration key
     * @param int $default Default value
     * @return int
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value !== null ? (int)$value : $default;
    }

    /**
     * Get configuration value as float
     *
     * @param string $key Configuration key
     * @param float $default Default value
     * @return float
     */
    public static function getFloat(string $key, float $default = 0.0): float
    {
        $value = self::get($key);
        return $value !== null ? (float)$value : $default;
    }

    /**
     * Get configuration value as array (comma-separated)
     *
     * @param string $key Configuration key
     * @param array $default Default value
     * @return array
     */
    public static function getArray(string $key, array $default = []): array
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return array_map('trim', explode(',', $value));
    }

    /**
     * Set configuration value (runtime only, not persisted)
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     */
    public static function set(string $key, $value): void
    {
        self::$config[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    /**
     * Check if configuration key exists
     *
     * @param string $key Configuration key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return isset(self::$config[$key]) || isset($_ENV[$key]) || getenv($key) !== false;
    }

    /**
     * Get all configuration values
     *
     * @return array
     */
    public static function all(): array
    {
        return self::$config;
    }

    /**
     * Get required configuration value (throws exception if not set)
     *
     * @param string $key Configuration key
     * @return mixed
     * @throws \RuntimeException
     */
    public static function required(string $key)
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            throw new \RuntimeException("Required configuration key '{$key}' is not set");
        }

        return $value;
    }

    /**
     * Check if running in production environment
     *
     * @return bool
     */
    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'production') === 'production';
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public static function isDebug(): bool
    {
        return self::getBool('APP_DEBUG', false);
    }

    /**
     * Get database configuration as array
     *
     * @return array
     */
    public static function database(): array
    {
        return [
            'connection' => self::get('DB_CONNECTION', 'mysql'),
            'host' => self::get('DB_HOST', '127.0.0.1'),
            'port' => self::getInt('DB_PORT', 3306),
            'database' => self::required('DB_DATABASE'),
            'username' => self::required('DB_USERNAME'),
            'password' => self::get('DB_PASSWORD', ''),
            'charset' => self::get('DB_CHARSET', 'utf8mb4'),
            'collation' => self::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
        ];
    }

    /**
     * Build DSN string for database connection
     *
     * @return string
     */
    public static function databaseDSN(): string
    {
        $db = self::database();
        return sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $db['connection'],
            $db['host'],
            $db['port'],
            $db['database'],
            $db['charset']
        );
    }
}
