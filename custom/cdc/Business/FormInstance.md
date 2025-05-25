# `CDC\FormInstance` - Form Instance Management

**File:** `custom/cdc/Business/FormInstance.php`
**Namespace:** `CDC`

## 1. Purpose

The `FormInstance` class is responsible for managing individual instances of a clinical form (eCRF page) being filled out for a specific subject within a study visit. It acts as a central piece in the data capture workflow, tracking the lifecycle (status) of a form's data and serving as the **critical bridge** between the clinical context (study, subject, visit, form domain, protocol version, branch) and the actual versioned data stored in `bX\DataCaptureService`.

Each record managed by this class typically corresponds to one `form_domain` (e.g., 'Vital Signs', 'Demographics') for one subject at one point in time/visit, under a specific `branch_code_actual`.

## 2. Dependencies

* `bX\CONN`: For database interactions with the `cdc_form_instance` table.
* `bX\Log`: For logging operations and errors.
* `bX\Profile`: Used internally to retrieve the `actorUserId` for actions.
* `bX\DataCaptureService`: Its `context_group_id` is stored. Methods in this class orchestrate calls to `saveRecord` or `getRecord`.
* `CDC\Study`: To resolve `studyId` to `study_internal_id_ref` and validate study existence.
* `CDC\ISF`: A `FormInstance` is typically part of an `ISF` (Investigator Site File) entry. `ISF` often orchestrates `FormInstance` operations.
* `CDC\Flowchart`: (Potentially) To verify that a `form_domain` is expected for the given `visit_num_actual`, `flow_chart_version`, and `branch_code_actual`.
* `CDC\CRF`: For retrieving form schemas, which might be used for validation or context.

## 3. Database Table: `cdc_form_instance`

This table stores metadata for each form instance.

* **Table Name**: `cdc_form_instance`
* **Columns**:
  * `form_instance_id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT): Unique identifier for the form instance.
  * `study_internal_id_ref` (BIGINT UNSIGNED, NOT NULL): FK to `cdc_study.study_internal_id`.
  * `isf_id_ref` (BIGINT UNSIGNED, NULL): Optional FK to `cdc_isf.isf_id`. Links this form instance to a specific visit event container.
  * `bnx_entity_id` (VARCHAR(255), NOT NULL): Bintelx entity ID for the patient/subject.
  * `visit_num_actual` (VARCHAR(255), NOT NULL): Actual visit identifier when data was captured.
  * `flow_chart_item_id_ref` (BIGINT UNSIGNED, NULL): Optional FK to `cdc_flow_chart_item.flow_chart_item_id`.
  * `flow_chart_version` (VARCHAR(255), NOT NULL): Protocol/Flowchart version active at the time of data entry for this instance.
  * `branch_code_actual` (VARCHAR(50), NULL): The specific study branch the patient was on when this form instance was created/data was captured. `NULL` or a special value like `'__COMMON__'` if not branch-specific at this level or if the concept doesn't apply.
  * `form_domain` (VARCHAR(50), NOT NULL): The domain or type of the form (e.g., "VS", "DM").
  * `status` (ENUM('DRAFT', 'OPEN', 'FINALIZED', 'LOCKED', 'CANCELLED'), NOT NULL, DEFAULT 'DRAFT'): Current status of the form instance.
  * `form_version` (INT UNSIGNED, NOT NULL, DEFAULT 0): Internal version for this form instance data (e.g., incremented on saves when 'OPEN').
  * `data_capture_context_group_id` (BIGINT UNSIGNED, NULL): FK (Conceptual) to `DataCaptureService.context_group.context_group_id`.
  * `created_by_actor_id` (VARCHAR(255), NULL): User ID of the creator.
  * `finalized_by_actor_id` (VARCHAR(255), NULL): User ID of the user who finalized.
  * `locked_by_actor_id` (VARCHAR(255), NULL): User ID of the user who locked.
  * `created_at` (TIMESTAMP, NOT NULL, DEFAULT CURRENT_TIMESTAMP): Timestamp of creation.
  * `updated_at` (TIMESTAMP, NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP): Timestamp of the last update.
  * `finalized_at` (TIMESTAMP, NULL): Timestamp of finalization.
  * `locked_at` (TIMESTAMP, NULL): Timestamp of locking.
* **Indexes**:
  * `PRIMARY KEY (form_instance_id)`
  * `idx_study_entity_visit_domain_branch` (`study_internal_id_ref`, `bnx_entity_id`(100), `visit_num_actual`(50), `form_domain`, `branch_code_actual`)
  * `idx_isf_id_ref` (`isf_id_ref`)
  * `idx_status` (`status`)
  * `idx_data_capture_context_group_id` (`data_capture_context_group_id`)

## 4. Key Concepts

* **`branch_code_actual`**: Stored in `cdc_form_instance`, this indicates the specific study branch the patient was following when this form instance's data was captured. It is essential for traceability and applying correct form display/rules if they vary by branch (as defined in `cdc_flow_chart_item`).
* **`data_capture_context_group_id`**: The identifier provided by `bX\DataCaptureService` (DCS) when data for the form instance is saved. It acts as the link to the versioned data elements associated with this specific `FormInstance` in DCS.
* **Status Management (`status`)**: Tracks the lifecycle of the form data (e.g., 'DRAFT', 'OPEN', 'FINALIZED', 'LOCKED'). Transitions are managed by `updateFormInstanceStatus` and can trigger business logic.
* **Form Version (`form_version`)**: An internal version number for the data *within this specific `cdc_form_instance` record*. This is distinct from the fine-grained field versions managed by DCS and can be incremented, for example, each time `saveFormInstanceData` is called while the status is 'OPEN'.

## 5. Core Static Methods

*Actor ID for all methods is obtained internally via `\bX\Profile`.*

### `getOrCreateFormInstance(string $studyId, string $bnxEntityId, string $visitNumActual, string $formDomain, string $flowChartVersion, ?string $branchCodeActual, ?int $isfIdRef = null, ?int $flowChartItemIdRef = null): array`

* **Purpose:** Finds an existing `DRAFT` or `OPEN` `FormInstance` matching the specified criteria (including `branchCodeActual`). If no suitable active instance is found, or if existing ones are in a terminal state, it creates a new `FormInstance` in `DRAFT` status, associated with the provided `branchCodeActual`. This is typically called before displaying a form for data entry or saving data.
* **Parameters:**
  * `$studyId` (string, **required**): Public ID of the study.
  * `$bnxEntityId` (string, **required**): Bintelx Entity ID for the subject.
  * `$visitNumActual` (string, **required**): Actual visit identifier.
  * `$formDomain` (string, **required**): The domain of the form (e.g., 'VS').
  * `$flowChartVersion` (string, **required**): The flowchart version active for this data capture.
  * `$branchCodeActual` (string, **nullable**): The specific branch code active for the subject at this time. Pass `null` or a designated "common" value if the instance is not branch-specific.
  * `$isfIdRef` (int, optional): Reference to the parent `cdc_isf.isf_id` if applicable and known.
  * `$flowChartItemIdRef` (int, optional): Reference to `cdc_flow_chart_item.flow_chart_item_id`.
* **Returns:** `['success' => bool, 'form_instance_id' => int|null, 'status' => string|null, 'data_capture_context_group_id' => int|null, 'branch_code_actual' => string|null, 'message' => string, 'is_new' => bool]`
  * `is_new` indicates if a new instance was created.

### `getFormInstanceDetails(int $formInstanceId): array`

* **Purpose:** Retrieves all details for a specific `form_instance_id` from the `cdc_form_instance` table.
* **Parameters:**
  * `$formInstanceId` (int, **required**): The ID of the form instance.
* **Returns:** `['success' => bool, 'details' => array|null, 'message' => string]` (where `details` includes `branch_code_actual`).

### `updateFormInstanceStatus(int $formInstanceId, string $newStatus, array $options = []): array`

* **Purpose:** Updates the status of a `FormInstance`. Implements status transition validation. Actor ID obtained internally.
* **Parameters:**
  * `$formInstanceId` (int, **required**): The ID of the form instance.
  * `$newStatus` (string, **required**): The target status.
  * `$options` (array, optional): May include specific actor IDs if different from current user (e.g., `'finalized_by_actor_id'`), or timestamps.
* **Returns:** `['success' => bool, 'message' => string]`

### `saveFormInstanceData(int $formInstanceId, array $fieldsDataFromUI, ?string $changeReason = null, ?string $defaultEventType = null): array`

* **Purpose:** Orchestrates the saving of data entered by a user for a specific `FormInstance`. It interacts with `bX\DataCaptureService::saveRecord`. Actor ID obtained internally.
* **Parameters:**
  * `$formInstanceId` (int, **required**): The ID of the form instance.
  * `$fieldsDataFromUI` (array, **required**): Associative array of `['field_name' => 'value', ...]` as submitted from the UI.
  * `$changeReason` (string, optional): Overall reason for change for this save operation for DCS.
  * `$defaultEventType` (string, optional): Default event type for DCS.
* **Returns:** `['success' => bool, 'message' => string, 'data_capture_context_group_id' => int|null, 'saved_fields_info' => array|null]`
* **Logic Highlights:**
  1.  Fetch `FormInstance` details (to get `bnx_entity_id`, `form_domain`, current `status`, `data_capture_context_group_id`).
  2.  Validate if data can be saved based on current `status`.
  3.  Prepare `$contextKeyValues` for DCS: `['BNX_ENTITY_ID' => ..., 'FORM_DOMAIN' => ...]`.
  4.  Transform `$fieldsDataFromUI` for `DCS::saveRecord`.
  5.  Call `\bX\DataCaptureService::saveRecord(...)`.
  6.  If successful, update `cdc_form_instance.data_capture_context_group_id` (if new/changed), increment `form_version`, update timestamps, potentially transition status (e.g., 'DRAFT' to 'OPEN').

### `getFormData(int $formInstanceId, ?array $fieldNames = null): array`

* **Purpose:** Retrieves the actual "hot" data values for a given `FormInstance` by calling `bX\DataCaptureService::getRecord`.
* **Parameters:**
  * `$formInstanceId` (int, **required**): The ID of the form instance.
  * `$fieldNames` (array, optional): Specific field names to retrieve. If null, all for the context.
* **Returns:** `['success' => bool, 'data' => array|null, 'message' => string]`
* **Logic Highlights:**
  1.  Fetch `FormInstance` details to get `bnx_entity_id`, `form_domain`, and `data_capture_context_group_id`.
  2.  Construct `$contextKeyValues` for DCS.
  3.  Call `\bX\DataCaptureService::getRecord(...)`.

### `updateISFLink(int $formInstanceId, int $isfId): array`

* **Purpose:** Updates the `isf_id_ref` for a `FormInstance`. Useful if the instance is created before the parent ISF ID is finalized. Actor ID obtained internally.
* **Parameters:**
  * `$formInstanceId` (int, **required**): The ID of the `FormInstance`.
  * `$isfId` (int, **required**): The `isf_id` from `cdc_isf` to link.
* **Returns:** `['success' => bool, 'message' => string]`

## 6. Interaction with `ISF`

The `ISF` (Investigator Site File) module/class typically orchestrates `FormInstance` operations as part of managing a complete visit event.

* **`ISF` Initiates `FormInstance` Creation/Retrieval:**
  * When an `ISF` entry (representing a visit event) is accessed or created, the `ISF` logic determines the required `form_domain`s for that visit based on the study's `Flowchart` (considering `flow_chart_version` and the patient's `branch_code_actual`).
  * For each required `form_domain`, `ISF` would call `FormInstance::getOrCreateFormInstance()`, passing all necessary context including its own `isf_id` as `$isfIdRef` and the patient's current `branchCodeActual`.
* **`ISF` Orchestrates Data Saving:**
  * When data for an entire visit (potentially multiple forms) is submitted, the `ISF` logic would typically iterate through the data for each `form_domain`.
  * For each `form_domain`'s data, it would ensure the corresponding `FormInstance` exists (via `getOrCreateFormInstance`) and then call `FormInstance::saveFormInstanceData()` to persist the data through DCS.
* **`cdc_form_instance.isf_id_ref` Role:** This foreign key directly links a `FormInstance` to its parent `ISF` visit event, allowing easy retrieval of all forms associated with a specific visit and ensuring data is managed under the correct visit context.

## 7. Example Workflow Snippet (Conceptual - Data Entry)

```php
<?php
// UI has determined studyId, bnxEntityId, visitNumActual, formDomain, 
// currentFlowchartVersion, and currentBranchForPatient.

$actorUserId = \bX\Profile::$account_id ?? 'USER_SYSTEM'; // Example of getting actor ID

$formInstanceInfo = \CDC\FormInstance::getOrCreateFormInstance(
    'PROT-001', 
    'PXYZ007', 
    'V1_DAY1', 
    'VS', 
    'Protocol_v1.0_Active',
    'ArmA', // branchCodeActual for the patient at this time
    $actorUserId // This would be obtained internally by the method
    // $isfIdRef, $flowChartItemIdRef would be passed if known
);

if (!$formInstanceInfo['success']) {
    // Handle error: cannot proceed
    exit("Error preparing form instance: " . $formInstanceInfo['message']);
}
$formInstanceId = $formInstanceInfo['form_instance_id'];

// 1. Get Schema to build the UI
$schemaResult = \CDC\CRF::getFormSchema('PROT-001', 'VS');
$formSchema = $schemaResult['success'] ? $schemaResult['schema'] : [];

// 2. Get existing data for this instance (if any)
$dataResult = \CDC\FormInstance::getFormData($formInstanceId);
$existingData = $dataResult['success'] ? $dataResult['data'] : [];

// 3. (In UI Controller/Service) Merge schema with existing data
$formDataForUI = [];
foreach ($formSchema as $fieldDef) {
    $fieldName = $fieldDef['field_name'];
    $fieldDef['value'] = $existingData[$fieldName]['value'] ?? null;
    $formDataForUI[] = $fieldDef;
}
// The UI now renders the form using $formDataForUI

// --- LATER, USER SUBMITS DATA ---
$submittedDataFromUI = [
    'VSORRES_SYSBP' => 120,
    'VSORRES_DIABP' => 80,
    // ... other fields
];
// $actorSaving = \bX\Profile::$account_id ?? 'USER_SYSTEM'; // Actor for saving

$saveOutcome = \CDC\FormInstance::saveFormInstanceData(
    $formInstanceId,
    $submittedDataFromUI,
    // $actorSaving, // Actor obtained internally
    "Routine data entry"
);

if ($saveOutcome['success']) {
    echo "VS Data saved successfully for instance ID: $formInstanceId";
    // Potentially update status
    // \CDC\FormInstance::updateFormInstanceStatus($formInstanceId, 'OPEN' /*, $actorSaving (internal) */);
} else {
    echo "Error saving VS Data: " . $saveOutcome['message'];
}
```

---