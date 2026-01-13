<?php
# package/files/files.endpoint.php
namespace files;

use bX\Router;
use bX\Response;
use bX\Args;
use bX\Profile;
use bX\FileService\Storage;
use bX\FileService\Upload;
use bX\FileService\Document;
use bX\FileService\Delivery;

/**
 * @endpoint   /api/files/check
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Pre-check hash for deduplication
 * @body       (JSON) {"hash": "sha256..."}
 */
Router::register(['POST'], 'check', function() {
    $options = ['scope_entity_id' => Profile::$scope_entity_id];
    $result = Upload::check(Args::$OPT, $options);

    # If storage exists but no document for this tenant, create one
    if ($result['success'] && $result['exists'] && empty($result['document_id'])) {
        $hash = Args::$OPT['hash'] ?? '';
        $storageInfo = Storage::info($hash);

        if ($storageInfo) {
            $docResult = Document::create([
                'storage_key' => $hash,
                'hash' => $hash,
                'size_bytes' => $storageInfo['size_bytes'],
                'mime_type' => Args::$OPT['mime_type'] ?? $storageInfo['mime_type'],
                'original_name' => Args::$OPT['original_name'] ?? 'unnamed'
            ], $options);

            if ($docResult['success']) {
                $result['document_id'] = $docResult['document_id'];
                $result['created'] = true;
            }
        }
    }

    return Response::json(['data' => $result]);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/files/upload/init
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Initialize chunked upload session
 * @body       (JSON) {"hash": "...", "size_bytes": 1024, "original_name": "file.pdf", "mime_type": "application/pdf"}
 */
Router::register(['POST'], 'upload/init', function() {
    $result = Upload::init(Args::$OPT, ['scope_entity_id' => Profile::$scope_entity_id]);
    $code = $result['success'] ? 201 : 400;
    return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/files/upload/{uploadId}/chunk
 * @method     PUT
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Upload a chunk
 */
Router::register(['PUT'], 'upload/(?P<uploadId>[a-f0-9]+)/chunk', function($uploadId) {
    # chunk_index comes from query string (php://input is binary, not consumed here)
    $chunkIndex = (int)($_GET['chunk_index'] ?? 0);

    # Upload::chunk reads php://input directly with stream_copy_to_stream
    $result = Upload::chunk([
        'upload_id' => $uploadId,
        'chunk_index' => $chunkIndex
    ], ['scope_entity_id' => Profile::$scope_entity_id]);

    return Response::json(['data' => $result]);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/files/upload/{uploadId}/complete
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Complete upload and assemble file
 */
Router::register(['POST'], 'upload/(?P<uploadId>[a-f0-9]+)/complete', function($uploadId) {
    $result = Upload::complete(
        ['upload_id' => $uploadId],
        ['scope_entity_id' => Profile::$scope_entity_id]
    );

    # Never expose internal paths to client
    unset($result['disk_path']);

    $code = $result['success'] ? 201 : 400;
    return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/files/upload/{uploadId}/status
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Get upload session status
 */
Router::register(['GET'], 'upload/(?P<uploadId>[a-f0-9]+)/status', function($uploadId) {
    $result = Upload::status(['upload_id' => $uploadId]);
    return Response::json(['data' => $result]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   /api/files/upload/{uploadId}/abort
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Abort upload session
 */
Router::register(['POST'], 'upload/(?P<uploadId>[a-f0-9]+)/abort', function($uploadId) {
    $result = Upload::abort(['upload_id' => $uploadId]);
    return Response::json(['data' => $result]);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/files/upload-simple
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Simple single-request upload (for small files)
 * @body       Multipart form with 'file' field
 */
Router::register(['POST'], 'upload-simple', function() {
    if (empty($_FILES['file'])) {
        return Response::json(['data' => ['success' => false, 'message' => 'No file uploaded']], 400);
    }

    $file = $_FILES['file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return Response::json(['data' => ['success' => false, 'message' => 'Upload error: ' . $file['error']]], 400);
    }

    # Store directly
    $storeResult = Storage::store($file['tmp_name']);
    if (!$storeResult['success']) {
        return Response::json(['data' => $storeResult], 500);
    }

    # Create document record
    $docResult = Document::create([
        'storage_key' => $storeResult['hash'],
        'hash' => $storeResult['hash'],
        'size_bytes' => $storeResult['size_bytes'],
        'mime_type' => $file['type'] ?: 'application/octet-stream',
        'original_name' => $file['name']
    ], ['scope_entity_id' => Profile::$scope_entity_id]);

    if (!$docResult['success']) {
        return Response::json(['data' => $docResult], 500);
    }

    return Response::json(['data' => [
        'success' => true,
        'document_id' => $docResult['document_id'],
        'hash' => $storeResult['hash'],
        'size_bytes' => $storeResult['size_bytes'],
        'deduplicated' => $storeResult['deduplicated']
    ]], 201);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/files/documents
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    List documents
 */
Router::register(['GET'], 'documents', function() {
    $result = Document::list(Args::$OPT, ['scope_entity_id' => Profile::$scope_entity_id]);
    return Response::json(['data' => $result]);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/files/documents/{id}
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Get document by ID
 */
Router::register(['GET'], 'documents/(?P<id>\d+)', function($id) {
    $result = Document::get(
        ['document_id' => (int)$id],
        ['scope_entity_id' => Profile::$scope_entity_id]
    );
    $code = $result['success'] ? 200 : 404;
    return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/files/documents/{id}/download
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Download document
 * @query      filename (optional) - Custom filename for download
 * @query      inline (optional) - If set, use Content-Disposition: inline for browser viewing
 */
Router::register(['GET'], 'documents/(?P<id>\d+)/download', function($id) {
    $resolve = Delivery::resolve(
        ['document_id' => (int)$id],
        ['scope_entity_id' => Profile::$scope_entity_id]
    );

    if (!$resolve['allowed']) {
        return Response::json(['data' => ['success' => false, 'reason' => $resolve['reason']]], 403);
    }

    $fileInfo = $resolve['file_info'];

    # Custom filename override
    if (!empty($_GET['filename'])) {
        $fileInfo['original_name'] = basename($_GET['filename']);
    }

    # Inline viewing (for browsers with PDF viewers, image viewers, etc.)
    if (isset($_GET['inline'])) {
        $fileInfo['inline'] = true;
    }

    Delivery::stream($fileInfo);
    exit;
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/files/documents/{id}/link
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Create public link for document
 * @body       (JSON) {"expires_in": 3600, "access_code": "secret123", "download_limit": 5}
 */
Router::register(['POST'], 'documents/(?P<id>\d+)/link', function($id) {
    $data = Args::$OPT;
    $data['document_id'] = (int)$id;

    $result = Delivery::createPublicLink($data, ['scope_entity_id' => Profile::$scope_entity_id]);
    $code = $result['success'] ? 201 : 400;
    return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/files/public/{linkId}
 * @method     GET
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Download via public link
 */
Router::register(['GET'], 'public/(?P<linkId>[a-f0-9]+)', function($linkId) {
    $accessCode = Args::$OPT['code'] ?? $_GET['code'] ?? null;

    $resolve = Delivery::resolvePublicLink([
        'link_id' => $linkId,
        'access_code' => $accessCode
    ]);

    if (!$resolve['allowed']) {
        $code = match($resolve['reason']) {
            'NOT_FOUND' => 404,
            'EXPIRED', 'LIMIT_EXCEEDED' => 410,
            'BAD_CODE', 'NO_PERMISSION' => 403,
            default => 400
        };
        return Response::json(['data' => ['success' => false, 'reason' => $resolve['reason']]], $code);
    }

    Delivery::stream($resolve['file_info']);
    exit;
}, ROUTER_SCOPE_PUBLIC);
