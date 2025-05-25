# CDC API Endpoints (Conceptual)

## 1. Introduction

This document outlines the conceptual API endpoints for the CDC (Clinical Data Capture) module. These endpoints expose the functionalities of the CDC Business Layer classes (`Study`, `CRF`, `Flowchart`, `ISF`, `FormInstance`, `Query`, `AuditTrail`) to be consumed by a frontend application or other services.

All endpoints should adhere to RESTful principles where applicable, use JSON for request and response bodies, and implement proper authentication and authorization (details of which are managed by the Bintelx framework). Standard HTTP status codes should be used to indicate outcomes.

## 2. General Considerations

* **Authentication/Authorization:** Handled by Bintelx. Endpoints assume an authenticated user context (`actorUserId` derived from `bX\Profile`).
* **Error Handling:** Return JSON responses with `{"success": false, "message": "Error description", "details": [...]}`.
* **Base Path:** All CDC endpoints could be prefixed, e.g., `/api/cdc/v1_`. and end with `*endpoint.php`

## 3. Study Management Endpoints

Corresponds to `CDC\Study` and `CDC\Study\Setup` functionalities.

### `POST /studies`
* **Purpose:** Create a new clinical study.
* **Request Body:** JSON matching parameters for `CDC\Study::createStudy($studyDetails)`.
* **Response:** JSON matching return of `CDC\Study::createStudy`.

### `GET /studies/{studyId}`
* **Purpose:** Get details of a specific study.
* **Path Parameter:** `studyId` (public ID).
* **Response:** JSON matching return of `CDC\Study::getStudyDetails`.

### `PUT /studies/{studyId}/status`
* **Purpose:** Update the status of a study.
* **Path Parameter:** `studyId`.
* **Request Body:** `{"newStatus": "ACTIVE"}`.
* **Response:** JSON matching return of `CDC\Study::updateStudyStatus`.

### `POST /studies/{studyId}/setup/crf-field`
* **Purpose:** Define a base CRF field in DataCaptureService ('CDC_APP').
* **Path Parameter:** `studyId` (for context, though field is global to CDC_APP).
* **Request Body:** JSON matching parameters for `CDC\CRF::defineCRFField($fieldName, $dataType, $label, $attributes)`.
* **Response:** JSON matching return of `CDC\CRF::defineCRFField`.

### `POST /studies/{studyId}/flowchart-versions/{flowchartVersion}/form-domains/{formDomain}/fields`
* **Purpose:** Add a field to a specific form domain structure for a given study and flowchart version.
* **Path Parameters:** `studyId`, `flowchartVersion`, `formDomain`.
* **Request Body:** JSON matching parameters for `CDC\CRF::addFormField($fieldName, $itemOrder, $options)`. (e.g., `{"fieldName": "VSPOS", "itemOrder": 80, "options": {"is_mandatory": false}}`)
* **Response:** JSON matching return of `CDC\CRF::addFormField`.

### `GET /studies/{studyId}/flowchart-versions/{flowchartVersion}/form-domains/{formDomain}/schema`
* **Purpose:** Get the versioned schema of a specific form domain.
* **Path Parameters:** `studyId`, `flowchartVersion`, `formDomain`.
* **Response:** JSON matching return of `CDC\CRF::getFormSchema`.

### `POST /studies/{studyId}/setup/configure-form`
* **Purpose:** Configure multiple fields for a form domain within a study and flowchart version (orchestrated by `CDC\Study\Setup`).
* **Path Parameter:** `studyId`.
* **Request Body:** JSON matching `$data` parameter for `CDC\Study\Setup::configureForm($flowchartVersion, $data)`. Example:
    ```json
    {
        "flowchartVersion": "PROT_V1-DRAFT",
        "formDomain": "VS",
        "fields": {
            "VSPERF": {"order": 10, "options": {"section_name":"General"}},
            "VSDTC": {"order": 20, "options": {"section_name":"General"}}
        }
    }
    ```
* **Response:** JSON matching return of `CDC\Study\Setup::configureForm`.


## 4. Flowchart Management Endpoints

Corresponds to `CDC\Flowchart` functionalities.

### `POST /studies/{studyId}/flowchart-versions`
* **Purpose:** Create a new flowchart version (DRAFT).
* **Path Parameter:** `studyId`.
* **Request Body:** `{"newFlowchartVersionString": "...", "description": "...", "copyFromFlowchartVersionString": "..." (optional)}`.
* **Response:** JSON matching `CDC\Flowchart::createFlowchartVersion`.

### `PUT /studies/{studyId}/flowchart-versions/{flowchartVersionString}/publish`
* **Purpose:** Publish a DRAFT flowchart version.
* **Path Parameters:** `studyId`, `flowchartVersionString` (the DRAFT version).
* **Request Body:** `{"finalPublishedVersionName": "..." (optional)}`.
* **Response:** JSON matching `CDC\Flowchart::publishFlowchartVersion`.

### `GET /studies/{studyId}/flowchart-versions/{flowchartVersionString}`
* **Purpose:** Get details of a flowchart version's status and description.
* **Response:** JSON matching `CDC\Flowchart::getFlowchartVersionStatusDetails`.

### `GET /studies/{studyId}/flowchart-versions`
* **Purpose:** List flowchart versions for a study.
* **Query Parameter:** `?status=DRAFT` (optional).
* **Response:** JSON matching `CDC\Flowchart::listFlowchartVersions`.

### `POST /studies/{studyId}/flowchart-versions/{draftFlowchartVersionString}/visits`
* **Purpose:** Add a visit (by `visitCode`) to a DRAFT flowchart version.
* **Path Parameters:** `studyId`, `draftFlowchartVersionString`.
* **Request Body:** `{"visitCode": "SCR", "orderNum": 10, "placementDetails": {"day_nominal": -7, ...}}`.
* **Response:** JSON matching `CDC\Flowchart::addVisitToFlowchart`.

### `POST /flowchart-visits/{flowChartId}/forms`
* **Purpose:** Add a form to a specific visit placement (within a DRAFT flowchart).
* **Path Parameter:** `flowChartId` (PK from `cdc_flow_chart`).
* **Request Body:** `{"formDomain": "DM", "itemOrder": 10, "branchCode": "__COMMON__", "options": {...}}`.
* **Response:** JSON matching `CDC\Flowchart::addFormToVisit`.

### `GET /studies/{studyId}/flowchart-versions/{flowchartVersionString}/details`
* **Purpose:** Get the detailed structure of a flowchart version.
* **Path Parameters:** `studyId`, `flowchartVersionString`.
* **Query Parameter:** `?branchCode=ArmA` (optional).
* **Response:** JSON matching `CDC\Flowchart::getFlowchartDetails`.

## 5. ISF (Visit Event) & Data Entry Endpoints

Corresponds to `CDC\ISF` and `CDC\FormInstance` functionalities.

### `POST /isfs/get-or-create`
* **Purpose:** Get an existing or create a new ISF for a patient visit.
* **Request Body:** `{"studyId": "...", "bnxEntityId": "...", "visitCode": "...", "flowchartVersionActual": "...", "explicitBranchCodeActual": "..." (optional), ...}`.
* **Response:** JSON matching `CDC\ISF::getOrCreateISF`.

### `GET /isfs/{isfId}`
* **Purpose:** Get details of an ISF and its form instances.
* **Path Parameter:** `isfId`.
* **Response:** JSON matching `CDC\ISF::getISFDetails`.

### `POST /isfs/{isfId}/data`
* **Purpose:** Save data for multiple forms within an ISF.
* **Path Parameter:** `isfId`.
* **Request Body:** `{"formsData": {"VS": {"VSPOS": "Sitting", ...}, "AE": {...}}, "changeReason": "..."}`.
* **Response:** JSON matching `CDC\ISF::saveISFVisitData`.

### `PUT /isfs/{isfId}/status`
* **Purpose:** Update the status of an ISF.
* **Path Parameter:** `isfId`.
* **Request Body:** `{"newStatus": "COMPLETED", "options": {...}}`.
* **Response:** JSON matching `CDC\ISF::updateISFStatus`.

### `POST /patient-branches/assign`
* **Purpose:** Assign a patient to a study branch.
* **Request Body:** `{"studyId": "...", "bnxEntityId": "...", "branchCode": "..."}`.
* **Response:** JSON matching `CDC\ISF::assignPatientToBranch`.

### `GET /studies/{studyId}/patients/{bnxEntityId}/active-branch`
* **Purpose:** Get the patient's active branch for a study.
* **Response:** JSON matching `CDC\ISF::getPatientActiveBranch`.

### `GET /form-instances/{formInstanceId}/data`
* **Purpose:** Get data for a specific form instance from DCS.
* **Path Parameter:** `formInstanceId`.
* **Query Parameter:** `?fieldNames=FIELD1,FIELD2` (optional).
* **Response:** JSON matching `CDC\FormInstance::getFormData`.

### `PUT /form-instances/{formInstanceId}/status`
* **Purpose:** Update status of a single form instance.
* **Request Body:** `{"newStatus": "FINALIZED", "options": {...}}`.
* **Response:** JSON matching `CDC\FormInstance::updateFormInstanceStatus`.

## 6. Query Management Endpoints

Corresponds to `CDC\Query` functionalities.

### `POST /form-instances/{formInstanceId}/queries`
* **Purpose:** Create a query on a field within a form instance.
* **Request Body:** `{"fieldName": "...", "queryText": "..."}`.
* **Response:** JSON matching `CDC\Query::createQuery`.

### `GET /form-instances/{formInstanceId}/queries`
* **Purpose:** List queries for a form instance.
* **Query Parameter:** `?status=OPEN` (optional).
* **Response:** JSON matching `CDC\Query::getQueriesForFormInstance`.

### `GET /queries/{queryId}`
* **Purpose:** Get details of a specific query.
* **Response:** JSON matching `CDC\Query::getQueryDetails`.

### `POST /queries/{queryId}/response`
* **Purpose:** Add a response to a query.
* **Request Body:** `{"responseText": "..."}`.
* **Response:** JSON matching `CDC\Query::addResponseToQuery`.

### `POST /queries/{queryId}/resolve`
* **Purpose:** Resolve a query.
* **Request Body:** `{"resolutionText": "..."}`.
* **Response:** JSON matching `CDC\Query::resolveQuery`.
  *(Similar endpoints for `closeQuery`, `cancelQuery`)*

## 7. Audit Trail Endpoints

Corresponds to `CDC\AuditTrail` functionalities.

### `GET /audit-trails/field`
* **Purpose:** Get audit trail for a field by direct context.
* **Query Parameters:** `?bnxEntityId=...&formDomain=...&fieldName=...`.
* **Response:** JSON matching `CDC\AuditTrail::getFieldAuditTrail`.

### `GET /form-instances/{formInstanceId}/fields/{fieldName}/audit-trail`
* **Purpose:** Get audit trail for a field within a form instance.
* **Response:** JSON matching `CDC\AuditTrail::getFieldAuditTrailByFormInstance`.

### `GET /form-instances/{formInstanceId}/audit-trail`
* **Purpose:** Get all audit trails for a form instance.
* **Response:** JSON matching `CDC\AuditTrail::getFormInstanceAuditTrail`.
