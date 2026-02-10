<?php
# bintelx/kernel/FileService/Document.php
namespace bX\FileService;

use bX\CONN;
use bX\Profile;
use bX\Tenant;
use bX\Log;

/**
 * Document - Logical document registry with metadata
 *
 * Manages file_documents table:
 *   - Registro lógico del documento (metadatos)
 *   - Vínculo a storage físico via storage_key (hash)
 *   - Multi-tenant via scope_entity_id
 *   - Soft delete con status
 *
 * All methods follow Bintelx signature: (array $data, array $options, ?callable $callback)
 *
 * @package bX\FileService
 */
class Document
{
    # Status constants
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_STORED = 'stored';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_QUARANTINED = 'quarantined';
    public const STATUS_DELETED = 'deleted';

    /**
     * Create document record
     *
     * @param array $data [
     *   'storage_key' => string (hash),
     *   'hash' => string,
     *   'size_bytes' => int,
     *   'mime_type' => string,
     *   'original_name' => string,
     *   'relative_path' => string (optional),
     *   'category' => string (optional),
     *   'tags' => array (optional)
     * ]
     * @param array $options ['scope_entity_id' => int]
     * @return array ['success', 'document_id']
     */
    public static function create(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        # Validate required fields
        if (empty($data['storage_key'])) {
            return ['success' => false, 'message' => 'storage_key is required'];
        }
        if (empty($data['hash'])) {
            return ['success' => false, 'message' => 'hash is required'];
        }

        # Validate tenant
        $tenant = Tenant::validateForWrite($options);
        if (!$tenant['valid']) {
            return ['success' => false, 'message' => $tenant['error']];
        }

        $storageKey = $data['storage_key'];
        $hash = $data['hash'];
        $sizeBytes = (int)($data['size_bytes'] ?? 0);
        $mimeType = $data['mime_type'] ?? 'application/octet-stream';
        $originalName = $data['original_name'] ?? 'unnamed';
        $relativePath = $data['relative_path'] ?? null;
        $category = $data['category'] ?? null;
        $tags = isset($data['tags']) ? json_encode($data['tags']) : null;

        # Check for existing document with same hash in same scope (deduplication)
        $existingSql = "SELECT document_id FROM file_documents
                        WHERE storage_key = :hash AND status != 'deleted'";
        $existingParams = [':hash' => $storageKey];
        $existingSql .= Tenant::whereClause('scope_entity_id', $options);
        $existingParams = array_merge($existingParams, Tenant::params($options));
        $existingSql .= " LIMIT 1";

        $existing = null;
        CONN::dml($existingSql, $existingParams, function($row) use (&$existing) {
            $existing = $row;
            return false;
        });

        if ($existing) {
            Log::logDebug("Document::create - Deduplicated: returning existing document {$existing['document_id']}");
            return [
                'success' => true,
                'document_id' => (int)$existing['document_id'],
                'storage_key' => $storageKey,
                'deduplicated' => true
            ];
        }

        # Get storage info
        $diskPath = Storage::getDiskPath($hash);
        $shardPath = Storage::getShardPath($hash);

        # Check for soft-deleted document with same key+scope (unique constraint)
        $deletedSql = "SELECT document_id FROM file_documents
                        WHERE storage_key = :hash AND status = 'deleted'";
        $deletedParams = [':hash' => $storageKey];
        $deletedSql .= Tenant::whereClause('scope_entity_id', $options);
        $deletedParams = array_merge($deletedParams, Tenant::params($options));
        $deletedSql .= " LIMIT 1";

        $deletedDoc = null;
        CONN::dml($deletedSql, $deletedParams, function($row) use (&$deletedDoc) {
            $deletedDoc = $row;
            return false;
        });

        if ($deletedDoc) {
            # Revive soft-deleted document
            $reviveSql = "UPDATE file_documents SET
                            status = 'stored', mime_type = :mime, original_name = :name,
                            size_bytes = :size, created_by = :created_by
                          WHERE document_id = :id";
            CONN::nodml($reviveSql, [
                ':mime' => $mimeType,
                ':name' => $originalName,
                ':size' => $sizeBytes,
                ':created_by' => Profile::$profile_id ?: null,
                ':id' => $deletedDoc['document_id']
            ]);

            $documentId = (int)$deletedDoc['document_id'];
            Log::logInfo("Document::create - Revived deleted document $documentId for $originalName");

            return [
                'success' => true,
                'document_id' => $documentId,
                'storage_key' => $storageKey,
                'revived' => true
            ];
        }

        $sql = "INSERT INTO file_documents
                (storage_key, hash_algo, hash, size_bytes, mime_type,
                 original_name, relative_path, storage_provider, disk_path, shard_path,
                 scope_entity_id, category, tags, status, created_by)
                VALUES
                (:storage_key, :algo, :hash, :size, :mime,
                 :name, :path, 'filesystem', :disk, :shard,
                 :scope, :category, :tags, 'stored', :created_by)";

        $result = CONN::nodml($sql, [
            ':storage_key' => $storageKey,
            ':algo' => Storage::HASH_ALGO,
            ':hash' => $hash,
            ':size' => $sizeBytes,
            ':mime' => $mimeType,
            ':name' => $originalName,
            ':path' => $relativePath,
            ':disk' => $diskPath,
            ':shard' => $shardPath,
            ':scope' => $tenant['scope'],
            ':category' => $category,
            ':tags' => $tags,
            ':created_by' => Profile::$profile_id ?: null
        ]);

        if (!$result['success']) {
            return ['success' => false, 'message' => 'Failed to create document record'];
        }

        $documentId = (int)$result['last_id'];

        Log::logInfo("Document::create - Created document $documentId for $originalName");

        return [
            'success' => true,
            'document_id' => $documentId,
            'storage_key' => $storageKey
        ];
    }

    /**
     * Get document by ID
     *
     * @param array $data ['document_id' => int]
     * @param array $options ['scope_entity_id' => int]
     * @return array Document data or error
     */
    public static function get(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $documentId = (int)($data['document_id'] ?? 0);

        if ($documentId <= 0) {
            return ['success' => false, 'message' => 'document_id is required'];
        }

        $sql = "SELECT * FROM file_documents WHERE document_id = :id";
        $params = [':id' => $documentId];

        # Apply tenant filter
        $sql .= Tenant::whereClause('scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));
        $sql .= " LIMIT 1";

        $doc = null;
        CONN::dml($sql, $params, function($row) use (&$doc) {
            $doc = $row;
            return false;
        });

        if (!$doc) {
            return ['success' => false, 'message' => 'Document not found'];
        }

        # Parse JSON fields
        if (!empty($doc['tags'])) {
            $doc['tags'] = json_decode($doc['tags'], true);
        }

        return [
            'success' => true,
            'data' => $doc
        ];
    }

    /**
     * List documents with filters
     *
     * @param array $data [
     *   'search' => string (optional),
     *   'category' => string (optional),
     *   'mime_type' => string (optional),
     *   'status' => string (optional, default: not deleted),
     *   'limit' => int (default: 50),
     *   'offset' => int (default: 0)
     * ]
     * @param array $options ['scope_entity_id' => int]
     * @param callable|null $callback For streaming
     * @return array List of documents
     */
    public static function list(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $search = $data['search'] ?? null;
        $category = $data['category'] ?? null;
        $mimeType = $data['mime_type'] ?? null;
        $status = $data['status'] ?? null;
        $limit = (int)($data['limit'] ?? 50);
        $offset = (int)($data['offset'] ?? 0);

        $sql = "SELECT document_id, storage_key, original_name, mime_type,
                       size_bytes, category, status, created_at, created_by
                FROM file_documents
                WHERE 1=1";
        $params = [];

        # Tenant filter
        $sql .= Tenant::whereClause('scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        # Status filter (default: exclude deleted)
        if ($status !== null) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        } else {
            $sql .= " AND status != 'deleted'";
        }

        # Search filter
        if ($search !== null) {
            $sql .= " AND (original_name LIKE :search OR category LIKE :search2)";
            $params[':search'] = "%$search%";
            $params[':search2'] = "%$search%";
        }

        # Category filter
        if ($category !== null) {
            $sql .= " AND category = :category";
            $params[':category'] = $category;
        }

        # MIME type filter
        if ($mimeType !== null) {
            $sql .= " AND mime_type LIKE :mime";
            $params[':mime'] = "$mimeType%";
        }

        # LIMIT/OFFSET need to be cast directly (PDO binding issues with integers)
        $sql .= " ORDER BY created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        if ($callback) {
            CONN::dml($sql, $params, $callback);
            return ['success' => true, 'streamed' => true];
        }

        $results = CONN::dml($sql, $params) ?? [];

        return [
            'success' => true,
            'data' => $results,
            'count' => count($results)
        ];
    }

    /**
     * Update document metadata
     *
     * @param array $data [
     *   'document_id' => int,
     *   'original_name' => string (optional),
     *   'category' => string (optional),
     *   'tags' => array (optional),
     *   'relative_path' => string (optional)
     * ]
     * @param array $options ['scope_entity_id' => int]
     * @return array ['success']
     */
    public static function update(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $documentId = (int)($data['document_id'] ?? 0);

        if ($documentId <= 0) {
            return ['success' => false, 'message' => 'document_id is required'];
        }

        # Validate tenant
        $tenant = Tenant::validateForWrite($options);
        if (!$tenant['valid']) {
            return ['success' => false, 'message' => $tenant['error']];
        }

        # Build update fields
        $updates = [];
        $params = [':id' => $documentId];

        if (isset($data['original_name'])) {
            $updates[] = "original_name = :name";
            $params[':name'] = $data['original_name'];
        }
        if (isset($data['category'])) {
            $updates[] = "category = :category";
            $params[':category'] = $data['category'];
        }
        if (isset($data['tags'])) {
            $updates[] = "tags = :tags";
            $params[':tags'] = json_encode($data['tags']);
        }
        if (isset($data['relative_path'])) {
            $updates[] = "relative_path = :path";
            $params[':path'] = $data['relative_path'];
        }

        if (empty($updates)) {
            return ['success' => false, 'message' => 'No fields to update'];
        }

        $sql = "UPDATE file_documents SET " . implode(', ', $updates) . "
                WHERE document_id = :id";
        $sql .= Tenant::whereClause('scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        $result = CONN::nodml($sql, $params);

        if (!$result['success'] || $result['affected_rows'] === 0) {
            return ['success' => false, 'message' => 'Document not found or not updated'];
        }

        return ['success' => true, 'message' => 'Document updated'];
    }

    /**
     * Soft delete document
     *
     * @param array $data ['document_id' => int]
     * @param array $options ['scope_entity_id' => int]
     * @return array ['success']
     */
    public static function delete(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $documentId = (int)($data['document_id'] ?? 0);

        if ($documentId <= 0) {
            return ['success' => false, 'message' => 'document_id is required'];
        }

        # Validate tenant
        $tenant = Tenant::validateForWrite($options);
        if (!$tenant['valid']) {
            return ['success' => false, 'message' => $tenant['error']];
        }

        # Check for legal hold
        $doc = self::get(['document_id' => $documentId], $options);
        if (!$doc['success']) {
            return $doc;
        }

        if (!empty($doc['data']['legal_hold'])) {
            return ['success' => false, 'message' => 'Document is under legal hold'];
        }

        $sql = "UPDATE file_documents SET status = 'deleted'
                WHERE document_id = :id";
        $params = [':id' => $documentId];
        $sql .= Tenant::whereClause('scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        $result = CONN::nodml($sql, $params);

        if (!$result['success'] || $result['affected_rows'] === 0) {
            return ['success' => false, 'message' => 'Document not found'];
        }

        Log::logInfo("Document::delete - Soft deleted document $documentId");

        return ['success' => true, 'message' => 'Document deleted'];
    }

    /**
     * Link document to business entity
     *
     * @param array $data [
     *   'document_id' => int,
     *   'entity_type' => string (e.g., 'order', 'employee', 'contract'),
     *   'entity_id' => int,
     *   'link_type' => string (default: 'attachment')
     * ]
     * @param array $options ['scope_entity_id' => int]
     * @return array ['success', 'link_id']
     */
    public static function link(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $documentId = (int)($data['document_id'] ?? 0);
        $entityType = $data['entity_type'] ?? '';
        $entityId = (int)($data['entity_id'] ?? 0);
        $linkType = $data['link_type'] ?? 'attachment';

        if ($documentId <= 0 || empty($entityType) || $entityId <= 0) {
            return ['success' => false, 'message' => 'document_id, entity_type, and entity_id are required'];
        }

        # Verify document exists and belongs to tenant
        $doc = self::get(['document_id' => $documentId], $options);
        if (!$doc['success']) {
            return $doc;
        }

        $sql = "INSERT INTO file_entity_links
                (document_id, entity_type, entity_id, link_type, created_by)
                VALUES (:doc, :type, :entity, :link_type, :created_by)
                ON DUPLICATE KEY UPDATE link_type = :link_type2";

        $result = CONN::nodml($sql, [
            ':doc' => $documentId,
            ':type' => $entityType,
            ':entity' => $entityId,
            ':link_type' => $linkType,
            ':link_type2' => $linkType,
            ':created_by' => Profile::$profile_id ?: null
        ]);

        if (!$result['success']) {
            return ['success' => false, 'message' => 'Failed to create link'];
        }

        return [
            'success' => true,
            'link_id' => $result['last_id'] ?: null,
            'message' => 'Document linked'
        ];
    }

    /**
     * Get documents linked to a business entity
     *
     * @param array $data [
     *   'entity_type' => string,
     *   'entity_id' => int,
     *   'link_type' => string (optional)
     * ]
     * @param array $options ['scope_entity_id' => int]
     * @return array List of documents
     */
    public static function getLinked(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $entityType = $data['entity_type'] ?? '';
        $entityId = (int)($data['entity_id'] ?? 0);
        $linkType = $data['link_type'] ?? null;

        if (empty($entityType) || $entityId <= 0) {
            return ['success' => false, 'message' => 'entity_type and entity_id are required'];
        }

        $sql = "SELECT d.*, l.link_type, l.created_at as linked_at
                FROM file_documents d
                JOIN file_entity_links l ON d.document_id = l.document_id
                WHERE l.entity_type = :type
                  AND l.entity_id = :entity
                  AND d.status != 'deleted'";
        $params = [':type' => $entityType, ':entity' => $entityId];

        $sql .= Tenant::whereClause('d.scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        if ($linkType !== null) {
            $sql .= " AND l.link_type = :link_type";
            $params[':link_type'] = $linkType;
        }

        $sql .= " ORDER BY l.created_at DESC";

        if ($callback) {
            CONN::dml($sql, $params, $callback);
            return ['success' => true, 'streamed' => true];
        }

        $results = CONN::dml($sql, $params) ?? [];

        return [
            'success' => true,
            'data' => $results,
            'count' => count($results)
        ];
    }

    /**
     * Get download URL for document (via Delivery)
     *
     * @param array $data ['document_id' => int]
     * @param array $options ['scope_entity_id' => int]
     * @return array ['success', 'url', 'expires_at']
     */
    public static function getDownloadUrl(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $doc = self::get($data, $options);
        if (!$doc['success']) {
            return $doc;
        }

        # For now, return direct path - Delivery API will handle auth
        return [
            'success' => true,
            'document_id' => $doc['data']['document_id'],
            'original_name' => $doc['data']['original_name'],
            'mime_type' => $doc['data']['mime_type'],
            'size_bytes' => $doc['data']['size_bytes'],
            'disk_path' => $doc['data']['disk_path']
        ];
    }
}
