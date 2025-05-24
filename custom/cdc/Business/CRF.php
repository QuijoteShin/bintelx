<?php # custom/cdc/Business/CRF.php

namespace CDC;

use bX\CONN;
use bX\Log;
use bX\DataCaptureService;
use CDC\Study; // To use Study::getStudyDetails
use Exception;

/**
 * Class CRF (Case Report Form)
 *
 * Manages the definition and structure of Clinical Forms.
 * It acts as an interface to DataCaptureService for defining fields
 * within the 'CDC_APP' context and manages the linkage between
 * form domains and fields (via `cdc_form_fields`) for specific studies.
 * Its key function is to provide the 'Form Schema' for UI rendering.
 *
 * @package CDC
 */
class CRF {

    const CDC_APPLICATION_NAME = 'CDC_APP';

    /**
     * Defines or updates a base CRF field in DataCaptureService.
     * A wrapper for DataCaptureService::defineCaptureField for 'CDC_APP'.
     *
     * @param string $fieldName Unique, CDISC-like name (e.g., 'VSORRES_SYSBP').
     * @param string $dataType 'VARCHAR', 'NUMERIC', 'DATE', 'BOOLEAN'.
     * @param string $label User-friendly label.
     * @param array $attributes Associative array for attributes_json.
     * @param string $actorUserId ID of the user performing the action.
     * @return array Mirrors DataCaptureService::defineCaptureField return.
     */
    public static function defineCRFField(string $fieldName, string $dataType, string $label, array $attributes = [], string $actorUserId): array {
        Log::logInfo("CRF::defineCRFField called.", ['field' => $fieldName, 'type' => $dataType, 'actor' => $actorUserId]);

        if (empty($fieldName) || empty($dataType) || empty($label) || empty($actorUserId)) {
            Log::logWarning("CRF::defineCRFField - Missing required parameters.", ['field' => $fieldName]);
            return ['success' => false, 'message' => 'Field Name, Data Type, Label, and Actor User ID are required.'];
        }

        $fieldDefinition = [
            'field_name' => $fieldName,
            'data_type' => $dataType,
            'label' => $label,
            'attributes_json' => $attributes,
            'is_active' => true,
        ];

        try {
            $result = DataCaptureService::defineCaptureField(
                self::CDC_APPLICATION_NAME,
                $fieldDefinition,
                $actorUserId
            );
            return $result;
        } catch (Exception $e) {
            Log::logError("CRF::defineCRFField Exception: " . $e->getMessage(), ['field' => $fieldName, 'trace' => substr($e->getTraceAsString(), 0, 500)]);
            return ['success' => false, 'message' => 'Error defining CRF field: ' . $e->getMessage()];
        }
    }

    /**
     * Links a CRF Field to a Form Domain for a specific Study, defining its order.
     * Inserts or updates a record in `cdc_form_fields`.
     *
     * @param string $studyId Public ID of the study.
     * @param string $formDomain Identifier for the form (e.g., 'VS').
     * @param string $fieldName The field_name to add.
     * @param int $itemOrder Sequence number for this field.
     * @param array $options Optional settings: 'is_mandatory', 'attributes_override_json', 'section_name'.
     * @param string $actorUserId ID of the user performing the action.
     * @return array ['success' => bool, 'form_field_id' => int|null, 'message' => string]
     */
    public static function addFormField(string $studyId, string $formDomain, string $fieldName, int $itemOrder, array $options = [], string $actorUserId): array {
        Log::logInfo("CRF::addFormField called.", ['study' => $studyId, 'domain' => $formDomain, 'field' => $fieldName, 'order' => $itemOrder, 'actor' => $actorUserId]);

        // Validate basic input
        if (empty($studyId) || empty($formDomain) || empty($fieldName) || empty($actorUserId)) {
            return ['success' => false, 'message' => 'Study ID, Form Domain, Field Name, and Actor User ID are required.'];
        }

        CONN::begin();
        try {
            // 1. Get Study Internal ID
            $studyDetailsResult = Study::getStudyDetails($studyId);
            if (!$studyDetailsResult['success']) {
                CONN::rollback();
                return $studyDetailsResult; // Return the error message from Study class
            }
            $studyInternalId = $studyDetailsResult['study_details']['study_internal_id'];

            // 2. TODO (Optional but recommended): Validate if $fieldName exists in capture_definition.
            //    This might require a direct query or a new method in DataCaptureService.
            //    For now, we proceed, relying on DB constraints or later validation.

            // 3. Prepare parameters for INSERT/UPDATE
            $isMandatory = $options['is_mandatory'] ?? true;
            $attributesOverride = isset($options['attributes_override_json'])
                ? (is_array($options['attributes_override_json']) ? json_encode($options['attributes_override_json']) : $options['attributes_override_json'])
                : null;
            $sectionName = $options['section_name'] ?? null;

            // Using INSERT ... ON DUPLICATE KEY UPDATE for idempotency
            $sql = "INSERT INTO cdc_form_fields
                        (study_internal_id_ref, form_domain, field_name, item_order, section_name, is_mandatory, attributes_override_json, created_at, updated_at)
                    VALUES
                        (:study_id, :domain, :field, :order, :section, :mandatory, :override, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        item_order = VALUES(item_order),
                        section_name = VALUES(section_name),
                        is_mandatory = VALUES(is_mandatory),
                        attributes_override_json = VALUES(attributes_override_json),
                        updated_at = NOW()";

            $params = [
                ':study_id' => $studyInternalId,
                ':domain' => $formDomain,
                ':field' => $fieldName,
                ':order' => $itemOrder,
                ':section' => $sectionName,
                ':mandatory' => $isMandatory,
                ':override' => $attributesOverride
            ];

            $result = CONN::nodml($sql, $params);

            if (!$result['success']) {
                throw new Exception("Failed to add/update form field. DB Error: " . ($result['error'] ?? 'Unknown'));
            }

            // Get the ID (might be the insert ID or we might need to select it if updated)
            // For simplicity, we'll return the insert ID if available.
            $formFieldId = $result['last_id'] ?? null;
            if (!$formFieldId && $result['rowCount'] > 0) {
                 // If it was an update, try to fetch the ID
                 $fetchSql = "SELECT form_field_id FROM cdc_form_fields WHERE study_internal_id_ref = :study_id AND form_domain = :domain AND field_name = :field";
                 $idRow = CONN::dml($fetchSql, [':study_id' => $studyInternalId, ':domain' => $formDomain, ':field' => $fieldName]);
                 $formFieldId = $idRow[0]['form_field_id'] ?? null;
            }

            CONN::commit();
            return ['success' => true, 'form_field_id' => $formFieldId, 'message' => "Field '$fieldName' added/updated in form '$formDomain'."];

        } catch (Exception $e) {
            if (CONN::isInTransaction()) CONN::rollback();
            Log::logError("CRF::addFormField Exception: " . $e->getMessage(), ['study' => $studyId, 'domain' => $formDomain, 'field' => $fieldName, 'trace' => substr($e->getTraceAsString(), 0, 500)]);
            return ['success' => false, 'message' => 'Error adding form field: ' . $e->getMessage()];
        }
    }

    /**
     * Retrieves the complete, ordered schema for a form within a study.
     * Merges data from `cdc_form_fields` and `capture_definition`.
     *
     * @param string $studyId Public ID of the study.
     * @param string $formDomain Identifier for the form (e.g., 'VS').
     * @return array ['success' => bool, 'schema' => array|null, 'message' => string]
     */
    public static function getFormSchema(string $studyId, string $formDomain): array {
        Log::logInfo("CRF::getFormSchema called.", ['study' => $studyId, 'domain' => $formDomain]);

        if (empty($studyId) || empty($formDomain)) {
            return ['success' => false, 'message' => 'Study ID and Form Domain are required.'];
        }

        try {
            // 1. Get Study Internal ID
            $studyDetailsResult = Study::getStudyDetails($studyId);
            if (!$studyDetailsResult['success']) {
                return $studyDetailsResult;
            }
            $studyInternalId = $studyDetailsResult['study_details']['study_internal_id'];

            // 2. Get fields and order from cdc_form_fields
            $sqlFields = "SELECT form_field_id, field_name, item_order, section_name, is_mandatory, attributes_override_json
                          FROM cdc_form_fields
                          WHERE study_internal_id_ref = :study_id AND form_domain = :domain
                          ORDER BY item_order ASC";
            $formFieldsRows = CONN::dml($sqlFields, [':study_id' => $studyInternalId, ':domain' => $formDomain]);

            if (empty($formFieldsRows)) {
                return ['success' => true, 'schema' => [], 'message' => "No fields configured for form '$formDomain' in study '$studyId'."];
            }

            $fieldNames = array_column($formFieldsRows, 'field_name');

            // 3. Get definitions from capture_definition
            $placeholders = implode(',', array_fill(0, count($fieldNames), '?'));
            $sqlDefs = "SELECT field_name, label, data_type, attributes_json
                        FROM capture_definition
                        WHERE application_name = ? AND field_name IN ($placeholders) AND is_active = 1";

            // Prepend application name to the field names array for parameters
            $paramsDefs = array_merge([self::CDC_APPLICATION_NAME], $fieldNames);
            $defRows = CONN::dml($sqlDefs, $paramsDefs);

            $definitionsMap = [];
            foreach ($defRows as $row) {
                $definitionsMap[$row['field_name']] = [
                    'label' => $row['label'],
                    'data_type' => $row['data_type'],
                    'attributes' => json_decode($row['attributes_json'], true) ?: []
                ];
            }

            // 4. Combine and build the final schema
            $schema = [];
            foreach ($formFieldsRows as $formField) {
                $fieldName = $formField['field_name'];
                if (isset($definitionsMap[$fieldName])) {
                    $baseDef = $definitionsMap[$fieldName];
                    $overrideAttr = json_decode($formField['attributes_override_json'], true) ?: [];

                    $schema[] = [
                        'field_name' => $fieldName,
                        'item_order' => (int)$formField['item_order'],
                        'label' => $baseDef['label'],
                        'data_type' => $baseDef['data_type'],
                        'is_mandatory' => (bool)$formField['is_mandatory'],
                        'section_name' => $formField['section_name'],
                        // Merge attributes, letting overrides take precedence
                        'attributes' => array_merge($baseDef['attributes'], $overrideAttr),
                        '_form_field_id' => (int)$formField['form_field_id'] // Internal ID for reference
                    ];
                } else {
                    Log::logWarning("CRF::getFormSchema - Definition not found or inactive for field '$fieldName'.", ['study' => $studyId, 'domain' => $formDomain]);
                }
            }

            return ['success' => true, 'schema' => $schema, 'message' => 'Schema retrieved successfully.'];

        } catch (Exception $e) {
            Log::logError("CRF::getFormSchema Exception: " . $e->getMessage(), ['study' => $studyId, 'domain' => $formDomain, 'trace' => substr($e->getTraceAsString(), 0, 500)]);
            return ['success' => false, 'schema' => null, 'message' => 'Error retrieving form schema: ' . $e->getMessage()];
        }
    }
}