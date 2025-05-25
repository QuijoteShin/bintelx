# `CDC\Flowchart` - Study Flowchart and Visit Schedule Management

**File:** `custom/cdc/Business/Flowchart.php`
**Namespace:** `CDC`

## 1. Purpose

The `Flowchart` class is responsible for managing the **entire lifecycle of a study's versioned schedule of assessments (flowcharts)**. This includes:

* Creating and versioning distinct flowchart configurations (`flow_chart_version_string`) for a study, managed via `cdc_flowchart_versions_status`.
* Defining the timeline of visits within each `flow_chart_version_string` by placing "Visit Definitions" (from `cdc_visit_definitions`) onto the schedule, creating `cdc_flow_chart` records.
* Assigning specific forms (`form_domain`s) to these visit placements, potentially making these assignments branch-specific (`branch_code` in `cdc_flow_chart_item`). The structure of these assigned forms is determined by `cdc_form_fields` for the corresponding `flow_chart_version_string`.
* Orchestrating the "DRAFT" -> "PUBLISHED" -> "ARCHIVED" lifecycle of flowchart versions.
* Providing functionality to copy an existing flowchart version's setup (visits, items, and associated form structures) as a basis for a new version or amendment.
* Retrieving the detailed structure of a specific flowchart version for UI rendering or operational use (e.g., by `ISF`).

This class ensures that the study setup is auditable, version-controlled, and that changes to a published protocol result in a new, distinct version, preserving historical accuracy.

## 2. Dependencies

* `bX\CONN`: For all database interactions.
* `bX\Log`: For logging operations and errors.
* `bX\Profile`: Used internally to retrieve the `actorUserId`.
* `CDC\Study`: To validate study existence and link flowcharts to `study_internal_id`.
* `CDC\VisitDefinitions` (Assumed Class/Methods): To validate `visit_code` and retrieve `visit_definition_id`.
* `CDC\CRF`: (Indirectly) The `form_domain`s placed on the flowchart are expected to have their detailed, versioned structures defined in `cdc_form_fields` (managed via `CRF::addFormField` which now includes `flow_chart_version`).

## 3. Database Tables Primarily Managed or Interacted With

* **`cdc_flowchart_versions_status`**: Manages the lifecycle (DRAFT, PUBLISHED, ARCHIVED) and identity of each `flow_chart_version_string` for a study.
* **`cdc_visit_definitions`**: Referenced to identify the *type* of visit being placed.
* **`cdc_flow_chart`**: Stores the specific placement (order, timing, overrides) of a `visit_definition_id` within a `flow_chart_version_string`.
* **`cdc_flow_chart_item`**: Stores which `form_domain`s (and for which `branch_code`) are associated with a specific `flow_chart_id` (a visit placement).
* **`cdc_form_fields`**: Read (conceptually) during copy operations to duplicate form structures for a new `flow_chart_version_string`. (Actual read might be implicit if copy logic queries this table).

## 4. Key Concepts

* **`flow_chart_version_string`**: The unique, operator-defined named version for an entire study setup configuration (e.g., "Protocol_v1.0", "Amendment2_DRAFT"). Its lifecycle is managed in `cdc_flowchart_versions_status`.
* **Visit Definition (`cdc_visit_definitions` record)**: A master definition of a *type* of visit (e.g., "Screening" with `visit_code`="SCR").
* **Visit Placement (`cdc_flow_chart` record)**: A specific instance of a `Visit Definition` placed onto the timeline of a `flow_chart_version_string`, identified by its own `flow_chart_id` (PK). It includes protocol-version-specific timing and order.
* **Visit Item (`cdc_flow_chart_item` record)**: A `form_domain` assigned to a `Visit Placement` (`flow_chart_id`) for a specific `branch_code`. The structure of this `form_domain` is fetched from `cdc_form_fields` using the corresponding `flow_chart_version_string`.
* **Immutable Published Versions**: Once a `flow_chart_version_string` is 'PUBLISHED', its associated configuration (visits in `cdc_flow_chart`, items in `cdc_flow_chart_item`, and form structures in `cdc_form_fields` for that version string) should be treated as immutable. Changes require creating a new DRAFT version.

## 5. Core Static Methods (Revised & Expanded)

*Actor ID for all methods creating/modifying data is obtained internally via `\bX\Profile`.*

### Flowchart Version Lifecycle Management

#### `createFlowchartVersion(string $studyId, string $newFlowchartVersionString, ?string $description = null, ?string $copyFromFlowchartVersionString = null): array`

* **Purpose:** Creates a new flowchart version entry in `cdc_flowchart_versions_status` with status 'DRAFT'. If `$copyFromFlowchartVersionString` is provided, it performs a deep copy of the entire setup (visits, visit items, and associated `cdc_form_fields` definitions) from the source version to the `newFlowchartVersionString`.
* **Parameters:**
    * `$studyId` (string, **required**).
    * `$newFlowchartVersionString` (string, **required**): The name for the new version.
    * `$description` (string, optional).
    * `$copyFromFlowchartVersionString` (string, optional): If provided, the setup from this version is copied.
* **Returns:** `['success' => bool, 'flowchart_version_status_id' => int|null, 'message' => string]`

#### `publishFlowchartVersion(string $studyId, string $draftFlowchartVersionString, ?string $finalPublishedVersionName = null): array`

* **Purpose:** Changes the status of a `flow_chart_version_string` in `cdc_flowchart_versions_status` from 'DRAFT' to 'PUBLISHED'. Optionally allows renaming the version string to a "clean" name upon publication. Handles deactivation of other 'PUBLISHED' versions if the system enforces only one active published version at a time for data entry initiation.
* **Parameters:**
    * `$studyId` (string, **required**).
    * `$draftFlowchartVersionString` (string, **required**): The DRAFT version to publish.
    * `$finalPublishedVersionName` (string, optional): If provided, the `flow_chart_version_string` is updated to this name.
* **Returns:** `['success' => bool, 'message' => string]`

#### `archiveFlowchartVersion(string $studyId, string $publishedFlowchartVersionString): array`

* **Purpose:** Changes the status of a 'PUBLISHED' `flow_chart_version_string` to 'ARCHIVED'. Archived versions are typically read-only and not used for new data entry.
* **Parameters:** `$studyId`, `$publishedFlowchartVersionString`.
* **Returns:** `['success' => bool, 'message' => string]`

#### `getFlowchartVersionStatusDetails(string $studyId, string $flowchartVersionString): array`

* **Purpose:** Retrieves details from `cdc_flowchart_versions_status`.
* **Returns:** `['success' => bool, 'details' => array|null, 'message' => string]`

#### `listFlowchartVersions(string $studyId, ?string $status = null): array`

* **Purpose:** Lists flowchart versions for a study, optionally filtered by status.
* **Returns:** `['success' => bool, 'versions' => array|null, 'message' => string]`

### Visit Placement Management (Operates on a 'DRAFT' `flow_chart_version_string`)

#### `addVisitToFlowchart(string $studyId, string $draftFlowchartVersionString, string $visitCode, int $orderNum, array $placementDetails = []): array`

* **Purpose:** Adds a `Visit Definition` (identified by `$visitCode`) to a DRAFT `flow_chart_version_string`. Creates a record in `cdc_flow_chart`.
* **Parameters:**
    * `$studyId` (string, **required**).
    * `$draftFlowchartVersionString` (string, **required**): Must reference a version in 'DRAFT' status.
    * `$visitCode` (string, **required**): The code from `cdc_visit_definitions`.
    * `$orderNum` (int, **required**): Order of this visit in this flowchart version.
    * `$placementDetails` (array, optional): Overrides for `day_nominal`, `day_min`, `day_max`, `visit_name_override`, `description_override`.
* **Returns:** `['success' => bool, 'flow_chart_id' => int|null, 'message' => string]` (PK from `cdc_flow_chart`).
* **Note:** Should handle idempotency using `INSERT ... ON DUPLICATE KEY UPDATE` based on `study_internal_id`, `draftFlowchartVersionString`, `visit_definition_id` (resolved from `visitCode`), `order_num`.

#### `updateVisitInFlowchart(int $flowChartId, array $placementDetails): array`

* **Purpose:** Modifies details of a visit placement (`cdc_flow_chart` record) within a DRAFT flowchart version.
* **Parameters:** `$flowChartId` (PK), `$placementDetails`.
* **Returns:** `['success' => bool, 'message' => string]`

#### `removeVisitFromFlowchart(int $flowChartId): array`

* **Purpose:** Removes a visit placement (and its associated `cdc_flow_chart_item`s) from a DRAFT flowchart version.
* **Returns:** `['success' => bool, 'message' => string]`

### Visit Item (Form Assignment) Management (Operates on a `flow_chart_id` from a DRAFT flowchart version)

#### `addFormToVisit(int $flowChartId, string $formDomain, int $itemOrder, ?string $branchCode = "__COMMON__", array $options = []): array`

* **Purpose:** Links a `form_domain` to a `Visit Placement` (`flowChartId`), for a specific `branch_code`. Creates/updates `cdc_flow_chart_item`. The `flowChartId` must belong to a DRAFT `flow_chart_version`.
* **Parameters:**
    * `$flowChartId` (int, **required**): PK from `cdc_flow_chart`.
    * `$formDomain` (string, **required**).
    * `$itemOrder` (int, **required**).
    * `$branchCode` (string, optional, default: "__COMMON__").
    * `$options` (array, optional): `item_title_override`, `item_type`, `is_mandatory`, `details_json`.
* **Returns:** `['success' => bool, 'flow_chart_item_id' => int|null, 'message' => string]`
* **Note:** Handles idempotency via `UNIQUE KEY (flow_chart_id, form_domain, branch_code)`.

#### `updateFormInVisit(int $flowChartItemId, int $itemOrder, ?string $branchCode = "__COMMON__", array $options = []): array`
* **Purpose:** Modifies an existing `cdc_flow_chart_item` within a DRAFT flowchart.
* **Returns:** `['success' => bool, 'message' => string]`

#### `removeFormFromVisit(int $flowChartItemId): array`
* **Purpose:** Removes a `cdc_flow_chart_item` from a visit within a DRAFT flowchart.
* **Returns:** `['success' => bool, 'message' => string]`

### Retrieval

#### `getFlowchartDetails(string $studyId, string $flowchartVersionString, ?string $targetBranchCode = null): array`

* **Purpose:** Retrieves the full schedule for a given `flowchartVersionString` (typically 'PUBLISHED' for data entry, 'DRAFT' for setup UI). Filters items by `targetBranchCode`.
* **Parameters:** `$studyId`, `$flowchartVersionString`, `$targetBranchCode` (optional).
* **Returns:** `['success' => bool, 'flowchart_details' => array|null, 'message' => string]`
    * `flowchart_details`: Array of visit placement objects (from `cdc_flow_chart`, joined with `cdc_visit_definitions`), each containing an `items` array (from `cdc_flow_chart_item`, filtered by branch). The structure of `items` should also contain enough information about the `form_domain` (like its defined title from `cdc_form_fields` for that `flowchartVersionString`).

## 6. Example Usage (Conceptual - Study Setup Lifecycle)

```php
// Assume CDC\Flowchart, CDC\Study, CDC\VisitDefinitions classes exist
// Actor ID is handled internally by the methods

$studyId = 'ONCO-007';

// 1. Create a new DRAFT flowchart version for the study
$versionResult = \CDC\Flowchart::createFlowchartVersion($studyId, "Protocol_V1-DRAFT", "Initial draft for protocol v1");
if (!$versionResult['success']) { exit("Failed to create draft version: " . $versionResult['message']); }
$draftVersion = "Protocol_V1-DRAFT";

// 2. Define visits (assuming visit_codes 'SCR', 'C1D1', 'C2D1' exist in cdc_visit_definitions for this study)
$scrVisit = \CDC\Flowchart::addVisitToFlowchart($studyId, $draftVersion, 'SCR', 10, ['day_nominal' => -7]);
$c1d1Visit = \CDC\Flowchart::addVisitToFlowchart($studyId, $draftVersion, 'C1D1', 20, ['day_nominal' => 1]);

if ($scrVisit['success'] && $c1d1Visit['success']) {
    $scrVisitFcId = $scrVisit['flow_chart_id'];
    $c1d1VisitFcId = $c1d1Visit['flow_chart_id'];

    // 3. Add forms to Screening visit
    \CDC\Flowchart::addFormToVisit($scrVisitFcId, 'DM', 10, '__COMMON__');
    \CDC\Flowchart::addFormToVisit($scrVisitFcId, 'MH', 20, '__COMMON__');
    \CDC\Flowchart::addFormToVisit($scrVisitFcId, 'ELIG_ARM_A', 30, 'ArmA'); // ArmA specific eligibility
    \CDC\Flowchart::addFormToVisit($scrVisitFcId, 'ELIG_ARM_B', 30, 'ArmB'); // ArmB specific eligibility

    // 4. Add forms to C1D1 visit
    \CDC\Flowchart::addFormToVisit($c1d1VisitFcId, 'VS', 10, '__COMMON__');
    \CDC\Flowchart::addFormToVisit($c1d1VisitFcId, 'TRT_A', 20, 'ArmA'); // ArmA treatment
    \CDC\Flowchart::addFormToVisit($c1d1VisitFcId, 'TRT_B', 20, 'ArmB'); // ArmB treatment
}

// 5. Operator reviews the setup for $draftVersion ... all looks good.
// Now, publish it with a clean name.
$publishResult = \CDC\Flowchart::publishFlowchartVersion($studyId, $draftVersion, "Protocol_v1.0");
if ($publishResult['success']) {
    \bX\Log::logInfo("Protocol_v1.0 is now PUBLISHED for study $studyId.");
    // UI for data entry would now use "Protocol_v1.0"
}

// --- LATER, AN AMENDMENT IS NEEDED ---

// 6. Create a new DRAFT version for amendment, copying from the published v1.0
$amendmentDraftVersion = "Protocol_v1.1-DRAFT";
$copyResult = \CDC\Flowchart::createFlowchartVersion($studyId, $amendmentDraftVersion, "Amendment 1 draft", "Protocol_v1.0");
if (!$copyResult['success']) { exit("Failed to copy for amendment: " . $copyResult['message']); }

// 7. Modify the $amendmentDraftVersion (e.g., add a new form to C1D1 for ArmA)
// First, find the flow_chart_id for C1D1 in this new draft version.
// (Conceptual: This requires querying cdc_flow_chart for studyId, $amendmentDraftVersion, and visit_definition_id for C1D1)
// $c1d1_v1_1_FcId = ... result of query ...
// \CDC\Flowchart::addFormToVisit($c1d1_v1_1_FcId, 'NEW_AE_FORM', 25, 'ArmA');

// 8. Publish the amendment
// $publishAmendmentResult = \CDC\Flowchart::publishFlowchartVersion($studyId, $amendmentDraftVersion, "Protocol_v1.1_Amd1");
```

---