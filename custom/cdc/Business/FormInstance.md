# `CDC\FormInstance` - Form Instance Management

**File:** `custom/cdc/Business/FormInstance.php`
**Namespace:** `CDC`

## 1. Purpose

The `FormInstance` class is responsible for managing individual instances of a clinical form (eCRF page) being filled out for a specific subject within a study visit. It acts as a central piece in the data capture workflow, tracking the lifecycle (status) of a form's data and serving as the **critical bridge** between the clinical context (study, subject, visit, form domain, protocol version) and the actual versioned data stored in `bX\DataCaptureService`.

Each record managed by this class typically corresponds to one `form_domain` (e.g., 'Vital Signs', 'Demographics') for one subject at one point in time/visit.

## 2. Dependencies

* `bX\CONN`: For database interactions with the `cdc_form_instance` table.
* `bX\Log`: For logging operations and errors.
* `bX\Profile`: To retrieve the `actorUserId` for actions.
* `bX\DataCaptureService`: Its `context_group_id` is stored, and methods here might orchestrate calls to `saveRecord` or `getRecord`.
* `CDC\Study`: To resolve `studyId` to `study_internal_id_ref` and validate study existence.
* `CDC\ISF`: A `FormInstance` is typically part of an `ISF` (Investigator Site File) entry, representing one form within a larger visit data collection event.
* `CDC\Flowchart`: (Potentially) To verify that a `form_domain` is expected for the given `visit_num_actual` and `flow_chart_version`.

## 3. Database Table

* **`cdc_form_instance`**: The primary table managed by this class. Key columns include:
    * `form_instance_id` (PK)
    * `study_internal_id_ref`
    * `bnx_entity_id` (Subject ID)
    * `visit_num_actual`
    * `form_domain`
    * `flow_chart_version` (Protocol version active at time of data entry for this instance)
    * `status` ('DRAFT', 'OPEN', 'FINALIZED', 'LOCKED', 'CANCELLED')
    * `form_version` (Internal version of this form instance's data, distinct from DCS field versions)
    * `data_capture_context_group_id` (FK to `bX\DataCaptureService.context_group`)

## 4. Key Concepts

* **Form Instance**: A single occurrence of an eCRF page or `form_domain` for a subject at a specific visit.
* **Lifecycle Status**: Tracks the progress of data entry and review for that specific form instance.
* **Data Link**: Connects the clinical context of the form instance to its underlying data in `DataCaptureService`.
* **Protocol Version Context**: Stores the `flow_chart_version` to ensure data is interpreted against the correct protocol under which it was captured.

## 5. Core Static Methods (Proposed)

### `getOrCreateFormInstance(string $studyId, string $bnxEntityId, string $visitNumActual, string $formDomain, string $flowChartVersion, string $actorUserId, ?int $isfIdRef = null, ?int $flowChartItemIdRef = null): array`

* **Purpose:** Finds an existing `DRAFT` or `OPEN` `FormInstance` matching the criteria. If none is found, or if existing ones are in a terminal state (e.g., `FINALIZED`, `LOCKED`), it creates a new `FormInstance` in `DRAFT` status. This is typically called before displaying a form for data entry or saving data.
* **Parameters:**
    * `$studyId` (string, **required**): Public ID of the study.
    * `$bnxEntityId` (string, **required**): Bintelx Entity ID for the subject.
    * `$visitNumActual` (string, **required**): Actual visit identifier.
    * `$formDomain` (string, **required**): The domain of the form (e.g., 'VS').
    * `$flowChartVersion` (string, **required**): The flowchart version active for this data capture.
    * `$actorUserId` (string, **required**): ID of the user performing the action.
    * `$isfIdRef` (int, optional): Reference to the parent `cdc_isf.isf_id` if applicable.
    * `$flowChartItemIdRef` (int, optional): Reference to `cdc_flow_chart_item.flow_chart_item_id` if instance directly corresponds to a planned item.
* **Returns:** `['success' => bool, 'form_instance_id' => int|null, 'status' => string|null, 'data_capture_context_group_id' => int|null, 'message' => string, 'is_new' => bool]`
    * `is_new` indicates if a new instance was created.

### `getFormInstanceDetails(int $formInstanceId): array`

* **Purpose:** Retrieves all details for a specific `form_instance_id` from the `cdc_form_instance` table.
* **Parameters:**
    * `$formInstanceId` (int, **required**): The ID of the form instance.
* **Returns:** `['success' => bool, 'details' => array|null, 'message' => string]`

### `updateFormInstanceStatus(int $formInstanceId, string $newStatus, string $actorUserId, array $options = []): array`

* **Purpose:** Updates the status of a `FormInstance` (e.g., from 'DRAFT' to 'OPEN', 'OPEN' to 'FINALIZED'). Implements status transition validation.
* **Parameters:**
    * `$formInstanceId` (int, **required**): The ID of the form instance.
    * `$newStatus` (string, **required**): The target status.
    * `$actorUserId` (string, **required**): ID of the user performing the status change.
    * `$options` (array, optional): May include specific actor IDs for certain statuses, e.g., `'finalized_by_actor_id'`, `'locked_by_actor_id'`, or a timestamp for `finalized_at`.
* **Returns:** `['success' => bool, 'message' => string]`

### `saveFormInstanceData(int $formInstanceId, array $fieldsDataFromUI, string $actorUserId, ?string $changeReason = null, ?string $defaultEventType = null): array`

* **Purpose:** Orchestrates the saving of data entered by a user for a specific `FormInstance`. It will interact with `bX\DataCaptureService::saveRecord`.
* **Parameters:**
    * `$formInstanceId` (int, **required**): The ID of the form instance.
    * `$fieldsDataFromUI` (array, **required**): Associative array of `['field_name' => 'value', ...]` as submitted from the UI.
    * `$actorUserId` (string, **required**): ID of the user saving the data.
    * `$changeReason` (string, optional): Overall reason for change for this save operation.
    * `$defaultEventType` (string, optional): Default event type for DCS.
* **Returns:** `['success' => bool, 'message' => string, 'data_capture_context_group_id' => int|null, 'saved_fields_info' => array|null]`
    * The return structure might also reflect updates to `form_version` or `status` of the `FormInstance`.
* **Logic Highlights:**
    1.  Fetch `FormInstance` details (to get `bnx_entity_id`, `form_domain`, current `status`, `data_capture_context_group_id`).
    2.  Validate if data can be saved based on current `status` (e.g., not if 'LOCKED').
    3.  Prepare `$contextKeyValues` for DCS: `['BNX_ENTITY_ID' => ..., 'FORM_DOMAIN' => ...]`.
    4.  Transform `$fieldsDataFromUI` into the array-of-arrays format required by `DCS::saveRecord`.
    5.  Call `\bX\DataCaptureService::saveRecord(...)`.
    6.  If successful:
        * If a new `context_group_id` is returned by DCS (or if the existing one was null), update `cdc_form_instance.data_capture_context_group_id`.
        * Increment `cdc_form_instance.form_version`.
        * Update `cdc_form_instance.updated_at` and `updated_by_actor_id` (if you add this field).
        * Potentially transition status (e.g., if 'DRAFT', move to 'OPEN').

### `getFormInstanceData(int $formInstanceId, ?array $fieldNames = null): array`

* **Purpose:** Retrieves the actual "hot" data values for a given `FormInstance` by calling `bX\DataCaptureService::getRecord`.
* **Parameters:**
    * `$formInstanceId` (int, **required**): The ID of the form instance.
    * `$fieldNames` (array, optional): Specific field names to retrieve. If null, all for the context.
* **Returns:** `['success' => bool, 'data' => array|null, 'message' => string]`
    * `data` is in the format returned by `DCS::getRecord`.
* **Logic Highlights:**
    1.  Fetch `FormInstance` details to get `bnx_entity_id`, `form_domain`, and crucially `data_capture_context_group_id`.
    2.  Construct `$contextKeyValues` using `bnx_entity_id` and `form_domain`.
    3.  Call `\bX\DataCaptureService::getRecord(...)`.

## 6. Example Workflow Snippet (Conceptual - Data Entry)

```php
<?php
// UI has determined studyId, bnxEntityId, visitNumActual, formDomain, currentFlowchartVersion
// User is about to open the 'VS' form for patient 'PXYZ007'

$formInstanceInfo = \CDC\FormInstance::getOrCreateFormInstance(
    'PROT-001', 
    'PXYZ007', 
    'V1_DAY1', 
    'VS', 
    'Protocol_v1.0_Active', 
    'USER_001' // actorUserId
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
$actorSaving = 'USER_001';

$saveOutcome = \CDC\FormInstance::saveFormInstanceData(
    $formInstanceId,
    $submittedDataFromUI,
    $actorSaving,
    "Routine data entry"
);

if ($saveOutcome['success']) {
    echo "VS Data saved successfully for instance ID: $formInstanceId";
    // Potentially update status, e.g., if it was 'DRAFT'
    // \CDC\FormInstance::updateFormInstanceStatus($formInstanceId, 'OPEN', $actorSaving);
} else {
    echo "Error saving VS Data: " . $saveOutcome['message'];
}
```

