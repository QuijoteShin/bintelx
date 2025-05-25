# `CDC\ISF` - Investigator Site File (Visit Event Management)

**File:** `custom/cdc/Business/ISF.php`
**Namespace:** `CDC`

## 1. Purpose

The `ISF` (Investigator Site File) class is a central component in managing clinical **visit events** within the CDC module. Its primary roles are:

* **Visit Event Management**: Represents and manages a single, specific visit event for a patient in a study (e.g., "Patient PXYZ007, Screening Visit for Study PROT-001, following Protocol v1.0, Arm A"). It encapsulates all activities and data points related to that visit.
* **`FormInstance` Orchestration**: Acts as a container and orchestrator for multiple `CDC\FormInstance` objects that belong to the same visit event. Each `FormInstance` corresponds to a specific eCRF page (`form_domain`) completed during the visit.
* **Schema Determination**: Collaborates with `CDC\Flowchart` and `CDC\CRF` to determine the precise set of forms (`form_domain`s) required for a visit, considering the active `flow_chart_version` and the patient's `branch_code_actual`.
* **Data Saving Orchestration**: Manages the process of saving data collected across multiple forms within the visit, delegating the persistence of individual form data to `CDC\FormInstance` (which in turn uses `bX\DataCaptureService`).

## 2. Dependencies

* `bX\CONN`: For database connectivity (primarily with `cdc_isf` and `cdc_patient_study_branch`).
* `bX\Log`: For logging activities and errors.
* `bX\Profile`: Used internally to retrieve the `actorUserId` for actions.
* `CDC\Study`: To resolve `studyId` to `study_internal_id` and validate study existence.
* `CDC\Flowchart`: To determine the expected forms for a visit based on `studyId`, `flow_chart_version`, `visit_num`, and `branch_code_actual`.
* `CDC\CRF`: To retrieve the schema/structure of individual forms (`form_domain`s).
* `CDC\FormInstance`: For creating, managing, and saving data for individual form instances linked to an ISF.

## 3. Database Tables

### Primary Table Managed:

* **`cdc_isf`**: Main table for visit event records.
    * `isf_id` (BIGINT UNSIGNED, PK, AUTO_INCREMENT)
    * `study_internal_id` (BIGINT UNSIGNED, NOT NULL, FK to `cdc_study`)
    * `bnx_entity_id` (VARCHAR(255), NOT NULL, Patient ID)
    * `flow_chart_id_ref` (BIGINT UNSIGNED, NULL, FK to `cdc_flow_chart`): Links to the specific planned visit in the flowchart.
    * `visit_num_actual` (VARCHAR(50), NOT NULL): The visit identifier for this event (e.g., "SCR", "V1", "UNSCHED01"). Derived from `cdc_flow_chart.visit_num` if scheduled.
    * `flow_chart_version` (VARCHAR(255), NOT NULL): The protocol/flowchart version active for this ISF.
    * `branch_code_actual` (VARCHAR(50), NOT NULL): The specific study branch the patient was on when this ISF was created/data was captured.
    * `isf_status` (VARCHAR(50), NOT NULL, DEFAULT 'IN_PROGRESS'): e.g., 'IN_PROGRESS', 'COMPLETED', 'FINALIZED', 'CANCELLED', 'LOCKED'.
    * `visit_date` (DATE, NULL): Actual date of the visit.
    * `created_by_actor_id` (VARCHAR(255), NULL)
    * `updated_by_actor_id` (VARCHAR(255), NULL)
    * `finalized_by_actor_id` (VARCHAR(255), NULL)
    * `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
    * `updated_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)
    * `finalized_at` (TIMESTAMP, NULL)

### Supporting Table (Managed by ISF methods):

* **`cdc_patient_study_branch`**: Manages the active and historical `branch_code` assignments for patients within a study.
    * `patient_study_branch_id` (PK, BIGINT UNSIGNED, AUTO_INCREMENT)
    * `study_internal_id` (BIGINT UNSIGNED, NOT NULL, FK to `cdc_study`)
    * `bnx_entity_id` (VARCHAR(255), NOT NULL, Patient ID)
    * `branch_code` (VARCHAR(50), NOT NULL, The assigned branch code)
    * `assigned_at` (TIMESTAMP, NOT NULL, DEFAULT CURRENT_TIMESTAMP): Timestamp when this branch assignment was made or became effective.
    * `is_active` (BOOLEAN, NOT NULL, DEFAULT TRUE): Indicates if this is the currently active branch for the patient in this study.
    * `created_by_actor_id` (VARCHAR(255), NULL)
    * `created_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP)
    * `updated_at` (TIMESTAMP, DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)
    * **Unique Key Strategy**: To ensure only one branch is active per patient per study, either a filtered unique index on (`study_internal_id`, `bnx_entity_id`, `is_active` where `is_active` = TRUE) if supported, or application logic in `assignPatientToBranch` must enforce this (deactivating old active branches when a new one is set). A simpler unique key for history might be (`study_internal_id`, `bnx_entity_id`, `assigned_at`).

### Related Tables (Interacted With):

* `cdc_form_instance`: Linked via `cdc_form_instance.isf_id_ref`.
* `cdc_study`, `cdc_flow_chart`, `cdc_flow_chart_item`.

## 4. Key Concepts

* **Visit Event (ISF Record)**: A specific occurrence of a patient visit. It acts as a container for all `FormInstance`s related to that single encounter.
* **`branch_code_actual`**: The definitive study branch the patient is following for this ISF event. It's determined at the time of ISF creation (or retrieval) and dictates the applicable forms from the flowchart. Managed via `cdc_patient_study_branch`.
* **Form Orchestration**: The process by which `ISF` determines the required `form_domain`s for a visit (using `Flowchart::getFlowchartDetails` with the `branch_code_actual`), potentially triggers schema retrieval (via `CRF::getFormSchema`), and manages the creation/retrieval of `FormInstance`s for data entry.

## 5. Core Static Methods (Proposed)

*Actor ID for all methods is obtained internally via `\bX\Profile`.*

### `getOrCreateISF(string $studyId, string $bnxEntityId, string $visitNum, string $flowchartVersion, ?string $explicitBranchCodeActual = null): array`

* **Purpose**: Retrieves an existing `ISF` record or creates a new one (typically in 'IN_PROGRESS' status). It's responsible for determining and recording the correct `branch_code_actual`.
    * If `$explicitBranchCodeActual` is provided, it's used (ideally after validation against allowed study branches).
    * If `null`, this method **must** call `ISF::getPatientActiveBranch($studyId, $bnxEntityId)` to determine the patient's current active branch.
    * If no active branch can be determined (and none was provided), it should typically return an error, unless the study/visit design allows for a "common" or "unbranched" path.
    * The determined `branch_code_actual` is stored in `cdc_isf.branch_code_actual`.
* **Parameters**:
    * `$studyId` (string, **required**): Public ID of the study.
    * `$bnxEntityId` (string, **required**): Patient ID.
    * `$visitNum` (string, **required**): Visit identifier (from `cdc_flow_chart.visit_num`).
    * `$flowchartVersion` (string, **required**): The flowchart version for this ISF.
    * `$explicitBranchCodeActual` (string, optional): If provided, this branch code is used.
* **Returns**: `['success' => bool, 'isf_id' => int|null, 'branch_code_actual' => string|null, 'isf_status' => string|null, 'message' => string, 'is_new' => bool]`

### `getISFDetails(int $isfId): array`

* **Purpose**: Retrieves details for a given `isf_id`, including its own record data and a list/summary of its associated `FormInstance`s (e.g., their IDs, `form_domain`s, and statuses).
* **Parameters**:
    * `$isfId` (int, **required**): Unique ID of the ISF record.
* **Returns**: `['success' => bool, 'isf_details' => array|null, 'form_instances' => array|null, 'message' => string]`
    * `isf_details`: Associative array of the `cdc_isf` record.
    * `form_instances`: Array of summaries for linked `cdc_form_instance` records.

### `saveISFVisitData(int $isfId, array $formsDataFromUI, ?string $changeReason = null): array`

* **Purpose**: Main method to save data for multiple forms within a specific ISF. Iterates through `$formsDataFromUI`. For each `form_domain`:
    1.  Retrieves the `cdc_isf` record (to confirm `studyId`, `bnxEntityId`, `flowchartVersion`, `branch_code_actual`).
    2.  Calls `FormInstance::getOrCreateFormInstance(...)`, passing the ISF's context (including `isfId` as `$isfIdRef`, `branch_code_actual`, `flowchartVersion`).
    3.  Calls `FormInstance::saveFormInstanceData(...)` with the data for that specific domain.
* **Parameters**:
    * `$isfId` (int, **required**): ID of the ISF.
    * `$formsDataFromUI` (array, **required**): Associative array: `['form_domain1' => ['field1' => 'val1', ...], 'form_domain2' => [...]]`.
    * `$changeReason` (string, optional): Overall reason for changes if applicable.
* **Returns**: `['success' => bool, 'results_by_domain' => array, 'message' => string]`
    * `results_by_domain`: Associative array with success/failure status for each `form_domain` processed.

### `updateISFStatus(int $isfId, string $newStatus, array $options = []): array`

* **Purpose**: Marks the ISF record with a new status (e.g., 'COMPLETED', 'FINALIZED', 'LOCKED'). May trigger cascading status updates or checks on linked `FormInstance`s (e.g., all forms must be 'FINALIZED' before ISF can be 'FINALIZED'). Actor ID obtained internally.
* **Parameters**:
    * `$isfId` (int, **required**).
    * `$newStatus` (string, **required**).
    * `$options` (array, optional): e.g. `finalized_at` timestamp or specific actor for finalization.
* **Returns**: `['success' => bool, 'message' => string]`

### `assignPatientToBranch(string $studyId, string $bnxEntityId, string $branchCode): array`

* **Purpose**: Manages `cdc_patient_study_branch` records. Sets the specified `$branchCode` as active for the patient in the study, deactivating any previous active branch for that patient/study. Actor ID obtained internally.
* **Parameters**:
    * `$studyId` (string, **required**).
    * `$bnxEntityId` (string, **required**).
    * `$branchCode` (string, **required**): The branch code to assign as active.
* **Returns**: `['success' => bool, 'message' => string]`

### `getPatientActiveBranch(string $studyId, string $bnxEntityId): array`

* **Purpose**: Retrieves the currently active `branch_code` for a patient in a study from `cdc_patient_study_branch`.
* **Parameters**:
    * `$studyId` (string, **required**).
    * `$bnxEntityId` (string, **required**).
* **Returns**: `['success' => bool, 'branch_code' => string|null, 'message' => string]` (null if no active branch).

## 6. Interaction with `FormInstance`, `Flowchart`, and `CRF`

* **Determining Expected Forms**:
    1.  `ISF::getOrCreateISF` determines the `studyId`, `visitNum`, `flowchartVersion`, and `branch_code_actual`.
    2.  This context is used to call `Flowchart::getFlowchartDetails(...)` to get the list of `form_domain`s expected for this visit event.
* **Displaying Forms**:
    1.  For each `form_domain` identified:
        * `CRF::getFormSchema($studyId, $flowchartVersion, $formDomain)` is called to get its structure (this uses the `flowchartVersion` to get the correct *versioned* form definition from `cdc_form_fields`).
        * `FormInstance::getOrCreateFormInstance(...)` is called (passing `isfId` and all context) to get/create the instance metadata.
        * `FormInstance::getFormData(...)` is called to retrieve any existing data.
        * UI merges schema and data.
* **Saving Data**:
    1.  `ISF::saveISFVisitData` receives data for multiple `form_domain`s.
    2.  It iterates, calling `FormInstance::getOrCreateFormInstance(...)` for each domain (passing `isf_id`, `branch_code_actual` from the ISF context, and `flowchartVersion`).
    3.  Then calls `FormInstance::saveFormInstanceData(...)` for each, which handles the `DataCaptureService` interaction.
    4.  The `isf_id_ref` in `cdc_form_instance` (set by `getOrCreateFormInstance`) establishes the link.

## 7. Example Workflow (Data Entry - Conceptual)

1.  **UI**: User selects Patient, Study, Visit.
2.  **Backend (Endpoint for ISF)**:
    * Calls `isf = CDC\ISF::getOrCreateISF(studyId, patientId, visitNum, activePublishedFlowchartVersion)`.
    * This internally calls `CDC\ISF::getPatientActiveBranch` to determine `isf.branch_code_actual`.
3.  **Backend**:
    * Calls `expectedForms = CDC\Flowchart::getFlowchartDetails(studyId, isf.flowchart_version, isf.branch_code_actual, isf.visit_num)`.
4.  **Backend (Loop for each `formDomain` in `expectedForms`):**
    * `schema = CDC\CRF::getFormSchema(studyId, isf.flowchart_version, formDomain)`.
    * `instanceInfo = CDC\FormInstance::getOrCreateFormInstance(studyId, patientId, isf.visit_num_actual, formDomain, isf.flowchart_version, isf.branch_code_actual, isf.isf_id)`.
    * `data = CDC\FormInstance::getFormData(instanceInfo.form_instance_id)`.
    * UI merges `schema` and `data` for display.
5.  **UI**: User enters data, clicks "Save". Payload includes `isf_id` and data grouped by `form_domain`.
6.  **Backend (Endpoint for ISF)**:
    * Calls `CDC\ISF::saveISFVisitData(isf_id, formsDataFromUI)`.
    * This method then iterates, calling `CDC\FormInstance::getOrCreateFormInstance` and `CDC\FormInstance::saveFormInstanceData` for each submitted domain.