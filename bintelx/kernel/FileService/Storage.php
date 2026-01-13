<?php
# bintelx/kernel/FileService/Storage.php
namespace bX\FileService;

use bX\Config;
use bX\Log;

/**
 * Storage - Physical file storage with 3-level hash sharding
 *
 * Estructura de directorios:
 *   /<basePath>/<h0h1>/<h2h3>/<h4h5>/<HASH_COMPLETO>
 *
 * Ejemplo (hash = "a1b2c3d4e5f6..."):
 *   /var/www/bintelx/storage/a1/b2/c3/a1b2c3d4e5f6789...
 *
 * Beneficios:
 *   - Máx ~256 directorios por nivel (00-ff)
 *   - Deduplicación automática (mismo hash = mismo archivo)
 *   - Filesystem eficiente con millones de archivos
 *
 * @package bX\FileService
 */
class Storage
{
    # Hash algorithm for content addressing
    public const HASH_ALGO = 'sha256';

    # Chunk size for uploads (4 MiB default)
    public const DEFAULT_CHUNK_SIZE = 4194304;

    # Shard depth (3 levels = 6 hex chars = /a1/b2/c3/)
    public const SHARD_DEPTH = 3;

    # Base storage path (configurable via UPLOAD_PATH env)
    private static ?string $basePath = null;

    /**
     * Get base storage path
     */
    public static function getBasePath(): string
    {
        if (self::$basePath === null) {
            self::$basePath = Config::get('UPLOAD_PATH', '/var/www/bintelx/storage');
        }
        return self::$basePath;
    }

    /**
     * Set base storage path (for testing)
     */
    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/');
    }

    /**
     * Calculate content hash from file path
     *
     * @param string $filePath Path to file
     * @return string|false Hash string or false on error
     */
    public static function hashFile(string $filePath): string|false
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            Log::logError("Storage::hashFile - File not found or not readable: $filePath");
            return false;
        }

        return hash_file(self::HASH_ALGO, $filePath);
    }

    /**
     * Calculate content hash from string
     *
     * @param string $content Content to hash
     * @return string Hash string
     */
    public static function hashContent(string $content): string
    {
        return hash(self::HASH_ALGO, $content);
    }

    /**
     * Calculate shard path from hash (3 levels)
     *
     * Example: "a1b2c3d4e5f6..." → "a1/b2/c3"
     *
     * @param string $hash Full hash string
     * @return string Shard path (no trailing slash)
     */
    public static function getShardPath(string $hash): string
    {
        $parts = [];
        for ($i = 0; $i < self::SHARD_DEPTH; $i++) {
            $parts[] = substr($hash, $i * 2, 2);
        }
        return implode('/', $parts);
    }

    /**
     * Get full disk path for a hash
     *
     * @param string $hash Content hash
     * @return string Full path to file
     */
    public static function getDiskPath(string $hash): string
    {
        $shard = self::getShardPath($hash);
        return self::getBasePath() . '/' . $shard . '/' . $hash;
    }

    /**
     * Get directory path for a hash (without filename)
     *
     * @param string $hash Content hash
     * @return string Directory path
     */
    public static function getDirectoryPath(string $hash): string
    {
        $shard = self::getShardPath($hash);
        return self::getBasePath() . '/' . $shard;
    }

    /**
     * Check if a file exists by hash
     *
     * @param string $hash Content hash
     * @return bool True if file exists
     */
    public static function exists(string $hash): bool
    {
        return file_exists(self::getDiskPath($hash));
    }

    /**
     * Store file content by hash (with deduplication)
     *
     * @param string $sourcePath Source file path
     * @param string|null $expectedHash Optional expected hash (for verification)
     * @return array ['success', 'hash', 'size_bytes', 'disk_path', 'shard_path', 'deduplicated']
     */
    public static function store(string $sourcePath, ?string $expectedHash = null): array
    {
        if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
            return [
                'success' => false,
                'message' => 'Source file not found or not readable'
            ];
        }

        # Calculate hash
        $hash = self::hashFile($sourcePath);
        if ($hash === false) {
            return [
                'success' => false,
                'message' => 'Failed to calculate hash'
            ];
        }

        # Verify expected hash if provided
        if ($expectedHash !== null && $hash !== $expectedHash) {
            return [
                'success' => false,
                'message' => 'Hash mismatch',
                'expected' => $expectedHash,
                'actual' => $hash
            ];
        }

        # Check for deduplication
        $diskPath = self::getDiskPath($hash);
        if (file_exists($diskPath)) {
            Log::logDebug("Storage::store - Deduplicated: $hash");
            return [
                'success' => true,
                'hash' => $hash,
                'size_bytes' => filesize($diskPath),
                'disk_path' => $diskPath,
                'shard_path' => self::getShardPath($hash),
                'deduplicated' => true
            ];
        }

        # Create shard directories
        $dirPath = self::getDirectoryPath($hash);
        if (!is_dir($dirPath)) {
            if (!mkdir($dirPath, 0755, true)) {
                return [
                    'success' => false,
                    'message' => 'Failed to create shard directory'
                ];
            }
        }

        # Move or copy file
        $sizeBytes = filesize($sourcePath);
        if (is_uploaded_file($sourcePath)) {
            $moved = move_uploaded_file($sourcePath, $diskPath);
        } else {
            $moved = rename($sourcePath, $diskPath);
            if (!$moved) {
                # Fallback to copy if rename fails (cross-device)
                $moved = copy($sourcePath, $diskPath);
                if ($moved) {
                    unlink($sourcePath);
                }
            }
        }

        if (!$moved) {
            return [
                'success' => false,
                'message' => 'Failed to move file to storage'
            ];
        }

        Log::logInfo("Storage::store - Stored: $hash ($sizeBytes bytes)");

        return [
            'success' => true,
            'hash' => $hash,
            'size_bytes' => $sizeBytes,
            'disk_path' => $diskPath,
            'shard_path' => self::getShardPath($hash),
            'deduplicated' => false
        ];
    }

    /**
     * Store content from string
     *
     * @param string $content Content to store
     * @return array Same as store()
     */
    public static function storeContent(string $content): array
    {
        $hash = self::hashContent($content);

        # Check for deduplication
        $diskPath = self::getDiskPath($hash);
        if (file_exists($diskPath)) {
            return [
                'success' => true,
                'hash' => $hash,
                'size_bytes' => strlen($content),
                'disk_path' => $diskPath,
                'shard_path' => self::getShardPath($hash),
                'deduplicated' => true
            ];
        }

        # Create shard directories
        $dirPath = self::getDirectoryPath($hash);
        if (!is_dir($dirPath)) {
            if (!mkdir($dirPath, 0755, true)) {
                return [
                    'success' => false,
                    'message' => 'Failed to create shard directory'
                ];
            }
        }

        # Write content
        $written = file_put_contents($diskPath, $content);
        if ($written === false) {
            return [
                'success' => false,
                'message' => 'Failed to write content to storage'
            ];
        }

        return [
            'success' => true,
            'hash' => $hash,
            'size_bytes' => $written,
            'disk_path' => $diskPath,
            'shard_path' => self::getShardPath($hash),
            'deduplicated' => false
        ];
    }

    /**
     * Read file content by hash
     *
     * @param string $hash Content hash
     * @return string|false Content or false if not found
     */
    public static function read(string $hash): string|false
    {
        $diskPath = self::getDiskPath($hash);
        if (!file_exists($diskPath)) {
            return false;
        }
        return file_get_contents($diskPath);
    }

    /**
     * Get file stream by hash
     *
     * @param string $hash Content hash
     * @return resource|false Stream resource or false
     */
    public static function stream(string $hash)
    {
        $diskPath = self::getDiskPath($hash);
        if (!file_exists($diskPath)) {
            return false;
        }
        return fopen($diskPath, 'rb');
    }

    /**
     * Delete file by hash (use with caution - check references first)
     *
     * @param string $hash Content hash
     * @return bool True if deleted
     */
    public static function delete(string $hash): bool
    {
        $diskPath = self::getDiskPath($hash);
        if (!file_exists($diskPath)) {
            return true; # Already gone
        }

        $deleted = unlink($diskPath);
        if ($deleted) {
            Log::logInfo("Storage::delete - Deleted: $hash");
            # Try to clean up empty directories
            self::cleanEmptyDirs($hash);
        }

        return $deleted;
    }

    /**
     * Clean up empty shard directories after delete
     */
    private static function cleanEmptyDirs(string $hash): void
    {
        $shardPath = self::getShardPath($hash);
        $parts = explode('/', $shardPath);
        $basePath = self::getBasePath();

        # Try to remove from deepest to shallowest
        for ($i = count($parts); $i > 0; $i--) {
            $path = $basePath . '/' . implode('/', array_slice($parts, 0, $i));
            if (is_dir($path) && count(scandir($path)) === 2) { # Only . and ..
                @rmdir($path);
            } else {
                break; # Stop if directory not empty
            }
        }
    }

    /**
     * Get file info by hash
     *
     * @param string $hash Content hash
     * @return array|null File info or null if not found
     */
    public static function info(string $hash): ?array
    {
        $diskPath = self::getDiskPath($hash);
        if (!file_exists($diskPath)) {
            return null;
        }

        return [
            'hash' => $hash,
            'disk_path' => $diskPath,
            'shard_path' => self::getShardPath($hash),
            'size_bytes' => filesize($diskPath),
            'modified_at' => date('c', filemtime($diskPath)),
            'mime_type' => mime_content_type($diskPath) ?: 'application/octet-stream'
        ];
    }

    /**
     * Verify file integrity
     *
     * @param string $hash Expected hash
     * @return bool True if file exists and hash matches
     */
    public static function verify(string $hash): bool
    {
        $diskPath = self::getDiskPath($hash);
        if (!file_exists($diskPath)) {
            return false;
        }

        $actualHash = hash_file(self::HASH_ALGO, $diskPath);
        return $actualHash === $hash;
    }
}
