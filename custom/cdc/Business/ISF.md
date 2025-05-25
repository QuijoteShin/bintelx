# `CDC\ISF` - Investigator Site File (Visit Event Management)

**File:** `custom/cdc/Business/ISF.php`
**Namespace:** `CDC`

## 1. Purpose

The `ISF` (Investigator Site File) class is a central component in managing clinical **visit events** within the CDC module. Its primary roles are:

* **Visit Event Management**: Represents and manages a single, specific visit event for a patient in a study. It creates and manages `cdc_isf` records.
* **`FormInstance` Orchestration**: Acts as a container and orchestrator for multiple `CDC\FormInstance` objects belonging to the same ISF/visit event.
* **Context & Schema Determination**: Collaborates with `CDC\Flowchart`, `CDC\VisitDefinitions` (conceptual, para obtener el `visit_definition_id` a partir de un `visit_code`), and `CDC\CRF` to determine the precise set of forms (`form_domain`s) required for a visit, ensuring the correct `flow_chart_version_actual` (from `cdc_flowchart_versions_status` via `cdc_flow_chart`) and the patient's `branch_code_actual` (from `cdc_patient_study_branch`) are used to fetch the versioned form schemas.
* **Data Saving Orchestration**: Manages the process of saving visit data, primarily by delegating the persistence of individual form data to `CDC\FormInstance`.
* **Patient Branch Management**: Provides methods to assign patients to study branches and retrieve their active branch, interacting with the `cdc_patient_study_branch` table.

## 2. Dependencies

* `bX\CONN`: For database connectivity.
* `bX\Log`: For logging.
* `bX\Profile`: Used internally to retrieve the `actorUserId`.
* `CDC\Study`: To validate studies and resolve `studyId` to `study_internal_id`.
* `CDC\Flowchart`: To get visit plans (`cdc_flow_chart`, `cdc_flow_chart_item`) based on `flow_chart_version`, `visit_code`, and `branch_code`.
* `CDC\CRF`: To get versioned form schemas (`cdc_form_fields`) using `studyId`, `flow_chart_version`, and `form_domain`.
* `CDC\FormInstance`: To manage individual form instances.
* `CDC\VisitDefinitions` (Conceptual or actual class): To map `visit_code` to `visit_definition_id`.

## 3. Database Tables

### Primary Table Managed:

* **`cdc_isf`**: Stores visit event records. Columns as per our consolidated `cdc.sql`:
  * `isf_id` (PK), `study_internal_id`, `bnx_entity_id`, `flow_chart_id` (FK to `cdc_flow_chart`), `visit_num_actual` (corresponds to `cdc_visit_definitions.visit_code`), `visit_name_actual`, `visit_date_actual`, `flow_chart_version_actual`, `branch_code_actual`, `status` (ENUM), `created_by_actor_id`, `updated_by_actor_id`, `finalized_by_actor_id`, `locked_by_actor_id`, timestamps.

### Supporting Table (Managed):

* **`cdc_patient_study_branch`**: Manages patient branch assignments. Columns as per our consolidated `cdc.sql`.
  * Key: `UNIQUE KEY uq_psb_study_entity_assigned (study_internal_id, bnx_entity_id, assigned_at)`.

### Related Tables (Interacted With):

* `cdc_form_instance`: Linked via `cdc_form_instance.isf_id`. **`cdc_isf_form_instance_link` is NOT used.**
* `cdc_study`, `cdc_visit_definitions`, `cdc_flow_chart`, `cdc_flow_chart_item`, `cdc_form_fields`, `cdc_flowchart_versions_status`.

## 4. Key Concepts

* **Visit Event (ISF Record)**: A specific visit encounter, capturing the precise study setup context (`flow_chart_version_actual`, `branch_code_actual`, and linked `flow_chart_id`) at the time of the visit.
* **`branch_code_actual`**: The definitive branch for this ISF, determined via `getPatientActiveBranch` or explicitly provided, and stored in `cdc_isf`.
* **`flow_chart_version_actual`**: The definitive protocol version for this ISF, must correspond to a 'PUBLISHED' version from `cdc_flowchart_versions_status`.

## 5. Core Static Methods

*Actor ID for all methods is obtained internally via `\bX\Profile`.*

### `assignPatientToBranch(string $studyId, string $bnxEntityId, string $branchCode, ?string $reason = null): array`

* **Purpose**: Manages `cdc_patient_study_branch`. Sets `$branchCode` as active, deactivating any previous active branch for the patient/study.
* **Parameters**: `$studyId`, `$bnxEntityId`, `$branchCode`, `$reason` (optional).
* **Returns**: `['success' => bool, 'message' => string, 'patient_study_branch_id' => int|null]`

### `getPatientActiveBranch(string $studyId, string $bnxEntityId): array`

* **Purpose**: Retrieves the currently active `branch_code` for a patient in a study from `cdc_patient_study_branch`.
* **Parameters**: `$studyId`, `$bnxEntityId`.
* **Returns**: `['success' => bool, 'branch_code' => string|null, 'message' => string]`

### `getOrCreateISF(string $studyId, string $bnxEntityId, string $visitCode, string $flowchartVersionActual, ?string $explicitBranchCodeActual = null, ?string $visitDateActual = null, ?string $visitNameActual = null): array`

* **Purpose**: Retrieves an existing `ISF` or creates a new one. Determines `branch_code_actual` and resolves `visitCode` and `flowchartVersionActual` to a specific `flow_chart_id`.
* **Parameters**:
  * `$studyId` (string, **required**).
  * `$bnxEntityId` (string, **required**).
  * `$visitCode` (string, **required**): The `visit_code` from `cdc_visit_definitions`.
  * `$flowchartVersionActual` (string, **required**): Must be a 'PUBLISHED' version (checked against `cdc_flowchart_versions_status`).
  * `$explicitBranchCodeActual` (string, optional).
  * `$visitDateActual` (string 'YYYY-MM-DD', optional).
  * `$visitNameActual` (string, optional): If different from `cdc_visit_definitions.visit_name`.
* **Internal Logic**:
  1. Get `study_internal_id` from `Study::getStudyDetails($studyId)`.
  2. Determine `branch_code_actual` (using `$explicitBranchCodeActual` or `ISF::getPatientActiveBranch`). Error if none found & study design requires it.
  3. Get `visit_definition_id` from `cdc_visit_definitions` using `study_internal_id` and `$visitCode`. Error if not found.
  4. Get `flow_chart_id` from `cdc_flow_chart` using `study_internal_id`, `$flowchartVersionActual`, and `visit_definition_id`. Error if this visit type is not planned in this flowchart version.
  5. Attempt to `SELECT cdc_isf` based on `study_internal_id`, `bnx_entity_id`, `flow_chart_id` (and potentially `visit_date_actual` if non-unique visits are allowed for the same planned `flow_chart_id`).
  6. If not found, `INSERT` new `cdc_isf` linking to `flow_chart_id`, and setting `visit_num_actual` (from `$visitCode`), `flow_chart_version_actual`, `branch_code_actual`, `visit_date_actual`, `visit_name_actual`. Status typically 'SCHEDULED' or 'IN_PROGRESS'.
* **Returns**: `['success' => bool, 'isf_id' => int|null, 'branch_code_actual' => string|null, 'status' => string|null, 'flow_chart_id' => int|null, 'message' => string, 'is_new' => bool]`

### `getISFDetails(int $isfId): array`

* **Purpose**: Retrieves `cdc_isf` details and summaries of its linked `FormInstance`s.
* **Parameters**: `$isfId`.
* **Returns**: `['success' => bool, 'isf_details' => array|null, 'form_instances' => array|null, 'message' => string]`

### `saveISFVisitData(int $isfId, array $formsDataFromUI, ?string $changeReason = null): array`

* **Purpose**: Main method to save data for multiple forms within an ISF.
* **Parameters**: `$isfId`, `$formsDataFromUI` (assoc. array `['form_domain1' => ['field1' => 'val1', ...]]`), `$changeReason` (optional).
* **Internal Logic**:
  1.  Fetch `cdc_isf` record for `$isfId` to get full context: `studyId` (public), `bnxEntityId`, `visitNumActual` (from its underlying `visit_code`), `flowchartVersionActual`, `branch_code_actual`.
  2.  For each `form_domain` in `$formsDataFromUI`:
    * Call `\CDC\FormInstance::getOrCreateFormInstance($studyId, $bnxEntityId, $visitNumActual, $formDomain, $flowchartVersionActual, $branch_code_actual, $isfId /* isfIdRef */)`.
    * Call `\CDC\FormInstance::saveFormInstanceData(form_instance_id, $domainSpecificData, $changeReason)`.
* **Returns**: `['success' => bool, 'results_by_domain' => array, 'message' => string]`

### `updateISFStatus(int $isfId, string $newStatus, array $options = []): array`

* **Purpose**: Updates `cdc_isf.status`.
* **Parameters**: `$isfId`, `$newStatus` (ENUM value), `$options` (optional).
* **Returns**: `['success' => bool, 'message' => string]`

## 6. Interaction with Other Modules

* **Determining Expected Forms**:
  1.  `ISF::getOrCreateISF` determines full context including `flow_chart_id` and `branch_code_actual`.
  2.  Call `\CDC\Flowchart::getVisitItems($flow_chart_id, $branch_code_actual)` (new conceptual method for `Flowchart` or adapt `getFlowchartDetails`) to get the list of `form_domain`s for this visit event.
* **Displaying Forms**:
  1.  For each `form_domain` from `Flowchart`:
    * Call `\CDC\CRF::getFormSchema($publicStudyId, $flowchartVersionActual, $formDomain)` to get its versioned structure.
    * Call `\CDC\FormInstance::getOrCreateFormInstance(...)` (passing `isf_id`, `branch_code_actual`, etc.).
    * Call `\CDC\FormInstance::getFormData(...)`.
    * UI merges schema and data.
* **Saving Data**: As described in `saveISFVisitData`.

## 7. Example Workflow (Data Entry - Conceptual)

1.  **UI**: User selects Patient P, Study S, Visit V (which is a `visit_code`).
2.  **Backend (ISF Endpoint)**:
  * Determines `activePublishedFcVersion` for Study S (from `cdc_flowchart_versions_status`).
  * Calls `isfContext = \CDC\ISF::getOrCreateISF(S_public_id, P_bnx_id, V_visit_code, activePublishedFcVersion)`.
3.  **Backend**:
  * If `isfContext.flow_chart_id` is available:
    * Calls `visitItems = \CDC\Flowchart::getVisitItems(isfContext.flow_chart_id, isfContext.branch_code_actual)`.
  * Else (unscheduled visit), determine forms based on other rules or allow user selection.
4.  **Backend (Loop for each `formDomain` in `visitItems`):**
  * `schema = \CDC\CRF::getFormSchema(S_public_id, isfContext.flow_chart_version_actual, formDomain)`.
  * `instanceMeta = \CDC\FormInstance::getOrCreateFormInstance(S_public_id, P_bnx_id, isfContext.visit_num_actual, formDomain, isfContext.flow_chart_version_actual, isfContext.branch_code_actual, isfContext.isf_id)`.
  * `instanceData = \CDC\FormInstance::getFormData(instanceMeta.form_instance_id)`.
  * UI merges `schema` and `instanceData`.
5.  **UI**: User enters data, submits (`isf_id` and `formsData`).
6.  **Backend (ISF Endpoint)**:
  * Calls `\CDC\ISF::saveISFVisitData(isf_id, formsDataFromUI)`.