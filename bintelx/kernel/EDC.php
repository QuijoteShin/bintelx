<?php
/**
 * EDC - Electronic Data Capture (Generic Form Engine)
 *
 * Generic form management system with:
 * - JSON schema-based form definitions
 * - Versioned form definitions (is_active pattern)
 * - Versioned data capture (via DataCaptureService ALCOA+)
 * - Multi-tenant support via Tenant:: (scope_entity_id)
 * - Complete audit trail
 *
 * SCOPE: Usa Tenant:: para filtrado multi-tenant.
 * NULL scope = NO ACCESS (AND 1=0), igual que el resto del kernel.
 *
 * This class is domain-agnostic and can be used for:
 * - Clinical data capture (CRF)
 * - Surveys and questionnaires
 * - Business forms
 * - Data collection of any kind
 *
 * @package bX
 * @version 1.1
 * @date 2026-02-13
 */

namespace bX;

use Exception;

class EDC {

    # Cache namespace (transparente: Swoole\Table o static array via bX\Cache)
    private const CACHE_NS = 'edc:schema';
    private const CACHE_TTL = 1800; # 30min

    // ==================== FORM DEFINITIONS ====================

    /**
     * Creates or updates a form definition
     *
     * Creates a new version of the form definition. The previous active version
     * is deactivated (is_active = FALSE), and the new version becomes active.
     *
     * @param string $formName Unique form identifier (slug-like)
     * @param array $schema Form structure in JSON format
     * @param int $actorProfileId Creator profile ID
     * @param int|null $scopeEntityId Organization ID (NULL = public/global form)
     * @return array ['success' => bool, 'form_definition_id' => int, 'version_number' => int]
     */
    public static function defineForm(
        string $formName,
        array $schema,
        int $actorProfileId,
        ?int $scopeEntityId = null
    ): array {
        CONN::begin();
        try {
            // Validar schema
            $validationResult = self::validateFormSchema($schema);
            if (!$validationResult['success']) {
                throw new Exception($validationResult['message']);
            }

            # Desactivar versión actual
            $opts = $scopeEntityId !== null ? ['scope_entity_id' => $scopeEntityId] : [];
            $sf = Tenant::filter('scope_entity_id', $opts);

            $sqlDeactivate = "UPDATE edc_form_definitions
                              SET is_active = FALSE
                              WHERE form_name = :name" . $sf['sql'] . "
                                AND is_active = TRUE";

            $params = array_merge([':name' => $formName], $sf['params']);
            CONN::nodml($sqlDeactivate, $params);

            # Obtener siguiente versión
            $sqlVersion = "SELECT COALESCE(MAX(version_number), 0) + 1 as next_version
                           FROM edc_form_definitions
                           WHERE form_name = :name" . $sf['sql'];

            $versionRow = CONN::dml($sqlVersion, $params)[0] ?? null;
            $nextVersion = (int)($versionRow['next_version'] ?? 1);

            // Insertar nueva versión activa (sin schema_json cache)
            $sqlInsert = "INSERT INTO edc_form_definitions
                            (scope_entity_id, form_name, form_title, form_description,
                             version_number, is_active, status,
                             created_by_profile_id, updated_by_profile_id)
                          VALUES
                            (:scope, :name, :title, :desc,
                             :version, TRUE, :status,
                             :actor, :actor)";

            $result = CONN::nodml($sqlInsert, [
                ':scope' => $scopeEntityId,
                ':name' => $formName,
                ':title' => $schema['title'] ?? $formName,
                ':desc' => $schema['description'] ?? null,
                ':version' => $nextVersion,
                ':status' => 'draft',
                ':actor' => $actorProfileId
            ]);

            if (!$result['success']) {
                throw new Exception("Failed to insert form definition");
            }

            $formDefId = (int)$result['last_id'];

            // Poblar tablas relacionales (para reportes y analíticas)
            self::populateRelationalStructure($formDefId, $schema, $actorProfileId);

            // Registrar campos en data_dictionary (para versionado de datos)
            self::registerFormFieldsInDictionary($formDefId, $schema, $actorProfileId);

            CONN::commit();

            // Invalidar cache
            $cacheKey = self::getCacheKey($formName, $scopeEntityId);
            Cache::delete(self::CACHE_NS, $cacheKey);
            Cache::notifyChannel(self::CACHE_NS);

            Log::logInfo('EDC.defineForm', [
                'form_name' => $formName,
                'form_definition_id' => $formDefId,
                'version' => $nextVersion,
                'actor' => $actorProfileId,
                'scope' => $scopeEntityId
            ]);

            return [
                'success' => true,
                'form_definition_id' => $formDefId,
                'version_number' => $nextVersion,
                'message' => 'Form definition created successfully'
            ];

        } catch (Exception $e) {
            if (CONN::isInTransaction()) CONN::rollback();
            Log::logError('EDC.defineForm: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Operation failed. Check logs for details.'];
        }
    }

    /**
     * Gets active form definition by name
     *
     * Builds schema from relational tables and caches in memory.
     *
     * @param string $formName Form identifier
     * @param int|null $scopeEntityId Organization ID (null = uses Profile scope via Tenant::)
     * @return array|null Form definition or null if not found
     */
    public static function getFormDefinition(
        string $formName,
        ?int $scopeEntityId = null
    ): ?array {
        $cacheKey = self::getCacheKey($formName, $scopeEntityId);
        return Cache::getOrSet(self::CACHE_NS, $cacheKey, self::CACHE_TTL, function() use ($formName, $scopeEntityId) {
            $sql = "SELECT form_definition_id, form_name, form_title, form_description,
                           version_number, status, scope_entity_id,
                           created_at, created_by_profile_id
                    FROM edc_form_definitions
                    WHERE form_name = :name
                      AND is_active = TRUE";

            $params = [':name' => $formName];

            $opts = $scopeEntityId !== null ? ['scope_entity_id' => $scopeEntityId] : [];
            $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);

            $sql .= " LIMIT 1";

            $rows = CONN::dml($sql, $params);
            if (empty($rows)) return null;

            $form = $rows[0];
            $form['schema'] = self::buildSchemaFromRelational($form['form_definition_id']);
            return $form;
        });
    }

    /**
     * Gets form definition by ID
     *
     * @param int $formDefId Form definition ID
     * @return array|null Form definition or null
     */
    public static function getFormDefinitionById(int $formDefId): ?array {
        $sql = "SELECT form_definition_id, form_name, form_title, form_description,
                       version_number, is_active, status, scope_entity_id,
                       created_at, created_by_profile_id
                FROM edc_form_definitions
                WHERE form_definition_id = :id";

        $rows = CONN::dml($sql, [':id' => $formDefId]);
        if (empty($rows)) return null;

        $form = $rows[0];

        // Build schema from relational tables
        $form['schema'] = self::buildSchemaFromRelational($formDefId);

        return $form;
    }

    /**
     * Lists all forms (active versions only)
     *
     * @param int|null $scopeEntityId Filter by organization (null = uses Profile scope via Tenant::)
     * @param string|null $status Filter by status (draft, published, archived)
     * @return array ['success' => bool, 'forms' => array, 'total' => int]
     */
    public static function listForms(
        ?int $scopeEntityId = null,
        ?string $status = null
    ): array {
        try {
            $sql = "SELECT form_definition_id, form_name, form_title, form_description,
                           version_number, status, scope_entity_id,
                           created_at, created_by_profile_id
                    FROM edc_form_definitions
                    WHERE is_active = TRUE";

            $params = [];

            $opts = $scopeEntityId !== null ? ['scope_entity_id' => $scopeEntityId] : [];
            $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);

            if ($status !== null) {
                $sql .= " AND status = :status";
                $params[':status'] = $status;
            }

            $sql .= " ORDER BY created_at DESC";

            $rows = CONN::dml($sql, $params);

            return [
                'success' => true,
                'forms' => $rows,
                'total' => count($rows)
            ];

        } catch (Exception $e) {
            Log::logError('EDC.listForms: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Operation failed. Check logs for details.'];
        }
    }

    /**
     * Publishes a form (changes status from draft to published)
     *
     * @param int $formDefId Form definition ID
     * @param int $actorProfileId Actor
     * @return array ['success' => bool, 'message' => string]
     */
    public static function publishForm(int $formDefId, int $actorProfileId): array {
        try {
            $result = CONN::nodml(
                "UPDATE edc_form_definitions
                 SET status = 'published',
                     updated_by_profile_id = :actor,
                     updated_at = NOW()
                 WHERE form_definition_id = :id
                   AND is_active = TRUE
                   AND status = 'draft'",
                [':id' => $formDefId, ':actor' => $actorProfileId]
            );

            if ($result['rowCount'] === 0) {
                return ['success' => false, 'message' => 'Form not found or already published'];
            }

            Log::logInfo('EDC.publishForm', ['form_definition_id' => $formDefId, 'actor' => $actorProfileId]);

            return ['success' => true, 'message' => 'Form published successfully'];

        } catch (Exception $e) {
            Log::logError('EDC.publishForm: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Operation failed. Check logs for details.'];
        }
    }

    // ==================== FORM RESPONSES ====================

    /**
     * Creates a form response instance
     *
     * @param string $formName Form identifier
     * @param int $respondentProfileId Who is responding
     * @param int|null $scopeEntityId Organization
     * @param array $metadata Additional metadata (optional)
     * @return array ['success' => bool, 'form_response_id' => int]
     */
    public static function createResponse(
        string $formName,
        int $respondentProfileId,
        ?int $scopeEntityId = null,
        array $metadata = []
    ): array {
        CONN::begin();
        try {
            # Obtener definición activa
            $formDef = self::getFormDefinition($formName, $scopeEntityId);
            if (!$formDef) {
                throw new Exception("Form definition not found: $formName");
            }

            # Solo se pueden crear responses de forms publicados
            if ($formDef['status'] !== 'published') {
                throw new Exception("Cannot create response for unpublished form");
            }

            # Resolver scope via Tenant::
            $opts = $scopeEntityId !== null ? ['scope_entity_id' => $scopeEntityId] : [];
            $resolvedScope = Tenant::forInsert($opts);

            # Crear response
            $sql = "INSERT INTO edc_form_responses
                        (form_definition_id, scope_entity_id, respondent_profile_id,
                         status, version_number, metadata_json)
                    VALUES
                        (:form_def_id, :scope, :respondent,
                         'in_progress', 1, :metadata)";

            $result = CONN::nodml($sql, [
                ':form_def_id' => $formDef['form_definition_id'],
                ':scope' => $resolvedScope,
                ':respondent' => $respondentProfileId,
                ':metadata' => json_encode($metadata)
            ]);

            if (!$result['success']) {
                throw new Exception("Failed to create response");
            }

            $responseId = (int)$result['last_id'];

            CONN::commit();

            Log::logInfo('EDC.createResponse', [
                'form_name' => $formName,
                'form_response_id' => $responseId,
                'respondent' => $respondentProfileId,
                'scope' => $scopeEntityId
            ]);

            return [
                'success' => true,
                'form_response_id' => $responseId,
                'form_definition_id' => $formDef['form_definition_id'],
                'form_name' => $formName
            ];

        } catch (Exception $e) {
            if (CONN::isInTransaction()) CONN::rollback();
            Log::logError('EDC.createResponse: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Operation failed. Check logs for details.'];
        }
    }

    /**
     * Saves form response data with ALCOA+ versioning
     *
     * Uses DataCaptureService for automatic versioning and audit trail.
     *
     * @param int $formResponseId Response ID
     * @param array $fieldsData ['field_id' => 'value', ...]
     * @param string $reason Reason for change (ALCOA: audit trail)
     * @param string|null $newStatus New status (optional): 'submitted', 'locked', etc.
     * @param array $contextPayload Optional context overrides (macro_context, event_context, sub_context, parent_context_id)
     * @param string|null $sourceSystem Optional source system override
     * @param string|null $deviceId Optional device id override
     * @return array ['success' => bool, 'context_group_id' => int, 'fields_saved' => int]
     */
    public static function saveResponseData(
        int $formResponseId,
        array $fieldsData,
        string $reason = 'Data entry',
        ?string $newStatus = null,
        array $contextPayload = [],
        ?string $sourceSystem = null,
        ?string $deviceId = null
    ): array {
        CONN::begin();
        try {
            // Actor desde sesión
            $actorId = Profile::ctx()->accountId;
            if (!$actorId) {
                throw new Exception('Not authenticated');
            }

            # Obtener metadata de response (con filtro tenant)
            $sql = "SELECT r.form_definition_id, r.scope_entity_id, r.status,
                           d.form_name
                    FROM edc_form_responses r
                    JOIN edc_form_definitions d ON r.form_definition_id = d.form_definition_id
                    WHERE r.form_response_id = :rid";
            $params = [':rid' => $formResponseId];
            $sql = Tenant::applySql($sql, 'r.scope_entity_id', [], $params);

            $rows = CONN::dml($sql, $params);
            if (empty($rows)) {
                throw new Exception("Response not found: $formResponseId");
            }

            $response = $rows[0];
            $formDefId = (int)$response['form_definition_id'];

            // Build schema from relational tables
            $schema = self::buildSchemaFromRelational($formDefId);

            // Validar estado (no se puede editar locked)
            if ($response['status'] === 'locked') {
                throw new Exception('Cannot edit locked response');
            }

            // Preparar valores para DataCaptureService
            $valuesData = [];
            foreach ($fieldsData as $fieldId => $value) {
                // Buscar field en schema para validar
                $fieldDef = self::findFieldInSchema($schema, $fieldId);
                if (!$fieldDef) {
                    Log::logWarning("EDC.saveResponseData: Field not found in schema: $fieldId");
                    continue;
                }

                $uniqueName = "edc.{$formDefId}.{$fieldId}";

                $valuesData[] = [
                    'variable_name' => $uniqueName,
                    'value' => $value,
                    'reason' => $reason
                ];
            }

            if (empty($valuesData)) {
                throw new Exception('No valid fields to save');
            }

            // Guardar con versionado ALCOA+ via DataCaptureService
            $resolvedDeviceId = $deviceId ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
            if (strlen($resolvedDeviceId) > 100) {
                $resolvedDeviceId = substr($resolvedDeviceId, 0, 100);
            }

            $macroCtx = $contextPayload['macro_context'] ?? 'EDC_APP';
            $eventCtx = $contextPayload['event_context'] ?? ('FORM_' . $formDefId);
            $subCtx = $contextPayload['sub_context'] ?? ('RESPONSE_' . $formResponseId);
            $parentCtx = $contextPayload['parent_context_id'] ?? null;

            $saveResult = DataCaptureService::saveData(
                actorProfileId: $actorId,
                subjectEntityId: $formResponseId,
                scopeEntityId: $response['scope_entity_id'] ? (int)$response['scope_entity_id'] : null,
                contextPayload: [
                    'macro_context' => $macroCtx,
                    'event_context' => $eventCtx,
                    'sub_context' => $subCtx,
                    'parent_context_id' => $parentCtx
                ],
                valuesData: $valuesData,
                contextType: $response['status'] === 'in_progress'
                    ? 'edc_draft_save'
                    : 'edc_submission',
                sourceSystem: $sourceSystem ?? 'EDC_WEB_APP',
                deviceId: $resolvedDeviceId
            );

            if (!$saveResult['success']) {
                throw new Exception($saveResult['message']);
            }

            // Actualizar metadata de response
            $updateSql = "UPDATE edc_form_responses
                          SET data_capture_context_group_id = :cgid,
                              updated_at = NOW()";

            $updateParams = [
                ':cgid' => $saveResult['context_group_id'],
                ':rid' => $formResponseId
            ];

            if ($newStatus !== null) {
                $updateSql .= ", status = :status";
                $updateParams[':status'] = $newStatus;

                if ($newStatus === 'submitted' && $response['status'] === 'in_progress') {
                    $updateSql .= ", submitted_at = NOW()";
                }
            }

            $updateSql .= " WHERE form_response_id = :rid";

            CONN::nodml($updateSql, $updateParams);

            CONN::commit();

            Log::logInfo('EDC.saveResponseData', [
                'form_response_id' => $formResponseId,
                'fields_saved' => count($valuesData),
                'new_status' => $newStatus,
                'actor' => $actorId
            ]);

            return [
                'success' => true,
                'form_response_id' => $formResponseId,
                'context_group_id' => $saveResult['context_group_id'],
                'fields_saved' => count($valuesData),
                'status' => $newStatus ?? $response['status']
            ];

        } catch (Exception $e) {
            if (CONN::isInTransaction()) CONN::rollback();
            Log::logError('EDC.saveResponseData: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Operation failed. Check logs for details.'];
        }
    }

    /**
     * Gets form response data
     *
     * Retrieves both metadata from edc_form_responses and field values
     * from DataCaptureService (data_values_history).
     *
     * @param int $formResponseId Response ID
     * @param array|null $fieldIds Specific fields to retrieve (null = all)
     * @return array ['success' => bool, 'metadata' => array, 'fields' => array]
     */
    public static function getResponseData(
        int $formResponseId,
        ?array $fieldIds = null
    ): array {
        try {
            # Obtener metadata (con filtro tenant)
            $sql = "SELECT r.form_response_id, r.form_definition_id, r.status, r.version_number,
                           r.submitted_at, r.locked_at, r.respondent_profile_id,
                           r.scope_entity_id, r.metadata_json, r.created_at,
                           d.form_name, d.form_title
                    FROM edc_form_responses r
                    JOIN edc_form_definitions d ON r.form_definition_id = d.form_definition_id
                    WHERE r.form_response_id = :rid";
            $params = [':rid' => $formResponseId];
            $sql = Tenant::applySql($sql, 'r.scope_entity_id', [], $params);

            $rows = CONN::dml($sql, $params);
            if (empty($rows)) {
                return ['success' => false, 'message' => 'Response not found'];
            }

            $metadata = $rows[0];
            $formDefId = (int)$metadata['form_definition_id'];

            // Preparar field names para DataCaptureService
            $variableNames = null;
            if ($fieldIds !== null) {
                $variableNames = array_map(
                    fn($fid) => "edc.{$formDefId}.{$fid}",
                    $fieldIds
                );
            }

            // Obtener datos desde DataCaptureService
            $dataResult = DataCaptureService::getHotData($formResponseId, $variableNames);

            // Transformar a formato {variable_name => full_data} con metadata ALCOA+
            $fields = [];
            foreach ($dataResult['data'] ?? [] as $variableName => $valueData) {
                // Mantener el nombre completo como clave para compatibilidad con frontend
                $fields[$variableName] = [
                    'value' => $valueData['value'],
                    'version' => $valueData['version'] ?? null,
                    'timestamp' => $valueData['timestamp'] ?? null,
                    'last_updated_by_profile_id' => $valueData['last_updated_by_profile_id'] ?? null,
                    'source_system' => $valueData['source_system'] ?? null,
                    'device_id' => $valueData['device_id'] ?? null,
                    'reason_for_change' => $valueData['reason_for_change'] ?? null,
                    'data_type' => $valueData['data_type'] ?? null,
                    'label' => $valueData['label'] ?? null
                ];
            }

            return [
                'success' => true,
                'metadata' => [
                    'form_response_id' => (int)$metadata['form_response_id'],
                    'form_definition_id' => $formDefId,
                    'form_name' => $metadata['form_name'],
                    'form_title' => $metadata['form_title'],
                    'status' => $metadata['status'],
                    'version_number' => (int)$metadata['version_number'],
                    'submitted_at' => $metadata['submitted_at'],
                    'locked_at' => $metadata['locked_at'],
                    'respondent_profile_id' => (int)$metadata['respondent_profile_id'],
                    'scope_entity_id' => $metadata['scope_entity_id'] ? (int)$metadata['scope_entity_id'] : null,
                    'created_at' => $metadata['created_at']
                ],
                'fields' => $fields
            ];

        } catch (Exception $e) {
            Log::logError('EDC.getResponseData: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Operation failed. Check logs for details.'];
        }
    }

    /**
     * Locks a form response (no more edits allowed)
     *
     * @param int $formResponseId Response ID
     * @param int $actorProfileId Who is locking
     * @return array ['success' => bool, 'message' => string]
     */
    public static function lockResponse(int $formResponseId, int $actorProfileId): array {
        try {
            # UPDATE con filtro tenant (previene lock cross-tenant)
            $lockSql = "UPDATE edc_form_responses
                 SET status = 'locked',
                     locked_at = NOW(),
                     locked_by_profile_id = :actor,
                     updated_at = NOW()
                 WHERE form_response_id = :rid
                   AND status IN ('in_progress', 'submitted')";
            $lockParams = [':rid' => $formResponseId, ':actor' => $actorProfileId];
            $lockSql = Tenant::applySql($lockSql, 'scope_entity_id', [], $lockParams);

            $result = CONN::nodml($lockSql, $lockParams);

            if ($result['rowCount'] === 0) {
                return ['success' => false, 'message' => 'Response not found or already locked'];
            }

            Log::logInfo('EDC.lockResponse', ['form_response_id' => $formResponseId, 'actor' => $actorProfileId]);

            return ['success' => true, 'message' => 'Response locked successfully'];

        } catch (Exception $e) {
            Log::logError('EDC.lockResponse: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Operation failed. Check logs for details.'];
        }
    }

    /**
     * Gets audit trail for a specific field in a response
     *
     * @param int $formResponseId Response ID (entity_id en EAV)
     * @param string $fieldId Field identifier
     * @param string|null $macroContext Optional macro context filter
     * @param string|null $eventContext Optional event context filter
     * @return array ['success' => bool, 'audit_trail' => array]
     */
    public static function getFieldAuditTrail(
        int $formResponseId,
        string $fieldId,
        ?string $macroContext = null,
        ?string $eventContext = null
    ): array {
        try {
            # Obtener form_definition_id CON filtro de tenant (seguridad cross-tenant)
            $sql = "SELECT form_definition_id FROM edc_form_responses WHERE form_response_id = :rid";
            $params = [':rid' => $formResponseId];
            $sql = Tenant::applySql($sql, 'scope_entity_id', [], $params);
            $rows = CONN::dml($sql, $params);
            if (empty($rows)) {
                return ['success' => false, 'message' => 'Response not found'];
            }

            $formDefId = (int)$rows[0]['form_definition_id'];
            $variableName = "edc.{$formDefId}.{$fieldId}";

            // Obtener audit trail desde DataCaptureService (filtrado opcional por contexto)
            $auditResult = DataCaptureService::getAuditTrailForVariable(
                $formResponseId,
                $variableName,
                $macroContext,
                $eventContext
            );

            if (!$auditResult['success']) {
                return $auditResult;
            }

            return [
                'success' => true,
                'form_response_id' => $formResponseId,
                'field_id' => $fieldId,
                'audit_trail' => $auditResult['trail'] ?? [],
                'macro_context' => $macroContext,
                'event_context' => $eventContext
            ];

        } catch (Exception $e) {
            Log::logError('EDC.getFieldAuditTrail: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Operation failed. Check logs for details.'];
        }
    }

    /**
     * Lists form responses with filters
     *
     * @param array $filters ['form_name', 'respondent_profile_id', 'status', 'scope_entity_id']
     * @return array ['success' => bool, 'responses' => array, 'total' => int]
     */
    public static function listResponses(array $filters = []): array {
        try {
            $sql = "SELECT r.form_response_id, r.form_definition_id, r.status,
                           r.respondent_profile_id, r.submitted_at, r.created_at,
                           d.form_name, d.form_title
                    FROM edc_form_responses r
                    JOIN edc_form_definitions d ON r.form_definition_id = d.form_definition_id
                    WHERE 1=1";

            $params = [];

            if (!empty($filters['form_name'])) {
                $sql .= " AND d.form_name = :form_name";
                $params[':form_name'] = $filters['form_name'];
            }

            if (!empty($filters['respondent_profile_id'])) {
                $sql .= " AND r.respondent_profile_id = :respondent";
                $params[':respondent'] = $filters['respondent_profile_id'];
            }

            if (!empty($filters['status'])) {
                $sql .= " AND r.status = :status";
                $params[':status'] = $filters['status'];
            }

            # Tenant filter SIEMPRE (scope=NULL → AND 1=0)
            $scopeOpts = [];
            if (array_key_exists('scope_entity_id', $filters) && $filters['scope_entity_id'] !== null) {
                $scopeOpts['scope_entity_id'] = $filters['scope_entity_id'];
            }
            $sql = Tenant::applySql($sql, 'r.scope_entity_id', $scopeOpts, $params);

            # Responses con datos primero, luego por fecha DESC
            $sql .= " ORDER BY (r.data_capture_context_group_id IS NOT NULL) DESC, r.created_at DESC";

            $rows = CONN::dml($sql, $params);

            return [
                'success' => true,
                'responses' => $rows,
                'total' => count($rows)
            ];

        } catch (Exception $e) {
            Log::logError('EDC.listResponses: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Operation failed. Check logs for details.'];
        }
    }

    // ==================== PRIVATE HELPERS ====================

    /**
     * Populates relational structure (sections, fields, options, logic rules)
     *
     * This creates the normalized relational structure for analytical queries.
     * The schema_json is kept as a render cache for the frontend.
     *
     * @param int $formDefId Form definition ID
     * @param array $schema Form schema
     * @param int $actorProfileId Actor
     */
    private static function populateRelationalStructure(
        int $formDefId,
        array $schema,
        int $actorProfileId
    ): void {
        // 1. Insert sections
        foreach ($schema['sections'] ?? [] as $section) {
            $sectionSql = "INSERT INTO edc_form_sections
                            (form_definition_id, section_code, section_title,
                             section_description, order_index, status)
                           VALUES
                            (:form_def_id, :code, :title, :desc, :order, 'active')";

            $sectionResult = CONN::nodml($sectionSql, [
                ':form_def_id' => $formDefId,
                ':code' => $section['section_id'] ?? 'section_' . ($section['order'] ?? 1),
                ':title' => $section['title'] ?? 'Untitled Section',
                ':desc' => $section['description'] ?? null,
                ':order' => $section['order'] ?? 1
            ]);

            $sectionDbId = (int)$sectionResult['last_id'];

            // 2. Insert fields for this section
            foreach ($section['fields'] ?? [] as $field) {
                $fieldCode = $field['field_id'];
                $variableName = "edc.{$formDefId}.{$fieldCode}";

                $fieldSql = "INSERT INTO edc_form_fields
                                (form_definition_id, form_section_id, field_code, variable_name,
                                 field_label, field_hint, data_type, control_type,
                                 is_required, is_pii, order_index, status,
                                 html_attributes_json, validation_rules_json, ui_hints_json)
                             VALUES
                                (:form_def_id, :section_id, :code, :var_name,
                                 :label, :hint, :data_type, :control_type,
                                 :required, :pii, :order, 'active',
                                 :html_attrs, :validation, :ui_hints)";

                $fieldResult = CONN::nodml($fieldSql, [
                    ':form_def_id' => $formDefId,
                    ':section_id' => $sectionDbId,
                    ':code' => $fieldCode,
                    ':var_name' => $variableName,
                    ':label' => $field['label'],
                    ':hint' => $field['hint'] ?? null,
                    ':data_type' => strtoupper($field['data_type']),
                    ':control_type' => $field['control_type'] ?? 'text',
                    ':required' => (int)($field['is_required'] ?? true),
                    ':pii' => (int)($field['is_pii'] ?? false),
                    ':order' => $field['order'] ?? 1,
                    ':html_attrs' => json_encode($field['html_attributes'] ?? []),
                    ':validation' => json_encode($field['validation'] ?? []),
                    ':ui_hints' => json_encode($field['ui_hints'] ?? [])
                ]);

                $fieldDbId = (int)$fieldResult['last_id'];

                // 3. Insert options if field has them
                if (!empty($field['options'])) {
                    foreach ($field['options'] as $optIdx => $option) {
                        $optionSql = "INSERT INTO edc_form_field_options
                                        (form_field_id, option_code, option_label,
                                         order_index, is_default, is_active)
                                      VALUES
                                        (:field_id, :code, :label, :order, :default, TRUE)";

                        CONN::nodml($optionSql, [
                            ':field_id' => $fieldDbId,
                            ':code' => $option['value'] ?? $option['code'] ?? $optIdx,
                            ':label' => $option['label'] ?? $option['value'] ?? "Option $optIdx",
                            ':order' => $option['order'] ?? ($optIdx + 1),
                            ':default' => (int)($option['is_default'] ?? false)
                        ]);
                    }
                }
            }
        }

        // 4. Insert logic rules
        foreach ($schema['logic_rules'] ?? [] as $rule) {
            $ruleSql = "INSERT INTO edc_form_logic_rules
                            (form_definition_id, source_field_code, target_field_code,
                             condition_expression, action, status, settings_json)
                        VALUES
                            (:form_def_id, :source, :target, :condition, :action, 'active', :settings)";

            CONN::nodml($ruleSql, [
                ':form_def_id' => $formDefId,
                ':source' => $rule['source_field'] ?? $rule['source_field_code'],
                ':target' => $rule['target_field'] ?? $rule['target_field_code'],
                ':condition' => $rule['condition'] ?? $rule['condition_expression'],
                ':action' => $rule['action'],
                ':settings' => json_encode($rule['settings'] ?? [])
            ]);
        }

        Log::logInfo('EDC.populateRelationalStructure', [
            'form_definition_id' => $formDefId,
            'sections_count' => count($schema['sections'] ?? []),
            'rules_count' => count($schema['logic_rules'] ?? [])
        ]);
    }

    /**
     * Registers form fields in data_dictionary via DataDefinition
     *
     * @param int $formDefId Form definition ID
     * @param array $schema Form schema
     * @param int $actorProfileId Actor
     */
    private static function registerFormFieldsInDictionary(
        int $formDefId,
        array $schema,
        int $actorProfileId
    ): void {
        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $uniqueName = "edc.{$formDefId}.{$field['field_id']}";

                // Preparar attributes_json con estructura HTML5
                $attributes = [
                    'control_type' => $field['control_type'] ?? DataDefinition::inferControlType($field['data_type']),
                    'html_attributes' => $field['html_attributes'] ?? [],
                    'validation' => $field['validation'] ?? [],
                    'ui_hints' => $field['ui_hints'] ?? []
                ];

                // Usar DataDefinition wrapper
                DataDefinition::defineField([
                    'unique_name' => $uniqueName,
                    'label' => $field['label'],
                    'data_type' => $field['data_type'],
                    'is_pii' => $field['is_pii'] ?? false,
                    'attributes_json' => $attributes,
                    'status' => 'active'
                ], $actorProfileId);
            }
        }
    }

    /**
     * Finds a field in the form schema by field_id
     *
     * @param array $schema Form schema
     * @param string $fieldId Field identifier
     * @return array|null Field definition or null
     */
    private static function findFieldInSchema(array $schema, string $fieldId): ?array {
        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if ($field['field_id'] === $fieldId) {
                    return $field;
                }
            }
        }
        return null;
    }

    /**
     * Validates form schema structure
     *
     * @param array $schema Form schema
     * @return array ['success' => bool, 'message' => string, 'errors' => array]
     */
    private static function validateFormSchema(array $schema): array {
        $errors = [];

        // Required fields
        if (empty($schema['title'])) {
            $errors[] = 'Form title is required';
        }

        if (empty($schema['sections'])) {
            $errors[] = 'At least one section is required';
        }

        // Validate sections
        foreach ($schema['sections'] ?? [] as $idx => $section) {
            if (empty($section['fields'])) {
                $errors[] = "Section $idx must have at least one field";
            }

            // Validate fields
            foreach ($section['fields'] ?? [] as $fieldIdx => $field) {
                if (empty($field['field_id'])) {
                    $errors[] = "Section $idx, field $fieldIdx: field_id is required";
                }
                if (empty($field['data_type'])) {
                    $errors[] = "Section $idx, field {$field['field_id']}: data_type is required";
                }
                if (empty($field['label'])) {
                    $errors[] = "Section $idx, field {$field['field_id']}: label is required";
                }

                // Validate data_type is valid
                $dataType = strtoupper($field['data_type']);
                if (!in_array($dataType, DataDefinition::DATA_TYPES)) {
                    $errors[] = "Section $idx, field {$field['field_id']}: invalid data_type '$dataType'";
                }
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => 'Schema validation failed',
                'errors' => $errors
            ];
        }

        return ['success' => true];
    }

    /**
     * Builds form schema from relational tables (sections, fields, options, rules)
     *
     * This is the core method that reconstructs the schema without TEXT fields.
     * Optimized with minimal queries using JOINs.
     *
     * @param int $formDefId Form definition ID
     * @return array Schema structure
     */
    private static function buildSchemaFromRelational(int $formDefId): array {
        // Single query for sections and fields with LEFT JOIN
        $sql = "SELECT
                    s.form_section_id, s.section_code, s.section_title, s.section_description,
                    s.order_index as section_order,
                    f.form_field_id, f.field_code, f.field_label, f.field_hint,
                    f.data_type, f.control_type, f.is_required, f.is_pii,
                    f.order_index as field_order,
                    f.html_attributes_json, f.validation_rules_json, f.ui_hints_json
                FROM edc_form_sections s
                LEFT JOIN edc_form_fields f ON s.form_section_id = f.form_section_id AND f.status = 'active'
                WHERE s.form_definition_id = :id AND s.status = 'active'
                ORDER BY s.order_index, f.order_index";

        $rows = CONN::dml($sql, [':id' => $formDefId]);

        // Get all options in single query
        $fieldIds = array_filter(array_unique(array_column($rows, 'form_field_id')));
        $optionsMap = [];

        if (!empty($fieldIds)) {
            $optSql = "SELECT form_field_id, option_code, option_label, order_index
                       FROM edc_form_field_options
                       WHERE form_field_id IN (" . implode(',', $fieldIds) . ")
                         AND is_active = TRUE
                       ORDER BY form_field_id, order_index";

            $optRows = CONN::dml($optSql, []);
            foreach ($optRows as $opt) {
                $optionsMap[$opt['form_field_id']][] = [
                    'value' => $opt['option_code'],
                    'label' => $opt['option_label']
                ];
            }
        }

        // Build schema structure
        $sectionsMap = [];
        foreach ($rows as $row) {
            $sectionId = $row['form_section_id'];

            // Initialize section if not exists
            if (!isset($sectionsMap[$sectionId])) {
                $sectionsMap[$sectionId] = [
                    'section_id' => $row['section_code'],
                    'title' => $row['section_title'],
                    'description' => $row['section_description'],
                    'order' => (int)$row['section_order'],
                    'fields' => []
                ];
            }

            // Add field if exists
            if ($row['form_field_id']) {
                $fieldData = [
                    'field_id' => $row['field_code'],
                    'label' => $row['field_label'],
                    'hint' => $row['field_hint'],
                    'data_type' => $row['data_type'],
                    'control_type' => $row['control_type'],
                    'is_required' => (bool)$row['is_required'],
                    'is_pii' => (bool)$row['is_pii'],
                    'order' => (int)$row['field_order'],
                    'html_attributes' => json_decode($row['html_attributes_json'], true) ?? [],
                    'validation' => json_decode($row['validation_rules_json'], true) ?? [],
                    'ui_hints' => json_decode($row['ui_hints_json'], true) ?? []
                ];

                // Add options if exists
                if (isset($optionsMap[$row['form_field_id']])) {
                    $fieldData['options'] = $optionsMap[$row['form_field_id']];
                }

                $sectionsMap[$sectionId]['fields'][] = $fieldData;
            }
        }

        // Convert map to array and sort by order
        $sections = array_values($sectionsMap);
        usort($sections, fn($a, $b) => $a['order'] <=> $b['order']);

        // Get logic rules
        $rulesSql = "SELECT source_field_code, target_field_code, condition_expression,
                            action, settings_json
                     FROM edc_form_logic_rules
                     WHERE form_definition_id = :id AND status = 'active'";

        $rulesRows = CONN::dml($rulesSql, [':id' => $formDefId]);

        $rules = array_map(function($rule) {
            return [
                'source_field' => $rule['source_field_code'],
                'target_field' => $rule['target_field_code'],
                'condition' => $rule['condition_expression'],
                'action' => $rule['action'],
                'settings' => json_decode($rule['settings_json'], true) ?? []
            ];
        }, $rulesRows);

        return [
            'sections' => $sections,
            'logic_rules' => $rules
        ];
    }

    /**
     * Generates cache key for form schema
     *
     * @param string $formName Form name
     * @param int|null $scopeEntityId Scope entity ID
     * @return string Cache key
     */
    private static function getCacheKey(string $formName, ?int $scopeEntityId): string {
        $scope = $scopeEntityId ?? Tenant::resolve();
        return ($scope ?? 0) . ':' . $formName;
    }

}
