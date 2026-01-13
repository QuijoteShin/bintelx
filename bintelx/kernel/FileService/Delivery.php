<?php
# bintelx/kernel/FileService/Delivery.php
namespace bX\FileService;

use bX\CONN;
use bX\Profile;
use bX\Tenant;
use bX\Log;

/**
 * Delivery - File access control and delivery
 *
 * Handles:
 *   - Permission checks
 *   - Public link validation
 *   - File streaming
 *   - Delivery audit logging
 *
 * All methods follow Bintelx signature: (array $data, array $options, ?callable $callback)
 *
 * @package bX\FileService
 */
class Delivery
{
    # Reason codes for audit
    public const REASON_OK = 'OK';
    public const REASON_NOT_FOUND = 'NOT_FOUND';
    public const REASON_NO_PERMISSION = 'NO_PERMISSION';
    public const REASON_EXPIRED = 'EXPIRED';
    public const REASON_BAD_CODE = 'BAD_CODE';
    public const REASON_LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';

    /**
     * Resolve delivery - check permissions and return file info
     *
     * @param array $data ['document_id' => int]
     * @param array $options ['scope_entity_id' => int]
     * @return array ['allowed', 'reason', 'file_info']
     */
    public static function resolve(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $documentId = (int)($data['document_id'] ?? 0);

        if ($documentId <= 0) {
            return self::deny(0, self::REASON_NOT_FOUND, $options);
        }

        # Get document
        $doc = Document::get(['document_id' => $documentId], $options);
        if (!$doc['success']) {
            self::logDelivery($documentId, false, self::REASON_NOT_FOUND, $options);
            return ['allowed' => false, 'reason' => self::REASON_NOT_FOUND];
        }

        $docData = $doc['data'];

        # Check if deleted
        if ($docData['status'] === 'deleted') {
            self::logDelivery($documentId, false, self::REASON_NOT_FOUND, $options);
            return ['allowed' => false, 'reason' => self::REASON_NOT_FOUND];
        }

        # Check explicit permissions
        if (self::hasPermission($documentId, Profile::$profile_id)) {
            self::logDelivery($documentId, true, self::REASON_OK, $options);
            return self::allow($docData);
        }

        # Check if owner (created_by)
        if ($docData['created_by'] == Profile::$profile_id) {
            self::logDelivery($documentId, true, self::REASON_OK, $options);
            return self::allow($docData);
        }

        # Tenant-level access (same scope = can read)
        # This is the default for multi-tenant - all users in scope can read
        self::logDelivery($documentId, true, self::REASON_OK, $options);
        return self::allow($docData);
    }

    /**
     * Resolve delivery via public link
     *
     * @param array $data [
     *   'link_id' => string,
     *   'access_code' => string (optional)
     * ]
     * @return array ['allowed', 'reason', 'file_info']
     */
    public static function resolvePublicLink(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $linkId = $data['link_id'] ?? '';
        $accessCode = $data['access_code'] ?? null;

        if (empty($linkId)) {
            return ['allowed' => false, 'reason' => self::REASON_NOT_FOUND];
        }

        # Get link
        $sql = "SELECT pl.*, d.status as doc_status, d.disk_path, d.original_name,
                       d.mime_type, d.size_bytes, d.scope_entity_id
                FROM file_public_links pl
                JOIN file_documents d ON pl.document_id = d.document_id
                WHERE pl.link_id = :id
                LIMIT 1";

        $link = null;
        CONN::dml($sql, [':id' => $linkId], function($row) use (&$link) {
            $link = $row;
            return false;
        });

        if (!$link) {
            return ['allowed' => false, 'reason' => self::REASON_NOT_FOUND];
        }

        $documentId = $link['document_id'];
        $scopeOptions = ['scope_entity_id' => $link['scope_entity_id']];

        # Check document status
        if ($link['doc_status'] === 'deleted') {
            self::logDelivery($documentId, false, self::REASON_NOT_FOUND, $scopeOptions, $linkId);
            return ['allowed' => false, 'reason' => self::REASON_NOT_FOUND];
        }

        # Check expiration
        if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
            self::logDelivery($documentId, false, self::REASON_EXPIRED, $scopeOptions, $linkId);
            return ['allowed' => false, 'reason' => self::REASON_EXPIRED];
        }

        # Check download limit
        if ($link['download_limit'] && $link['downloads_count'] >= $link['download_limit']) {
            self::logDelivery($documentId, false, self::REASON_LIMIT_EXCEEDED, $scopeOptions, $linkId);
            return ['allowed' => false, 'reason' => self::REASON_LIMIT_EXCEEDED];
        }

        # Check access code
        if ($link['access_code_hash']) {
            if (!$accessCode || !password_verify($accessCode, $link['access_code_hash'])) {
                self::logDelivery($documentId, false, self::REASON_BAD_CODE, $scopeOptions, $linkId);
                return ['allowed' => false, 'reason' => self::REASON_BAD_CODE];
            }
        }

        # Increment download count
        CONN::nodml(
            "UPDATE file_public_links SET downloads_count = downloads_count + 1 WHERE link_id = :id",
            [':id' => $linkId]
        );

        self::logDelivery($documentId, true, self::REASON_OK, $scopeOptions, $linkId);

        return [
            'allowed' => true,
            'reason' => self::REASON_OK,
            'file_info' => [
                'document_id' => $documentId,
                'disk_path' => $link['disk_path'],
                'original_name' => $link['original_name'],
                'mime_type' => $link['mime_type'],
                'size_bytes' => $link['size_bytes']
            ]
        ];
    }

    /**
     * Create public link for document
     *
     * @param array $data [
     *   'document_id' => int,
     *   'expires_in' => int (seconds, optional),
     *   'access_code' => string (optional),
     *   'download_limit' => int (optional)
     * ]
     * @param array $options ['scope_entity_id' => int]
     * @return array ['success', 'link_id', 'url']
     */
    public static function createPublicLink(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $documentId = (int)($data['document_id'] ?? 0);

        if ($documentId <= 0) {
            return ['success' => false, 'message' => 'document_id is required'];
        }

        # Verify document exists
        $doc = Document::get(['document_id' => $documentId], $options);
        if (!$doc['success']) {
            return $doc;
        }

        # Generate link ID
        $linkId = bin2hex(random_bytes(32));

        # Calculate expiration
        $expiresAt = null;
        if (!empty($data['expires_in'])) {
            $expiresAt = date('Y-m-d H:i:s', time() + (int)$data['expires_in']);
        }

        # Hash access code if provided
        $accessCodeHash = null;
        if (!empty($data['access_code'])) {
            $accessCodeHash = password_hash($data['access_code'], PASSWORD_DEFAULT);
        }

        $downloadLimit = isset($data['download_limit']) ? (int)$data['download_limit'] : null;

        $sql = "INSERT INTO file_public_links
                (link_id, document_id, expires_at, access_code_hash, download_limit, created_by)
                VALUES (:link_id, :doc_id, :expires, :code_hash, :limit, :created_by)";

        $result = CONN::nodml($sql, [
            ':link_id' => $linkId,
            ':doc_id' => $documentId,
            ':expires' => $expiresAt,
            ':code_hash' => $accessCodeHash,
            ':limit' => $downloadLimit,
            ':created_by' => Profile::$profile_id ?: null
        ]);

        if (!$result['success']) {
            return ['success' => false, 'message' => 'Failed to create public link'];
        }

        Log::logInfo("Delivery::createPublicLink - Created link $linkId for document $documentId");

        return [
            'success' => true,
            'link_id' => $linkId,
            'expires_at' => $expiresAt,
            'has_access_code' => $accessCodeHash !== null
        ];
    }

    /**
     * Stream file to output
     *
     * @param array $data ['document_id' => int] or ['disk_path' => string]
     * @param array $options
     */
    public static function stream(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): void {
        $diskPath = $data['disk_path'] ?? null;
        $originalName = $data['original_name'] ?? 'download';
        $mimeType = $data['mime_type'] ?? 'application/octet-stream';
        $sizeBytes = $data['size_bytes'] ?? null;
        $inline = $data['inline'] ?? false;

        if (!$diskPath || !file_exists($diskPath)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            return;
        }

        if ($sizeBytes === null) {
            $sizeBytes = filesize($diskPath);
        }

        # Set headers
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $sizeBytes);

        if ($inline) {
            header('Content-Disposition: inline; filename="' . addslashes($originalName) . '"');
        } else {
            header('Content-Disposition: attachment; filename="' . addslashes($originalName) . '"');
        }

        # Disable output buffering for large files
        if (ob_get_level()) {
            ob_end_clean();
        }

        # Stream file
        readfile($diskPath);
    }

    /**
     * Grant permission to profile
     *
     * @param array $data [
     *   'document_id' => int,
     *   'profile_id' => int,
     *   'role' => string (reader, writer, editor, owner)
     * ]
     * @param array $options ['scope_entity_id' => int]
     * @return array ['success']
     */
    public static function grant(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $documentId = (int)($data['document_id'] ?? 0);
        $profileId = (int)($data['profile_id'] ?? 0);
        $role = $data['role'] ?? 'reader';

        if ($documentId <= 0 || $profileId <= 0) {
            return ['success' => false, 'message' => 'document_id and profile_id are required'];
        }

        $sql = "INSERT INTO file_permissions
                (document_id, principal_type, principal_id, role, granted_by)
                VALUES (:doc, 'profile', :profile, :role, :granted_by)
                ON DUPLICATE KEY UPDATE role = :role2, granted_by = :granted_by2";

        $result = CONN::nodml($sql, [
            ':doc' => $documentId,
            ':profile' => $profileId,
            ':role' => $role,
            ':role2' => $role,
            ':granted_by' => Profile::$profile_id ?: null,
            ':granted_by2' => Profile::$profile_id ?: null
        ]);

        return ['success' => $result['success']];
    }

    /**
     * Revoke permission
     */
    public static function revoke(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $documentId = (int)($data['document_id'] ?? 0);
        $profileId = (int)($data['profile_id'] ?? 0);

        if ($documentId <= 0 || $profileId <= 0) {
            return ['success' => false, 'message' => 'document_id and profile_id are required'];
        }

        $sql = "DELETE FROM file_permissions
                WHERE document_id = :doc AND principal_type = 'profile' AND principal_id = :profile";

        CONN::nodml($sql, [':doc' => $documentId, ':profile' => $profileId]);

        return ['success' => true];
    }

    /**
     * Check if profile has permission
     */
    private static function hasPermission(int $documentId, ?int $profileId): bool
    {
        if (!$profileId) {
            return false;
        }

        $sql = "SELECT id FROM file_permissions
                WHERE document_id = :doc
                  AND principal_type = 'profile'
                  AND principal_id = :profile
                  AND (expires_at IS NULL OR expires_at > NOW())
                LIMIT 1";

        $result = null;
        CONN::dml($sql, [':doc' => $documentId, ':profile' => $profileId], function($row) use (&$result) {
            $result = $row;
            return false;
        });

        return $result !== null;
    }

    /**
     * Build allow response
     */
    private static function allow(array $docData): array
    {
        return [
            'allowed' => true,
            'reason' => self::REASON_OK,
            'file_info' => [
                'document_id' => $docData['document_id'],
                'disk_path' => $docData['disk_path'],
                'original_name' => $docData['original_name'],
                'mime_type' => $docData['mime_type'],
                'size_bytes' => $docData['size_bytes']
            ]
        ];
    }

    /**
     * Build deny response
     */
    private static function deny(int $documentId, string $reason, array $options): array
    {
        self::logDelivery($documentId, false, $reason, $options);
        return ['allowed' => false, 'reason' => $reason];
    }

    /**
     * Log delivery attempt
     */
    private static function logDelivery(
        int $documentId,
        bool $allowed,
        string $reason,
        array $options,
        ?string $linkId = null
    ): void {
        $sql = "INSERT INTO file_delivery_log
                (document_id, principal_type, principal_id, public_link_id,
                 allowed, reason_code, delivery_method, ip_address, user_agent, scope_entity_id)
                VALUES
                (:doc, :ptype, :pid, :link,
                 :allowed, :reason, :method, :ip, :ua, :scope)";

        CONN::nodml($sql, [
            ':doc' => $documentId,
            ':ptype' => Profile::$profile_id ? 'profile' : null,
            ':pid' => Profile::$profile_id ?: null,
            ':link' => $linkId,
            ':allowed' => $allowed ? 1 : 0,
            ':reason' => $reason,
            ':method' => $allowed ? 'stream' : null,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':scope' => $options['scope_entity_id'] ?? null
        ]);
    }
}
