# `CDC\FormInstance` - Form Instance Management

**File:** `custom/cdc/Business/FormInstance.php`
**Namespace:** `CDC`

## 1. Purpose

The `FormInstance` class is responsible for managing individual instances of a clinical form (eCRF page) being filled out for a specific subject within a study visit event (represented by an `ISF` record). It acts as a central piece in the data capture workflow, tracking the lifecycle (status) of a form's data and serving as the **critical bridge** between the clinical context (study, subject, visit event details including `flow_chart_version_actual` and `branch_code_actual`) and the actual versioned data stored in `bX\DataCaptureService`.

Each `cdc_form_instance` record corresponds to one `form_domain` (e.g., 'Vital Signs', 'Demographics') for one subject, linked to a specific `isf_id`, and captured under a specific `flow_chart_version_actual` and `branch_code_actual`.

## 2. Dependencies

* `bX\CONN`: For database interactions with the `cdc_form_instance` table.
* `bX\Log`: For logging operations and errors.
* `bX\Profile`: Used internally to retrieve the `actorUserId` for actions.
* `bX\DataCaptureService`: Its `context_group_id` is stored. This class orchestrates calls to `saveRecord` and `getRecord`.
* `CDC\Study`: To resolve `studyId` to `study_internal_id` and validate study existence (often called by parent modules like `ISF`).
* `CDC\ISF`: (Conceptually) A `FormInstance` is created and managed in the context of an `ISF` visit event.
* `CDC\CRF`: (Indirectly) Used by orchestrating layers (like `ISF` or UI controllers) to fetch the correct versioned schema (using `CRF::getFormSchema` with the `flow_chart_version_actual`) before data entry or validation.

## 3. Database Table: `cdc_form_instance`

This table stores metadata for each form instance, reflecting our consolidated `cdc.sql`.

* **Table Name**: `cdc_form_instance`
* **Key Columns**:
  * `form_instance_id` (PK)
  * `isf_id` (FK to `cdc_isf.isf_id`, **required**)
  * `study_internal_id` (FK, denormalized)
  * `bnx_entity_id` (Patient ID, denormalized)
  * `flow_chart_item_id` (FK, optional, to link to the planned item)
  * `form_domain`
  * `flow_chart_version_actual` (VARCHAR(255), **required**): The protocol/flowchart version active when data was captured.
  * `branch_code_actual` (VARCHAR(50), **required**, default '__COMMON__'): The branch active for the subject.
  * `status` (ENUM('NOT_STARTED', 'DRAFT', 'OPEN', 'COMPLETED', 'FINALIZED', 'LOCKED', 'QUERIED', 'CANCELLED'), DEFAULT 'NOT_STARTED')
  * `form_version_instance` (INT UNSIGNED, DEFAULT 0): Internal save count/version for this instance.
  * `data_capture_context_group_id` (BIGINT UNSIGNED, NULL): Link to `DataCaptureService`.
  * Actor ID columns (`created_by_actor_id`, `updated_by_actor_id`, `finalized_by_actor_id`, `locked_by_actor_id`).
  * Timestamps (`created_at`, `updated_at`, `finalized_at`, `locked_at`).

## 4. Key Concepts

* **`branch_code_actual`**: Stored in `cdc_form_instance`, inherited from the parent `ISF` event, indicating the branch context for this data.
* **`flow_chart_version_actual`**: Stored in `cdc_form_instance`, inherited from `ISF`, indicating the exact protocol version under which this data was collected. This is used by `CRF::getFormSchema` to fetch the correct form structure.
* **`data_capture_context_group_id`**: The link to `DataCaptureService`.
* **Status Management (`status`)**: Tracks the lifecycle of the form's data entry and review.
* **`isf_id`**: Ensures every `FormInstance` is part of a defined visit event (ISF).

## 5. Core Static Methods

*Actor ID for all methods creating/modifying data is obtained internally via `\bX\Profile`.*

### `getOrCreateFormInstance(int $isfId, string $formDomain): array`

* **Purpose:** Finds an existing `FormInstance` linked to the given `$isfId` and `$formDomain` that is in a non-terminal state (e.g., 'NOT_STARTED', 'DRAFT', 'OPEN', 'QUERIED'). If none exists, or if existing ones are terminal, it creates a new `FormInstance` in 'NOT_STARTED' or 'DRAFT' status. It inherits `study_internal_id`, `bnx_entity_id`, `flow_chart_version_actual`, and `branch_code_actual` from the parent `cdc_isf` record.
* **Parameters:**
  * `$isfId` (int, **required**): The ID of the parent `cdc_isf` visit event.
  * `$formDomain` (string, **required**): The domain of the form (e.g., 'VS').
* **Returns:** `['success' => bool, 'form_instance_id' => int|null, 'status' => string|null, 'data_capture_context_group_id' => int|null, 'branch_code_actual' => string|null, 'flow_chart_version_actual' => string|null, 'message' => string, 'is_new' => bool]`
  * `is_new` indicates if a new instance was created.
* **Internal Logic:**
  1. Fetch parent `cdc_isf` record using `$isfId` to get context: `study_internal_id`, `bnx_entity_id`, `flow_chart_version_actual`, `branch_code_actual`.
  2. Query `cdc_form_instance` for an existing suitable instance.
  3. If not found or unsuitable, `INSERT` new record into `cdc_form_instance`.

### `getFormInstanceDetails(int $formInstanceId): array`

* **Purpose:** Retrieves all details for a specific `form_instance_id` from `cdc_form_instance`.
* **Parameters:** `$formInstanceId` (int, **required**).
* **Returns:** `['success' => bool, 'details' => array|null, 'message' => string]`

### `updateFormInstanceStatus(int $formInstanceId, string $newStatus, array $options = []): array`

* **Purpose:** Updates the status of a `FormInstance`. Implements status transition validation.
* **Parameters:**
  * `$formInstanceId` (int, **required**).
  * `$newStatus` (string, **required**, must be a valid ENUM value).
  * `$options` (array, optional): May include specific actor IDs or timestamps for `finalized_by_actor_id`, `locked_by_actor_id`, `finalized_at`, `locked_at`.
* **Returns:** `['success' => bool, 'message' => string]`

### `saveFormInstanceData(int $formInstanceId, array $fieldsDataFromUI, ?string $changeReason = null, ?string $defaultEventType = null): array`

* **Purpose:** Orchestrates saving data for this `FormInstance` to `bX\DataCaptureService`.
* **Parameters:**
  * `$formInstanceId` (int, **required**).
  * `$fieldsDataFromUI` (array, **required**): Associative array `['field_name' => 'value', ...]`.
  * `$changeReason` (string, optional).
  * `$defaultEventType` (string, optional).
* **Returns:** `['success' => bool, 'message' => string, 'data_capture_context_group_id' => int|null, 'saved_fields_info' => array|null]`
* **Logic Highlights:**
  1.  Fetch `FormInstance` details (to get `bnx_entity_id`, `form_domain`, `status`, current `data_capture_context_group_id`).
  2.  Validate status (e.g., not 'LOCKED').
  3.  Prepare `$contextKeyValues` for DCS: `['BNX_ENTITY_ID' => ..., 'FORM_DOMAIN' => ...]`.
  4.  Transform `$fieldsDataFromUI` for `DCS::saveRecord`.
  5.  Call `\bX\DataCaptureService::saveRecord(...)`.
  6.  If successful: update `cdc_form_instance.data_capture_context_group_id` (if new/changed), increment `form_version_instance`, update timestamps, potentially transition `status` (e.g., 'NOT_STARTED' or 'DRAFT' to 'OPEN').

### `getFormData(int $formInstanceId, ?array $fieldNames = null): array`

* **Purpose:** Retrieves "hot" data for a `FormInstance` from `bX\DataCaptureService`.
* **Parameters:** `$formInstanceId`, `$fieldNames` (optional array).
* **Returns:** `['success' => bool, 'data' => array|null, 'message' => string]` (DCS `getRecord` format).
* **Logic Highlights:**
  1.  Fetch `FormInstance` details for `bnx_entity_id`, `form_domain`, `data_capture_context_group_id`.
  2.  Construct `$contextKeyValues`.
  3.  Call `\bX\DataCaptureService::getRecord(...)`.

*(Nota: El método `updateISFLink` que estaba en una versión anterior de este MD ya no es tan necesario si `getOrCreateFormInstance` siempre toma `isfId` y crea el link directamente, o si se asume que una FormInstance siempre se crea en el contexto de un ISF.)*

## 6. Interaction with `ISF`

* `ISF` methods (like `getOrCreateISF` and `saveISFVisitData`) are the primary callers of `FormInstance` methods.
* When an ISF event is initiated, `ISF` determines the relevant context (`studyId`, `bnxEntityId`, `visitNumActual`, `flowchartVersionActual`, `branchCodeActual`).
* `ISF` then iterates through the `form_domain`s expected for that visit event (obtained via `Flowchart::getVisitItems`). For each `form_domain`:
  * It calls `FormInstance::getOrCreateFormInstance(...)`, passing the `isf_id` and all other context.
  * When data is submitted for the visit, `ISF::saveISFVisitData` calls `FormInstance::saveFormInstanceData(...)` for each form's data.

## 7. Example Workflow Snippet (Conceptual - Data Entry, initiated by ISF context)

```php
// --- Within a method of CDC\ISF, after an ISF record ($isf) is created/retrieved ---
// $isf properties: $isf->isf_id, $isf->study_id (public), $isf->bnx_entity_id, 
//                 $isf->visit_num_actual, $isf->flow_chart_version_actual, $isf->branch_code_actual

// For a specific formDomain (e.g., 'VS') determined to be part of this ISF:
$formDomain = 'VS';

$formInstanceInfo = \CDC\FormInstance::getOrCreateFormInstance(
    $isf->isf_id, // Pass the parent ISF ID
    $formDomain
);

if (!$formInstanceInfo['success']) {
    // Handle error
    // exit("Error preparing form instance for $formDomain: " . $formInstanceInfo['message']);
}
$formInstanceId = $formInstanceInfo['form_instance_id'];

// 1. Get Schema (ISF or controller would use the ISF's flowchartVersionActual)
$schemaResult = \CDC\CRF::getFormSchema($isf->study_id, $isf->flow_chart_version_actual, $formDomain);
$formSchema = $schemaResult['success'] ? $schemaResult['schema'] : [];

// 2. Get existing data for this instance
$dataResult = \CDC\FormInstance::getFormData($formInstanceId);
$existingData = $dataResult['success'] ? $dataResult['data'] : [];

// 3. Merge schema and data for UI (done by UI controller)
// ... (as in CRF.md example) ...

// --- LATER, USER SUBMITS DATA for this formDomain within the ISF ---
// $submittedVSData = ['VSORRES_SYSBP' => 120, 'VSORRES_DIABP' => 80];

$saveOutcome = \CDC\FormInstance::saveFormInstanceData(
    $formInstanceId,
    $submittedVSData,
    "Routine data entry for VS"
);

if ($saveOutcome['success']) {
    // Log success for this form
} else {
    // Log error for this form
}
```