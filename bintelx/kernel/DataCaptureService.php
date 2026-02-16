<?php
/**
 * DataCaptureService - EAV Data Persistence with ALCOA+ Compliance
 *
 * Version: 2.0
 *
 * Purpose: Servicio centralizado para captura de datos usando modelo EAV versionado
 * con soporte completo para:
 * - ALCOA+ (Attributable, Legible, Contemporaneous, Original, Accurate)
 * - Multi-tenant (scope_entity_id) via Tenant:: — NULL = NO ACCESS (AND 1=0)
 *   Columnas de contexto (macro/event/sub/parent) mantienen NULL como identidad vía contextWhere()
 * - Multi-source (source_system, device_id)
 * - Versionado atómico (is_active)
 *
 * ============================================================================
 * REGLA CRÍTICA: NUNCA ACCEDER DIRECTAMENTE A LAS TABLAS EAV
 * ============================================================================
 *
 * Las tablas internas del sistema EAV son:
 *   - data_dictionary
 *   - data_values_history
 *   - context_groups
 *
 * PROHIBIDO hacer SELECT/INSERT/UPDATE/DELETE directo a estas tablas.
 * SIEMPRE usar los métodos de esta clase:
 *
 *   - defineCaptureField()  → Definir/actualizar variables (IDEMPOTENTE)
 *   - saveData()            → Guardar valores con contexto ALCOA+
 *   - getHotData()          → Leer valores actuales
 *   - getAuditTrailForVariable() → Leer historial de cambios
 *
 * ============================================================================
 * IDEMPOTENCIA
 * ============================================================================
 *
 * defineCaptureField() es IDEMPOTENTE (UPSERT por unique_name):
 *   - Si la variable no existe → INSERT
 *   - Si la variable existe → UPDATE
 *
 * Por lo tanto, NO es necesario verificar existencia antes de llamar:
 *
 *   // INCORRECTO - no hacer esto
 *   $exists = CONN::dml("SELECT 1 FROM data_dictionary WHERE unique_name = :name");
 *   if (empty($exists)) {
 *       DataCaptureService::defineCaptureField($def, $profileId);
 *   }
 *
 *   // CORRECTO - llamar directamente
 *   DataCaptureService::defineCaptureField($def, $profileId);
 *
 * ============================================================================
 *
 * @package bX
 * @version 2.0
 * @date 2025-11-14
 */

namespace bX;

use Exception;

class DataCaptureService {

    /**
     * Define o actualiza una variable en el diccionario de datos
     *
     * IDEMPOTENTE: Realiza UPSERT por unique_name.
     * - Si no existe → INSERT
     * - Si existe → UPDATE
     *
     * NO es necesario verificar existencia antes de llamar.
     * Llamar directamente cuantas veces sea necesario.
     *
     * @param array $fieldDefinition ['unique_name', 'label', 'data_type', 'is_pii', 'attributes_json', 'status']
     * @param int $actorProfileId ID del perfil que define el campo
     * @return array ['success' => bool, 'variable_id' => int, 'message' => string]
     */
    public static function defineCaptureField(
        array $fieldDefinition,
        int $actorProfileId
    ): array {
        if (empty($fieldDefinition['unique_name']) || empty($fieldDefinition['data_type'])) {
            return ['success' => false, 'message' => "unique_name and data_type are required."];
        }

        if (!CONN::isInTransaction()) {
            CONN::begin();
            $ownTransaction = true;
        } else {
            $ownTransaction = false;
        }

        try {
            $uniqueName = $fieldDefinition['unique_name'];
            $dataType = strtoupper($fieldDefinition['data_type']);

            // 1. Verificar si ya existe
            $sqlSelect = "SELECT variable_id FROM data_dictionary WHERE unique_name = :name";
            $existingDefRows = CONN::dml($sqlSelect, [':name' => $uniqueName]);
            $existingId = $existingDefRows[0]['variable_id'] ?? null;

            $params = [
                ':unique_name' => $uniqueName,
                ':label' => $fieldDefinition['label'] ?? null,
                ':data_type' => $dataType,
                ':attributes_json' => isset($fieldDefinition['attributes_json'])
                    ? json_encode($fieldDefinition['attributes_json'])
                    : null,
                ':is_pii' => (int)($fieldDefinition['is_pii'] ?? false),
                ':status' => $fieldDefinition['status'] ?? 'active',
                ':updated_by_profile_id' => $actorProfileId
            ];

            if ($existingId) {
                // UPDATE existente
                $sql = "UPDATE data_dictionary SET
                            label = :label,
                            data_type = :data_type,
                            attributes_json = :attributes_json,
                            is_pii = :is_pii,
                            status = :status,
                            updated_by_profile_id = :updated_by_profile_id,
                            updated_at = NOW()
                        WHERE unique_name = :unique_name";
                $result = CONN::nodml($sql, $params);
                $variableId = $existingId;
            } else {
                // INSERT nuevo
                $sql = "INSERT INTO data_dictionary
                            (unique_name, label, data_type, attributes_json, is_pii, status,
                             created_by_profile_id, updated_by_profile_id)
                        VALUES
                            (:unique_name, :label, :data_type, :attributes_json, :is_pii, :status,
                             :updated_by_profile_id, :updated_by_profile_id)";
                $result = CONN::nodml($sql, $params);
                if (!$result['success']) {
                    throw new Exception("Failed to insert field definition. " . ($result['error'] ?? ''));
                }
                $variableId = (int)$result['last_id'];
            }

            if ($ownTransaction) CONN::commit();
            return ['success' => true, 'variable_id' => $variableId];

        } catch (Exception $e) {
            if ($ownTransaction && CONN::isInTransaction()) CONN::rollback();
            Log::logError("defineCaptureField Exception: " . $e->getMessage(), ['def' => $fieldDefinition]);
            return ['success' => false, 'message' => 'Operation failed. Check logs for details.'];
        }
    }

    /**
     * Guarda datos con versionado atómico y contexto completo
     *
     * Esta es la función principal de persistencia EAV con ALCOA+
     *
     * Versionado inteligente: antes de crear una nueva versión, compara el valor
     * actual vs el nuevo mediante valueHasChanged() (type-aware: tolerancia numérica
     * para DECIMAL, normalización de booleans, comparación de IDs para ENTITY_REF).
     * Solo versiona cuando hay un cambio real — esto produce audit trails limpios
     * donde cada versión representa una mutación efectiva del dato.
     *
     * @param int $actorProfileId Quién registra (ALCOA: Attributable)
     * @param int $subjectEntityId De quién es el dato (paciente, cliente, activo)
     * @param int|null $scopeEntityId En qué ámbito (empresa, estudio, proyecto) - NULL para datos personales
     * @param array $contextPayload ['macro_context', 'event_context', 'sub_context', 'parent_context_id']
     * @param array $valuesData [['variable_name', 'value', 'reason'], ...]
     * @param string $contextType Tipo de evento (ej: 'clinical_study_visit', 'profile_update', 'external_import')
     * @param string|null $sourceSystem Sistema origen (ej: 'CDC_APP', 'external.ehr.clinicaX', 'device.apple_watch')
     * @param string|null $deviceId Identificador del dispositivo (ALCOA: Original)
     *   Estándar oficial: Browser fingerprint de /public/bintelx.client.js
     *   Generado con: canvas + webgl + hardware + screen + fonts + audio (hash truncado a 100 chars)
     *   Ejemplo: BintelxClient.getDeviceId() en cliente web
     * @return array ['success', 'context_group_id', 'saved_fields_info', 'message']
     */
    public static function saveData(
        int $actorProfileId,
        int $subjectEntityId,
        ?int $scopeEntityId,
        array $contextPayload,
        array $valuesData,
        string $contextType = 'generic_update',
        ?string $sourceSystem = null,
        ?string $deviceId = null
    ): array {
        if (!CONN::isInTransaction()) {
            CONN::begin();
            $ownTransaction = true;
        } else {
            $ownTransaction = false;
        }

        try {
            // PASO 1: Obtener o crear el ContextGroup (ticket/hito)
            $contextGroupId = self::getOrCreateContextGroup(
                $actorProfileId,
                $subjectEntityId,
                $scopeEntityId,
                $contextPayload,
                $contextType
            );

            $savedFieldsInfo = [];
            $currentTimestamp = date('Y-m-d H:i:s'); // ALCOA: Contemporaneous

            // PASO 2: Iterar por cada variable a guardar
            foreach ($valuesData as $valueInput) {
                $variableName = $valueInput['variable_name'];
                $newValue = $valueInput['value'];
                $changeReason = $valueInput['reason'] ?? null;

                // 2.1 Obtener definición de la variable
                $def = self::getVariableDefinition($variableName);
                if (!$def) {
                    throw new Exception("No active definition found for variable '$variableName'.");
                }
                $variableId = (int)$def['variable_id'];
                $dataType = strtoupper($def['data_type']);

                // 2.2 Mapear valor a columna de BD correcta
                list($colName, $colValue) = self::mapValueToDbColumn($dataType, $newValue);

                // 2.3 Obtener versión actual desde SNAPSHOT (CQRS read model)
                $sqlFindCurrent = "SELECT current_value_id, current_version
                                   FROM data_values_current
                                   WHERE entity_id = :eid
                                     AND variable_id = :vid
                                   FOR UPDATE"; // Lock optimista

                $currentRow = CONN::dml($sqlFindCurrent, [
                    ':eid' => $subjectEntityId,
                    ':vid' => $variableId
                ])[0] ?? null;

                $nextVersionNum = 1;

                if ($currentRow) {
                    // Existe snapshot, obtener valor actual desde history para diff
                    $sqlGetValue = "SELECT value_string, value_decimal, value_datetime,
                                           value_boolean, value_entity_ref
                                    FROM data_values_history
                                    WHERE value_id = :vid";

                    $activeRow = CONN::dml($sqlGetValue, [
                        ':vid' => (int)$currentRow['current_value_id']
                    ])[0] ?? null;

                    if ($activeRow) {
                        // DIFF: Comparar valor actual vs nuevo
                        $currentValue = self::mapValueFromDbColumn($dataType, $activeRow);
                        $hasChanged = self::valueHasChanged($dataType, $currentValue, $newValue);

                        if (!$hasChanged) {
                            // Valor no cambió, skip versionado
                            $savedFieldsInfo[] = [
                                'variable_name' => $variableName,
                                'value_id' => (int)$currentRow['current_value_id'],
                                'version' => (int)$currentRow['current_version'],
                                'timestamp' => $currentTimestamp,
                                'skipped' => true,
                                'reason' => 'Value unchanged'
                            ];
                            continue; // No versionar este campo
                        }

                        $nextVersionNum = (int)$currentRow['current_version'] + 1;
                    }
                }

                // PASO ATÓMICO B: Insertar nueva versión en history (append-only, sin is_active)
                // IMPORTANTE: Historial inmutable para ALCOA+ compliance
                $sqlInsert = "INSERT INTO data_values_history
                                (entity_id, variable_id, context_group_id, profile_id, account_id,
                                 timestamp, version, reason_for_change,
                                 source_system, device_id, $colName)
                              VALUES
                                (:eid, :vid, :cgid, :pid, :aid,
                                 :ts, :ver, :reason,
                                 :source, :device, :val)";

                $insertResult = CONN::nodml($sqlInsert, [
                    ':eid' => $subjectEntityId,
                    ':vid' => $variableId,
                    ':cgid' => $contextGroupId,
                    ':pid' => $actorProfileId,
                    ':aid' => \bX\Profile::ctx()->accountId,  // ALCOA: Attributable (legal identity)
                    ':ts' => $currentTimestamp,        // ALCOA: Contemporaneous
                    ':ver' => $nextVersionNum,
                    ':reason' => $changeReason,        // ALCOA: Accurate + Attributable
                    ':source' => $sourceSystem,        // ALCOA: Original
                    ':device' => $deviceId,            // ALCOA: Original
                    ':val' => $colValue
                ]);

                if (!$insertResult['success']) {
                    throw new Exception("Failed to insert new version for '$variableName'. DB Error: " . ($insertResult['error'] ?? ''));
                }

                $newVersionId = (int)$insertResult['last_id'];

                // PASO ATÓMICO C: Sincronizar SNAPSHOT (CQRS read model) - EXPLÍCITO
                $sqlUpsertSnapshot = "INSERT INTO data_values_current
                                        (entity_id, variable_id, current_value_id,
                                         current_version, current_context_group_id, last_updated_at)
                                      VALUES
                                        (:eid, :vid, :value_id, :ver, :cgid, :ts)
                                      ON DUPLICATE KEY UPDATE
                                        current_value_id = :value_id,
                                        current_version = :ver,
                                        current_context_group_id = :cgid,
                                        last_updated_at = :ts";

                $snapshotResult = CONN::nodml($sqlUpsertSnapshot, [
                    ':eid' => $subjectEntityId,
                    ':vid' => $variableId,
                    ':value_id' => $newVersionId,
                    ':ver' => $nextVersionNum,
                    ':cgid' => $contextGroupId,
                    ':ts' => $currentTimestamp
                ]);

                if (!$snapshotResult['success']) {
                    throw new Exception("Failed to sync snapshot for '$variableName'. DB Error: " . ($snapshotResult['error'] ?? ''));
                }

                $savedFieldsInfo[] = [
                    'variable_name' => $variableName,
                    'value_id' => $newVersionId,
                    'version' => $nextVersionNum,
                    'timestamp' => $currentTimestamp,
                    'snapshot_synced' => true
                ];

                Log::logDebug("DataCaptureService: Saved $variableName v$nextVersionNum", [
                    'entity_id' => $subjectEntityId,
                    'context_group_id' => $contextGroupId,
                    'snapshot_synced' => true
                ]);
            }

            if ($ownTransaction) CONN::commit();

            return [
                'success' => true,
                'message' => 'Data saved successfully.',
                'context_group_id' => $contextGroupId,
                'saved_fields_info' => $savedFieldsInfo
            ];

        } catch (Exception $e) {
            if ($ownTransaction && CONN::isInTransaction()) CONN::rollback();
            Log::logError("saveData Exception: " . $e->getMessage(), [
                'subject_entity' => $subjectEntityId,
                'scope_entity' => $scopeEntityId,
                'context' => $contextPayload
            ]);
            return ['success' => false, 'message' => 'Error saving data. Check logs for details.'];
        }
    }

    /**
     * Lee datos "calientes" (is_active = true)
     *
     * Reemplaza a getRecord con mejor semántica
     *
     * @param int $entityId Sujeto del que leer datos
     * @param array|null $variableNames Lista de variables a leer (null = todas)
     * @return array ['success', 'data' => ['variable_name' => ['value', 'label', 'version', ...]]]
     */
    public static function getHotData(int $entityId, ?array $variableNames = null): array {
        try {
            // Query SNAPSHOT table (CQRS read model) con JOIN a history para valores
            $sql = "SELECT
                        dd.unique_name, dd.label, dd.data_type, dd.attributes_json,
                        dvh.value_string, dvh.value_decimal, dvh.value_datetime,
                        dvh.value_boolean, dvh.value_entity_ref,
                        dvc.current_version as version,
                        dvh.timestamp, dvh.profile_id,
                        dvh.source_system, dvh.device_id, dvh.reason_for_change,
                        dvc.current_context_group_id
                    FROM data_values_current dvc
                    JOIN data_values_history dvh ON dvc.current_value_id = dvh.value_id
                    JOIN data_dictionary dd ON dvc.variable_id = dd.variable_id
                    WHERE
                        dvc.entity_id = :eid
                        AND dd.status = 'active'";

            $params = [':eid' => $entityId];

            if ($variableNames !== null && !empty($variableNames)) {
                $placeholders = [];
                foreach ($variableNames as $index => $vn) {
                    $paramName = ":vn" . $index;
                    $placeholders[] = $paramName;
                    $params[$paramName] = $vn;
                }
                $sql .= " AND dd.unique_name IN (" . implode(',', $placeholders) . ")";
            }

            $rows = CONN::dml($sql, $params);
            $results = [];

            foreach ($rows as $row) {
                $results[$row['unique_name']] = [
                    'value' => self::mapValueFromDbColumn($row['data_type'], $row),
                    'label' => $row['label'],
                    'data_type' => $row['data_type'],
                    'attributes' => json_decode($row['attributes_json'], true) ?: [],
                    'version' => (int)$row['version'],
                    'timestamp' => $row['timestamp'],              // ALCOA
                    'last_updated_by_profile_id' => (int)$row['profile_id'], // ALCOA
                    'source_system' => $row['source_system'],      // ALCOA
                    'device_id' => $row['device_id'],              // ALCOA
                    'reason_for_change' => $row['reason_for_change'], // ALCOA
                    'context_group_id' => (int)$row['current_context_group_id'] // CQRS snapshot
                ];
            }

            return ['success' => true, 'data' => $results];

        } catch (Exception $e) {
            Log::logError("getHotData Exception: " . $e->getMessage(), ['entity' => $entityId]);
            return ['success' => false, 'data' => null, 'message' => 'Failed to retrieve data. Check logs for details.'];
        }
    }

    /**
     * Lee el historial de una variable (audit trail vertical)
     *
     * Lectura VERTICAL: una variable, sus versiones ordenadas por version DESC
     *
     * @param int $entityId Sujeto
     * @param string $variableName Nombre único de la variable
     * @param string|null $macroContext Filtro por macro_context
     * @param string|null $eventContext Filtro por event_context
     * @param array $options Opciones: 'limit' (int), 'offset' (int), 'from' (datetime), 'to' (datetime)
     * @return array ['success', 'trail' => [...]]
     */
    public static function getAuditTrailForVariable(
        int $entityId,
        string $variableName,
        ?string $macroContext = null,
        ?string $eventContext = null,
        array $options = []
    ): array {
        try {
            $def = self::getVariableDefinition($variableName);
            if (!$def) {
                return [
                    'success' => false,
                    'trail' => null,
                    'message' => "Variable '$variableName' not found."
                ];
            }

            $sql = "SELECT
                        h.value_id, h.version, h.is_active,
                        h.value_string, h.value_decimal, h.value_datetime,
                        h.value_boolean, h.value_entity_ref,
                        h.timestamp, h.profile_id, h.reason_for_change,
                        h.source_system, h.device_id, h.context_group_id,
                        h.inserted_at,
                        cg.macro_context, cg.event_context, cg.sub_context, cg.scope_entity_id
                    FROM data_values_history h
                    JOIN context_groups cg ON h.context_group_id = cg.context_group_id
                    WHERE
                        h.entity_id = :eid
                        AND h.variable_id = :vid";

            $params = [':eid' => $entityId, ':vid' => (int)$def['variable_id']];
            if ($macroContext !== null) {
                $sql .= " AND cg.macro_context = :macro";
                $params[':macro'] = $macroContext;
            }
            if ($eventContext !== null) {
                $sql .= " AND cg.event_context = :evt";
                $params[':evt'] = $eventContext;
            }
            if (!empty($options['from'])) {
                $sql .= " AND h.timestamp >= :from_ts";
                $params[':from_ts'] = $options['from'];
            }
            if (!empty($options['to'])) {
                $sql .= " AND h.timestamp <= :to_ts";
                $params[':to_ts'] = $options['to'];
            }

            $sql .= " ORDER BY h.version DESC";

            if (!empty($options['limit'])) {
                $limit = min((int)$options['limit'], 500);
                $offset = (int)($options['offset'] ?? 0);
                $sql .= " LIMIT {$limit} OFFSET {$offset}";
            }

            $rows = CONN::dml($sql, $params);

            $trail = [];
            foreach ($rows as $row) {
                $trail[] = [
                    'value_id' => (int)$row['value_id'],
                    'version' => (int)$row['version'],
                    'is_active' => (bool)$row['is_active'],
                    'value_at_version' => self::mapValueFromDbColumn($def['data_type'], $row),
                    'timestamp' => $row['timestamp'],
                    'inserted_at' => $row['inserted_at'],
                    'profile_id' => (int)$row['profile_id'],
                    'reason_for_change' => $row['reason_for_change'],
                    'source_system' => $row['source_system'],
                    'device_id' => $row['device_id'],
                    'context_group_id' => (int)$row['context_group_id'],
                    'macro_context' => $row['macro_context'],
                    'event_context' => $row['event_context'],
                    'sub_context' => $row['sub_context'],
                    'scope_entity_id' => $row['scope_entity_id'] !== null ? (int)$row['scope_entity_id'] : null
                ];
            }

            return ['success' => true, 'trail' => $trail];

        } catch (Exception $e) {
            Log::logError("getAuditTrail Exception: " . $e->getMessage(), [
                'entity' => $entityId,
                'var' => $variableName
            ]);
            return ['success' => false, 'trail' => null, 'message' => 'Failed to retrieve audit trail. Check logs for details.'];
        }
    }

    /**
     * Audit trail unificado — eventos horizontales con trail de versiones
     *
     * Combina lectura horizontal (context_group = evento con variables pivoteadas)
     * con trail de versiones (cada variable muestra su historial dentro del evento).
     *
     * Modos de uso:
     *   - Sin variable_name: eventos completos con todas las variables del contexto
     *   - Con variable_name(s): trail de versiones filtrado, con contexto completo
     *
     * @param int $entityId Sujeto (engagement, customer, unit, etc.)
     * @param array $options Filtros y paginación:
     *   - 'variable_name'  => string    Una variable (trail de versiones)
     *   - 'variable_names' => string[]  Varias variables a incluir en el pivot
     *   - 'macro_context'  => string    Nivel 1 (ej: 'engagement', 'units')
     *   - 'event_context'  => string    Nivel 2 (ej: 'activity_stream', 'pvp_change')
     *   - 'sub_context'    => string    Nivel 3 (ej: 'created', 'sla_started')
     *   - 'context_type'   => string    Tipo de evento (ej: 'activity_log', 'alcoa_audit')
     *   - 'scope_entity_id'=> int       Filtro tenant
     *   - 'from'           => string    Fecha inicio (YYYY-MM-DD HH:MM:SS)
     *   - 'to'             => string    Fecha fin
     *   - 'limit'          => int       Máximo de eventos (default 50)
     *   - 'offset'         => int       Offset para paginación (default 0)
     *   - 'order'          => string    'DESC' (default) o 'ASC'
     * @param callable|null $callback Streaming por evento horizontal
     * @return array ['success', 'events' => [...], 'total' => int]
     */
    public static function getAuditTrail(
        int $entityId,
        array $options = [],
        ?callable $callback = null
    ): array {
        try {
            $limit = min((int)($options['limit'] ?? 50), 500);
            $offset = (int)($options['offset'] ?? 0);
            $order = strtoupper($options['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

            # Normalizar variable_name (string) → variable_names (array)
            $variableNames = $options['variable_names'] ?? null;
            if (!empty($options['variable_name']) && is_string($options['variable_name'])) {
                $variableNames = [$options['variable_name']];
            }

            # 1. Construir WHERE para context_groups
            $cgWhere = ['cg.subject_entity_id = :eid'];
            $cgParams = [':eid' => $entityId];

            if (!empty($options['macro_context'])) {
                $cgWhere[] = 'cg.macro_context = :macro';
                $cgParams[':macro'] = $options['macro_context'];
            }
            if (!empty($options['event_context'])) {
                $cgWhere[] = 'cg.event_context = :event';
                $cgParams[':event'] = $options['event_context'];
            }
            if (!empty($options['sub_context'])) {
                $cgWhere[] = 'cg.sub_context = :sub';
                $cgParams[':sub'] = $options['sub_context'];
            }
            if (!empty($options['context_type'])) {
                $cgWhere[] = 'cg.context_type = :ctype';
                $cgParams[':ctype'] = $options['context_type'];
            }
            if (array_key_exists('scope_entity_id', $options)) {
                $scopeOpts = $options['scope_entity_id'] !== null
                    ? ['scope_entity_id' => $options['scope_entity_id']]
                    : [];
                $sf = Tenant::filter('cg.scope_entity_id', $scopeOpts);
                $cgWhere[] = ltrim($sf['sql'], ' AND ');
                $cgParams = array_merge($cgParams, $sf['params']);
            }
            if (!empty($options['from'])) {
                $cgWhere[] = 'cg.timestamp >= :from_ts';
                $cgParams[':from_ts'] = $options['from'];
            }
            if (!empty($options['to'])) {
                $cgWhere[] = 'cg.timestamp <= :to_ts';
                $cgParams[':to_ts'] = $options['to'];
            }

            # Si hay filtro de variables, solo context_groups que tengan esas variables
            if ($variableNames) {
                $vnJoinPlaceholders = [];
                foreach ($variableNames as $vi => $vn) {
                    $key = ":vnj{$vi}";
                    $vnJoinPlaceholders[] = $key;
                    $cgParams[$key] = $vn;
                }
                $cgWhere[] = "EXISTS (
                    SELECT 1 FROM data_values_history hf
                    JOIN data_dictionary ddf ON hf.variable_id = ddf.variable_id
                    WHERE hf.context_group_id = cg.context_group_id
                    AND ddf.unique_name IN (" . implode(',', $vnJoinPlaceholders) . ")
                )";
            }

            $whereClause = implode(' AND ', $cgWhere);

            # Count total para paginación
            $countRows = CONN::dml(
                "SELECT COUNT(*) as total FROM context_groups cg WHERE {$whereClause}",
                $cgParams
            );
            $total = (int)($countRows[0]['total'] ?? 0);

            if ($total === 0) {
                return ['success' => true, 'events' => [], 'total' => 0];
            }

            # Obtener context_groups paginados
            $contextGroups = CONN::dml(
                "SELECT cg.context_group_id, cg.timestamp, cg.profile_id,
                        cg.macro_context, cg.event_context, cg.sub_context,
                        cg.context_type, cg.scope_entity_id
                 FROM context_groups cg
                 WHERE {$whereClause}
                 ORDER BY cg.timestamp {$order}
                 LIMIT {$limit} OFFSET {$offset}",
                $cgParams
            );

            if (empty($contextGroups)) {
                return ['success' => true, 'events' => [], 'total' => $total];
            }

            # 2. Obtener valores con trail de versiones
            $cgIds = array_column($contextGroups, 'context_group_id');
            $valParams = [];
            $cgPlaceholders = [];
            foreach ($cgIds as $i => $cgId) {
                $key = ":cg{$i}";
                $cgPlaceholders[] = $key;
                $valParams[$key] = $cgId;
            }

            $valSql = "SELECT h.context_group_id, h.value_id,
                              dd.unique_name, dd.data_type,
                              h.value_string, h.value_decimal, h.value_datetime,
                              h.value_boolean, h.value_entity_ref,
                              h.version, h.timestamp as value_timestamp,
                              h.profile_id as value_profile_id,
                              h.reason_for_change, h.source_system, h.device_id
                       FROM data_values_history h
                       JOIN data_dictionary dd ON h.variable_id = dd.variable_id
                       WHERE h.context_group_id IN (" . implode(',', $cgPlaceholders) . ")";

            # Filtrar variables
            if ($variableNames) {
                $vnPlaceholders = [];
                foreach ($variableNames as $vi => $vn) {
                    $key = ":vn{$vi}";
                    $vnPlaceholders[] = $key;
                    $valParams[$key] = $vn;
                }
                $valSql .= " AND dd.unique_name IN (" . implode(',', $vnPlaceholders) . ")";
            }

            $valSql .= " ORDER BY h.version ASC";

            # Agrupar por context_group, cada variable con su trail de versiones
            $valuesByGroup = [];
            CONN::dml($valSql, $valParams, function($row) use (&$valuesByGroup) {
                $cgId = (int)$row['context_group_id'];
                $varName = $row['unique_name'];

                $versionEntry = [
                    'value' => self::mapValueFromDbColumn($row['data_type'], $row),
                    'version' => (int)$row['version'],
                    'value_id' => (int)$row['value_id'],
                    'timestamp' => $row['value_timestamp'],
                    'profile_id' => (int)$row['value_profile_id'],
                    'reason' => $row['reason_for_change'],
                    'source_system' => $row['source_system'],
                    'device_id' => $row['device_id']
                ];

                if (!isset($valuesByGroup[$cgId][$varName])) {
                    # Primera versión de esta variable en este context_group
                    $valuesByGroup[$cgId][$varName] = $versionEntry;
                    $valuesByGroup[$cgId][$varName]['trail'] = [$versionEntry];
                } else {
                    # Versión adicional — actualizar valor actual y agregar al trail
                    $valuesByGroup[$cgId][$varName]['value'] = $versionEntry['value'];
                    $valuesByGroup[$cgId][$varName]['version'] = $versionEntry['version'];
                    $valuesByGroup[$cgId][$varName]['timestamp'] = $versionEntry['timestamp'];
                    $valuesByGroup[$cgId][$varName]['profile_id'] = $versionEntry['profile_id'];
                    $valuesByGroup[$cgId][$varName]['reason'] = $versionEntry['reason'];
                    $valuesByGroup[$cgId][$varName]['trail'][] = $versionEntry;
                }
            });

            # 3. Construir eventos horizontales
            $events = [];
            foreach ($contextGroups as $cg) {
                $cgId = (int)$cg['context_group_id'];
                $event = [
                    'context_group_id' => $cgId,
                    'timestamp' => $cg['timestamp'],
                    'profile_id' => (int)$cg['profile_id'],
                    'macro_context' => $cg['macro_context'],
                    'event_context' => $cg['event_context'],
                    'sub_context' => $cg['sub_context'],
                    'context_type' => $cg['context_type'],
                    'scope_entity_id' => $cg['scope_entity_id'] !== null ? (int)$cg['scope_entity_id'] : null,
                    'data' => $valuesByGroup[$cgId] ?? []
                ];

                if ($callback) {
                    $result = $callback($event);
                    if ($result === false) break;
                } else {
                    $events[] = $event;
                }
            }

            return $callback
                ? ['success' => true, 'total' => $total]
                : ['success' => true, 'events' => $events, 'total' => $total];

        } catch (Exception $e) {
            Log::logError("getAuditTrail Exception: " . $e->getMessage(), [
                'entity' => $entityId,
                'options' => $options
            ]);
            return ['success' => false, 'events' => null, 'message' => 'Failed to retrieve audit trail. Check logs for details.'];
        }
    }

    /**
     * Obtiene timeline unificado de una variable para un sujeto
     *
     * Incluye datos de múltiples scope_entity_id (multi-origen)
     * Útil para ver historial clínico de múltiples hospitales/estudios
     *
     * @param int $entityId Sujeto (paciente, cliente)
     * @param string $variableName Variable a consultar
     * @param int|null $scopeEntityId Filtrar por ámbito específico (null = todos)
     * @return array ['success', 'timeline' => [...]]
     */
    public static function getTimeline(
        int $entityId,
        string $variableName,
        ?int $scopeEntityId = null
    ): array {
        try {
            $def = self::getVariableDefinition($variableName);
            if (!$def) {
                return [
                    'success' => false,
                    'timeline' => null,
                    'message' => "Variable '$variableName' not found."
                ];
            }

            $sql = "SELECT
                        h.value_id, h.version, h.is_active,
                        h.value_string, h.value_decimal, h.value_datetime,
                        h.value_boolean, h.value_entity_ref,
                        h.timestamp, h.profile_id, h.source_system, h.device_id,
                        cg.scope_entity_id, cg.context_type,
                        cg.macro_context, cg.event_context, cg.sub_context
                    FROM data_values_history h
                    JOIN context_groups cg ON h.context_group_id = cg.context_group_id
                    WHERE
                        h.entity_id = :eid
                        AND h.variable_id = :vid";

            $params = [':eid' => $entityId, ':vid' => (int)$def['variable_id']];

            if ($scopeEntityId !== null) {
                $sql .= " AND cg.scope_entity_id = :scope";
                $params[':scope'] = $scopeEntityId;
            }

            $sql .= " ORDER BY h.timestamp DESC";

            $rows = CONN::dml($sql, $params);

            $timeline = [];
            foreach ($rows as $row) {
                $timeline[] = [
                    'value' => self::mapValueFromDbColumn($def['data_type'], $row),
                    'timestamp' => $row['timestamp'],
                    'is_active' => (bool)$row['is_active'],
                    'version' => (int)$row['version'],
                    'profile_id' => (int)$row['profile_id'],
                    'source_system' => $row['source_system'],
                    'device_id' => $row['device_id'],
                    'scope_entity_id' => $row['scope_entity_id'] ? (int)$row['scope_entity_id'] : null,
                    'context_type' => $row['context_type'],
                    'macro_context' => $row['macro_context'],
                    'event_context' => $row['event_context'],
                    'sub_context' => $row['sub_context']
                ];
            }

            return ['success' => true, 'timeline' => $timeline];

        } catch (Exception $e) {
            Log::logError("getTimeline Exception: " . $e->getMessage(), [
                'entity' => $entityId,
                'var' => $variableName
            ]);
            return ['success' => false, 'timeline' => null, 'message' => 'Failed to retrieve timeline. Check logs for details.'];
        }
    }

    // ========================================================================
    // MÉTODOS PRIVADOS / HELPERS
    // ========================================================================

    /**
     * Obtiene definición de una variable desde data_dictionary
     *
     * @param string $variableName Unique name (ej: 'cdisc.vs.systolic')
     * @return array|null ['variable_id', 'label', 'data_type', 'attributes_json']
     */
    private static function getVariableDefinition(string $variableName): ?array {
        $rows = CONN::dml(
            "SELECT variable_id, label, data_type, attributes_json
             FROM data_dictionary
             WHERE unique_name = :name AND status = 'active'
             LIMIT 1",
            [':name' => $variableName]
        );
        return $rows[0] ?? null;
    }

    /**
     * Obtiene o crea un ContextGroup (ticket/hito)
     *
     * El context_group es el eje HORIZONTAL del EAV: agrupa múltiples
     * variables que pertenecen al mismo evento. Estructura jerárquica:
     *   - macro_context  → dominio/app (ej: 'engagement', 'units')
     *   - event_context  → función/flujo (ej: 'activity_stream', 'pvp_change')
     *   - sub_context    → acción específica (ej: 'created', 'sla_started')
     *   - parent_context_id → jerarquía entre context_groups
     *
     * IDEMPOTENTE por combinación completa: subject+profile+type+macro+event+sub+parent.
     * Si todos coinciden, reusa el context_group existente (las variables se versionan
     * dentro del mismo grupo). Si alguno difiere, crea uno nuevo.
     *
     * @param int $actorProfileId Quién crea el contexto
     * @param int $subjectEntityId De quién es el evento (paciente, cliente)
     * @param int|null $scopeEntityId En qué ámbito (empresa, estudio, proyecto)
     * @param array $contextPayload ['macro_context', 'event_context', 'sub_context', 'parent_context_id']
     * @param string $contextType Tipo de evento
     * @return int context_group_id
     * @throws Exception Si falla la creación
     */
    private static function getOrCreateContextGroup(
        int $actorProfileId,
        int $subjectEntityId,
        ?int $scopeEntityId,
        array $contextPayload,
        string $contextType
    ): int {

        $macro = $contextPayload['macro_context'] ?? null;
        $event = $contextPayload['event_context'] ?? null;
        $sub = $contextPayload['sub_context'] ?? null;
        $parentContextId = $contextPayload['parent_context_id'] ?? null;

        # scope_entity_id: filtrado via Tenant::
        $scopeOpts = $scopeEntityId !== null ? ['scope_entity_id' => $scopeEntityId] : [];
        $sf = Tenant::filter('scope_entity_id', $scopeOpts);

        # Columnas de contexto: NULL es identidad legítima (no tenant)
        $nw = [
            self::contextWhere('macro_context', $macro, ':macro'),
            self::contextWhere('event_context', $event, ':event'),
            self::contextWhere('sub_context', $sub, ':sub'),
            self::contextWhere('parent_context_id', $parentContextId, ':parent'),
        ];

        $sqlFind = "SELECT context_group_id FROM context_groups
                    WHERE subject_entity_id = :subject_eid
                      AND profile_id = :pid
                      AND context_type = :type"
                 . $sf['sql']
                 . " AND " . implode(' AND ', array_column($nw, 'sql'))
                 . " LIMIT 1";

        $paramsFind = array_merge(
            [':subject_eid' => $subjectEntityId, ':pid' => $actorProfileId, ':type' => $contextType],
            $sf['params']
        );
        foreach ($nw as $w) {
            $paramsFind = array_merge($paramsFind, $w['params']);
        }

        $existing = CONN::dml($sqlFind, $paramsFind)[0] ?? null;

        if ($existing) {
            return (int)$existing['context_group_id'];
        }

        // No existe, crear nuevo
        $currentTimestamp = date('Y-m-d H:i:s');

        $sqlInsert = "INSERT INTO context_groups
                        (subject_entity_id, scope_entity_id, profile_id,
                         timestamp, context_type,
                         macro_context, event_context, sub_context, parent_context_id,
                         created_by_profile_id, created_by_account_id)
                      VALUES
                        (:subject_eid, :scope_eid, :pid,
                         :ts, :type,
                         :macro, :event, :sub, :parent,
                         :cbpid, :cbaid)";

        $paramsInsert = [
            ':subject_eid' => $subjectEntityId,
            ':scope_eid' => $scopeEntityId,
            ':pid' => $actorProfileId,
            ':ts' => $currentTimestamp,
            ':type' => $contextType,
            ':macro' => $macro,
            ':event' => $event,
            ':sub' => $sub,
            ':parent' => $parentContextId,
            ':cbpid' => $actorProfileId,
            ':cbaid' => \bX\Profile::ctx()->accountId  // ALCOA: Attributable (legal identity via account → entity)
        ];

        $result = CONN::nodml($sqlInsert, $paramsInsert);

        if (!$result['success'] || empty($result['last_id'])) {
            $dbError = $result['error'] ?? 'Unknown DB error during context_group insert';
            Log::logError("Failed to create context_group. DB Error: " . $dbError, $paramsInsert);
            throw new Exception("Failed to create context_group.");
        }

        return (int)$result['last_id'];
    }

    /**
     * Mapea un valor al nombre de columna correcto en data_values_history
     *
     * @param string $dataType 'STRING', 'DECIMAL', 'DATETIME', 'BOOLEAN', 'ENTITY_REF'
     * @param mixed $value Valor a guardar
     * @return array [columnName, columnValue]
     */
    private static function mapValueToDbColumn(string $dataType, $value): array {
        switch ($dataType) {
            case 'DECIMAL':
            case 'NUMERIC':
                return ['value_decimal', is_numeric($value) ? $value : null];

            case 'BOOLEAN':
                return ['value_boolean', $value ? 1 : 0];

            case 'DATETIME':
                return ['value_datetime', $value];

            case 'ENTITY_REF':
                return ['value_entity_ref', is_numeric($value) ? (int)$value : null];

            case 'JSON':
                $encoded = $value !== null ? (is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)) : null;
                return ['value_string', $encoded];

            case 'STRING':
            default:
                return ['value_string', $value !== null ? (string)$value : null];
        }
    }

    /**
     * Lee un valor desde la columna correcta en data_values_history
     *
     * @param string $dataType 'STRING', 'DECIMAL', 'DATETIME', 'BOOLEAN', 'ENTITY_REF', 'JSON'
     * @param array $row Fila de la BD con todas las columnas value_*
     * @return mixed El valor deserializado
     */
    private static function mapValueFromDbColumn(string $dataType, array $row) {
        switch ($dataType) {
            case 'DECIMAL':
            case 'NUMERIC':
                return $row['value_decimal'];

            case 'BOOLEAN':
                return $row['value_boolean'];

            case 'DATETIME':
                return $row['value_datetime'];

            case 'ENTITY_REF':
                return $row['value_entity_ref'];

            case 'JSON':
                return json_decode($row['value_string'] ?? 'null', true);

            case 'STRING':
            default:
                return $row['value_string'];
        }
    }

    /**
     * Compara si un valor ha cambiado vs el valor actual
     *
     * Maneja comparaciones específicas por tipo de dato:
     * - STRING: Comparación directa (case-sensitive)
     * - DECIMAL/NUMERIC: Comparación con tolerancia de precisión
     * - BOOLEAN: Normalización y comparación
     * - DATETIME: Comparación de timestamps
     * - ENTITY_REF: Comparación de IDs
     *
     * @param string $dataType Tipo de dato (STRING, DECIMAL, BOOLEAN, etc.)
     * @param mixed $currentValue Valor actual en BD
     * @param mixed $newValue Nuevo valor a guardar
     * @return bool True si el valor cambió, False si es igual
     */
    private static function valueHasChanged(string $dataType, $currentValue, $newValue): bool {
        # Normalizar nulls
        if ($currentValue === null && ($newValue === null || $newValue === '')) {
            return false;
        }
        if (($currentValue === null || $currentValue === '') && $newValue === null) {
            return false;
        }

        switch ($dataType) {
            case 'DECIMAL':
            case 'NUMERIC':
                # Comparar como floats con tolerancia
                $current = (float)$currentValue;
                $new = (float)$newValue;
                return abs($current - $new) > 0.0000001;

            case 'BOOLEAN':
                # Normalizar booleanos
                $current = filter_var($currentValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $new = filter_var($newValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                return $current !== $new;

            case 'DATETIME':
                # Comparar como strings (timestamps normalizados)
                $currentTs = is_string($currentValue) ? trim($currentValue) : '';
                $newTs = is_string($newValue) ? trim($newValue) : '';
                return $currentTs !== $newTs;

            case 'ENTITY_REF':
                # Comparar IDs como enteros
                return (int)$currentValue !== (int)$newValue;

            case 'STRING':
            default:
                # Comparación directa de strings
                $currentStr = (string)$currentValue;
                $newStr = (string)$newValue;
                return $currentStr !== $newStr;
        }
    }

    # WHERE para columnas de contexto donde NULL es identidad legítima (no tenant)
    # Solo para: macro_context, event_context, sub_context, parent_context_id
    # scope_entity_id usa Tenant:: (null = AND 1=0)
    private static function contextWhere(string $column, $value, string $paramName): array
    {
        if ($value !== null) {
            return ['sql' => "{$column} = {$paramName}", 'params' => [$paramName => $value]];
        }
        return ['sql' => "{$column} IS NULL", 'params' => []];
    }

    /**
     * Obtiene datos EAV en formato horizontal (array PHP)
     *
     * Transforma estructura vertical EAV a horizontal tradicional:
     * EAV: entity_id | variable_name | value
     * Horizontal: entity_id | blood_pressure | heart_rate | ...
     *
     * @param array $filters Filtros opcionales
     *   - 'scope_entity_id' => int
     *   - 'subject_entity_ids' => array  # Lista de entity_ids a filtrar
     *   - 'variable_names' => array      # Lista de variables a incluir
     *   - 'convert_types' => bool        # Convertir valores según data_type (default: false)
     *   - 'context_group_id' => int      # Filtrar por contexto específico
     *   - 'macro_context' => string      # Nivel 1 de contexto
     *   - 'event_context' => string      # Nivel 2 de contexto
     *   - 'sub_context' => string        # Nivel 3 de contexto
     *   - 'parent_context_id' => int     # Nivel 4 jerárquico
     *
     * @param callable|null $callback Callback($horizontalRow): mixed
     *   - Recibe cada fila horizontal: ['entity_id' => X, 'var1' => val1, 'var2' => val2, ...]
     *   - Puede hacer echo directo para streaming
     *   - O retornar valor para acumular
     *   - Si retorna false, detiene procesamiento (early exit)
     *
     * Lectura HORIZONTAL: pivotea datos EAV verticales a filas planas.
     * El context_group es el eje de agrupación — cada context_group_id
     * representa un ticket/evento que agrupa múltiples variables.
     * Los 3 niveles de contexto (macro, event, sub) + parent_context_id
     * actúan como dimensiones jerárquicas para filtrar.
     *
     * Actualmente agrupa por entity_id. Para event streams donde un mismo
     * entity_id tiene múltiples context_groups (ej: activity log), se
     * necesitaría pivotar por context_group_id.
     *
     * @return array|null Array de filas si no hay callback, null si hay callback
     */
    public static function getHorizontalData(array $filters = [], ?callable $callback = null): ?array
    {
        # 1. Construir WHERE clause
        $whereConditions = ['h.is_active = 1'];
        $params = [];

        if (array_key_exists('scope_entity_id', $filters)) {
            $scopeOpts = $filters['scope_entity_id'] !== null
                ? ['scope_entity_id' => $filters['scope_entity_id']]
                : [];
            $sf = Tenant::filter('cg.scope_entity_id', $scopeOpts);
            $whereConditions[] = ltrim($sf['sql'], ' AND ');
            $params = array_merge($params, $sf['params']);
        }

        if (!empty($filters['subject_entity_ids']) && is_array($filters['subject_entity_ids'])) {
            $placeholders = [];
            foreach ($filters['subject_entity_ids'] as $idx => $entityId) {
                $key = ":entity_id_$idx";
                $placeholders[] = $key;
                $params[$key] = $entityId;
            }
            $whereConditions[] = 'cg.subject_entity_id IN (' . implode(',', $placeholders) . ')';
        }

        if (!empty($filters['variable_names']) && is_array($filters['variable_names'])) {
            $placeholders = [];
            foreach ($filters['variable_names'] as $idx => $varName) {
                $key = ":var_name_$idx";
                $placeholders[] = $key;
                $params[$key] = $varName;
            }
            $whereConditions[] = 'd.unique_name IN (' . implode(',', $placeholders) . ')';
        }

        if (!empty($filters['context_group_id'])) {
            $whereConditions[] = 'h.context_group_id = :context_group_id';
            $params[':context_group_id'] = $filters['context_group_id'];
        }

        # Filtros de los 4 niveles de contexto
        if (isset($filters['macro_context'])) {
            $whereConditions[] = 'cg.macro_context = :macro_context';
            $params[':macro_context'] = $filters['macro_context'];
        }

        if (isset($filters['event_context'])) {
            $whereConditions[] = 'cg.event_context = :event_context';
            $params[':event_context'] = $filters['event_context'];
        }

        if (isset($filters['sub_context'])) {
            $whereConditions[] = 'cg.sub_context = :sub_context';
            $params[':sub_context'] = $filters['sub_context'];
        }

        if (!empty($filters['parent_context_id'])) {
            $whereConditions[] = 'cg.parent_context_id = :parent_context_id';
            $params[':parent_context_id'] = $filters['parent_context_id'];
        }

        $whereClause = implode(' AND ', $whereConditions);

        # 2. Query datos verticales
        # SIEMPRE JOIN con context_groups (entity_id está en cg, no en h)
        $sql = "SELECT
                    cg.subject_entity_id AS entity_id,
                    d.unique_name,
                    d.data_type,
                    h.value_string,
                    h.value_decimal,
                    h.value_boolean,
                    h.value_datetime,
                    h.value_entity_ref,
                    h.timestamp
                FROM data_values_history h
                INNER JOIN data_dictionary d ON h.variable_id = d.variable_id
                INNER JOIN context_groups cg ON h.context_group_id = cg.context_group_id
                WHERE $whereClause
                ORDER BY cg.subject_entity_id, d.unique_name";

        $convertTypes = $filters['convert_types'] ?? false;
        $horizontalData = [];
        $currentEntityId = null;
        $currentRow = [];

        # 3. Procesar cada fila vertical y construir horizontal
        CONN::dml($sql, $params, function($row) use(&$horizontalData, &$currentEntityId, &$currentRow, $convertTypes, $callback) {
            $entityId = $row['entity_id'];
            $varName = $row['unique_name'];
            $dataType = $row['data_type'];

            # Obtener valor según tipo
            if ($convertTypes) {
                try {
                    $value = self::mapValueFromDbColumn($dataType, $row);
                } catch (\Exception $e) {
                    Log::logWarning("DataCaptureService::getHorizontalData - Type conversion failed", [
                        'entity_id' => $entityId,
                        'variable' => $varName,
                        'data_type' => $dataType,
                        'error' => $e->getMessage()
                    ]);
                    $value = $row['value_string']; # Fallback a string
                }
            } else {
                # Sin conversión, usar valor raw según tipo
                $value = match($dataType) {
                    'DECIMAL', 'NUMERIC' => $row['value_decimal'],
                    'BOOLEAN' => $row['value_boolean'],
                    'DATETIME' => $row['value_datetime'],
                    'ENTITY_REF' => $row['value_entity_ref'],
                    default => $row['value_string']
                };
            }

            # Si cambia entity_id, emitir fila anterior
            if ($currentEntityId !== null && $currentEntityId !== $entityId) {
                if ($callback) {
                    $result = $callback($currentRow);
                    if ($result === false) {
                        return false; # Early exit
                    }
                } else {
                    $horizontalData[] = $currentRow;
                }
                $currentRow = [];
            }

            # Inicializar nueva fila si es necesario
            if (empty($currentRow)) {
                $currentRow = ['entity_id' => $entityId];
            }

            # Agregar variable a fila horizontal
            $currentRow[$varName] = $value;
            $currentEntityId = $entityId;
        });

        # 4. Emitir última fila si existe
        if (!empty($currentRow)) {
            if ($callback) {
                $callback($currentRow);
            } else {
                $horizontalData[] = $currentRow;
            }
        }

        return $callback ? null : $horizontalData;
    }
}
