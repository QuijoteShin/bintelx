# `CDC\ISF` - Investigator Site File (Visit Event Management)

**File:** `custom/cdc/Business/ISF.php`
**Namespace:** `CDC`

## 1. Purpose

The `ISF` (Investigator Site File) class is a central component in managing clinical **visit events** within the CDC module. Its primary roles are:

* **Visit Event Management**: Represents and manages a single, specific visit event for a patient in a study (e.g., "Patient PXYZ007, Screening Visit for Study PROT-001, following Protocol v1.0, Arm A"). It encapsulates all activities and data points related to that visit by creating and managing a `cdc_isf` record.
* **`FormInstance` Orchestration**: Acts as a container and orchestrator for multiple `CDC\FormInstance` objects that belong to the same ISF/visit event. Each `FormInstance` corresponds to a specific eCRF page (`form_domain`) completed during the visit.
* **Schema Determination**: Collaborates with `CDC\Flowchart` and `CDC\CRF` to determine the precise set of forms (`form_domain`s) required for a visit, considering the active `flow_chart_version` and the patient's determined `branch_code_actual`.
* **Data Saving Orchestration**: Manages the process of saving data collected across multiple forms within the visit, primarily by delegating the persistence of individual form data to `CDC\FormInstance` (which in turn uses `bX\DataCaptureService`).
* **Patient Branch Management**: Provides methods to assign patients to study branches and retrieve their active branch, interacting with the `cdc_patient_study_branch` table.

## 2. Dependencies

* `bX\CONN`: For database connectivity (primarily with `cdc_isf` and `cdc_patient_study_branch`).
* `bX\Log`: For logging operations and errors.
* `bX\Profile`: Used internally to retrieve the `actorUserId` for actions requiring audit.
* `CDC\Study`: To resolve `studyId` (public) to `study_internal_id` and validate study existence.
* `CDC\Flowchart`: To determine the expected forms for a visit based on `studyId`, `flow_chart_version`, `visit_code` (from `cdc_visit_definitions`), and `branch_code_actual`.
* `CDC\CRF`: To retrieve the versioned schema/structure of individual forms (`form_domain`s) using the correct `flow_chart_version`.
* `CDC\FormInstance`: For creating, managing, and saving data for individual form instances linked to an ISF.

## 3. Database Tables

### Primary Table Managed by `ISF` methods:

* **`cdc_isf`**: Main table for visit event records.
  * `isf_id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT)
  * `study_internal_id` (BIGINT UNSIGNED, NOT NULL, FK to `cdc_study`)
  * `bnx_entity_id` (VARCHAR(255), NOT NULL, Patient ID)
  * `flow_chart_id` (BIGINT UNSIGNED, NULL, FK to `cdc_flow_chart`): Links to the specific planned visit instance in the flowchart, if applicable. Will be `NULL` for unscheduled visits.
  * `visit_num_actual` (VARCHAR(50), NOT NULL): The visit identifier for this event (e.g., "SCR", "V1", "UNSCHED01"). Derived from `cdc_visit_definitions.visit_code` via `cdc_flow_chart` if scheduled, or user-defined for unscheduled.
  * `visit_name_actual` (VARCHAR(255), NULL): Actual/friendly name of the visit event, especially if unscheduled or different from the planned `visit_name` in `cdc_visit_definitions`.
  * `visit_date_actual` (DATE, NULL): Actual date the visit occurred.
  * `flow_chart_version_actual` (VARCHAR(255), NOT NULL): The specific flowchart version active for this patient at the time of this visit event (from `cdc_flowchart_versions_status` or parent `cdc_flow_chart`).
  * `branch_code_actual` (VARCHAR(50), NOT NULL, DEFAULT '__COMMON__'): Actual branch this visit event adheres to, determined from `cdc_patient_study_branch` or defaults if not applicable.
  * `status` (ENUM('SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED', 'MISSED'), NOT NULL, DEFAULT 'SCHEDULED'): Status of the overall visit event.
  * `created_by_actor_id` (VARCHAR(255), NULL)
  * `updated_by_actor_id` (VARCHAR(255), NULL)
  * `finalized_by_actor_id` (VARCHAR(255), NULL)
  * `created_at` (TIMESTAMP, NOT NULL, DEFAULT CURRENT_TIMESTAMP)
  * `updated_at` (TIMESTAMP, NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)
  * `finalized_at` (TIMESTAMP, NULL)

### Supporting Table (Managed by `ISF` methods):

* **`cdc_patient_study_branch`**: Manages active and historical `branch_code` assignments.
  * `patient_study_branch_id` (PK)
  * `study_internal_id` (FK)
  * `bnx_entity_id`
  * `branch_code`
  * `assigned_at` (TIMESTAMP)
  * `is_active` (BOOLEAN, default FALSE, only one TRUE per study/patient)
  * `reason_for_assignment` (TEXT, NULL)
  * `created_by_actor_id`
  * ( `created_at`, `updated_at` )
  * **Unique Key Strategy**: `UNIQUE KEY uq_psb_study_entity_assigned (study_internal_id, bnx_entity_id, assigned_at)` to allow history. Application logic in `assignPatientToBranch` must enforce only one `is_active = TRUE` per study/patient.

### Related Tables (Interacted With):

* `cdc_form_instance`: Linked via `cdc_form_instance.isf_id`. **The table `cdc_isf_form_instance_link` is considered redundant and NOT used.**
* `cdc_study`, `cdc_visit_definitions`, `cdc_flow_chart`, `cdc_flow_chart_item`, `cdc_form_fields`.
* `cdc_flowchart_versions_status`: To determine active/published flowchart versions.

## 4. Key Concepts

* **Visit Event (ISF Record)**: A specific occurrence of a patient visit. It acts as a container for all `FormInstance`s related to that single encounter. It records the `flow_chart_version_actual` and `branch_code_actual` to provide the precise context of the study setup at the time of the visit.
* **`branch_code_actual`**: The definitive study branch the patient is following for this ISF event. It's determined at ISF creation (via `getPatientActiveBranch` or an explicit parameter) and stored in `cdc_isf`. This dictates the applicable forms from the `cdc_flow_chart_item` definitions.
* **Form Orchestration**: The process by which `ISF` determines the required `form_domain`s for a visit (using `Flowchart::getFlowchartDetails` with the `flow_chart_version_actual` and `branch_code_actual`), then triggers retrieval of their versioned schemas (via `CRF::getFormSchema` using the same `flow_chart_version_actual`), and manages the `FormInstance`s for data entry.

## 5. Core Static Methods (Proposed)

*Actor ID for all methods creating/modifying data is obtained internally via `\bX\Profile`.*

### `assignPatientToBranch(string $studyId, string $bnxEntityId, string $branchCode, ?string $reason = null): array`

* **Purpose**: Manages `cdc_patient_study_branch` records. Sets the specified `$branchCode` as active for the patient in the study, deactivating any previous active branch for that patient/study.
* **Parameters**:
  * `$studyId` (string, **required**).
  * `$bnxEntityId` (string, **required**).
  * `$branchCode` (string, **required**): The branch code to assign as active.
  * `$reason` (string, optional): Reason for this assignment.
* **Returns**: `['success' => bool, 'message' => string, 'patient_study_branch_id' => int|null]`

### `getPatientActiveBranch(string $studyId, string $bnxEntityId): array`

* **Purpose**: Retrieves the currently active `branch_code` for a patient in a study from `cdc_patient_study_branch`.
* **Parameters**:
  * `$studyId` (string, **required**).
  * `$bnxEntityId` (string, **required**).
* **Returns**: `['success' => bool, 'branch_code' => string|null, 'message' => string]` (`branch_code` is null if no active branch found).

### `getOrCreateISF(string $studyId, string $bnxEntityId, string $visitNumActual, string $flowchartVersionActual, ?string $explicitBranchCodeActual = null, ?date $visitDateActual = null, ?string $visitNameActual = null): array`

* **Purpose**: Retrieves an existing `ISF` record (based on `studyId`, `bnxEntityId`, `visitNumActual`, `flowchartVersionActual`, and the determined `branch_code_actual`) or creates a new one if it doesn't exist (typically in 'SCHEDULED' or 'IN_PROGRESS' status). It's responsible for determining and recording the correct `branch_code_actual`.
  * If `$explicitBranchCodeActual` is provided, it's used (after validation).
  * If `null`, this method **must** call `ISF::getPatientActiveBranch($studyId, $bnxEntityId)` to determine the patient's current active branch.
  * If no active branch can be determined and it's required by study design, an error is returned.
  * The determined/validated `branch_code_actual` is stored/used for lookup in `cdc_isf`.
  * Retrieves `flow_chart_id` by looking up `cdc_flow_chart` using `studyId`, `flowchartVersionActual`, and `visit_definition_id` (derived from `visitNumActual` via `cdc_visit_definitions`).
* **Parameters**:
  * `$studyId` (string, **required**).
  * `$bnxEntityId` (string, **required**).
  * `$visitNumActual` (string, **required**): The visit identifier (should map to `cdc_visit_definitions.visit_code`).
  * `$flowchartVersionActual` (string, **required**): The active, published flowchart version for this ISF.
  * `$explicitBranchCodeActual` (string, optional): If provided, this branch code is used.
  * `$visitDateActual` (date string, YYYY-MM-DD, optional): Actual date of the visit.
  * `$visitNameActual` (string, optional): Actual name if unscheduled or different from plan.
* **Returns**: `['success' => bool, 'isf_id' => int|null, 'branch_code_actual' => string|null, 'status' => string|null, 'flow_chart_id' => int|null, 'message' => string, 'is_new' => bool]`

### `getISFDetails(int $isfId): array`

* **Purpose**: Retrieves comprehensive details for a given `isfId`, including `cdc_isf` record data and summaries of its linked `FormInstance`s (e.g., ID, `form_domain`, status, `branch_code_actual` from the `FormInstance`).
* **Parameters**:
  * `$isfId` (int, **required**).
* **Returns**: `['success' => bool, 'isf_details' => array|null, 'form_instances' => array|null, 'message' => string]`

### `saveISFVisitData(int $isfId, array $formsDataFromUI, ?string $changeReason = null): array`

* **Purpose**: Main method to save data for multiple forms within a specific ISF. Iterates through `$formsDataFromUI`. For each `form_domain`:
  1.  Retrieves the parent `cdc_isf` record (to get `studyId`, `bnxEntityId`, `visitNumActual`, `flowchartVersionActual`, `branch_code_actual`).
  2.  Calls `FormInstance::getOrCreateFormInstance(...)`, passing all necessary context from the ISF record, including `isf_id` as `$isfIdRef`.
  3.  Calls `FormInstance::saveFormInstanceData(...)` with the data for that specific domain.
* **Parameters**:
  * `$isfId` (int, **required**).
  * `$formsDataFromUI` (array, **required**): Associative array: `['form_domain1' => ['field1' => 'val1', ...], 'form_domain2' => [...]]`.
  * `$changeReason` (string, optional): Overall reason for changes.
* **Returns**: `['success' => bool, 'results_by_domain' => array, 'message' => string]`
  * `results_by_domain`: Associative array with success/failure status for each `form_domain`.

### `updateISFStatus(int $isfId, string $newStatus, array $options = []): array`

* **Purpose**: Updates the ISF status. May trigger cascading actions on linked `FormInstance`s.
* **Parameters**:
  * `$isfId` (int, **required**).
  * `$newStatus` (string, **required**).
  * `$options` (array, optional): e.g., `finalized_at` timestamp or specific actor.
* **Returns**: `['success' => bool, 'message' => string]`

## 6. Interaction with `FormInstance`, `Flowchart`, and `CRF`

* **Determining Expected Forms for a Visit Event**:
  1.  `ISF::getOrCreateISF` determines/validates `studyId`, `visitNumActual` (maps to a `visit_code`), `flowchartVersionActual`, and `branch_code_actual`. It also resolves the `flow_chart_id` (the specific visit instance in the flowchart).
  2.  This context is then used to call `Flowchart::getFlowchartDetails($studyId, $flowchartVersionActual, $branch_code_actual)` (or a more specific method like `Flowchart::getVisitItems($flow_chart_id, $branch_code_actual)`) to get the list of `form_domain`s expected for this specific visit event.
* **Displaying Forms**:
  1.  For each `form_domain` identified as expected:
    * `CRF::getFormSchema($studyId, $flowchartVersionActual, $formDomain)` is called to get its versioned structure.
    * `FormInstance::getOrCreateFormInstance(...)` (passing the `isf_id`, `branch_code_actual`, `flowchartVersionActual` etc.) gets/creates the form instance metadata record.
    * `FormInstance::getFormData(...)` retrieves any existing data for that instance from DCS.
    * The UI merges the schema with the data for display.
* **Saving Data**:
  1.  `ISF::saveISFVisitData` receives an `isf_id` and data for multiple `form_domain`s.
  2.  It iterates. For each `form_domain`'s data:
    * It ensures the `FormInstance` exists by calling `FormInstance::getOrCreateFormInstance(...)`, passing the `isf_id` and all other necessary context derived from the parent ISF record (like `studyId`, `bnxEntityId`, `visitNumActual`, `flowchartVersionActual`, `branch_code_actual`).
    * It then calls `FormInstance::saveFormInstanceData(...)` for that specific `FormInstance`.
  3.  The `isf_id` in `cdc_form_instance` (set by `getOrCreateFormInstance` via the `$isfIdRef` parameter) establishes the direct link.

## 7. Example Workflow (Data Entry - Conceptual)

1.  **UI**: User navigates to Patient P, Study S, Visit V.
2.  **Backend (ISF Endpoint)**:
  * Calls `isfContext = CDC\ISF::getOrCreateISF(S, P, V, currentPublishedFcVersion)`. This determines `branch_code_actual` and `flow_chart_id`.
3.  **Backend**:
  * Calls `visitPlan = CDC\Flowchart::getFlowchartDetails(S, isfContext.flowchart_version_actual, isfContext.branch_code_actual)` (or a method to get items for `isfContext.flow_chart_id` and `isfContext.branch_code_actual`).
4.  **Backend (Loop for each `formDomain` in `visitPlan.items`):**
  * `schema = CDC\CRF::getFormSchema(S, isfContext.flowchart_version_actual, formDomain)`.
  * `instanceMeta = CDC\FormInstance::getOrCreateFormInstance(S, P, V, formDomain, isfContext.flowchart_version_actual, isfContext.branch_code_actual, isfContext.isf_id)`.
  * `instanceData = CDC\FormInstance::getFormData(instanceMeta.form_instance_id)`.
  * UI merges `schema` and `instanceData`.
5.  **UI**: User enters data, submits. Payload has `isf_id` and `formsData` by `form_domain`.
6.  **Backend (ISF Endpoint)**:
  * Calls `CDC\ISF::saveISFVisitData(isf_id, formsDataFromUI)`.