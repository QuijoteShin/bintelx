<?php // bintelx/kernel/DataCaptureService.php

namespace bX;

use Exception; // Using base Exception


// Sin autoload o distinto contexto necesitas:
// use bX\CONN;
// use bX\Log;

class DataCaptureService {

    /**
     * Defines a new capturable field type for an application or updates an existing one.
     *
     * @param string $applicationName The unique name of the application (e.g., 'CDC_APP').
     * @param array $fieldDefinition Associative array containing field properties:
     *        'field_name' (string, required): Unique name for this field (e.g., 'DM_BRTHDTC').
     *        'label' (string, optional): User-friendly label for UI (e.g., "Date of Birth").
     *        'data_type' (string, required): Base storage type (VARCHAR, NUMERIC, DATE, BOOLEAN).
     *        'attributes_json' (string|array, optional): JSON for UI hints, validation, datalists, calculation, constraints.
     *        'is_active' (bool, optional, default: true): Whether the definition is active.
     *        'description' (string, optional): Can be passed here or within attributes_json.
     * @param string $actorUserId User ID performing this definition change.
     * @return array ['success' => bool, 'definition_id' => int|null, 'message' => string]
     */
    public static function defineCaptureField(
        string $applicationName,
        array $fieldDefinition,
        string $actorUserId
    ): array {
        if (empty($fieldDefinition['field_name']) || empty($fieldDefinition['data_type'])) {
            Log::logWarning("defineCaptureField: Missing field_name or data_type.", ['app' => $applicationName, 'def' => $fieldDefinition]);
            return ['success' => false, 'message' => "field_name and data_type are required."];
        }

        // Iniciar transacción explícitamente
        if (!CONN::isInTransaction()) { // Prevenir anidamiento innecesario si ya está en una.
            CONN::begin();
            $ownTransaction = true;
        } else {
            $ownTransaction = false;
        }
        
        try {
            $fieldName = $fieldDefinition['field_name'];
            $label = $fieldDefinition['label'] ?? null;
            $dataType = strtoupper($fieldDefinition['data_type']);
            $attributesInput = $fieldDefinition['attributes_json'] ?? []; // Default to array
            $isActive = $fieldDefinition['is_active'] ?? true;

            $attributesJson = null;
            // Asegurar que attributesInput sea un array para manipulación
            if (is_string($attributesInput)) {
                $decodedAttributes = json_decode($attributesInput, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $attributesInput = $decodedAttributes;
                } else {
                     Log::logWarning("defineCaptureField: Invalid JSON string provided for attributes_json. Treating as empty.", ['app' => $applicationName, 'field' => $fieldName, 'json_error' => json_last_error_msg()]);
                    $attributesInput = []; // Default to empty array on decode error
                }
            } elseif (!is_array($attributesInput)) {
                 $attributesInput = []; // Si no es string ni array, default a array vacío
            }

            // Incorporar 'description' en attributes_json si se pasa por separado y no existe ya.
            if (isset($fieldDefinition['description']) && !isset($attributesInput['description'])) {
                $attributesInput['description'] = $fieldDefinition['description'];
            }
            $attributesJson = !empty($attributesInput) ? json_encode($attributesInput) : null;


            // 1. Consultar si ya existe para determinar si es INSERT o UPDATE y para logging
            $sqlSelect = "SELECT definition_id, application_name, field_name, label, data_type, attributes_json, is_active 
                          FROM capture_definition 
                          WHERE application_name = :app_name AND field_name = :field_name";
            $existingDefRows = CONN::dml($sqlSelect, [':app_name' => $applicationName, ':field_name' => $fieldName]);
            $existingDef = $existingDefRows[0] ?? null;
            $previousDefinitionJson = $existingDef ? json_encode($existingDef) : null;

            $definitionId = null;
            $actionType = '';

            if ($existingDef) { // UPDATE
                $definitionId = (int)$existingDef['definition_id'];
                $actionType = 'DEFINITION_UPDATED';
                $sqlUpdate = "UPDATE capture_definition SET 
                                label = :label, data_type = :data_type, attributes_json = :attributes_json,
                                is_active = :is_active, updated_at = NOW()
                              WHERE definition_id = :def_id";
                $updateResult = CONN::nodml($sqlUpdate, [
                    ':label' => $label, ':data_type' => $dataType, ':attributes_json' => $attributesJson,
                    ':is_active' => $isActive, ':def_id' => $definitionId
                ]);
                if (!$updateResult['success']) {
                    throw new Exception("Failed to update capture field definition for '$fieldName'. DB error: ".($updateResult['error'] ?? 'Unknown'));
                }
            } else { // INSERT
                $actionType = 'DEFINITION_CREATED';
                $sqlInsert = "INSERT INTO capture_definition 
                                (application_name, field_name, label, data_type, attributes_json, is_active, created_at, updated_at)
                              VALUES (:app_name, :field_name, :label, :data_type, :attributes_json, :is_active, NOW(), NOW())";
                $insertResult = CONN::nodml($sqlInsert, [
                    ':app_name' => $applicationName, ':field_name' => $fieldName, ':label' => $label,
                    ':data_type' => $dataType, ':attributes_json' => $attributesJson, ':is_active' => $isActive
                ]);
                if (!$insertResult['success'] || empty($insertResult['last_id'])) {
                    throw new Exception("Failed to insert new capture field definition for '$fieldName'. DB error: ".($insertResult['error'] ?? 'Unknown'));
                }
                $definitionId = (int)$insertResult['last_id'];
            }

            // Para el log, obtener el estado actual después del insert/update
            $currentDefForLogRows = CONN::dml($sqlSelect, [':app_name' => $applicationName, ':field_name' => $fieldName]);
            $newDefinitionJson = !empty($currentDefForLogRows[0]) ? json_encode($currentDefForLogRows[0]) : null;

            self::logDefinitionChange($definitionId, $actorUserId, $actionType, $previousDefinitionJson, $newDefinitionJson);

            if ($ownTransaction) CONN::commit();
            return ['success' => true, 'definition_id' => $definitionId, 'message' => "Capture field '$fieldName' for app '$applicationName' processed."];

        } catch (Exception $e) {
            if ($ownTransaction && CONN::isInTransaction()) CONN::rollback();
            Log::logError("DataCaptureService::defineCaptureField Exception: " . $e->getMessage(), ['app' => $applicationName, 'def' => $fieldDefinition, 'trace_short' => substr($e->getTraceAsString(), 0, 1000)]);
            return ['success' => false, 'definition_id' => null, 'message' => "Error defining field: " . $e->getMessage()];
        }
    }

    /**
     * Saves or updates a set of data fields for a given application and context.
     * Allows per-field overrides for audit metadata (reason, eventType, signatureType).
     */
    public static function saveRecord(
        string $applicationName,
        array $contextKeyValues,
        array $fieldsData, // Array de [['field_name'=>'X', 'value'=>'Y', 'reason?'=>..., 'eventType?'=>..., 'signatureType?'=>...], ...]
        string $actorUserId,
        string $defaultChangeReason = null,
        string $defaultSignatureType = null,
        string $defaultEventType = null // e.g., 'FORM_SAVE', 'INITIAL_PATIENT_INTAKE'
    ): array {
        if (!CONN::isInTransaction()) {
            CONN::begin();
            $ownTransaction = true;
        } else {
            $ownTransaction = false;
        }

        try {
            $contextGroupId = self::getOrCreateContextGroup($applicationName, $contextKeyValues);
            // getOrCreateContextGroup ahora lanza excepción en fallo, así que no es necesario chequear $contextGroupId aquí.

            $savedFieldsInfo = [];

            foreach ($fieldsData as $fieldInput) {
                if (!isset($fieldInput['field_name']) || !array_key_exists('value', $fieldInput)) {
                     Log::logWarning("saveRecord: Invalid field data structure. Skipping.", ['app' => $applicationName, 'ctx' => $contextKeyValues, 'input' => $fieldInput]);
                     continue; // O lanzar excepción si se prefiere que toda la operación falle.
                }
                $fieldName = $fieldInput['field_name'];
                $fieldValue = $fieldInput['value'];
                
                $changeReason = $fieldInput['reason'] ?? $defaultChangeReason;
                $signatureType = $fieldInput['signatureType'] ?? $defaultSignatureType;
                $eventType = $fieldInput['eventType'] ?? $defaultEventType;

                $definition = self::getCaptureDefinition($applicationName, $fieldName);
                if (!$definition) { // getCaptureDefinition ya filtra por is_active = 1
                    throw new Exception("No active capture definition found for field '$fieldName' in application '$applicationName'.");
                }
                $definitionId = (int)$definition['definition_id'];
                $dataType = strtoupper($definition['data_type']);

                $existingCaptureData = self::getCaptureDataByDefinitionAndContext($definitionId, $contextGroupId);
                $captureDataId = isset($existingCaptureData['capture_data_id']) ? (int)$existingCaptureData['capture_data_id'] : null;
                $currentSequentialVersionNum = $captureDataId ? (int)$existingCaptureData['current_sequential_version_num'] : 0;
                $nextSequentialVersionNum = $currentSequentialVersionNum + 1;

                $effectiveEventType = $eventType ?: ($captureDataId ? 'UPDATE' : 'INITIAL_ENTRY'); // Default event type if not provided

                $dbFieldValueVarchar = null;
                $dbFieldValueNumeric = null;
                switch ($dataType) {
                    case 'NUMERIC': case 'INTEGER': case 'FLOAT': case 'DECIMAL':
                        if ($fieldValue === null) {
                            $dbFieldValueNumeric = null;
                        } elseif (is_numeric($fieldValue)) {
                            $dbFieldValueNumeric = $fieldValue;
                        } else {
                            Log::logWarning("saveRecord: Non-numeric value for NUMERIC field '$fieldName'. Storing as NULL.", ['value' => $fieldValue, 'app' => $applicationName, 'ctx' => $contextKeyValues]);
                            $dbFieldValueNumeric = null; // Forzar NULL si no es numérico y no es NULL
                        }
                        break;
                    case 'DATE': // Podría requerir validación de formato YYYY-MM-DD
                    case 'DATETIME': // Podría requerir validación de formato ISO8601
                    case 'BOOLEAN': // Podría normalizarse a '0'/'1' o 'true'/'false'
                    case 'VARCHAR': default:
                        $dbFieldValueVarchar = ($fieldValue !== null) ? (string)$fieldValue : null;
                        break;
                }

                $currentCaptureDataIdRefForVersion = $captureDataId ?? 0; // Será 0 para un nuevo capture_data
                $sqlVersion = "INSERT INTO capture_data_version 
                                   (capture_data_id_ref, sequential_version_num, field_value_varchar_versioned, field_value_numeric_versioned,
                                    changed_at, changed_by_user_id, change_reason, signature_type, event_type)
                               VALUES (:cd_id_ref, :seq_num, :val_vchar, :val_num, NOW(), :actor_id, :reason, :sig_type, :evt_type)";
                $versionInsertResult = CONN::nodml($sqlVersion, [
                    ':cd_id_ref' => $currentCaptureDataIdRefForVersion, ':seq_num' => $nextSequentialVersionNum,
                    ':val_vchar' => $dbFieldValueVarchar, ':val_num' => $dbFieldValueNumeric,
                    ':actor_id' => $actorUserId, ':reason' => $changeReason,
                    ':sig_type' => $signatureType, ':evt_type' => $effectiveEventType
                ]);
                if (!$versionInsertResult['success'] || empty($versionInsertResult['last_id'])) {
                    throw new Exception("Failed to insert data version for field '$fieldName'. DB error: ".($versionInsertResult['error'] ?? 'Unknown'));
                }
                $newVersionId = (int)$versionInsertResult['last_id'];

                $newOrUpdatedCaptureDataId = null;
                if (!$captureDataId) { // --- INSERT new capture_data ---
                    $sqlInsertHot = "INSERT INTO capture_data 
                                        (definition_id_ref, context_group_id_ref, field_value_varchar, field_value_numeric,
                                         current_version_id_ref, current_sequential_version_num, created_at, updated_at)
                                     VALUES (:def_id, :ctx_id, :val_vchar, :val_num, :curr_ver_id, :seq_num, NOW(), NOW())";
                    $insertHotResult = CONN::nodml($sqlInsertHot, [
                        ':def_id' => $definitionId, ':ctx_id' => $contextGroupId,
                        ':val_vchar' => $dbFieldValueVarchar, ':val_num' => $dbFieldValueNumeric,
                        ':curr_ver_id' => $newVersionId, 
                        ':seq_num' => $nextSequentialVersionNum
                    ]);
                    if (!$insertHotResult['success'] || empty($insertHotResult['last_id'])) {
                        throw new Exception("Failed to insert new hot data record for field '$fieldName'. DB error: ".($insertHotResult['error'] ?? 'Unknown'));
                    }
                    $newOrUpdatedCaptureDataId = (int)$insertHotResult['last_id'];

                    $updateVersionRef = CONN::nodml("UPDATE capture_data_version SET capture_data_id_ref = :cd_id WHERE version_id = :v_id",
                        [':cd_id' => $newOrUpdatedCaptureDataId, ':v_id' => $newVersionId]);
                    if(!$updateVersionRef['success']){
                         throw new Exception("CRITICAL: Failed to update version's capture_data_id_ref for field '$fieldName'. Orphaned version created: $newVersionId");
                    }
                } else { // --- UPDATE existing capture_data ---
                    $newOrUpdatedCaptureDataId = $captureDataId;
                    $sqlUpdateHot = "UPDATE capture_data SET
                                        field_value_varchar = :val_vchar, field_value_numeric = :val_num,
                                        current_version_id_ref = :curr_ver_id, current_sequential_version_num = :curr_seq_num,
                                        updated_at = NOW()
                                     WHERE capture_data_id = :cd_id";
                    $updateHotResult = CONN::nodml($sqlUpdateHot, [
                        ':val_vchar' => $dbFieldValueVarchar, ':val_num' => $dbFieldValueNumeric,
                        ':curr_ver_id' => $newVersionId, ':curr_seq_num' => $nextSequentialVersionNum,
                        ':cd_id' => $newOrUpdatedCaptureDataId
                    ]);
                    if (!$updateHotResult['success']) {
                        throw new Exception("Failed to update hot data record for field '$fieldName'. DB error: ".($updateHotResult['error'] ?? 'Unknown'));
                    }
                }
                $savedFieldsInfo[] = [
                    'field_name' => $fieldName,
                    'capture_data_id' => $newOrUpdatedCaptureDataId,
                    'version_id' => $newVersionId,
                    'sequential_version_num' => $nextSequentialVersionNum
                ];
            }

            if ($ownTransaction) CONN::commit();
            return [
                'success' => true, 'message' => 'Data record(s) saved successfully with versioning.',
                'context_group_id' => $contextGroupId, 'saved_fields_info' => $savedFieldsInfo
            ];

        } catch (Exception $e) {
            if ($ownTransaction && CONN::isInTransaction()) CONN::rollback();
            Log::logError("DataCaptureService::saveRecord Exception: " . $e->getMessage(), ['app' => $applicationName, 'ctxKeys' => $contextKeyValues, 'field_names_attempted' => array_column($fieldsData, 'field_name'), 'trace_short' => substr($e->getTraceAsString(), 0, 1000)]);
            return ['success' => false, 'message' => "Error saving record: " . $e->getMessage(), 'context_group_id' => null, 'saved_fields_info' => null];
        }
    }

    /**
     * Retrieves the current ("hot") values of specified fields for a given context,
     * including their definition metadata. Returns definitions even if no data is captured yet for them.
     */
    public static function getRecord(string $applicationName, array $contextKeyValues, array $fieldNames = null): array {
        // No transacción explícita aquí, ya que es una operación de solo lectura.
        try {
            // Primero, intenta obtener el context_group_id si ya existe.
            // No crearlo si no existe, porque getRecord es solo para leer.
            // Si contextKeyValues está vacío, es un "contexto global de aplicación".
            $contextGroupId = self::getContextGroup($applicationName, $contextKeyValues);

            // Si el contexto no existe (y no es el global vacío que podría no tener items) Y NO es el contexto global vacío
            // Y contextKeyValues NO está vacío, significa que el contexto específico no existe.
            if ($contextGroupId === null && !empty($contextKeyValues)) {
                 return ['success' => true, 'data' => [], 'message' => 'Context group not found for the given specific criteria. No data captured yet for this exact context.'];
            }
            // Si contextKeyValues está vacío Y el contexto global no existe (raro, implicaría que ni context_group está creado para la app)
            if ($contextGroupId === null && empty($contextKeyValues)) {
                Log::logInfo("getRecord: Attempting to get/create global context for app '$applicationName' as it was not found.", ['app' => $applicationName]);
                // Para getRecord, si el contexto es global y no existe, no deberíamos crearlo aquí,
                // sino devolver definiciones de campos sin datos.
                // Sin embargo, un LEFT JOIN en capture_data necesita un context_group_id.
                // Si no hay datos, no hay contexto, pero aún queremos las definiciones.
                // Vamos a ajustar la consulta para que funcione incluso si contextGroupId es null (para el caso de "dame las definiciones").
            }


            $sql = "SELECT cdef.field_name, cdef.label, cdef.data_type, cdef.attributes_json, 
                           cd.field_value_varchar, cd.field_value_numeric, 
                           cd.current_sequential_version_num, cd.updated_at AS data_updated_at,
                           cd.capture_data_id, cd.current_version_id_ref
                    FROM capture_definition cdef "; // Iniciar con capture_definition

            // Si tenemos un contextGroupId (es decir, el contexto existe, con o sin datos), hacemos LEFT JOIN a capture_data
            // Si contextGroupId es null (contexto no específico o no existente), el LEFT JOIN simplemente no encontrará coincidencias en capture_data.
            $sql .= "LEFT JOIN capture_data cd ON cdef.definition_id = cd.definition_id_ref AND cd.context_group_id_ref = :ctx_grp_id ";
            $sql .= "WHERE cdef.application_name = :app_name AND cdef.is_active = 1";

            $queryParams = [':app_name' => $applicationName];
            // :ctx_grp_id puede ser null si el contexto no se resolvió o es global y no tiene items aún.
            // La BD manejará `col = NULL` en el JOIN como falso, lo cual es el comportamiento deseado para el LEFT JOIN.
            $queryParams[':ctx_grp_id'] = $contextGroupId;


            if ($fieldNames !== null && !empty($fieldNames)) {
                $placeholders = [];
                foreach ($fieldNames as $index => $fn) {
                    $paramName = ":fn" . $index;
                    $placeholders[] = $paramName;
                    $queryParams[$paramName] = $fn;
                }
                $sql .= " AND cdef.field_name IN (" . implode(',', $placeholders) . ")";
            }
            $sql .= " ORDER BY cdef.field_name";

            $rows = CONN::dml($sql, $queryParams);
            $results = [];
            if ($rows) {
                foreach ($rows as $row) {
                    $value = null;
                    if ($row['capture_data_id'] !== null) { // Solo hay valor si hay una entrada en capture_data
                        switch (strtoupper($row['data_type'])) {
                            case 'NUMERIC': case 'INTEGER': case 'FLOAT': case 'DECIMAL':
                                $value = $row['field_value_numeric'];
                                break;
                            default:
                                $value = $row['field_value_varchar'];
                                break;
                        }
                    }
                    $results[$row['field_name']] = [
                        'value' => $value,
                        'label' => $row['label'],
                        'data_type' => $row['data_type'],
                        'attributes' => json_decode($row['attributes_json'], true) ?: [],
                        'current_version_num' => $row['capture_data_id'] !== null ? (int)$row['current_sequential_version_num'] : null,
                        'data_last_updated_at' => $row['capture_data_id'] !== null ? $row['data_updated_at'] : null,
                        '_capture_data_id' => $row['capture_data_id'] ? (int)$row['capture_data_id'] : null,
                        '_current_version_id' => $row['capture_data_id'] ? (int)$row['current_version_id_ref'] : null,
                    ];
                }
            }
            return ['success' => true, 'data' => $results, 'message' => 'Record data and definitions retrieved.'];
        } catch (Exception $e) {
            Log::logError("DataCaptureService::getRecord Exception: " . $e->getMessage(), ['app' => $applicationName, 'ctxKeys' => $contextKeyValues, 'fields' => $fieldNames, 'trace_short' => substr($e->getTraceAsString(),0,1000)]);
            return ['success' => false, 'data' => null, 'message' => "Error retrieving record: " . $e->getMessage()];
        }
    }

    /**
     * Retrieves the complete audit trail (all versions) for a specific field within a context.
     */
    public static function getAuditTrailForField(string $applicationName, array $contextKeyValues, string $fieldName): array {
        // No transacción aquí.
        try {
            $contextGroupId = self::getContextGroup($applicationName, $contextKeyValues);
            if ($contextGroupId === null && !empty($contextKeyValues)) {
                return ['success' => true, 'trail' => [], 'message' => 'Context group not found. No audit trail available.'];
            }
            // Si es contexto global vacío y no se encuentra, es un caso especial.
            // Asumimos que si $contextKeyValues está vacío, $contextGroupId será el global si existe, o null.
            // Si es null, y no se encontró nada en capture_data, no habrá trail.

            $definition = self::getCaptureDefinition($applicationName, $fieldName);
            if (!$definition) {
                return ['success' => false, 'trail' => null, 'message' => "Capture definition for field '$fieldName', app '$applicationName' not found or inactive."];
            }
            $definitionId = (int)$definition['definition_id'];
            $dataType = strtoupper($definition['data_type']);

            // Se necesita un contextGroupId para buscar capture_data_id.
            // Si el contexto es global y no existe, $contextGroupId será null.
            if ($contextGroupId === null) {
                 // Si el contexto es el global vacío y no se encontró, o si es un contexto específico no encontrado,
                 // entonces no puede haber datos capturados para él.
                 return ['success' => true, 'trail' => [], 'message' => 'Context group not resolved, no data to audit for this context.'];
            }


            $captureDataInfo = self::getCaptureDataByDefinitionAndContext($definitionId, $contextGroupId);
            if (!$captureDataInfo || !isset($captureDataInfo['capture_data_id'])) {
                return ['success' => true, 'trail' => [], 'message' => "No data (and thus no audit trail) found for field '$fieldName' in this specific context."];
            }
            $captureDataId = (int)$captureDataInfo['capture_data_id'];

            $sql = "SELECT version_id, sequential_version_num, field_value_varchar_versioned, field_value_numeric_versioned,
                           changed_at, changed_by_user_id, change_reason, signature_type, event_type
                    FROM capture_data_version
                    WHERE capture_data_id_ref = :cd_id
                    ORDER BY sequential_version_num DESC";

            $versionRows = CONN::dml($sql, [':cd_id' => $captureDataId]);
            $trail = [];
            if ($versionRows) {
                foreach ($versionRows as $row) {
                    $valueAtVersion = null;
                    switch ($dataType) {
                        case 'NUMERIC': case 'INTEGER': case 'FLOAT': case 'DECIMAL':
                            $valueAtVersion = $row['field_value_numeric_versioned'];
                            break;
                        default:
                            $valueAtVersion = $row['field_value_varchar_versioned'];
                            break;
                    }
                    $trailEntry = $row;
                    $trailEntry['value_at_version'] = $valueAtVersion;
                    unset($trailEntry['field_value_varchar_versioned'], $trailEntry['field_value_numeric_versioned']);
                    $trail[] = $trailEntry;
                }
            }
            return ['success' => true, 'trail' => $trail, 'message' => 'Audit trail retrieved.'];
        } catch (Exception $e) {
            Log::logError("DataCaptureService::getAuditTrailForField Exception: " . $e->getMessage(), ['app' => $applicationName, 'ctxKeys' => $contextKeyValues, 'field' => $fieldName, 'trace_short' => substr($e->getTraceAsString(),0,1000)]);
            return ['success' => false, 'trail' => null, 'message' => "Error retrieving audit trail: " . $e->getMessage()];
        }
    }

    // --- Helper / Private static methods ---

    private static function getCaptureDefinition(string $applicationName, string $fieldName): ?array {
        // Este método asume que se busca una definición activa.
        $rows = CONN::dml(
            "SELECT definition_id, label, data_type, attributes_json 
             FROM capture_definition 
             WHERE application_name = :app_name AND field_name = :field_name AND is_active = 1 LIMIT 1",
            [':app_name' => $applicationName, ':field_name' => $fieldName]
        );
        return $rows[0] ?? null;
    }

    private static function getCaptureDataByDefinitionAndContext(int $definitionId, int $contextGroupId): ?array {
        $rows = CONN::dml(
            "SELECT capture_data_id, current_sequential_version_num 
             FROM capture_data 
             WHERE definition_id_ref = :def_id AND context_group_id_ref = :ctx_grp_id LIMIT 1",
            [':def_id' => $definitionId, ':ctx_grp_id' => $contextGroupId]
        );
        return $rows[0] ?? null;
    }

    /**
     * Retrieves an existing ContextGroup ID. Returns null if not found.
     * Does NOT create if not found.
     */
    private static function getContextGroup(string $applicationName, array $contextKeyValues): ?int {
        if (empty($contextKeyValues)) { // Check for global context for the application
            $sqlFindEmpty = "SELECT cg.context_group_id FROM context_group cg
                             LEFT JOIN context_group_item cgi ON cg.context_group_id = cgi.context_group_id_ref
                             WHERE cg.application_name = :app_name AND cgi.context_group_item_id IS NULL
                             LIMIT 1";
            $groupRow = CONN::dml($sqlFindEmpty, [':app_name' => $applicationName]);
            return isset($groupRow[0]['context_group_id']) ? (int)$groupRow[0]['context_group_id'] : null;
        }

        ksort($contextKeyValues); 
        $numKeys = count($contextKeyValues);
        $itemConditions = [];
        $params = [':app_name' => $applicationName, ':num_keys' => $numKeys];
        $i = 0;
        foreach ($contextKeyValues as $key => $value) {
            $keyParam = ":key".$i; $valParam = ":val".$i;
            $itemConditions[] = "EXISTS (SELECT 1 FROM context_group_item cgi_match_$i 
                                          WHERE cgi_match_$i.context_group_id_ref = cg.context_group_id 
                                            AND cgi_match_$i.context_key = ".$keyParam." 
                                            AND cgi_match_$i.context_value = ".$valParam.")";
            $params[$keyParam] = $key; $params[$valParam] = (string)$value; $i++;
        }
        $itemConditionsSql = implode(" AND ", $itemConditions);

        $sql = "SELECT cg.context_group_id
                FROM context_group cg
                WHERE cg.application_name = :app_name
                  AND $itemConditionsSql
                  AND (SELECT COUNT(*) FROM context_group_item cgi_count 
                       WHERE cgi_count.context_group_id_ref = cg.context_group_id) = :num_keys
                LIMIT 1";
        
        $groupRow = CONN::dml($sql, $params);
        return isset($groupRow[0]['context_group_id']) ? (int)$groupRow[0]['context_group_id'] : null;
    }

    /**
     * Gets an existing ContextGroup ID or creates a new one if not found.
     * This method assumes it's called within a transaction if creation occurs.
     * Throws Exception on failure to create.
     */
    private static function getOrCreateContextGroup(string $applicationName, array $contextKeyValues): int {
        $contextGroupId = self::getContextGroup($applicationName, $contextKeyValues);

        if ($contextGroupId !== null) {
            return $contextGroupId;
        }

        // Not found, so create.
        $insertCgResult = CONN::nodml(
            "INSERT INTO context_group (application_name, created_at) VALUES (:app_name, NOW())",
            [':app_name' => $applicationName]
        );
        if (!$insertCgResult['success'] || empty($insertCgResult['last_id'])) {
            $dbError = $insertCgResult['error'] ?? 'Unknown DB error during context_group insert';
            Log::logError("Failed to create new context_group for application '$applicationName'. DB Error: ".$dbError, ['app' => $applicationName, 'ctxKeys' => $contextKeyValues]);
            throw new Exception("Failed to create new context_group for application '$applicationName'.");
        }
        $newContextGroupId = (int)$insertCgResult['last_id'];

        if (!empty($contextKeyValues)) {
            $sqlItem = "INSERT INTO context_group_item (context_group_id_ref, context_key, context_value, created_at) 
                        VALUES (:ctx_grp_id, :key, :value, NOW())";
            foreach ($contextKeyValues as $key => $value) {
                $itemInsertResult = CONN::nodml($sqlItem, [
                    ':ctx_grp_id' => $newContextGroupId, ':key' => $key, ':value' => (string)$value
                ]);
                if (!$itemInsertResult['success']) {
                    $dbErrorItem = $itemInsertResult['error'] ?? "Unknown DB error during context_group_item insert for key '$key'";
                    Log::logError("Failed to insert context_group_item '$key' for new group ID $newContextGroupId. DB Error: ".$dbErrorItem, ['app' => $applicationName, 'new_cgid' => $newContextGroupId, 'key_failed' => $key]);
                    throw new Exception("Failed to fully create context group items for $applicationName, key '$key'.");
                }
            }
        }
        Log::logInfo("Created new context_group.", ['app' => $applicationName, 'cgid' => $newContextGroupId, 'ctxKeys_count' => count($contextKeyValues)]);
        return $newContextGroupId;
    }

    /**
     * Logs a change in a capture definition.
     */
    private static function logDefinitionChange(
        int $definitionIdRef,
        string $actorUserId,
        string $changeDescription,
        ?string $previousDefinitionJson,
        ?string $newDefinitionJson
    ): void {
        try {
            $sql = "INSERT INTO capture_definition_version 
                        (definition_id_ref, effective_from, changed_by_user_id, change_description, 
                         previous_definition_json, new_definition_json)
                    VALUES (:def_id_ref, NOW(), :actor_id, :change_desc, :prev_json, :new_json)";
            $logResult = CONN::nodml($sql, [
                ':def_id_ref' => $definitionIdRef, ':actor_id' => $actorUserId,
                ':change_desc' => $changeDescription, ':prev_json' => $previousDefinitionJson,
                ':new_json' => $newDefinitionJson
            ]);
            if(!$logResult['success']){
                 Log::logWarning("Failed to log definition change for ID $definitionIdRef. DB Error: ".($logResult['error'] ?? 'Unknown'),
                    ['def_id' => $definitionIdRef, 'actor' => $actorUserId, 'change_type' => $changeDescription]
                );
            }
        } catch (Exception $e) {
            Log::logWarning("DataCaptureService::logDefinitionChange - Exception for ID $definitionIdRef: " . $e->getMessage(),
                ['def_id' => $definitionIdRef, 'actor' => $actorUserId, 'change_type' => $changeDescription, 'trace_short' => substr($e->getTraceAsString(),0,500)]
            );
        }
    }
}