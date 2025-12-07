<?php
/**
 * DataCaptureService - EAV Data Persistence with ALCOA+ Compliance
 *
 * Version: 2.0
 *
 * Purpose: Servicio centralizado para captura de datos usando modelo EAV versionado
 * con soporte completo para:
 * - ALCOA+ (Attributable, Legible, Contemporaneous, Original, Accurate)
 * - Multi-tenant (scope_entity_id)
 * - Multi-source (source_system, device_id)
 * - Versionado atómico (is_active)
 *
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
     * Escribe en data_dictionary con auditoría completa
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
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Guarda datos con versionado atómico y contexto completo
     *
     * Esta es la función principal de persistencia EAV con ALCOA+
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

                // 2.3 Obtener versión activa actual (si existe)
                $sqlFindActive = "SELECT value_id, version
                                  FROM data_values_history
                                  WHERE entity_id = :eid
                                    AND variable_id = :vid
                                    AND is_active = TRUE
                                  FOR UPDATE"; // Lock para concurrencia

                $activeRow = CONN::dml($sqlFindActive, [
                    ':eid' => $subjectEntityId,
                    ':vid' => $variableId
                ])[0] ?? null;

                $currentVersionId = null;
                $nextVersionNum = 1;

                if ($activeRow) {
                    $currentVersionId = (int)$activeRow['value_id'];
                    $nextVersionNum = (int)$activeRow['version'] + 1;

                    // PASO ATÓMICO A: Desactivar versión anterior
                    $sqlDisable = "UPDATE data_values_history
                                   SET is_active = FALSE
                                   WHERE value_id = :vid";
                    $disableResult = CONN::nodml($sqlDisable, [':vid' => $currentVersionId]);
                    if (!$disableResult['success']) {
                        throw new Exception("Failed to disable old version for '$variableName'.");
                    }
                }

                // PASO ATÓMICO B: Insertar nueva versión activa
                // IMPORTANTE: Incluir timestamp (ALCOA), source_system, device_id, account_id
                $sqlInsert = "INSERT INTO data_values_history
                                (entity_id, variable_id, context_group_id, profile_id, account_id,
                                 timestamp, version, is_active, reason_for_change,
                                 source_system, device_id, $colName)
                              VALUES
                                (:eid, :vid, :cgid, :pid, :aid,
                                 :ts, :ver, TRUE, :reason,
                                 :source, :device, :val)";

                $insertResult = CONN::nodml($sqlInsert, [
                    ':eid' => $subjectEntityId,
                    ':vid' => $variableId,
                    ':cgid' => $contextGroupId,
                    ':pid' => $actorProfileId,
                    ':aid' => \bX\Profile::$account_id,  // ALCOA: Attributable (legal identity)
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
                $savedFieldsInfo[] = [
                    'variable_name' => $variableName,
                    'value_id' => $newVersionId,
                    'version' => $nextVersionNum,
                    'timestamp' => $currentTimestamp
                ];
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
            return ['success' => false, 'message' => "Error saving data: " . $e->getMessage()];
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
            $sql = "SELECT
                        dd.unique_name, dd.label, dd.data_type, dd.attributes_json,
                        h.value_string, h.value_decimal, h.value_datetime,
                        h.value_boolean, h.value_entity_ref,
                        h.version, h.timestamp, h.profile_id,
                        h.source_system, h.device_id, h.reason_for_change
                    FROM data_values_history h
                    JOIN data_dictionary dd ON h.variable_id = dd.variable_id
                    WHERE
                        h.entity_id = :eid
                        AND h.is_active = TRUE
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
                    'reason_for_change' => $row['reason_for_change'] // ALCOA
                ];
            }

            return ['success' => true, 'data' => $results];

        } catch (Exception $e) {
            Log::logError("getHotData Exception: " . $e->getMessage(), ['entity' => $entityId]);
            return ['success' => false, 'data' => null, 'message' => $e->getMessage()];
        }
    }

    /**
     * Lee el historial completo de una variable (audit trail)
     *
     * Retorna TODAS las versiones (activas e inactivas) para auditoría ALCOA+
     *
     * @param int $entityId Sujeto
     * @param string $variableName Nombre único de la variable
     * @return array ['success', 'trail' => [['version', 'value', 'timestamp', 'profile_id', ...]]]
     */
    public static function getAuditTrailForVariable(
        int $entityId,
        string $variableName,
        ?string $macroContext = null,
        ?string $eventContext = null
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

            $sql .= " ORDER BY h.version DESC";
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
            return ['success' => false, 'trail' => null, 'message' => $e->getMessage()];
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
            return ['success' => false, 'timeline' => null, 'message' => $e->getMessage()];
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
     * IMPORTANTE: Usa subject_entity_id y scope_entity_id
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

        // Construir consulta para buscar contexto existente
        $sqlFind = "SELECT context_group_id FROM context_groups
                    WHERE subject_entity_id = :subject_eid
                      AND profile_id = :pid
                      AND context_type = :type
                      AND " . ($scopeEntityId ? "scope_entity_id = :scope_eid" : "scope_entity_id IS NULL") . "
                      AND " . ($macro ? "macro_context = :macro" : "macro_context IS NULL") . "
                      AND " . ($event ? "event_context = :event" : "event_context IS NULL") . "
                      AND " . ($sub ? "sub_context = :sub" : "sub_context IS NULL") . "
                      AND " . ($parentContextId ? "parent_context_id = :parent" : "parent_context_id IS NULL") . "
                    LIMIT 1";

        $paramsFind = [
            ':subject_eid' => $subjectEntityId,
            ':pid' => $actorProfileId,
            ':type' => $contextType
        ];
        if ($scopeEntityId) $paramsFind[':scope_eid'] = $scopeEntityId;
        if ($macro) $paramsFind[':macro'] = $macro;
        if ($event) $paramsFind[':event'] = $event;
        if ($sub) $paramsFind[':sub'] = $sub;
        if ($parentContextId) $paramsFind[':parent'] = $parentContextId;

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
            ':cbaid' => \bX\Profile::$account_id  // ALCOA: Attributable (legal identity via account → entity)
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

            case 'STRING':
            default:
                return ['value_string', $value !== null ? (string)$value : null];
        }
    }

    /**
     * Lee un valor desde la columna correcta en data_values_history
     *
     * @param string $dataType 'STRING', 'DECIMAL', 'DATETIME', 'BOOLEAN', 'ENTITY_REF'
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

            case 'STRING':
            default:
                return $row['value_string'];
        }
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
     * @return array|null Array de filas si no hay callback, null si hay callback
     */
    public static function getHorizontalData(array $filters = [], ?callable $callback = null): ?array
    {
        # 1. Construir WHERE clause
        $whereConditions = ['h.is_active = 1'];
        $params = [];

        if (!empty($filters['scope_entity_id'])) {
            $whereConditions[] = 'cg.scope_entity_id = :scope_entity_id';
            $params[':scope_entity_id'] = $filters['scope_entity_id'];
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
