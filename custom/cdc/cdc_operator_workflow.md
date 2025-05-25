# CDC Operator Workflows and Use Cases

## 1. Introduction

This document outlines the key operational workflows and use cases from the perspective of different user roles 
interacting with the CDC (Clinical Data Capture) system. Its purpose is to provide functional context to guide the 
development of business logic, API endpoints, and user interfaces, ensuring the system is efficient, auditable, 
and addresses critical requirements for clinical trial data management, including version control of study 
configurations.

## 2. User Roles (Personas)

* **Study Designer / Setup Admin:** Responsible for configuring the study, CRFs, flowchart, and branches.
* **Data Manager:** Oversees data quality, manages queries, and potentially performs data review and locking.
* **CRA (Clinical Research Associate):** Monitors study progress, verifies data, and may raise queries.
* **Investigator / Site Staff (Clinical User):** Enters patient data into eCRFs, responds to queries.

## 3. Core Process: Study Setup and Configuration (Study Designer/Setup Admin)

This process details how a study's structure and data collection instruments are defined and versioned.

### Use Case 3.1: Creating a New Clinical Study

* **Actor:** Study Designer
* **Goal:** Register a new study in the system.
* **Steps:**
    1.  Provide core study metadata (ID, Title, Sponsor, Protocol ID).
    2.  System calls `CDC\Study::createStudy`.
    3.  Study is created with an initial status (e.g., 'PENDING_SETUP').
* **Key System Requirement:** Unique `study_id`.

### Use Case 3.2: Defining Global/Reusable CRF Fields

* **Actor:** Study Designer / Data Standards Manager
* **Goal:** Create or update base definitions for individual data fields.
* **Steps:**
    1.  Specify `fieldName`, `dataType`, `label`, base `attributes` (e.g., for a "Systolic Blood Pressure" field).
    2.  System calls `CDC\CRF::defineCRFField`.
* **Key System Requirement:** `DataCaptureService` versions these definitions (`capture_definition_version`).

### Use Case 3.3: Designing Form Structures for the Study (Versioned)

* **Actor:** Study Designer
* **Goal:** Define the content, layout, and order of fields for each `form_domain` (e.g., 'VS', 'DM') to be used in a specific `flow_chart_version` of the study.
* **Pre-conditions:** Base CRF fields are defined (Use Case 3.2). Study exists. A `flow_chart_version` (e.g., "PROT_V1-DRAFT") is being worked on.
* **Steps:**
    1.  For a given `studyId` and `flow_chart_version` (e.g., "PROT_V1-DRAFT"):
    2.  Operator selects a `form_domain` (e.g., 'VS').
    3.  Operator adds `field_name`s to this `form_domain`, specifying `item_order`, `section_name`, `is_mandatory`, and any `attributes_override_json`.
    4.  System calls `CDC\CRF::addFormField` for each field, passing the `flow_chart_version`. This populates `cdc_form_fields` linked to that `flow_chart_version`.
* **Operator Concern/Fear:** Ensuring this form structure is auditable and that changes don't affect already published/active protocol versions.
* **System Aspect:** `cdc_form_fields` records are tied to the `flow_chart_version`. Modifying a form for a *new* protocol amendment means creating records for the *new* `flow_chart_version`.

### Use Case 3.4: Designing a Flowchart Version (Visits, Forms per Visit/Branch)

* **Actor:** Study Designer
* **Goal:** Define the schedule of visits and assign versioned forms to visits and branches for a specific `flow_chart_version`.
* **Pre-conditions:** Study exists. Form structures for this `flow_chart_version` are defined (Use Case 3.3). `cdc_visit_definitions` may exist (if using that model).
* **Steps:**
    1.  Operator initiates work on a `flow_chart_version` (e.g., "PROT_V1-ArmA-DRAFT").
        * (Optional: UI allows copying from an existing `flow_chart_version` as a starting point. System calls a `CDC\Flowchart::copyFlowchartSetup` method, which duplicates `cdc_flow_chart`, `cdc_flow_chart_item`, and relevant `cdc_form_fields` records for the new version string).
    2.  Operator adds/defines visits (using `CDC\Flowchart::addVisitToFlowchart`), specifying `visit_name`, `visit_num`, timing, etc., all under the current DRAFT `flow_chart_version`.
    3.  For each visit, operator assigns `form_domain`s, specifying `item_order` and the `branch_code` (`__COMMON__` or specific like 'ArmA', 'ArmB') using `CDC\Flowchart::addFormToVisit`.
* **Operator Concern/Fear:** Effort in setting up multiple similar branches; ensuring branches are distinct and correctly configured.
* **System Aspect:** "Copy" functionality. `branch_code` on `cdc_flow_chart_item`. The `flow_chart_version` acts as the container for the entire branch-specific setup.

### Use Case 3.5: Publishing a Flowchart Version

* **Actor:** Study Designer/Admin (with appropriate permissions)
* **Goal:** Finalize a DRAFT `flow_chart_version` and make it available for active data collection.
* **Steps:**
    1.  Operator selects a DRAFT `flow_chart_version` (e.g., "PROT_V1-ArmA-DRAFT").
    2.  Operator initiates "Publish" action.
    3.  System may prompt for a "clean" version name (e.g., "PROT_V1.0-ArmA") or generate one.
    4.  System updates the status of this `flow_chart_version_string` to 'PUBLISHED' (e.g., in a `cdc_flowchart_versions_status` table).
    5.  System calls `CDC\Flowchart::setActiveFlowchartVersion` if this is to be the *currently active* version for new patient entries / visit scheduling for that branch.
* **Operator Concern/Fear:** Ensuring that a PUBLISHED configuration is locked and cannot be accidentally modified; clear distinction between DRAFT and live versions.
* **System Aspect:** Status management for `flow_chart_version`. Application logic enforces that PUBLISHED flowchart configurations (and their associated `cdc_form_fields`) are immutable *for that version string*.

### Use Case 3.6: Creating an Amendment (New Flowchart Version after Publication)

* **Actor:** Study Designer
* **Goal:** Modify a study's design after a flowchart version has been published and is in use.
* **Steps:**
    1.  Operator selects a PUBLISHED `flow_chart_version` to amend (e.g., "PROT_V1.0-ArmA").
    2.  Operator initiates "Create Amendment" or "Create New Version From This".
    3.  System prompts for a new DRAFT `flow_chart_version` name (e.g., "PROT_V1.1-ArmA-DRAFT").
    4.  System calls `CDC\Flowchart::copyFlowchartSetup` (or similar) to duplicate all `cdc_flow_chart`, `cdc_flow_chart_item`, and associated `cdc_form_fields` records from the source version to the new DRAFT version.
    5.  Operator now works on the new DRAFT version (Use Cases 3.3, 3.4).
    6.  Once ready, operator publishes the new DRAFT version (Use Case 3.5).
* **Key System Requirement:** Ensures that changes for an amendment are isolated to a new `flow_chart_version`, preserving the history and integrity of previously published versions.

## 4. Core Process: Patient Data Management (Clinical User, Data Manager)

### Use Case 4.1: Assigning/Updating Patient's Study Branch

* **Actor:** Clinical User / System (e.g., post-randomization)
* **Goal:** Record or update the patient's current treatment arm/branch.
* **Steps:**
    1.  Identify `studyId`, `bnxEntityId`, new `branchCode`.
    2.  System calls `CDC\ISF::assignPatientToBranch` (or a dedicated `CDC\Patient::assignBranch`).
* **System Aspect:** `cdc_patient_study_branch` table updated, ensuring only one branch is active.

### Use Case 4.2: Data Entry for a Patient Visit (ISF)

* **Actor:** Clinical User
* **Goal:** Enter/update data for a patient's scheduled or unscheduled visit.
* **Pre-conditions:** Study setup is PUBLISHED. Patient is assigned to a branch.
* **Steps:**
    1.  User selects Patient, Study, Visit (`visit_num`).
    2.  Backend (e.g., ISF endpoint) calls `CDC\ISF::getOrCreateISF`.
        * `ISF::getOrCreateISF` determines the `flow_chart_version` (active, published) and the `branch_code_actual` (from `cdc_patient_study_branch`).
        * Creates/retrieves `cdc_isf` record.
    3.  Backend calls `CDC\Flowchart::getFlowchartDetails` (passing `flow_chart_version`, `branch_code_actual`) to get the list of `form_domain`s expected for this visit/branch.
    4.  For each `form_domain`:
        * Backend calls `CDC\CRF::getFormSchema` (passing `studyId`, `flow_chart_version`, `form_domain`) to get the precise form structure.
        * Backend calls `CDC\FormInstance::getOrCreateFormInstance` (passing context including `isf_id`, `branch_code_actual`, `flow_chart_version`).
        * Backend calls `CDC\FormInstance::getFormData` to fetch existing data.
        * UI merges schema and data and renders the form.
    5.  User enters/modifies data and submits.
    6.  Backend (ISF endpoint) receives data for multiple forms (if applicable).
    7.  Backend calls `CDC\ISF::saveISFVisitData`, which iterates through submitted `form_domain`s:
        * For each, calls `CDC\FormInstance::saveFormInstanceData`.
        * `FormInstance::saveFormInstanceData` calls `bX\DataCaptureService::saveRecord` with context `['BNX_ENTITY_ID', 'FORM_DOMAIN']`.
        * `cdc_form_instance` is updated with `data_capture_context_group_id` and `flow_chart_version`, `branch_code_actual` are already set.
* **Operator Concern/Fear:** Data saved against wrong form version or wrong patient context; data loss; audit trail issues.
* **System Aspect:** `flow_chart_version` and `branch_code_actual` stored on `cdc_form_instance` ensure data is tied to the correct setup snapshot. DCS provides data audit.

### Use Case 4.3: Managing Data Queries (Data Manager, Clinical User)

* **Actor:** Data Manager (creates), Clinical User (responds)
* **Goal:** Identify, communicate, and resolve data discrepancies.
* **Steps:** (Simplified)
    1.  DM identifies issue with a specific field in a `cdc_form_instance`.
    2.  DM creates query using `CDC\Query::createQuery` (linking to `form_instance_id`, `field_name`).
    3.  Clinical User sees query, provides response using `CDC\Query::respondToQuery`.
    4.  DM reviews response, closes query using `CDC\Query::closeQuery`.
* **System Aspect:** `cdc_query` table links to `cdc_form_instance`.

## 5. Core Process: Monitoring and Audit

### Use Case 5.1: Reviewing "How a Configuration Looked Before" (Setup Audit)

* **Actor:** Data Manager, Auditor, Study Designer
* **Goal:** Understand the exact structure of visits and forms as they were for a specific historical (or current published) `flow_chart_version`.
* **Steps:**
    1.  Identify `studyId` and target `flow_chart_version`.
    2.  System calls `CDC\Flowchart::getFlowchartDetails` (passing `studyId`, `flow_chart_version`, and optionally a `branch_code` if viewing a specific branch's configuration). This shows visits and the `form_domain`s assigned.
    3.  For each `form_domain` listed:
        * System calls `CDC\CRF::getFormSchema` (passing `studyId`, `flow_chart_version`, `form_domain`). This shows the exact fields, order, sections, mandatory status, and attributes for that form *as defined for that `flow_chart_version`*.
* **Key System Requirement:** This reconstructs the setup precisely because `cdc_form_fields` is versioned by `flow_chart_version`.

## 6. Key Considerations for Operators (Summary)

* **Efficiency in Setup:** Ability to copy existing configurations (flowcharts, form blueprints) to reduce repetitive work.
* **Clarity of State:** Clear distinction between DRAFT and PUBLISHED configurations. Operators should not be able to accidentally modify a PUBLISHED/live setup.
* **Control over Versioning:** Use of meaningful "named versions" for flowcharts/protocols.
* **Auditability:** Complete traceability for both captured patient data AND changes to study configuration over time.
* **Flexibility for Complex Designs:** Robust support for branches and dynamic elements typical in clinical trials.