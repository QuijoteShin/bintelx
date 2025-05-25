# `CDC\AuditTrail` - CDC Audit Trail Access

**File:** `custom/cdc/Business/AuditTrail.php`
**Namespace:** `CDC`

## 1. Purpose

The `AuditTrail` class provides methods to retrieve data change histories specifically tailored to the CDC module's context. It acts as a wrapper around `bX\DataCaptureService::getAuditTrailForField`, simplifying calls by pre-setting the `applicationName` to `\CDC\CRF::CDC_APPLICATION_NAME` ('CDC_APP') and assisting in the translation of CDC entity identifiers (like `form_instance_id`) into the `contextKeyValues` required by the `DataCaptureService`.

Its primary goal is to make it easier for other CDC components or UI layers to request and display audit trails for specific data points or entire form instances, respecting the versioned setup under which data was captured.

## 2. Dependencies

* `bX\DataCaptureService`: Core dependency for fetching raw audit trail data.
* `bX\Log`: For logging.
* `CDC\FormInstance`: Used to retrieve context (`bnx_entity_id`, `form_domain`, `study_internal_id`, `flow_chart_version_actual`) from a `form_instance_id`.
* `CDC\CRF`: Used by `getFormInstanceAuditTrail` to determine all relevant `field_name`s for a given form instance by fetching its schema (which is versioned by `flow_chart_version_actual` via `CRF::getFormSchema`).
* `CDC\Study`: (Indirectly) To resolve `study_internal_id` to a public `studyId` if needed by `CRF::getFormSchema`.

## 3. Database Interaction

* This class **does not directly write** to any `cdc_*` database tables.
* It **reads** from `cdc_form_instance` (via `CDC\FormInstance` class methods) to obtain context.
* It **reads** from `cdc_form_fields` and `capture_definition` (via `CDC\CRF` class methods) to get versioned form schemas.

## 4. Key Concepts

* **Field-Level Audit Trail**: The history of changes for a single `field_name` within a `DataCaptureService` context (`BNX_ENTITY_ID`, `FORM_DOMAIN`).
* **Form-Level Audit Trail**: An aggregation of field-level audit trails for all fields defined in the specific version of a form (`form_domain` and `flow_chart_version_actual`) associated with a `FormInstance`.
* **DCS Context Translation**: Translating `formInstanceId` into the `['BNX_ENTITY_ID', 'FORM_DOMAIN']` context for `DataCaptureService`.

## 5. Core Static Methods

### `getFieldAuditTrail(string $bnxEntityId, string $formDomain, string $fieldName): array`

* **Purpose:** Retrieves the audit trail for a single field using its direct DCS context keys.
* **Parameters:**
  * `$bnxEntityId` (string, **required**).
  * `$formDomain` (string, **required**).
  * `$fieldName` (string, **required**).
* **Returns:** `['success' => bool, 'trail' => array|null, 'message' => string]` (mirrors DCS return).
* **Internal Logic:**
  1. Constructs `$contextKeyValues = ['BNX_ENTITY_ID' => $bnxEntityId, 'FORM_DOMAIN' => $formDomain]`.
  2. Calls `\bX\DataCaptureService::getAuditTrailForField(\CDC\CRF::CDC_APPLICATION_NAME, $contextKeyValues, $fieldName)`.

### `getFieldAuditTrailByFormInstance(int $formInstanceId, string $fieldName): array`

* **Purpose:** Retrieves the audit trail for a specific field within a known `FormInstance`.
* **Parameters:**
  * `$formInstanceId` (int, **required**).
  * `$fieldName` (string, **required**).
* **Returns:** `['success' => bool, 'trail' => array|null, 'message' => string]`
* **Internal Logic:**
  1. Calls `\CDC\FormInstance::getFormInstanceDetails($formInstanceId)` to get `bnx_entity_id` and `form_domain`.
  2. If successful, calls `self::getFieldAuditTrail($bnx_entity_id, $form_domain, $fieldName)`.

### `getFormInstanceAuditTrail(int $formInstanceId): array`

* **Purpose:** Retrieves audit trails for **all** fields belonging to the specified `FormInstance`, based on the form structure defined for its `flow_chart_version_actual`.
* **Parameters:**
  * `$formInstanceId` (int, **required**).
* **Returns:** `['success' => bool, 'trails_by_field' => array|null, 'message' => string]`
  * `trails_by_field`: `['fieldName1' => [...trail...], 'fieldName2' => [...trail...]]`.
* **Internal Logic:**
  1. Call `\CDC\FormInstance::getFormInstanceDetails($formInstanceId)` to get `study_internal_id`, `bnx_entity_id`, `form_domain`, and `flow_chart_version_actual`.
  2. Resolve `study_internal_id` to public `studyId` (e.g., using `\CDC\Study::getStudyDetailsByInternalId`).
  3. Call `\CDC\CRF::getFormSchema($publicStudyId, $flow_chart_version_actual, $form_domain)` to get the list of `field_name`s.
  4. For each `field_name` in the schema, call `self::getFieldAuditTrail($bnx_entity_id, $form_domain, $fieldName)`.
  5. Aggregate results into `trails_by_field`.

## 6. Example Usage

```php
// Assume $formInstanceId = 123; (for a specific VS form instance)
// Assume $specificFieldName = 'VSORRES_SYSBP';

// Get audit trail for a single field
$fieldTrailResult = \CDC\AuditTrail::getFieldAuditTrailByFormInstance($formInstanceId, $specificFieldName);

if ($fieldTrailResult['success']) {
    // \bX\Log::logInfo("Audit Trail for field '$specificFieldName':", $fieldTrailResult['trail']);
}

// Get audit trails for all fields in the form instance
$formTrailResult = \CDC\AuditTrail::getFormInstanceAuditTrail($formInstanceId);

if ($formTrailResult['success']) {
    // \bX\Log::logInfo("Full Form Audit Trail for instance $formInstanceId:", $formTrailResult['trails_by_field']);
}
```

---