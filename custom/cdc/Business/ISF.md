# `CDC\ISF` - Investigator Site File (Visit Event Management)

**File:** `custom/cdc/Business/ISF.php`
**Namespace:** `CDC`

## 1. Purpose

The `ISF` (Investigator Site File) class is a central component in managing clinical **visit events** within the CDC module. Its primary roles are:

* **Visit Event Management**: Represents and manages a single, specific visit event for a patient in a study. It creates and manages `cdc_isf` records.
* **`FormInstance` Orchestration**: Acts as a container and orchestrator for multiple `CDC\FormInstance` objects belonging to the same ISF/visit event.
* **Context & Schema Determination**: Collaborates with `CDC\Flowchart` and `CDC\CRF` to determine the precise set of forms (`form_domain`s) required for a visit, ensuring the correct `flow_chart_version` (from `cdc_flowchart_versions_status`) and the patient's `branch_code_actual` (from `cdc_patient_study_branch`) are used to fetch the versioned form schemas.
* **Data Saving Orchestration**: Manages the process of saving visit data, delegating persistence to `CDC\FormInstance`.
* **Patient Branch Management**: Manages patient assignments to study branches via `cdc_patient_study_branch`.

## 2. Dependencies

* `bX\CONN`: For database connectivity.
* `bX\Log`: For logging.
* `bX\Profile`: Used internally to retrieve the `actorUserId`.
* `CDC\Study`: To validate studies and resolve `study_id`.
* `CDC\Flowchart`: To get visit plans (`cdc_flow_chart`, `cdc_flow_chart_item`) based on `flow_chart_version` and `branch_code`.
* `CDC\CRF`: To get versioned form schemas (`cdc_form_fields`) using `flow_chart_version`.
* `CDC\FormInstance`: To manage individual form instances.

## 3. Database Tables

### Primary Table Managed:

* **`cdc_isf`**: Stores visit event records, reflecting the structure defined in our consolidated `cdc.sql`.
  * Includes `isf_id`, `study_internal_id`, `bnx_entity_id`, `flow_chart_id` (FK), `visit_num_actual`, `visit_name_actual`, `visit_date_actual`, `flow_chart_version_actual`, `branch_code_actual`, `status` (ENUM), actor IDs, and timestamps.

### Supporting Table (Managed):

* **`cdc_patient_study_branch`**: Manages patient branch assignments, reflecting the structure defined in our consolidated `cdc.sql`.
  * Includes `patient_study_branch_id`, `study_internal_id`, `bnx_entity_id`, `branch_code`, `assigned_at`, `is_active`, actor IDs, and timestamps.
  * **Crucially uses `UNIQUE KEY uq_psb_study_entity_assigned` for history.**

### Related Tables (Interacted With):

* `cdc_form_instance`: Linked via `cdc_form_instance.isf_id`. **`cdc_isf_form_instance_link` is NOT used.**
* `cdc_study`, `cdc_visit_definitions`, `cdc_flow_chart`, `cdc_flow_chart_item`, `cdc_form_fields`, `cdc_flowchart_versions_status`.

## 4. Key Concepts

* **Visit Event (ISF Record)**: Represents a single visit encounter, capturing the precise study setup context (`flow_chart_version_actual`, `branch_code_actual`) at the time of the visit.
* **`branch_code_actual`**: The definitive branch for this ISF, determined via `getPatientActiveBranch` or explicitly provided, and stored in `cdc_isf`.
* **`flow_chart_version_actual`**: The definitive protocol version for this ISF, must correspond to a 'PUBLISHED' version from `cdc_flowchart_versions_status` for data entry.

## 5. Core Static Methods

*Actor ID for all methods is obtained internally via `\bX\Profile`.*

### `assignPatientToBranch(string $studyId, string $bnxEntityId, string $branchCode, ?string $reason = null): array`

* **Purpose**: Manages `cdc_patient_study_branch`. Sets `$branchCode` as active, deactivating any previous active branch for the patient/study. Enforces the "only one active" rule.
* **Parameters**: `$studyId`, `$bnxEntityId`, `$branchCode`, `$reason` (optional).
* **Returns**: `['success' => bool, 'message' => string, 'patient_study_branch_id' => int|null]`

### `getPatientActiveBranch(string $studyId, string $bnxEntityId): array`

* **Purpose**: Retrieves the currently active `branch_code` for a patient in a study from `cdc_patient_study_branch` (where `is_active` = true).
* **Parameters**: `$studyId`, `$bnxEntityId`.
* **Returns**: `['success' => bool, 'branch_code' => string|null, 'message' => string]`

### `getOrCreateISF(string $studyId, string $bnxEntityId, string $visitCode, string $flowchartVersionActual, ?string $explicitBranchCodeActual = null, ?string $visitDateActual = null, ?string $visitNameActual = null): array`

* **Purpose**: Retrieves an existing `ISF` or creates a new one. Determines `branch_code_actual` and `flow_chart_id`.
* **Parameters**:
  * `$studyId` (string, **required**).
  * `$bnxEntityId` (string, **required**).
  * `$visitCode` (string, **required**): The `visit_code` from `cdc_visit_definitions`.
  * `$flowchartVersionActual` (string, **required**): Must be a 'PUBLISHED' version.
  * `$explicitBranchCodeActual` (string, optional).
  * `$visitDateActual` (string 'YYYY-MM-DD', optional).
  * `$visitNameActual` (string, optional).
* **Internal Logic**:
  1.  Get `study_internal_id`.
  2.  Determine `branch_code_actual` (using explicit or `getPatientActiveBranch`). Error if none found & required.
  3.  Get `visit_definition_id` from `cdc_visit_definitions` using `study_internal_id` and `$visitCode`. Error if not found.
  4.  Get `flow_chart_id` from `cdc_flow_chart` using `study_internal_id`, `$flowchartVersionActual`, and `visit_definition_id`. Error if not found (means visit not planned in this version).
  5.  Attempt to `SELECT` `cdc_isf` based on `study_internal_id`, `bnx_entity_id`, `flow_chart_id`. (Need a clear rule for uniqueness - maybe allow multiple but only one 'IN_PROGRESS'?)
  6.  If not found, `INSERT` new `cdc_isf` with all context, `status = 'SCHEDULED'` or `'IN_PROGRESS'`.
* **Returns**: `['success' => bool, 'isf_id' => int|null, 'branch_code_actual' => string|null, 'status' => string|null, 'flow_chart_id' => int|null, 'message' => string, 'is_new' => bool]`

### `getISFDetails(int $isfId): array`

* **Purpose**: Retrieves `cdc_isf` details and summaries of its linked `FormInstance`s.
* **Parameters**: `$isfId`.
* **Returns**: `['success' => bool, 'isf_details' => array|null, 'form_instances' => array|null, 'message' => string]`

### `saveISFVisitData(int $isfId, array $formsDataFromUI, ?string $changeReason = null): array`

* **Purpose**: Orchestrates saving data for multiple forms within an ISF.
* **Parameters**: `$isfId`, `$formsDataFromUI` (assoc. array `['form_domain' => ['field' => 'value']]`), `$changeReason` (optional).
* **Internal Logic**:
  1.  Get `cdc_isf` record for `$isfId` to retrieve context (`studyId`, `bnxEntityId`, `visitNumActual`, `flowchartVersionActual`, `branch_code_actual`).
  2.  For each `form_domain` in `$formsDataFromUI`:
    * Call `FormInstance::getOrCreateFormInstance(...)` passing all ISF context.
    * Call `FormInstance::saveFormInstanceData(...)` with the form's data.
* **Returns**: `['success' => bool, 'results_by_domain' => array, 'message' => string]`

### `updateISFStatus(int $isfId, string $newStatus, array $options = []): array`

* **Purpose**: Updates the `cdc_isf.status`.
* **Parameters**: `$isfId`, `$newStatus` (must be one of the ENUM values), `$options` (optional).
* **Returns**: `['success' => bool, 'message' => string]`

## 6. Interaction with Other Modules

* **Determining Expected Forms**:
  1.  `ISF::getOrCreateISF` determines the full context.
  2.  This context (especially `flow_chart_id` and `branch_code_actual`) is used to call `Flowchart::getVisitItems($flow_chart_id, $branch_code_actual)` to get the list of required `form_domain`s.
* **Displaying Forms**:
  1.  For each expected `form_domain`:
    * `CRF::getFormSchema($studyId, $flowchartVersionActual, $formDomain)` is called to get its *versioned* structure.
    * `FormInstance::getOrCreateFormInstance(...)` gets/creates the instance record, *including the `isf_id`*.
    * `FormInstance::getFormData(...)` retrieves data from DCS.
* **Saving Data**:
  1.  `ISF::saveISFVisitData` calls `FormInstance::getOrCreateFormInstance(...)` (passing `isf_id`) and then `FormInstance::saveFormInstanceData(...)`. The `isf_id` ensures the link.

## 7. Example Workflow (Data Entry - Conceptual)

1.  **UI**: User selects Patient P, Study S, Visit V (which maps to a `visit_code`).
2.  **Backend (ISF Endpoint)**:
  * Calls `isfContext = CDC\ISF::getOrCreateISF(S, P, visit_code, activePublishedFcVersion)`.
3.  **Backend**:
  * Calls `visitItems = CDC\Flowchart::getVisitItems(isfContext.flow_chart_id, isfContext.branch_code_actual)`.
4.  **Backend (Loop for each `formDomain` in `visitItems`):**
  * `schema = CDC\CRF::getFormSchema(S, isfContext.flowchart_version_actual, formDomain)`.
  * `instanceMeta = CDC\FormInstance::getOrCreateFormInstance(..., isfContext.isf_id)`.
  * `instanceData = CDC\FormInstance::getFormData(instanceMeta.form_instance_id)`.
  * UI merges `schema` and `instanceData`.
5.  **UI**: User enters data, submits (`isf_id` and `formsData`).
6.  **Backend (ISF Endpoint)**:
  * Calls `CDC\ISF::saveISFVisitData(isf_id, formsDataFromUI)`.