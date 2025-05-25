# `CDC\AuditTrail` - CDC Audit Trail Access

**File:** `custom/cdc/Business/AuditTrail.php`
**Namespace:** `CDC`

## 1. Purpose

The `AuditTrail` class provides methods to retrieve data change histories specifically tailored to the CDC module's context. It acts as a wrapper around `bX\DataCaptureService::getAuditTrailForField`, simplifying calls by pre-setting the `applicationName` to 'CDC_APP' and assisting in the translation of CDC entity identifiers (like `form_instance_id`) into the `contextKeyValues` required by the `DataCaptureService`.

Its primary goal is to make it easier for other CDC components or UI layers to request and display audit trails for specific data points or entire form instances.

## 2. Dependencies

* `bX\DataCaptureService`: This is the core dependency, as all audit trail data is ultimately fetched from it.
* `bX\Log`: For logging any errors during the process.
* `CDC\FormInstance`: Used by methods like `getFieldAuditTrailByFormInstance` and `getFormInstanceAuditTrail` to retrieve the necessary context (`bnx_entity_id`, `form_domain`) from a `form_instance_id`.
* `CDC\CRF`: Used by `getFormInstanceAuditTrail` to determine all relevant `field_name`s for a given form instance by fetching its schema (which is versioned by `flow_chart_version`).
* `CDC\Study`: (Indirectly) `FormInstance` and `CRF` depend on `Study` to resolve `studyId`.

## 3. Database Interaction

* This class **does not directly write** to any `cdc_*` database tables.
* It **reads** from `cdc_form_instance` (via `CDC\FormInstance` class methods) to obtain context for `DataCaptureService` calls.
* It **reads** from `cdc_form_fields` and `capture_definition` (via `CDC\CRF` class methods) to get form schemas.

## 4. Key Concepts

* **Field-Level Audit Trail**: The complete history of changes for a single data point (a specific `field_name` within a `form_domain` for a `bnx_entity_id`).
* **Form-Level Audit Trail**: An aggregation of the audit trails for all fields that constitute a specific `FormInstance` (considering its `form_domain` and `flow_chart_version_actual`).
* **DCS Context Translation**: A key function is to take a CDC-specific identifier (like `$formInstanceId`) and resolve it into the `contextKeyValues` (`['BNX_ENTITY_ID' => ..., 'FORM_DOMAIN' => ...]`) that `DataCaptureService` requires.

## 5. Core Static Methods (Proposed)

### `getFieldAuditTrail(string $bnxEntityId, string $formDomain, string $fieldName): array`

* **Purpose:** Retrieves the audit trail for a single, specific field identified by its direct `DataCaptureService` context keys relevant to CDC.
* **Parameters:**
    * `$bnxEntityId` (string, **required**): The Bintelx Entity ID for the patient/subject.
    * `$formDomain` (string, **required**): The `form_domain` where the field resides.
    * `$fieldName` (string, **required**): The specific `field_name` of the data point.
* **Returns:** `['success' => bool, 'trail' => array|null, 'message' => string]`
    * `trail`: An array of version records as returned by `DataCaptureService::getAuditTrailForField`.
* **Internal Logic:**
    1. Constructs `$contextKeyValues = ['BNX_ENTITY_ID' => $bnxEntityId, 'FORM_DOMAIN' => $formDomain]`.
    2. Calls `\bX\DataCaptureService::getAuditTrailForField(\CDC\CRF::CDC_APPLICATION_NAME, $contextKeyValues, $fieldName)`.

### `getFieldAuditTrailByFormInstance(int $formInstanceId, string $fieldName): array`

* **Purpose:** Retrieves the audit trail for a specific field, using a `form_instance_id` to derive the necessary context.
* **Parameters:**
    * `$formInstanceId` (int, **required**): The ID of the `cdc_form_instance`.
    * `$fieldName` (string, **required**): The specific `field_name`.
* **Returns:** `['success' => bool, 'trail' => array|null, 'message' => string]`
* **Internal Logic:**
    1. Calls `\CDC\FormInstance::getFormInstanceDetails($formInstanceId)` to get `bnx_entity_id` and `form_domain`.
    2. If successful, constructs `$contextKeyValues` and calls `\bX\DataCaptureService::getAuditTrailForField`.

### `getFormInstanceAuditTrail(int $formInstanceId): array`

* **Purpose:** Retrieves audit trails for **all** fields that belong to the specified `FormInstance`. This involves getting the form's schema for the correct `flow_chart_version_actual` associated with the `formInstanceId`.
* **Parameters:**
    * `$formInstanceId` (int, **required**): The ID of the `cdc_form_instance`.
* **Returns:** `['success' => bool, 'trails_by_field' => array|null, 'message' => string]`
    * `trails_by_field`: An associative array where keys are `field_name`s and values are their respective audit trail arrays.
        ```json
        {
            "success": true,
            "trails_by_field": {
                "VSPERF": [ {"version_id": 1, ...}, {"version_id": 2, ...} ],
                "VSDTC": [ {"version_id": 3, ...} ]
            },
            "message": "Audit trails retrieved successfully."
        }
        ```
* **Internal Logic:**
    1. Calls `\CDC\FormInstance::getFormInstanceDetails($formInstanceId)` to get `study_internal_id`, `bnx_entity_id`, `form_domain`, and crucially `flow_chart_version_actual`. Also need public `studyId` if `CRF::getFormSchema` expects it.
    2. Calls `\CDC\Study::getStudyDetailsByInternalId($study_internal_id)` to get public `studyId` (if needed for `CRF::getFormSchema`).
    3. Calls `\CDC\CRF::getFormSchema($publicStudyId, $flow_chart_version_actual, $form_domain)` to get the list of `field_name`s for that form as defined in that specific protocol version.
    4. Constructs the base `$contextKeyValues = ['BNX_ENTITY_ID' => $bnx_entity_id, 'FORM_DOMAIN' => $form_domain]`.
    5. Iterates through each `field_name` from the schema:
        * Calls `\bX\DataCaptureService::getAuditTrailForField(\CDC\CRF::CDC_APPLICATION_NAME, $contextKeyValues, $fieldName)`.
        * Stores the result in the `trails_by_field` associative array.
    6. Returns the aggregated results.

## 6. Example Usage

```php
<?php
// Assume $formInstanceId = 123; (for a specific VS form instance)
// Assume $specificFieldName = 'VSORRES_SYSBP';

// Get audit trail for a single field using formInstanceId
$fieldTrailResult = \CDC\AuditTrail::getFieldAuditTrailByFormInstance($formInstanceId, $specificFieldName);

if ($fieldTrailResult['success']) {
    echo "Audit Trail for field '$specificFieldName':\n";
    // print_r($fieldTrailResult['trail']);
} else {
    // \bX\Log::logError("Failed to get field audit trail: " . $fieldTrailResult['message']);
}

// Get audit trails for all fields in the form instance
$formTrailResult = \CDC\AuditTrail::getFormInstanceAuditTrail($formInstanceId);

if ($formTrailResult['success']) {
    echo "Full Form Audit Trail for instance $formInstanceId:\n";
    foreach ($formTrailResult['trails_by_field'] as $fieldName => $trail) {
        echo "  Field: $fieldName\n";
        // print_r($trail);
    }
} else {
    // \bX\Log::logError("Failed to get form instance audit trail: " . $formTrailResult['message']);
}

// Get audit trail using direct context (less common from CDC module itself, but possible)
// $directTrailResult = \CDC\AuditTrail::getFieldAuditTrail('PXYZ007', 'VS', 'VSORRES_SYSBP');
```

---