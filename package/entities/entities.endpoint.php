<?php
# package/entities/entities.endpoint.php
namespace entities;

use bX\Router;
use bX\Response;
use bX\Args;
use bX\Profile;
use bX\CONN;
use bX\Tenant;
use bX\Entity;
use bX\Entity\Graph;
use bX\DataCaptureService;

# Definir variables EAV para entity (si no existen)
# Contexto: macro=entity, event=contact_info
$entityVariables = [
    ['unique_name' => 'entity.email', 'label' => 'Email', 'data_type' => 'STRING', 'is_pii' => true],
    ['unique_name' => 'entity.phone', 'label' => 'Teléfono', 'data_type' => 'STRING', 'is_pii' => true],
    ['unique_name' => 'entity.address', 'label' => 'Dirección', 'data_type' => 'STRING', 'is_pii' => false],
];
foreach ($entityVariables as $varDef) {
    $exists = CONN::dml("SELECT 1 FROM data_dictionary WHERE unique_name = :name LIMIT 1", [':name' => $varDef['unique_name']]);
    if (empty($exists)) {
        DataCaptureService::defineCaptureField($varDef, 1); # profile_id=1 (system)
    }
}

/**
 * @endpoint   /api/entities/list
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    List entities visible to current scope (admin sees all)
 * @query      limit (int) - Max results (default 100)
 * @query      offset (int) - Pagination offset
 * @query      entity_type (string) - Filter by type
 * @note       Usa EXISTS + query separada para relation_kinds (optimizado para escala)
 * @index      Requiere: entity_relationships(scope_entity_id, entity_id)
 */
Router::register(['GET'], 'list', function() {
    $limit = min((int)(Args::$OPT['limit'] ?? 100), 500);
    $offset = (int)(Args::$OPT['offset'] ?? 0);
    $entityType = Args::$OPT['entity_type'] ?? null;
    $scopeId = Profile::$scope_entity_id;
    $params = [];

    # Query 1: Obtener entities únicos (EXISTS para usuarios, directo para admin)
    if (Tenant::isAdmin()) {
        $sql = "SELECT e.entity_id, e.primary_name, e.entity_type,
                       e.national_id, e.national_isocode,
                       e.status, e.created_at
                FROM entities e
                WHERE 1=1";
    } else {
        # EXISTS es más eficiente que DISTINCT+JOIN cuando hay múltiples relaciones por entity
        $sql = "SELECT e.entity_id, e.primary_name, e.entity_type,
                       e.national_id, e.national_isocode,
                       e.status, e.created_at
                FROM entities e
                WHERE EXISTS (
                    SELECT 1 FROM entity_relationships er
                    WHERE er.entity_id = e.entity_id
                    AND er.scope_entity_id = :scope
                )";
        $params[':scope'] = $scopeId;
    }

    if ($entityType) {
        $sql .= " AND e.entity_type = :entity_type";
        $params[':entity_type'] = $entityType;
    }

    # LIMIT/OFFSET interpolados (ya sanitizados como int arriba)
    $sql .= " ORDER BY e.created_at DESC LIMIT {$limit} OFFSET {$offset}";

    $entities = CONN::dml($sql, $params) ?? [];

    if (empty($entities)) {
        return Response::json(['data' => [
            'success' => true,
            'data' => [],
            'count' => 0
        ]]);
    }

    # Query 2: Obtener relation_kinds para los entity_ids encontrados (desagregado)
    $entityIds = array_column($entities, 'entity_id');
    $relationsByEntity = [];

    if (!Tenant::isAdmin() && !empty($entityIds)) {
        $placeholders = [];
        $relParams = [':scope' => $scopeId];
        foreach ($entityIds as $i => $id) {
            $key = ":eid{$i}";
            $placeholders[] = $key;
            $relParams[$key] = $id;
        }

        $relSql = "SELECT entity_id, relation_kind
                   FROM entity_relationships
                   WHERE scope_entity_id = :scope
                   AND entity_id IN (" . implode(',', $placeholders) . ")";

        $relations = CONN::dml($relSql, $relParams) ?? [];

        # Agrupar kinds por entity_id
        foreach ($relations as $rel) {
            $eid = $rel['entity_id'];
            if (!isset($relationsByEntity[$eid])) {
                $relationsByEntity[$eid] = [];
            }
            $relationsByEntity[$eid][] = $rel['relation_kind'];
        }
    }

    # Enriquecer entities con relation_kinds y datos EAV
    foreach ($entities as &$entity) {
        $eid = $entity['entity_id'];

        # relation_kinds como array (puede tener múltiples)
        $entity['relation_kinds'] = $relationsByEntity[$eid] ?? [];

        # Datos EAV (email, phone)
        $eav = DataCaptureService::getHotData((int)$eid, ['entity.email', 'entity.phone']);
        $entity['email'] = $eav['data']['entity.email']['value'] ?? null;
        $entity['phone'] = $eav['data']['entity.phone']['value'] ?? null;
    }

    return Response::json(['data' => [
        'success' => true,
        'data' => $entities,
        'count' => count($entities)
    ]]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   /api/entities/{id}
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Get entity by ID (admin: all, users: only accessible via relationship)
 */
Router::register(['GET'], '(?P<id>\d+)', function($id) {
    $entityId = (int)$id;
    $scopeId = Profile::$scope_entity_id;
    $params = [':id' => $entityId];

    # Admin: acceso directo sin verificar relaciones
    if (Tenant::isAdmin()) {
        $sql = "SELECT e.entity_id, e.primary_name, e.entity_type,
                       e.national_id, e.national_isocode,
                       e.status, e.identity_hash,
                       e.canonical_entity_id, e.created_at
                FROM entities e
                WHERE e.entity_id = :id";
    } else {
        # Usuarios: verificar acceso via relationship con EXISTS
        $sql = "SELECT e.entity_id, e.primary_name, e.entity_type,
                       e.national_id, e.national_isocode,
                       e.status, e.identity_hash,
                       e.canonical_entity_id, e.created_at
                FROM entities e
                WHERE e.entity_id = :id
                AND EXISTS (
                    SELECT 1 FROM entity_relationships er
                    WHERE er.entity_id = e.entity_id
                    AND er.scope_entity_id = :scope
                )";
        $params[':scope'] = $scopeId;
    }

    $result = CONN::dml($sql, $params);
    $entity = $result[0] ?? null;

    if (!$entity) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'Entity not found or not accessible'
        ]], 404);
    }

    # Obtener relation_kinds para este entity (desagregado)
    $relationKinds = [];
    if (!Tenant::isAdmin()) {
        $relSql = "SELECT relation_kind FROM entity_relationships
                   WHERE entity_id = :id AND scope_entity_id = :scope";
        $relations = CONN::dml($relSql, [':id' => $entityId, ':scope' => $scopeId]) ?? [];
        $relationKinds = array_column($relations, 'relation_kind');
    }
    $entity['relation_kinds'] = $relationKinds;

    # Obtener datos EAV (email, phone, address)
    $eav = DataCaptureService::getHotData($entityId, ['entity.email', 'entity.phone', 'entity.address']);
    $entity['email'] = $eav['data']['entity.email']['value'] ?? null;
    $entity['phone'] = $eav['data']['entity.phone']['value'] ?? null;
    $entity['address'] = $eav['data']['entity.address']['value'] ?? null;

    return Response::json(['data' => [
        'success' => true,
        'data' => $entity
    ]]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   /api/entities/create
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Create new entity
 * @body       (JSON) {entity_type, primary_name, national_id, national_isocode, email, phone, address}
 */
Router::register(['POST'], 'create', function() {
    $data = Args::$OPT;
    $options = ['scope_entity_id' => Profile::$scope_entity_id];

    # Validate required fields
    if (empty($data['primary_name'])) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'primary_name is required'
        ]], 400);
    }

    $tenant = Tenant::validateForWrite($options);
    if (!$tenant['valid']) {
        return Response::json(['data' => [
            'success' => false,
            'message' => $tenant['error']
        ]], 403);
    }

    # Calculate identity_hash if national_id provided
    $identityHash = null;
    if (!empty($data['national_id']) && !empty($data['national_isocode'])) {
        $identityHash = Entity::calculateIdentityHash(
            $data['national_isocode'],
            $data['national_id']
        );
    }

    # Insert entity (solo columnas de tabla entities)
    $sql = "INSERT INTO entities
            (entity_type, primary_name, national_id, national_isocode,
             identity_hash, status, created_by_profile_id, created_at)
            VALUES
            (:entity_type, :primary_name, :national_id, :national_isocode,
             :identity_hash, 'active', :created_by, NOW())";

    $params = [
        ':entity_type' => $data['entity_type'] ?? 'general',
        ':primary_name' => $data['primary_name'],
        ':national_id' => $data['national_id'] ?? null,
        ':national_isocode' => $data['national_isocode'] ?? 'CL',
        ':identity_hash' => $identityHash,
        ':created_by' => Profile::$profile_id
    ];

    $result = CONN::nodml($sql, $params);

    if (!$result['success']) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'Error creating entity: ' . ($result['error'] ?? 'Unknown error')
        ]], 500);
    }

    $entityId = (int)$result['last_id'];

    # Guardar email/phone/address en EAV via DataCaptureService
    # Contexto: macro=entity, event=contact_info
    $eavValues = [];
    if (!empty($data['email'])) {
        $eavValues[] = ['variable_name' => 'entity.email', 'value' => $data['email']];
    }
    if (!empty($data['phone'])) {
        $eavValues[] = ['variable_name' => 'entity.phone', 'value' => $data['phone']];
    }
    if (!empty($data['address'])) {
        $eavValues[] = ['variable_name' => 'entity.address', 'value' => $data['address']];
    }

    if (!empty($eavValues)) {
        DataCaptureService::saveData(
            Profile::$profile_id,           # actorProfileId
            $entityId,                       # subjectEntityId (el entity creado)
            Profile::$scope_entity_id,       # scopeEntityId (tenant)
            [                                # contextPayload (3 niveles)
                'macro_context' => 'entity',
                'event_context' => 'contact_info',
                'sub_context' => 'primary'
            ],
            $eavValues,                      # valuesData
            'entity_create'                  # contextType
        );
    }

    return Response::json(['data' => [
        'success' => true,
        'entity_id' => $entityId,
        'identity_hash' => $identityHash
    ]], 201);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/entities/check-identity/{isocode}/{national_id}
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Check if entity with national ID exists (for deduplication/shadow detection)
 * @param      isocode (string) - ISO country code (CL, AR, MX, etc.)
 * @param      national_id (string) - National identifier (RUT, DNI, RFC, etc.)
 */
Router::register(['GET'], 'check-identity/(?P<isocode>[A-Z]{2})/(?P<national_id>[^/]+)', function($isocode, $national_id) {
    $nationalId = urldecode($national_id);

    if (empty($nationalId)) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'national_id is required'
        ]], 400);
    }

    # Calculate identity hash
    $identityHash = Entity::calculateIdentityHash($isocode, $nationalId);

    # Search for existing entity with this hash
    $sql = "SELECT entity_id, primary_name, entity_type, national_id, status
            FROM entities
            WHERE identity_hash = :hash
            LIMIT 1";

    $result = CONN::dml($sql, [':hash' => $identityHash]);
    $existing = $result[0] ?? null;

    if ($existing) {
        return Response::json(['data' => [
            'success' => true,
            'exists' => true,
            'entity_id' => (int)$existing['entity_id'],
            'primary_name' => $existing['primary_name'],
            'entity_type' => $existing['entity_type'],
            'identity_hash' => $identityHash
        ]]);
    }

    return Response::json(['data' => [
        'success' => true,
        'exists' => false,
        'identity_hash' => $identityHash
    ]]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   /api/entities/{id}/shadows
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Find shadow entities (same identity_hash, different entity_id)
 */
Router::register(['GET'], '(?P<id>\d+)/shadows', function($id) {
    # Get entity's identity_hash
    $sql = "SELECT identity_hash FROM entities WHERE entity_id = :id";
    $result = CONN::dml($sql, [':id' => (int)$id]);
    $entity = $result[0] ?? null;

    if (!$entity || empty($entity['identity_hash'])) {
        return Response::json(['data' => [
            'success' => true,
            'count' => 0,
            'shadows' => []
        ]]);
    }

    # Find shadows
    $shadows = Entity::findShadows($entity['identity_hash'], (int)$id);

    return Response::json(['data' => [
        'success' => true,
        'count' => count($shadows),
        'shadows' => $shadows
    ]]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   /api/entities/stats
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Get entity statistics for current scope (admin sees global stats)
 */
Router::register(['GET'], 'stats', function() {
    $options = ['scope_entity_id' => Profile::$scope_entity_id];
    $params = [];

    # Admin ve estadísticas globales
    if (Tenant::isAdmin()) {
        $sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN entity_type = 'supplier' OR entity_type LIKE '%supplier%' THEN 1 ELSE 0 END) as suppliers,
                    SUM(CASE WHEN entity_type = 'customer' OR entity_type LIKE '%customer%' THEN 1 ELSE 0 END) as customers,
                    SUM(CASE WHEN entity_type = 'person' THEN 1 ELSE 0 END) as persons
                FROM entities";
    } else {
        # Usuarios normales: estadísticas basadas en relaciones
        $sql = "SELECT
                    COUNT(DISTINCT e.entity_id) as total,
                    SUM(CASE WHEN er.relation_kind = 'supplier_of' THEN 1 ELSE 0 END) as suppliers,
                    SUM(CASE WHEN er.relation_kind = 'customer_of' THEN 1 ELSE 0 END) as customers,
                    SUM(CASE WHEN e.entity_type = 'person' THEN 1 ELSE 0 END) as persons
                FROM entities e
                INNER JOIN entity_relationships er ON e.entity_id = er.entity_id
                WHERE 1=1";
        $sql = Tenant::applySql($sql, 'er.scope_entity_id', $options, $params);
    }

    $result = CONN::dml($sql, $params);
    $stats = $result[0] ?? ['total' => 0, 'suppliers' => 0, 'customers' => 0, 'persons' => 0];

    return Response::json(['data' => [
        'success' => true,
        'total' => (int)$stats['total'],
        'suppliers' => (int)$stats['suppliers'],
        'customers' => (int)$stats['customers'],
        'persons' => (int)$stats['persons']
    ]]);
}, ROUTER_SCOPE_READ);

/**
 * @endpoint   /api/entity-relationships/create
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Create relationship between profile and entity
 * @body       (JSON) {entity_id, relation_kind}
 */
Router::register(['POST'], '../entity-relationships/create', function() {
    $data = Args::$OPT;
    $options = ['scope_entity_id' => Profile::$scope_entity_id];

    $entityId = (int)($data['entity_id'] ?? 0);
    $relationKind = $data['relation_kind'] ?? 'contact_of';

    if ($entityId <= 0) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'entity_id is required'
        ]], 400);
    }

    $result = Graph::create([
        'profile_id' => Profile::$profile_id,
        'entity_id' => $entityId,
        'relation_kind' => $relationKind
    ], $options);

    $code = $result['success'] ? 201 : 400;
    return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_WRITE);
