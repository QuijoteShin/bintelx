<?php # bintelx/kernel/Crypto.php
namespace bX;

/**
 * Utilidades de encriptación y hashing
 *
 * Soporta:
 * - XOR encryption (bin_encrypt, bin_decrypt)
 * - xxHash (xxh32, xxh64, xxh128) via shell wrapper
 */
class Crypto {
    /**
     * Encripta usando XOR repetido. Retorna binario puro (misma longitud que $plain).
     */
    public static function bin_encrypt(string $plain, string $key): string
    {
        $cipher = '';
        $keyLen = strlen($key);
        for ($i = 0, $len = strlen($plain); $i < $len; $i++) {
            $cipher .= $plain[$i] ^ $key[$i % $keyLen];
        }
        return $cipher; # Binario
    }

    /**
     * Desencripta usando XOR repetido. Retorna binario puro (misma longitud que $cipher).
     */
    public static function bin_decrypt(string $cipher, string $key): string
    {
        # Es el mismo proceso de encrypt, ya que XOR es reversible.
        return self::bin_encrypt($cipher, $key);
    }

    /**
     * xxHash 32-bit via shell command
     *
     * @param string $data Data to hash
     * @return string|false Hex hash string or false on failure
     */
    # Channel-safe: usa hash() nativo (PHP 8.1+) en vez de shell_exec que bloquea el event loop
    public static function xxh32(string $data): string|false
    {
        return hash('xxh32', $data);
    }

    /**
     * xxHash 64-bit via shell command
     *
     * @param string $data Data to hash
     * @return string|false Hex hash string or false on failure
     */
    public static function xxh64(string $data): string|false
    {
        return hash('xxh64', $data);
    }

    /**
     * xxHash 128-bit via shell command
     *
     * @param string $data Data to hash
     * @return string|false Hex hash string or false on failure
     */
    public static function xxh128(string $data): string|false
    {
        return hash('xxh128', $data);
    }

    /**
     * xxHash file (auto-detect best algorithm)
     *
     * @param string $filePath Path to file
     * @param string $algorithm Algorithm (32, 64, 128)
     * @return string|false Hex hash string or false on failure
     */
    public static function xxhFile(string $filePath, string $algorithm = '64'): string|false
    {
        if (!file_exists($filePath)) {
            Log::logError("Crypto::xxhFile - File not found", ['path' => $filePath]);
            return false;
        }

        $hashFlag = match($algorithm) {
            '32' => '-H32',
            '64' => '-H64',
            '128' => '-H128',
            default => '-H64'
        };

        $command = sprintf('xxhsum %s %s', $hashFlag, escapeshellarg($filePath));
        $output = shell_exec($command);

        if ($output === null || $output === false) {
            Log::logError("Crypto::xxhFile - Command failed", [
                'command' => $command,
                'file' => $filePath
            ]);
            return false;
        }

        # Output format: "hash  filename"
        $parts = explode(' ', trim($output));
        return $parts[0] ?? false;
    }

    /**
     * Generate unique ID using xxHash128
     * Útil para IDs rápidos basados en timestamp + random
     *
     * @return string 32-char hex ID
     */
    public static function generateUniqueId(): string
    {
        $data = microtime(true) . random_bytes(16) . getmypid();
        return self::xxh128($data) ?: bin2hex(random_bytes(16));
    }
}
