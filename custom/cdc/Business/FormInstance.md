# `CDC\FormInstance` - Form Instance Management

**File:** `custom/cdc/Business/FormInstance.php`
**Namespace:** `CDC`

## 1. Purpose

The `FormInstance` class is responsible for managing individual instances of a clinical form (eCRF page) being filled out for a specific subject within a study visit event (represented by an `ISF` record). It acts as a central piece in the data capture workflow, tracking the lifecycle (status) of a form's data and serving as the **critical bridge** between the clinical context (study, subject, ISF event which contains `flow_chart_version_actual` and `branch_code_actual`) and the actual versioned data stored in `bX\DataCaptureService`.

Each `cdc_form_instance` record corresponds to one `form_domain` for one subject, linked to a specific `isf_id`, and captured under a specific `flow_chart_version_actual` and `branch_code_actual` (these latter two are inherited from the parent ISF).

## 2. Dependencies

* `bX\CONN`: For database interactions with `cdc_form_instance`.
* `bX\Log`: For logging.
* `bX\Profile`: Used internally to retrieve `actorUserId`.
* `bX\DataCaptureService`: Its `context_group_id` is stored; this class orchestrates calls to its `saveRecord` and `getRecord`.
* `CDC\Study`: (Indirectly) Used by `ISF` to provide study context.
* `CDC\ISF`: (Crucially) `FormInstance` is created and managed in the context of an `ISF` record. This class will need to fetch details from the parent `ISF` record.
* `CDC\CRF`: (Indirectly) The form schema (fetched by `CRF::getFormSchema` using the `flow_chart_version_actual` from the `FormInstance`/`ISF`) is used by the UI or validation layers.

## 3. Database Table: `cdc_form_instance`

Reflects our consolidated `cdc.sql`.

* **Table Name**: `cdc_form_instance`
* **Key Columns**:
  * `form_instance_id` (PK)
  * `isf_id` (FK to `cdc_isf.isf_id`, **required**)
  * `study_internal_id` (FK, denormalized from ISF)
  * `bnx_entity_id` (Patient ID, denormalized from ISF)
  * `flow_chart_item_id` (FK, optional, to planned item)
  * `form_domain`
  * `flow_chart_version_actual` (VARCHAR(255), **required**, denormalized from ISF): Protocol version for this data.
  * `branch_code_actual` (VARCHAR(50), **required**, default '__COMMON__', denormalized from ISF): Branch for this data.
  * `status` (ENUM('NOT_STARTED', 'DRAFT', 'OPEN', 'COMPLETED', 'FINALIZED', 'LOCKED', 'QUERIED', 'CANCELLED'), DEFAULT 'NOT_STARTED')
  * `form_version_instance` (INT UNSIGNED, DEFAULT 0): Internal save counter.
  * `data_capture_context_group_id` (BIGINT UNSIGNED, NULL): Link to DCS.
  * Actor IDs (`created_by_actor_id`, `updated_by_actor_id`, `finalized_by_actor_id`, `locked_by_actor_id`).
  * Timestamps.

## 4. Key Concepts

* **Link to ISF**: Every `FormInstance` *must* belong to an `ISF` event (`isf_id`).
* **Context Inheritance**: `flow_chart_version_actual` and `branch_code_actual` are inherited from the parent `ISF` at the time of `FormInstance` creation/retrieval, ensuring data is captured against the correct, versioned setup.
* **Data Link (`data_capture_context_group_id`)**: The crucial link to `DataCaptureService`.
* **Status Management (`status`)**: Tracks the form's data entry lifecycle.

## 5. Core Static Methods

*Actor ID for all methods creating/modifying data is obtained internally via `\bX\Profile`.*

### `getOrCreateFormInstance(int $isfId, string $formDomain): array`

* **Purpose:** Finds an existing `FormInstance` (non-terminal state) linked to `$isfId` and `$formDomain`. If none, creates a new one in 'NOT_STARTED' or 'DRAFT' status. It **inherits** `study_internal_id`, `bnx_entity_id`, `flow_chart_version_actual`, and `branch_code_actual` from the parent `cdc_isf` record identified by `$isfId`.
* **Parameters:**
  * `$isfId` (int, **required**): The ID of the parent `cdc_isf` visit event.
  * `$formDomain` (string, **required**): The domain of the form (e.g., 'VS').
* **Returns:** `['success' => bool, 'form_instance_id' => int|null, 'status' => string|null, 'data_capture_context_group_id' => int|null, 'branch_code_actual' => string|null, 'flow_chart_version_actual' => string|null, 'message' => string, 'is_new' => bool]`
* **Internal Logic Summary**:
  1. Fetch parent `cdc_isf` record using `$isfId` to get `study_internal_id`, `bnx_entity_id`, `visit_num_actual` (from `isf` or its linked `flow_chart_id`), `flow_chart_version_actual`, `branch_code_actual`. Error if ISF not found.
  2. Query `cdc_form_instance` for an existing suitable instance based on `$isfId`, `$formDomain`.
  3. If not found or unsuitable, `INSERT` new record into `cdc_form_instance`, populating inherited fields.

### `getFormInstanceDetails(int $formInstanceId): array`

* **Purpose:** Retrieves all details for a specific `form_instance_id`.
* **Returns:** `['success' => bool, 'details' => array|null, 'message' => string]` (where `details` includes all `cdc_form_instance` columns).

### `updateFormInstanceStatus(int $formInstanceId, string $newStatus, array $options = []): array`

* **Purpose:** Updates the `status` of a `FormInstance`. Validates status transitions.
* **Parameters:** `$formInstanceId`, `$newStatus` (ENUM value), `$options` (optional).
* **Returns:** `['success' => bool, 'message' => string]`

### `saveFormInstanceData(int $formInstanceId, array $fieldsDataFromUI, ?string $changeReason = null, ?string $defaultEventType = null): array`

* **Purpose:** Orchestrates saving data for this `FormInstance` to `bX\DataCaptureService`.
* **Parameters:** `$formInstanceId`, `$fieldsDataFromUI` (`['field_name' => 'value', ...]`), `$changeReason`, `$defaultEventType`.
* **Returns:** `['success' => bool, 'message' => string, 'data_capture_context_group_id' => int|null, 'saved_fields_info' => array|null]`
* **Logic Highlights:**
  1. Fetch `FormInstance` details (for `bnx_entity_id`, `form_domain`, `status`, current `data_capture_context_group_id`).
  2. Validate status.
  3. Prepare DCS context: `['BNX_ENTITY_ID' => ..., 'FORM_DOMAIN' => ...]`.
  4. Call `\bX\DataCaptureService::saveRecord(...)`.
  5. If successful: update `cdc_form_instance.data_capture_context_group_id`, increment `form_version_instance`, update timestamps, potentially transition `status`.

### `getFormData(int $formInstanceId, ?array $fieldNames = null): array`

* **Purpose:** Retrieves "hot" data for a `FormInstance` from `bX\DataCaptureService`.
* **Parameters:** `$formInstanceId`, `$fieldNames` (optional array).
* **Returns:** `['success' => bool, 'data' => array|null, 'message' => string]`.
* **Logic Highlights:**
  1. Fetch `FormInstance` details for `bnx_entity_id`, `form_domain`, `data_capture_context_group_id`.
  2. Construct DCS context.
  3. Call `\bX\DataCaptureService::getRecord(...)`.

## 6. Interaction with `ISF`

* `ISF` is the primary orchestrator. When a visit event (ISF) is processed:
  * `ISF` determines the list of required `form_domain`s for the visit, using `Flowchart::getVisitItems` with the ISF's `flow_chart_id` and `branch_code_actual`.
  * For each `form_domain`, `ISF` calls `FormInstance::getOrCreateFormInstance($isf_id, $form_domain)`.
  * When data for the entire visit is submitted to an `ISF` endpoint, `ISF::saveISFVisitData` iterates through the data for each form and calls `FormInstance::saveFormInstanceData(...)` for each.

## 7. Example Workflow Snippet (Conceptual - how ISF might use FormInstance)

```php
// --- Inside a method of CDC\ISF, after $isfId is known ---
// $isfRecord = //... fetched cdc_isf record with all its details ...
// $formDomainsForThisVisit = //... list obtained from Flowchart::getVisitItems($isfRecord['flow_chart_id'], $isfRecord['branch_code_actual']) ...

foreach ($formDomainsForThisVisit as $formDomain) {
    $instanceInfo = \CDC\FormInstance::getOrCreateFormInstance(
        $isfRecord['isf_id'],
        $formDomain
    );
    if ($instanceInfo['success']) {
        $formInstanceId = $instanceInfo['form_instance_id'];
        // UI would then get schema for $formDomain using $isfRecord['flow_chart_version_actual']
        // $schema = \CDC\CRF::getFormSchema($isfRecord['study_id_public'], $isfRecord['flow_chart_version_actual'], $formDomain);
        // And then get data:
        // $data = \CDC\FormInstance::getFormData($formInstanceId);
        // ...
    }
}

// --- Later, when saving data received for the whole ISF ---
// $formsDataFromUI = ['VS' => [...vs_data...], 'DM' => [...dm_data...]];
// foreach ($formsDataFromUI as $formDomain => $domainSpecificData) {
//     $instanceInfo = \CDC\FormInstance::getOrCreateFormInstance($isfId, $formDomain); // Ensures instance exists
//     if ($instanceInfo['success']) {
//         \CDC\FormInstance::saveFormInstanceData($instanceInfo['form_instance_id'], $domainSpecificData);
//     }
// }
```

---