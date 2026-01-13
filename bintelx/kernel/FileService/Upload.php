<?php
# bintelx/kernel/FileService/Upload.php
namespace bX\FileService;

use bX\CONN;
use bX\Profile;
use bX\Tenant;
use bX\Log;
use bX\Config;

/**
 * Upload - Chunked/resumable upload session management
 *
 * Flow:
 *   1. POST /uploads/check   - Hash pre-check (deduplication)
 *   2. POST /uploads/init    - Create session, get uploadId
 *   3. PUT  /uploads/{id}/chunk (x N times, 4-16 MiB each)
 *   4. POST /uploads/{id}/complete - Assemble final file
 *
 * All methods follow Bintelx signature: (array $data, array $options, ?callable $callback)
 *
 * @package bX\FileService
 */
class Upload
{
    # Session status constants
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABORTED = 'aborted';
    public const STATUS_EXPIRED = 'expired';

    # Default session expiration (24 hours)
    public const DEFAULT_EXPIRATION = 86400;

    # Temp directory for chunks
    private static ?string $tempPath = null;

    /**
     * Get temp path for chunks
     */
    private static function getTempPath(): string
    {
        if (self::$tempPath === null) {
            $base = Config::get('UPLOAD_PATH', '/var/www/bintelx/storage');
            self::$tempPath = $base . '/.chunks';
            if (!is_dir(self::$tempPath)) {
                mkdir(self::$tempPath, 0755, true);
            }
        }
        return self::$tempPath;
    }

    /**
     * Check if hash already exists (deduplication pre-check)
     *
     * @param array $data ['hash' => string]
     * @param array $options ['scope_entity_id' => int]
     * @return array ['exists' => bool, 'document_id' => int|null]
     */
    public static function check(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $hash = $data['hash'] ?? '';

        if (empty($hash)) {
            return ['success' => false, 'message' => 'hash is required'];
        }

        # Check physical storage
        $exists = Storage::exists($hash);

        if (!$exists) {
            return [
                'success' => true,
                'exists' => false,
                'document_id' => null
            ];
        }

        # Check if document exists for this tenant
        $tenant = Tenant::validateForWrite($options);
        if (!$tenant['valid']) {
            return ['success' => false, 'message' => $tenant['error']];
        }

        $sql = "SELECT document_id FROM file_documents
                WHERE storage_key = :hash AND status != 'deleted'";
        $params = [':hash' => $hash];

        $sql .= Tenant::whereClause('scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));
        $sql .= " LIMIT 1";

        $doc = null;
        CONN::dml($sql, $params, function($row) use (&$doc) {
            $doc = $row;
            return false;
        });

        return [
            'success' => true,
            'exists' => true,
            'document_id' => $doc ? (int)$doc['document_id'] : null,
            'storage_exists' => true
        ];
    }

    /**
     * Initialize upload session
     *
     * @param array $data [
     *   'hash' => string (expected hash),
     *   'size_bytes' => int,
     *   'original_name' => string,
     *   'mime_type' => string,
     *   'chunk_size' => int (optional, default 4MiB)
     * ]
     * @param array $options ['scope_entity_id' => int]
     * @return array ['success', 'upload_id', 'chunk_size', 'total_chunks']
     */
    public static function init(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        # Validate required fields
        if (empty($data['hash'])) {
            return ['success' => false, 'message' => 'hash is required'];
        }
        if (empty($data['size_bytes']) || $data['size_bytes'] <= 0) {
            return ['success' => false, 'message' => 'size_bytes is required'];
        }
        if (empty($data['original_name'])) {
            return ['success' => false, 'message' => 'original_name is required'];
        }

        # Validate tenant
        $tenant = Tenant::validateForWrite($options);
        if (!$tenant['valid']) {
            return ['success' => false, 'message' => $tenant['error']];
        }

        $hash = $data['hash'];
        $sizeBytes = (int)$data['size_bytes'];
        $originalName = $data['original_name'];
        $mimeType = $data['mime_type'] ?? 'application/octet-stream';
        $chunkSize = (int)($data['chunk_size'] ?? Storage::DEFAULT_CHUNK_SIZE);

        # Calculate total chunks
        $totalChunks = (int)ceil($sizeBytes / $chunkSize);

        # Generate upload ID
        $uploadId = bin2hex(random_bytes(32));

        # Calculate expiration
        $expiresAt = date('Y-m-d H:i:s', time() + self::DEFAULT_EXPIRATION);

        # Create session record
        $sql = "INSERT INTO file_upload_sessions
                (upload_id, storage_key, total_size, chunk_size, total_chunks,
                 received_chunks, next_offset, original_name, mime_type,
                 scope_entity_id, created_by, status, expires_at)
                VALUES
                (:upload_id, :hash, :size, :chunk_size, :total_chunks,
                 '[]', 0, :name, :mime, :scope, :created_by, 'active', :expires)";

        $result = CONN::nodml($sql, [
            ':upload_id' => $uploadId,
            ':hash' => $hash,
            ':size' => $sizeBytes,
            ':chunk_size' => $chunkSize,
            ':total_chunks' => $totalChunks,
            ':name' => $originalName,
            ':mime' => $mimeType,
            ':scope' => $tenant['scope'],
            ':created_by' => Profile::$profile_id ?: null,
            ':expires' => $expiresAt
        ]);

        if (!$result['success']) {
            return ['success' => false, 'message' => 'Failed to create upload session'];
        }

        # Create temp directory for this upload
        $tempDir = self::getTempPath() . '/' . $uploadId;
        if (!mkdir($tempDir, 0755, true)) {
            return ['success' => false, 'message' => 'Failed to create temp directory'];
        }

        Log::logInfo("Upload::init - Session created: $uploadId for $originalName");

        return [
            'success' => true,
            'upload_id' => $uploadId,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'expires_at' => $expiresAt
        ];
    }

    /**
     * Receive a chunk
     *
     * @param array $data [
     *   'upload_id' => string,
     *   'chunk_index' => int,
     *   'chunk_data' => string (binary content)
     * ]
     * @param array $options ['scope_entity_id' => int]
     * @return array ['success', 'received', 'remaining']
     */
    public static function chunk(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $uploadId = $data['upload_id'] ?? '';
        $chunkIndex = (int)($data['chunk_index'] ?? -1);
        $chunkData = $data['chunk_data'] ?? '';

        if (empty($uploadId)) {
            return ['success' => false, 'message' => 'upload_id is required'];
        }
        if ($chunkIndex < 0) {
            return ['success' => false, 'message' => 'chunk_index is required'];
        }
        if (empty($chunkData)) {
            return ['success' => false, 'message' => 'chunk_data is required'];
        }

        # Get session
        $session = self::getSession($uploadId);
        if (!$session) {
            return ['success' => false, 'message' => 'Upload session not found'];
        }

        if ($session['status'] !== self::STATUS_ACTIVE) {
            return ['success' => false, 'message' => 'Upload session is not active'];
        }

        # Check expiration
        if (strtotime($session['expires_at']) < time()) {
            self::updateStatus($uploadId, self::STATUS_EXPIRED);
            return ['success' => false, 'message' => 'Upload session expired'];
        }

        # Validate chunk index
        if ($chunkIndex >= $session['total_chunks']) {
            return ['success' => false, 'message' => 'Invalid chunk index'];
        }

        # Write chunk to temp directory
        $tempDir = self::getTempPath() . '/' . $uploadId;
        $chunkFile = $tempDir . '/chunk_' . str_pad($chunkIndex, 6, '0', STR_PAD_LEFT);

        $written = file_put_contents($chunkFile, $chunkData);
        if ($written === false) {
            return ['success' => false, 'message' => 'Failed to write chunk'];
        }

        # Update received chunks
        $receivedChunks = json_decode($session['received_chunks'], true) ?: [];
        if (!in_array($chunkIndex, $receivedChunks)) {
            $receivedChunks[] = $chunkIndex;
            sort($receivedChunks);
        }

        $sql = "UPDATE file_upload_sessions
                SET received_chunks = :chunks,
                    next_offset = :offset
                WHERE upload_id = :id";

        CONN::nodml($sql, [
            ':chunks' => json_encode($receivedChunks),
            ':offset' => ($chunkIndex + 1) * $session['chunk_size'],
            ':id' => $uploadId
        ]);

        $remaining = $session['total_chunks'] - count($receivedChunks);

        return [
            'success' => true,
            'chunk_index' => $chunkIndex,
            'received' => count($receivedChunks),
            'remaining' => $remaining,
            'complete' => $remaining === 0
        ];
    }

    /**
     * Complete upload - assemble chunks and store
     *
     * @param array $data ['upload_id' => string]
     * @param array $options ['scope_entity_id' => int]
     * @return array ['success', 'hash', 'document_id']
     */
    public static function complete(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $uploadId = $data['upload_id'] ?? '';

        if (empty($uploadId)) {
            return ['success' => false, 'message' => 'upload_id is required'];
        }

        # Get session
        $session = self::getSession($uploadId);
        if (!$session) {
            return ['success' => false, 'message' => 'Upload session not found'];
        }

        if ($session['status'] !== self::STATUS_ACTIVE) {
            return ['success' => false, 'message' => 'Upload session is not active'];
        }

        # Verify all chunks received
        $receivedChunks = json_decode($session['received_chunks'], true) ?: [];
        if (count($receivedChunks) < $session['total_chunks']) {
            return [
                'success' => false,
                'message' => 'Not all chunks received',
                'received' => count($receivedChunks),
                'expected' => $session['total_chunks']
            ];
        }

        # Assemble file
        $tempDir = self::getTempPath() . '/' . $uploadId;
        $assembledFile = $tempDir . '/assembled';

        $outHandle = fopen($assembledFile, 'wb');
        if (!$outHandle) {
            return ['success' => false, 'message' => 'Failed to create assembled file'];
        }

        for ($i = 0; $i < $session['total_chunks']; $i++) {
            $chunkFile = $tempDir . '/chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT);
            if (!file_exists($chunkFile)) {
                fclose($outHandle);
                return ['success' => false, 'message' => "Missing chunk $i"];
            }
            $chunkData = file_get_contents($chunkFile);
            fwrite($outHandle, $chunkData);
        }
        fclose($outHandle);

        # Verify hash
        $actualHash = hash_file(Storage::HASH_ALGO, $assembledFile);
        if ($actualHash !== $session['storage_key']) {
            # Cleanup
            self::cleanup($uploadId);
            return [
                'success' => false,
                'message' => 'Hash mismatch after assembly',
                'expected' => $session['storage_key'],
                'actual' => $actualHash
            ];
        }

        # Store in sharded storage
        $storeResult = Storage::store($assembledFile, $actualHash);
        if (!$storeResult['success']) {
            return $storeResult;
        }

        # Create document record
        $docResult = Document::create([
            'storage_key' => $actualHash,
            'hash' => $actualHash,
            'size_bytes' => $session['total_size'],
            'mime_type' => $session['mime_type'],
            'original_name' => $session['original_name']
        ], $options);

        if (!$docResult['success']) {
            return $docResult;
        }

        # Update session status
        self::updateStatus($uploadId, self::STATUS_COMPLETED);

        # Cleanup temp files
        self::cleanup($uploadId);

        Log::logInfo("Upload::complete - Completed: $uploadId → document {$docResult['document_id']}");

        return [
            'success' => true,
            'hash' => $actualHash,
            'document_id' => $docResult['document_id'],
            'disk_path' => $storeResult['disk_path'],
            'deduplicated' => $storeResult['deduplicated']
        ];
    }

    /**
     * Abort upload session
     *
     * @param array $data ['upload_id' => string]
     * @return array ['success']
     */
    public static function abort(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $uploadId = $data['upload_id'] ?? '';

        if (empty($uploadId)) {
            return ['success' => false, 'message' => 'upload_id is required'];
        }

        self::updateStatus($uploadId, self::STATUS_ABORTED);
        self::cleanup($uploadId);

        Log::logInfo("Upload::abort - Aborted: $uploadId");

        return ['success' => true, 'message' => 'Upload aborted'];
    }

    /**
     * Get upload session status
     *
     * @param array $data ['upload_id' => string]
     * @return array Session info
     */
    public static function status(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $uploadId = $data['upload_id'] ?? '';

        if (empty($uploadId)) {
            return ['success' => false, 'message' => 'upload_id is required'];
        }

        $session = self::getSession($uploadId);
        if (!$session) {
            return ['success' => false, 'message' => 'Upload session not found'];
        }

        $receivedChunks = json_decode($session['received_chunks'], true) ?: [];

        return [
            'success' => true,
            'upload_id' => $uploadId,
            'status' => $session['status'],
            'total_chunks' => (int)$session['total_chunks'],
            'received_chunks' => count($receivedChunks),
            'missing_chunks' => array_diff(range(0, $session['total_chunks'] - 1), $receivedChunks),
            'next_offset' => (int)$session['next_offset'],
            'expires_at' => $session['expires_at']
        ];
    }

    /**
     * Get session from database
     */
    private static function getSession(string $uploadId): ?array
    {
        $sql = "SELECT * FROM file_upload_sessions WHERE upload_id = :id LIMIT 1";
        $session = null;
        CONN::dml($sql, [':id' => $uploadId], function($row) use (&$session) {
            $session = $row;
            return false;
        });
        return $session;
    }

    /**
     * Update session status
     */
    private static function updateStatus(string $uploadId, string $status): void
    {
        $sql = "UPDATE file_upload_sessions SET status = :status WHERE upload_id = :id";
        CONN::nodml($sql, [':status' => $status, ':id' => $uploadId]);
    }

    /**
     * Cleanup temp files
     */
    private static function cleanup(string $uploadId): void
    {
        $tempDir = self::getTempPath() . '/' . $uploadId;
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($tempDir);
        }
    }

    /**
     * Cleanup expired sessions (run via cron)
     *
     * @return array ['cleaned' => int]
     */
    public static function cleanupExpired(): array
    {
        $sql = "SELECT upload_id FROM file_upload_sessions
                WHERE status = 'active' AND expires_at < NOW()";

        $expired = [];
        CONN::dml($sql, [], function($row) use (&$expired) {
            $expired[] = $row['upload_id'];
        });

        foreach ($expired as $uploadId) {
            self::updateStatus($uploadId, self::STATUS_EXPIRED);
            self::cleanup($uploadId);
        }

        Log::logInfo("Upload::cleanupExpired - Cleaned " . count($expired) . " sessions");

        return ['success' => true, 'cleaned' => count($expired)];
    }
}
