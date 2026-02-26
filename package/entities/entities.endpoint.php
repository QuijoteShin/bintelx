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
use bX\GeoService;
use bX\DataCaptureService;

# Definir variables EAV para entity (si no existen)
# Contexto: macro=entity, event=contact_info
$entityVariables = [
    ['unique_name' => 'entity.email', 'label' => 'Email', 'data_type' => 'STRING', 'is_pii' => true],
    ['unique_name' => 'entity.phone', 'label' => 'Teléfono', 'data_type' => 'STRING', 'is_pii' => true],
    ['unique_name' => 'entity.address', 'label' => 'Dirección', 'data_type' => 'STRING', 'is_pii' => false],
    ['unique_name' => 'entity.giros', 'label' => 'Giros comerciales', 'data_type' => 'JSON', 'is_pii' => false],
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
 * @query      relation_kind (string) - Filter by relation kind (customer, supplier, contact, partner, etc.)
 * @note       Usa EXISTS + query separada para relation_kinds (optimizado para escala)
 * @index      Requiere: entity_relationships(scope_entity_id, entity_id)
 */
Router::register(['GET'], 'list', function() {
    $limit = min((int)(Args::ctx()->opt['limit'] ?? 100), 500);
    $offset = (int)(Args::ctx()->opt['offset'] ?? 0);
    $entityType = Args::ctx()->opt['entity_type'] ?? null;
    $relationKind = Args::ctx()->opt['relation_kind'] ?? null;
    $options = ['scope_entity_id' => Profile::ctx()->scopeEntityId];
    $profileId = Profile::ctx()->profileId;
    $params = [];

    # force_scope: entities es dato operativo per-tenant, admin debe ver solo su workspace activo
    $options['force_scope'] = true;

    # Query 1: Obtener entities únicos via EXISTS + tenant filter (admin y usuarios)
    {
        # EXISTS es más eficiente que DISTINCT+JOIN cuando hay múltiples relaciones por entity
        # Filtrar por: profile_id (relaciones del usuario) + scope_entity_id (tenant)
        $tenantFilter = Tenant::filter('er.scope_entity_id', $options);
        $relationKindFilter = $relationKind ? "AND er.relation_kind = :relation_kind" : "";
        $sql = "SELECT e.entity_id, e.primary_name, e.entity_type,
                       e.national_id, e.national_isocode,
                       e.status, e.created_at
                FROM entities e
                WHERE EXISTS (
                    SELECT 1 FROM entity_relationships er
                    WHERE er.entity_id = e.entity_id
                    AND er.status = 'active'
                    {$relationKindFilter}
                    {$tenantFilter['sql']}
                )";
        # $params[':profile_id'] = $profileId; # Removed to allow seeing all tenant entities
        if ($relationKind) {
            $params[':relation_kind'] = $relationKind;
        }
        $params = array_merge($params, $tenantFilter['params']);
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

    # Query 2: Obtener relation_kinds para los entity_ids encontrados
    # Traemos TODOS los tipos de relación del entity (no solo del profile actual)
    # Esto permite mostrar badges correctos: un entity puede ser cliente Y proveedor
    $entityIds = array_column($entities, 'entity_id');
    $relationsByEntity = [];

    if (!empty($entityIds)) {
        $placeholders = [];
        $relParams = [];
        foreach ($entityIds as $i => $id) {
            $key = ":eid{$i}";
            $placeholders[] = $key;
            $relParams[$key] = $id;
        }

        # force_scope ya está en $options — filtra por workspace activo
        $tenantFilter2 = Tenant::filter('scope_entity_id', $options);
        $relSql = "SELECT DISTINCT entity_id, relation_kind
                   FROM entity_relationships
                   WHERE status = 'active'
                   AND entity_id IN (" . implode(',', $placeholders) . ")"
                   . $tenantFilter2['sql'];
        $relParams = array_merge($relParams, $tenantFilter2['params']);

        # Usar callback para armar el índice directamente
        CONN::dml($relSql, $relParams, function($row) use (&$relationsByEntity) {
            $eid = $row['entity_id'];
            $kind = $row['relation_kind'];
            if (!isset($relationsByEntity[$eid])) {
                $relationsByEntity[$eid] = [];
            }
            # Evitar duplicados
            if (!in_array($kind, $relationsByEntity[$eid])) {
                $relationsByEntity[$eid][] = $kind;
            }
        });
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
 * @endpoint   /api/entities/find.json
 * @method     POST
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Find entities by text search
 * @body       (JSON) {query, relation_kind, limit}
 */
Router::register(['POST'], 'find.json', function() {
    $data = Args::ctx()->opt;
    $query = trim($data['query'] ?? $data['search'] ?? '');
    $relationKind = $data['relation_kind'] ?? null;
    $limit = min((int)($data['limit'] ?? 20), 100);
    $options = ['scope_entity_id' => Profile::ctx()->scopeEntityId, 'force_scope' => true];
    $params = [];

    $tenantFilter = Tenant::filter('er.scope_entity_id', $options);
    $kindSql = $relationKind ? "AND er.relation_kind = :kind" : "";

    $sql = "SELECT e.entity_id, e.primary_name, e.entity_type,
                   e.national_id, e.national_isocode, e.status
            FROM entities e ";

    $conditions = ["1=1"];

    # Visibility: Entities linked to the current scope
    $exists = "EXISTS (
        SELECT 1 FROM entity_relationships er
        WHERE er.entity_id = e.entity_id
        AND er.status = 'active'
        {$kindSql}
        {$tenantFilter['sql']}
    )";
    $conditions[] = $exists;
    $params = array_merge($params, $tenantFilter['params']);
    if ($relationKind) {
        $params[':kind'] = $relationKind;
    }

    if (!empty($query)) {
        $conditions[] = "(e.primary_name LIKE :q OR e.national_id LIKE :q)";
        $params[':q'] = "%{$query}%";
    }

    $sql .= " WHERE " . implode(" AND ", $conditions);
    $sql .= " ORDER BY e.primary_name ASC LIMIT {$limit}";

    $rows = CONN::dml($sql, $params) ?? [];

    return Response::json(['data' => [
        'success' => true,
        'data' => $rows,
        'count' => count($rows)
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
    $profileId = Profile::ctx()->profileId;
    $options = ['scope_entity_id' => Profile::ctx()->scopeEntityId, 'force_scope' => true];
    $params = [':id' => $entityId];

    # Verificar acceso via relationship + tenant (force_scope para admin también)
    $tenantFilter = Tenant::filter('er.scope_entity_id', $options);
    $sql = "SELECT e.entity_id, e.primary_name, e.entity_type,
                   e.national_id, e.national_isocode,
                   e.status, e.identity_hash,
                   e.canonical_entity_id, e.created_at
            FROM entities e
            WHERE e.entity_id = :id
            AND EXISTS (
                SELECT 1 FROM entity_relationships er
                WHERE er.entity_id = e.entity_id
                AND er.status = 'active'
                {$tenantFilter['sql']}
            )";
    $params = array_merge($params, $tenantFilter['params']);

    $result = CONN::dml($sql, $params);
    $entity = $result[0] ?? null;

    if (!$entity) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'Entity not found or not accessible'
        ]], 404);
    }

    # Obtener relation_kinds para este entity dentro del scope activo
    $tenantFilter2 = Tenant::filter('scope_entity_id', $options);
    $relSql = "SELECT DISTINCT relation_kind FROM entity_relationships
               WHERE entity_id = :id AND status = 'active'"
               . $tenantFilter2['sql'];
    $relParams = array_merge([':id' => $entityId], $tenantFilter2['params']);
    $relations = CONN::dml($relSql, $relParams) ?? [];
    $entity['relation_kinds'] = array_column($relations, 'relation_kind');

    # Obtener owner_label si es dueño (relationship_label de relación owner)
    $ownerLabel = CONN::dml(
        "SELECT relationship_label FROM entity_relationships
         WHERE entity_id = :id AND relation_kind = 'owner'
           AND profile_id = :pid AND status = 'active' LIMIT 1",
        [':id' => $entityId, ':pid' => $profileId]
    );
    $entity['owner_label'] = $ownerLabel[0]['relationship_label'] ?? null;

    # Obtener datos EAV más recientes (para campos principales)
    $eav = DataCaptureService::getHotData($entityId, ['entity.email', 'entity.phone', 'entity.address', 'entity.giros']);
    $entity['email'] = $eav['data']['entity.email']['value'] ?? null;
    $entity['phone'] = $eav['data']['entity.phone']['value'] ?? null;
    $entity['address'] = $eav['data']['entity.address']['value'] ?? null;
    $entity['giros'] = $eav['data']['entity.giros']['value'] ?? [];

    # Obtener datos EAV agrupados por contexto (relation_kind)
    # Permite ver: dirección como proveedor vs dirección como cliente
    $contactByContext = [];
    $ctxSql = "SELECT cg.event_context, d.unique_name,
                      COALESCE(h.value_string, h.value_decimal, h.value_datetime) as value
               FROM data_values_history h
               JOIN context_groups cg ON cg.context_group_id = h.context_group_id
               JOIN data_dictionary d ON d.variable_id = h.variable_id
               WHERE cg.subject_entity_id = :eid
                 AND cg.macro_context = 'entity'
                 AND h.is_active = 1
                 AND d.unique_name IN ('entity.email', 'entity.phone', 'entity.address')
               ORDER BY cg.event_context, h.timestamp DESC";

    CONN::dml($ctxSql, [':eid' => $entityId], function($row) use (&$contactByContext) {
        $ctx = $row['event_context'];
        $varName = $row['unique_name'];
        $shortName = str_replace('entity.', '', $varName); # email, phone, address

        if (!isset($contactByContext[$ctx])) {
            $contactByContext[$ctx] = [];
        }
        # Solo guardar el primero (más reciente por ORDER BY)
        if (!isset($contactByContext[$ctx][$shortName])) {
            $contactByContext[$ctx][$shortName] = $row['value'];
        }
    });

    $entity['contact_by_context'] = $contactByContext;

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
    $data = Args::ctx()->opt;
    $options = ['scope_entity_id' => Profile::ctx()->scopeEntityId];

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
        $nationalIdType = $data['national_id_type']
            ?? GeoService::detectNationalIdType($data['national_isocode'], $data['national_id'])
            ?? 'TAX_ID';
        $identityHash = Entity::calculateIdentityHash(
            $data['national_isocode'],
            $data['national_id'],
            $nationalIdType
        );

        # Check for existing entity with same identity_hash
        $existing = CONN::dml(
            "SELECT entity_id FROM entities WHERE identity_hash = :hash LIMIT 1",
            [':hash' => $identityHash]
        );

        if (!empty($existing)) {
            $existingEntityId = (int)$existing[0]['entity_id'];
            
            # If we're just creating, return the existing ID so the frontend can link it
            return Response::json(['data' => [
                'success' => true,
                'entity_id' => $existingEntityId,
                'identity_hash' => $identityHash,
                'message' => 'Entity already exists, returned existing ID'
            ]], 200);
        }
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
        ':created_by' => Profile::ctx()->profileId
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
            Profile::ctx()->profileId,           # actorProfileId
            $entityId,                       # subjectEntityId (el entity creado)
            Profile::ctx()->scopeEntityId,       # scopeEntityId (tenant)
            [                                # contextPayload (3 niveles)
                'macro_context' => 'entity',
                'event_context' => 'contact_info',
                'sub_context' => 'primary'
            ],
            $eavValues,                      # valuesData
            'entity_create'                  # contextType
        );
    }

    # Guardar giros comerciales (JSON array en EAV)
    if (!empty($data['giros']) && is_array($data['giros'])) {
        DataCaptureService::saveData(
            Profile::ctx()->profileId,
            $entityId,
            Profile::ctx()->scopeEntityId,
            [
                'macro_context' => 'entity',
                'event_context' => 'business_info',
                'sub_context' => 'giros'
            ],
            [['variable_name' => 'entity.giros', 'value' => array_values(array_filter($data['giros']))]],
            'entity_create'
        );
    }

    return Response::json(['data' => [
        'success' => true,
        'entity_id' => $entityId,
        'identity_hash' => $identityHash
    ]], 201);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/entities/{id}/update
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Update existing entity
 * @body       (JSON) {entity_type, primary_name, national_id, national_isocode, email, phone, address}
 */
Router::register(['POST'], '(?P<id>\d+)/update', function($id) {
    $entityId = (int)$id;
    $data = Args::ctx()->opt;
    $profileId = Profile::ctx()->profileId;
    $options = ['scope_entity_id' => Profile::ctx()->scopeEntityId, 'force_scope' => true];

    # Verificar que el entity existe y es accesible en este scope
    $tenantFilter = Tenant::filter('er.scope_entity_id', $options);
    $accessCheck = CONN::dml(
        "SELECT e.entity_id FROM entities e
         WHERE e.entity_id = :id
         AND EXISTS (
             SELECT 1 FROM entity_relationships er
             WHERE er.entity_id = e.entity_id
             AND er.status = 'active'
             {$tenantFilter['sql']}
         )",
        array_merge([':id' => $entityId], $tenantFilter['params'])
    );

    if (empty($accessCheck)) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'Entity not found or not accessible'
        ]], 404);
    }

    # Construir UPDATE dinámico solo con campos proporcionados
    $updates = [];
    $params = [':id' => $entityId, ':updated_by' => $profileId];

    if (isset($data['entity_type'])) {
        $updates[] = 'entity_type = :entity_type';
        $params[':entity_type'] = $data['entity_type'];
    }
    if (isset($data['primary_name'])) {
        $updates[] = 'primary_name = :primary_name';
        $params[':primary_name'] = $data['primary_name'];
    }
    if (array_key_exists('national_id', $data)) {
        $updates[] = 'national_id = :national_id';
        $params[':national_id'] = $data['national_id'];
    }
    if (array_key_exists('national_isocode', $data)) {
        $updates[] = 'national_isocode = :national_isocode';
        $params[':national_isocode'] = $data['national_isocode'];
    }

    # Recalcular identity_hash si cambió national_id o national_isocode
    $newNationalId = $data['national_id'] ?? null;
    $newIsocode = $data['national_isocode'] ?? null;
    if ($newNationalId !== null || $newIsocode !== null) {
        # Obtener valores actuales para completar el hash
        $current = CONN::dml(
            "SELECT national_id, national_isocode FROM entities WHERE entity_id = :id",
            [':id' => $entityId]
        )[0] ?? [];

        $finalNationalId = $newNationalId ?? $current['national_id'];
        $finalIsocode = $newIsocode ?? $current['national_isocode'] ?? 'CL';

        if (!empty($finalNationalId)) {
            $finalIdType = $data['national_id_type']
                ?? GeoService::detectNationalIdType($finalIsocode, $finalNationalId)
                ?? 'TAX_ID';
            $identityHash = Entity::calculateIdentityHash($finalIsocode, $finalNationalId, $finalIdType);
            $updates[] = 'identity_hash = :identity_hash';
            $params[':identity_hash'] = $identityHash;
            $updates[] = 'national_id_type = :national_id_type';
            $params[':national_id_type'] = $finalIdType;

            # Revalidar checksum
            $validation = GeoService::validateNationalId($finalIsocode, $finalNationalId, $finalIdType);
            $updates[] = 'identity_checksum_ok = :identity_checksum_ok';
            $params[':identity_checksum_ok'] = $validation['valid'] ? 1 : 0;
        }
    }

    # Ejecutar UPDATE si hay cambios en tabla entities
    if (!empty($updates)) {
        $updates[] = 'updated_by_profile_id = :updated_by';
        $sql = "UPDATE entities SET " . implode(', ', $updates) . " WHERE entity_id = :id";
        $result = CONN::nodml($sql, $params);

        if (!$result['success']) {
            return Response::json(['data' => [
                'success' => false,
                'message' => 'Error updating entity: ' . ($result['error'] ?? 'Unknown')
            ]], 500);
        }
    }

    # Actualizar datos EAV (email, phone, address)
    $eavValues = [];
    if (array_key_exists('email', $data)) {
        $eavValues[] = ['variable_name' => 'entity.email', 'value' => $data['email'] ?: ''];
    }
    if (array_key_exists('phone', $data)) {
        $eavValues[] = ['variable_name' => 'entity.phone', 'value' => $data['phone'] ?: ''];
    }
    if (array_key_exists('address', $data)) {
        $eavValues[] = ['variable_name' => 'entity.address', 'value' => $data['address'] ?: ''];
    }

    if (!empty($eavValues)) {
        DataCaptureService::saveData(
            $profileId,
            $entityId,
            Profile::ctx()->scopeEntityId,
            [
                'macro_context' => 'entity',
                'event_context' => 'contact_info',
                'sub_context' => 'primary'
            ],
            $eavValues,
            'entity_update'
        );
    }

    # Actualizar giros comerciales (JSON array en EAV)
    if (array_key_exists('giros', $data)) {
        $giros = is_array($data['giros']) ? array_values(array_filter($data['giros'])) : [];
        DataCaptureService::saveData(
            $profileId,
            $entityId,
            Profile::ctx()->scopeEntityId,
            [
                'macro_context' => 'entity',
                'event_context' => 'business_info',
                'sub_context' => 'giros'
            ],
            [['variable_name' => 'entity.giros', 'value' => $giros]],
            'entity_update'
        );
    }

    return Response::json(['data' => [
        'success' => true,
        'entity_id' => $entityId,
        'message' => 'Entity updated'
    ]]);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/entities/ensure
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Ensure entity exists (create or reuse) + add relationship + contextual data
 * @body       (JSON) {
 *               entity_type, primary_name, national_id, national_isocode,
 *               relation_kind,
 *               email, phone, address (datos contextuales guardados por relation_kind)
 *             }
 * @note       Los módulos usan este endpoint para agregar entities desde su contexto.
 *             Si el entity ya existe (por identity_hash), se reutiliza.
 *             La data contextual (email, phone, address) se guarda con event_context=relation_kind
 *             permitiendo que un entity tenga diferentes direcciones/teléfonos por contexto.
 */
Router::register(['POST'], 'ensure', function() {
    $data = Args::ctx()->opt;
    $options = ['scope_entity_id' => Profile::ctx()->scopeEntityId];

    # Validaciones
    if (empty($data['primary_name'])) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'primary_name is required'
        ]], 400);
    }

    $relationKind = $data['relation_kind'] ?? 'contact';

    $tenant = Tenant::validateForWrite($options);
    if (!$tenant['valid']) {
        return Response::json(['data' => [
            'success' => false,
            'message' => $tenant['error']
        ]], 403);
    }

    # Paso 1: Buscar entity existente por identity_hash
    $entityId = null;
    $created = false;
    $identityHash = null;

    if (!empty($data['national_id']) && !empty($data['national_isocode'])) {
        $upsertIdType = $data['national_id_type']
            ?? GeoService::detectNationalIdType($data['national_isocode'], $data['national_id'])
            ?? 'TAX_ID';
        $identityHash = Entity::calculateIdentityHash(
            $data['national_isocode'],
            $data['national_id'],
            $upsertIdType
        );

        $existing = CONN::dml(
            "SELECT entity_id FROM entities WHERE identity_hash = :hash LIMIT 1",
            [':hash' => $identityHash]
        );

        if (!empty($existing)) {
            $entityId = (int)$existing[0]['entity_id'];
        }
    }

    # Paso 2: Si no existe, crear entity
    if (!$entityId) {
        $sql = "INSERT INTO entities
                (entity_type, primary_name, national_id, national_isocode,
                 identity_hash, status, created_by_profile_id, created_at)
                VALUES
                (:entity_type, :primary_name, :national_id, :national_isocode,
                 :identity_hash, 'active', :created_by, NOW())";

        $result = CONN::nodml($sql, [
            ':entity_type' => $data['entity_type'] ?? 'general',
            ':primary_name' => $data['primary_name'],
            ':national_id' => $data['national_id'] ?? null,
            ':national_isocode' => $data['national_isocode'] ?? 'CL',
            ':identity_hash' => $identityHash,
            ':created_by' => Profile::ctx()->profileId
        ]);

        if (!$result['success']) {
            return Response::json(['data' => [
                'success' => false,
                'message' => 'Error creating entity: ' . ($result['error'] ?? 'Unknown')
            ]], 500);
        }

        $entityId = (int)$result['last_id'];
        $created = true;
    }

    # Paso 3: Crear relación (Graph::create aplica role templates automáticamente)
    $graphResult = Graph::create([
        'profile_id' => Profile::ctx()->profileId,
        'entity_id' => $entityId,
        'relation_kind' => $relationKind
    ], $options);

    # Paso 4: Guardar data contextual en EAV con event_context = relation_kind
    # Esto permite: proveedor tiene dirección de despacho, cliente tiene dirección de facturación
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
            Profile::ctx()->profileId,
            $entityId,
            Profile::ctx()->scopeEntityId,
            [
                'macro_context' => 'entity',
                'event_context' => $relationKind,  # Contexto = tipo de relación
                'sub_context' => 'default'
            ],
            $eavValues,
            'entity_ensure'
        );
    }

    # Paso 5: Devolver entity completo con todos sus relation_kinds y data
    $entity = CONN::dml(
        "SELECT entity_id, primary_name, entity_type, national_id, national_isocode, status
         FROM entities WHERE entity_id = :id",
        [':id' => $entityId]
    )[0] ?? null;

    # Obtener relation_kinds dentro del scope activo
    $tenantFilterEnsure = Tenant::filter('scope_entity_id', $options);
    $relSql = "SELECT DISTINCT relation_kind FROM entity_relationships
               WHERE entity_id = :id AND status = 'active'" . $tenantFilterEnsure['sql'];
    $relParams = array_merge([':id' => $entityId], $tenantFilterEnsure['params']);
    $relations = CONN::dml($relSql, $relParams) ?? [];
    $entity['relation_kinds'] = array_column($relations, 'relation_kind');

    # Obtener data EAV (la más reciente de cualquier contexto)
    $eav = DataCaptureService::getHotData($entityId, ['entity.email', 'entity.phone', 'entity.address', 'entity.giros']);
    $entity['email'] = $eav['data']['entity.email']['value'] ?? null;
    $entity['phone'] = $eav['data']['entity.phone']['value'] ?? null;
    $entity['address'] = $eav['data']['entity.address']['value'] ?? null;
    $entity['giros'] = $eav['data']['entity.giros']['value'] ?? [];

    return Response::json(['data' => [
        'success' => true,
        'created' => $created,
        'entity_id' => $entityId,
        'entity' => $entity,
        'relationship' => $graphResult,
        'context' => $relationKind
    ]], $created ? 201 : 200);
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

    # Calculate identity hash — tipo vía query param o auto-detect
    $checkIdType = Args::$OPT['national_id_type']
        ?? GeoService::detectNationalIdType($isocode, $nationalId)
        ?? 'TAX_ID';
    $identityHash = Entity::calculateIdentityHash($isocode, $nationalId, $checkIdType);

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
    $profileId = Profile::ctx()->profileId;
    $options = ['scope_entity_id' => Profile::ctx()->scopeEntityId, 'force_scope' => true];
    $params = [];

    # Estadísticas basadas en relaciones dentro del scope activo
    $tenantFilter = Tenant::filter('er.scope_entity_id', $options);
    $sql = "SELECT
                COUNT(DISTINCT e.entity_id) as total,
                SUM(CASE WHEN er.relation_kind = 'supplier' THEN 1 ELSE 0 END) as suppliers,
                SUM(CASE WHEN er.relation_kind = 'customer' THEN 1 ELSE 0 END) as customers,
                SUM(CASE WHEN e.entity_type = 'person' THEN 1 ELSE 0 END) as persons
            FROM entities e
            INNER JOIN entity_relationships er ON e.entity_id = er.entity_id
            WHERE er.status = 'active'"
            . $tenantFilter['sql'];
    $params = array_merge($params, $tenantFilter['params']);

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
 * @endpoint   /api/entities/relationships/create
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Create relationship between profile and entity
 * @body       (JSON) {entity_id, relation_kind, relationship_label?, scope_entity_id?}
 */
Router::register(['POST'], 'relationships/create', function() {
    $data = Args::ctx()->opt;

    $entityId = (int)($data['entity_id'] ?? 0);
    $relationKind = $data['relation_kind'] ?? 'contact';
    $relationshipLabel = $data['relationship_label'] ?? null;

    # scope_entity_id: if provided (e.g., for owner), use it; otherwise use current scope
    $scopeEntityId = isset($data['scope_entity_id'])
        ? (int)$data['scope_entity_id']
        : Profile::ctx()->scopeEntityId;

    $options = ['scope_entity_id' => $scopeEntityId];

    if ($entityId <= 0) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'entity_id is required'
        ]], 400);
    }

    $relationData = [
        'profile_id' => Profile::ctx()->profileId,
        'entity_id' => $entityId,
        'relation_kind' => $relationKind
    ];

    # Add optional label (for owner: CEO, Director, etc.)
    if ($relationshipLabel) {
        $relationData['relationship_label'] = $relationshipLabel;
    }

    $result = Graph::createIfNotExists($relationData, $options);

    $code = $result['success'] ? 201 : 400;
    return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/entities/relationships/delete
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Delete/deactivate relationship between profile and entity
 * @body       (JSON) {entity_id, relation_kind}
 */
Router::register(['POST'], 'relationships/delete', function() {
    $data = Args::ctx()->opt;

    $entityId = (int)($data['entity_id'] ?? 0);
    $relationKind = $data['relation_kind'] ?? '';

    if ($entityId <= 0 || empty($relationKind)) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'entity_id and relation_kind are required'
        ]], 400);
    }

    $result = CONN::nodml(
        "UPDATE entity_relationships
         SET status = 'inactive', updated_by_profile_id = :actor
         WHERE profile_id = :profile_id
           AND entity_id = :entity_id
           AND relation_kind = :kind
           AND status = 'active'",
        [
            ':profile_id' => Profile::ctx()->profileId,
            ':entity_id' => $entityId,
            ':kind' => $relationKind,
            ':actor' => Profile::ctx()->profileId
        ]
    );

    if (($result['rowCount'] ?? 0) === 0) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'Relationship not found'
        ]], 404);
    }

    return Response::json(['data' => ['success' => true, 'message' => 'Relationship deleted']]);
}, ROUTER_SCOPE_WRITE);

/**
 * @endpoint   /api/entities/relationships/update
 * @method     POST
 * @scope      ROUTER_SCOPE_WRITE
 * @purpose    Update relationship label
 * @body       (JSON) {entity_id, relation_kind, relationship_label}
 */
Router::register(['POST'], 'relationships/update', function() {
    $data = Args::ctx()->opt;

    $entityId = (int)($data['entity_id'] ?? 0);
    $relationKind = $data['relation_kind'] ?? '';
    $relationshipLabel = $data['relationship_label'] ?? null;

    if ($entityId <= 0 || empty($relationKind)) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'entity_id and relation_kind are required'
        ]], 400);
    }

    $result = CONN::nodml(
        "UPDATE entity_relationships
         SET relationship_label = :label, updated_by_profile_id = :actor
         WHERE profile_id = :profile_id
           AND entity_id = :entity_id
           AND relation_kind = :kind
           AND status = 'active'",
        [
            ':profile_id' => Profile::ctx()->profileId,
            ':entity_id' => $entityId,
            ':kind' => $relationKind,
            ':label' => $relationshipLabel,
            ':actor' => Profile::ctx()->profileId
        ]
    );

    if (($result['rowCount'] ?? 0) === 0) {
        return Response::json(['data' => [
            'success' => false,
            'message' => 'Relationship not found'
        ]], 404);
    }

    return Response::json(['data' => ['success' => true, 'message' => 'Relationship updated']]);
}, ROUTER_SCOPE_WRITE);
