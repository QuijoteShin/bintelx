# `CDC\Flowchart` - Study Flowchart and Visit Schedule Management

**File:** `custom/cdc/Business/Flowchart.php`
**Namespace:** `CDC`

## 1. Purpose

The `Flowchart` class is responsible for managing the **entire lifecycle of a study's versioned schedule of assessments (flowcharts)**. This includes:

* Creating and versioning distinct flowchart configurations (`flow_chart_version_string`) for a study, managed via the `cdc_flowchart_versions_status` table.
* Defining the timeline of visits within each `flow_chart_version_string` by placing "Visit Definitions" (from `cdc_visit_definitions`) onto the schedule, creating `cdc_flow_chart` records.
* Assigning specific forms (`form_domain`s) to these visit placements, making these assignments potentially branch-specific (`branch_code` in `cdc_flow_chart_item`). The structure of these assigned forms is determined by `cdc_form_fields` for the corresponding `flow_chart_version_string`.
* Orchestrating the "DRAFT" -> "PUBLISHED" -> "ARCHIVED" lifecycle of flowchart versions.
* Providing functionality to copy an existing flowchart version's entire setup (visits, items, and associated `cdc_form_fields` definitions) as a basis for a new version or amendment.
* Retrieving the detailed structure of a specific flowchart version for UI rendering or operational use (e.g., by `ISF`).

This class ensures that the study setup is auditable, version-controlled, and that changes to a published protocol result in a new, distinct version, preserving historical accuracy.

## 2. Dependencies

* `bX\CONN`: For all database interactions.
* `bX\Log`: For logging operations and errors.
* `bX\Profile`: Used internally to retrieve the `actorUserId`.
* `CDC\Study`: To validate study existence and link flowcharts to `study_internal_id`.
* `CDC\VisitDefinitions` (Conceptual or actual class to manage `cdc_visit_definitions`): To validate `visit_code` and retrieve `visit_definition_id`.
* `CDC\CRF`: (Indirectly) The `form_domain`s placed on the flowchart are expected to have their detailed, versioned structures defined in `cdc_form_fields` (managed via `CRF::addFormField` which now includes `flow_chart_version`). Methods within `Flowchart` that copy setup will need to interact with `cdc_form_fields`.

## 3. Database Tables Primarily Managed or Interacted With

* **`cdc_flowchart_versions_status`**: Manages the lifecycle (DRAFT, PUBLISHED, ARCHIVED) and identity of each `flow_chart_version_string` for a study.
* **`cdc_visit_definitions`**: Referenced to identify the *type* of visit being placed.
* **`cdc_flow_chart`**: Stores the specific placement (order, timing, overrides) of a `visit_definition_id` within a `flow_chart_version_string`.
* **`cdc_flow_chart_item`**: Stores which `form_domain`s (and for which `branch_code`) are associated with a specific `flow_chart_id` (a visit placement).
* **`cdc_form_fields`**: Records from this table (which are versioned by `flow_chart_version`) are conceptually duplicated when a flowchart version is copied.
* `cdc_study`.

## 4. Key Concepts

* **`flow_chart_version_string`**: The unique, operator-defined named version for an entire study setup configuration (e.g., "Protocol_v1.0", "Amendment2_DRAFT"). Its lifecycle (`DRAFT`, `PUBLISHED`, `ARCHIVED`) is managed in `cdc_flowchart_versions_status`.
* **Visit Definition (`cdc_visit_definitions` record)**: A master definition of a *type* of visit (e.g., "Screening" with `visit_code`="SCR").
* **Visit Placement (`cdc_flow_chart` record)**: A specific instance of a `Visit Definition` placed onto the timeline of a `flow_chart_version_string`, identified by its own `flow_chart_id` (PK). It includes protocol-version-specific timing and order.
* **Visit Item (`cdc_flow_chart_item` record)**: A `form_domain` assigned to a `Visit Placement` (`flow_chart_id`) for a specific `branch_code`. The structure of this `form_domain` is fetched from `cdc_form_fields` using the corresponding `flow_chart_version_string`.
* **Immutable Published Versions**: Once a `flow_chart_version_string` is 'PUBLISHED', its associated configuration (visits in `cdc_flow_chart`, items in `cdc_flow_chart_item`, and form structures in `cdc_form_fields` for that version string) must be treated as immutable. Changes require creating a new DRAFT version.

## 5. Core Static Methods

*Actor ID for all methods creating/modifying data is obtained internally via `\bX\Profile`.*

### Flowchart Version Lifecycle Management

#### `createFlowchartVersion(string $studyId, string $newFlowchartVersionString, ?string $description = null, ?string $copyFromFlowchartVersionString = null): array`

* **Purpose:** Creates a new flowchart version entry in `cdc_flowchart_versions_status` (default status 'DRAFT'). If `$copyFromFlowchartVersionString` is provided, it performs a **deep copy** of the entire setup:
  * `cdc_flow_chart` records (visit placements).
  * `cdc_flow_chart_item` records (form assignments to visits/branches).
  * Relevant `cdc_form_fields` records (form structures) associated with the source version are duplicated for the `newFlowchartVersionString`.
* **Parameters:**
  * `$studyId` (string, **required**).
  * `$newFlowchartVersionString` (string, **required**): The name for the new version.
  * `$description` (string, optional).
  * `$copyFromFlowchartVersionString` (string, optional): If provided, the setup from this version is copied.
* **Returns:** `['success' => bool, 'flowchart_version_status_id' => int|null, 'message' => string]`

#### `publishFlowchartVersion(string $studyId, string $draftFlowchartVersionString, ?string $finalPublishedVersionName = null): array`

* **Purpose:** Changes the status of a `flow_chart_version_string` in `cdc_flowchart_versions_status` from 'DRAFT' to 'PUBLISHED'. Optionally allows renaming the version string (e.g., from "Amd1-DRAFT" to "Protocol_v1.1_Amd1"). May handle deactivation of other 'PUBLISHED' versions based on study rules (e.g., only one 'PUBLISHED' version active for new data entry at a time).
* **Parameters:**
  * `$studyId` (string, **required**).
  * `$draftFlowchartVersionString` (string, **required**): The DRAFT version to publish.
  * `$finalPublishedVersionName` (string, optional): If provided, `flow_chart_version_string` is updated to this name.
* **Returns:** `['success' => bool, 'message' => string]`

#### `archiveFlowchartVersion(string $studyId, string $publishedFlowchartVersionString): array`

* **Purpose:** Changes the status of a 'PUBLISHED' `flow_chart_version_string` to 'ARCHIVED' in `cdc_flowchart_versions_status`. Archived versions are read-only for setup and typically not used for initiating new data entry.
* **Parameters:** `$studyId`, `$publishedFlowchartVersionString`.
* **Returns:** `['success' => bool, 'message' => string]`

#### `getFlowchartVersionStatusDetails(string $studyId, string $flowchartVersionString): array`

* **Purpose:** Retrieves details for a specific `flow_chart_version_string` from `cdc_flowchart_versions_status`.
* **Returns:** `['success' => bool, 'details' => array|null, 'message' => string]`

#### `listFlowchartVersions(string $studyId, ?string $status = null): array`

* **Purpose:** Lists all `flow_chart_version_string`s for a study, optionally filtered by `status`.
* **Returns:** `['success' => bool, 'versions' => array|null, 'message' => string]` (array of version status details).

### Visit Placement Management (Operates on a 'DRAFT' `flow_chart_version_string`)

#### `addVisitToFlowchart(string $studyId, string $draftFlowchartVersionString, string $visitCode, int $orderNum, array $placementDetails = []): array`

* **Purpose:** Adds a `Visit Definition` (identified by `$visitCode` from `cdc_visit_definitions`) to a DRAFT `draftFlowchartVersionString`. Creates a record in `cdc_flow_chart`.
* **Parameters:**
  * `$studyId` (string, **required**).
  * `$draftFlowchartVersionString` (string, **required**): Must reference a version with status 'DRAFT'.
  * `$visitCode` (string, **required**): The `visit_code` from `cdc_visit_definitions`.
  * `$orderNum` (int, **required**): Order of this visit placement in this flowchart version.
  * `$placementDetails` (array, optional): Associative array for `cdc_flow_chart` overrides: `day_nominal`, `day_min`, `day_max`, `visit_name_override`, `description_override`.
* **Returns:** `['success' => bool, 'flow_chart_id' => int|null, 'message' => string]` (PK from `cdc_flow_chart`).
* **Note:** Handles idempotency based on `study_internal_id`, `draftFlowchartVersionString`, `visit_definition_id` (from `visitCode`), `order_num`.

#### `updateVisitInFlowchart(int $flowChartId, array $placementDetails): array`

* **Purpose:** Modifies details of a visit placement (`cdc_flow_chart` record). The `flow_chart_version` associated with `$flowChartId` must be 'DRAFT'.
* **Parameters:** `$flowChartId` (PK from `cdc_flow_chart`), `$placementDetails`.
* **Returns:** `['success' => bool, 'message' => string]`

#### `removeVisitFromFlowchart(int $flowChartId): array`

* **Purpose:** Removes a visit placement (and its associated `cdc_flow_chart_item`s) from `cdc_flow_chart`. The `flow_chart_version` must be 'DRAFT'.
* **Returns:** `['success' => bool, 'message' => string]`

### Visit Item (Form Assignment) Management (Operates on a `flow_chart_id` from a DRAFT flowchart version)

#### `addFormToVisit(int $flowChartId, string $formDomain, int $itemOrder, ?string $branchCode = "__COMMON__", array $options = []): array`

* **Purpose:** Links a `form_domain` to a `Visit Placement` (`flowChartId`), for a specific `branch_code`. Creates/updates `cdc_flow_chart_item`. The `flow_chart_version` associated with `$flowChartId` must be 'DRAFT'. The structure for `$formDomain` must exist in `cdc_form_fields` for the same `flow_chart_version`.
* **Parameters:**
  * `$flowChartId` (int, **required**): PK from `cdc_flow_chart`.
  * `$formDomain` (string, **required**).
  * `$itemOrder` (int, **required**).
  * `$branchCode` (string, optional, default: "__COMMON__").
  * `$options` (array, optional): `item_title_override`, `item_type`, `is_mandatory`, `details_json`.
* **Returns:** `['success' => bool, 'flow_chart_item_id' => int|null, 'message' => string]`
* **Note:** Handles idempotency via `UNIQUE KEY (flow_chart_id, form_domain, branch_code)`.

#### `updateFormInVisit(int $flowChartItemId, int $itemOrder, ?string $branchCode = "__COMMON__", array $options = []): array`
* **Purpose:** Modifies an existing `cdc_flow_chart_item`. Its parent `flow_chart_version` must be 'DRAFT'.
* **Returns:** `['success' => bool, 'message' => string]`

#### `removeFormFromVisit(int $flowChartItemId): array`
* **Purpose:** Removes a `cdc_flow_chart_item`. Its parent `flow_chart_version` must be 'DRAFT'.
* **Returns:** `['success' => bool, 'message' => string]`

### Retrieval

#### `getFlowchartDetails(string $studyId, string $flowchartVersionString, ?string $targetBranchCode = null): array`

* **Purpose:** Retrieves the full schedule (visits and their items) for a given `flowchartVersionString` (typically 'PUBLISHED' or 'DRAFT'). Filters items by `targetBranchCode` (showing specific branch items + `__COMMON__` items).
* **Parameters:** `$studyId`, `$flowchartVersionString`, `$targetBranchCode` (optional).
* **Returns:** `['success' => bool, 'flowchart_details' => array|null, 'message' => string]`
  * `flowchart_details`: Array of visit placement objects. Each contains its details (from `cdc_flow_chart` joined with `cdc_visit_definitions`) and an `items` array (from `cdc_flow_chart_item`).
      ```json
      // Conceptual structure for a specific targetBranchCode
      {
          "flow_chart_id": 1, // PK of the cdc_flow_chart record
          "flow_chart_version": "Protocol_v1.0",
          "visit_definition_id": 10,
          "visit_code": "SCR",
          "visit_name": "Screening Visit", // from cdc_visit_definitions or override
          "order_num": 1, // ... other cdc_flow_chart fields ...
          "items": [ // Filtered for targetBranchCode + __COMMON__
              {
                  "flow_chart_item_id": 101,
                  "form_domain": "DM",
                  "item_title_override": "Demographics Data",
                  "branch_code": "__COMMON__", 
                  "item_order": 10,
                  "is_mandatory": true
                  // ... other cdc_flow_chart_item fields ...
              },
              {
                  "flow_chart_item_id": 102,
                  "form_domain": "ELIG_ARMA",
                  "item_title_override": "Eligibility for Arm A",
                  "branch_code": "ArmA", // This item only shows if targetBranchCode is 'ArmA'
                  "item_order": 20,
                  "is_mandatory": true
              }
          ]
      }
      ```

## 6. Example Usage (Conceptual - Study Setup Lifecycle)

```php
// Assume CDC\Flowchart, CDC\Study, CDC\VisitDefinitions classes exist
// Actor ID is handled internally

$studyId = 'ONCO-007'; // Public Study ID

// 1. Admin defines Visit Types for the study (using CDC\VisitDefinitions methods - not shown here)
// Example: VisitDefinition for "Screening" (visit_code: "SCR") created.
// Example: VisitDefinition for "Cycle 1 Day 1" (visit_code: "C1D1") created.

// 2. Create a new DRAFT flowchart version
$versionResult = \CDC\Flowchart::createFlowchartVersion($studyId, "PROT_V1-DRAFT", "Initial draft for protocol v1");
if (!$versionResult['success']) { exit("Failed: " . $versionResult['message']); }
$draftVersion = "PROT_V1-DRAFT";

// 3. Add visit placements to this DRAFT version
$scrVisit = \CDC\Flowchart::addVisitToFlowchart($studyId, $draftVersion, 'SCR', 10, ['day_nominal' => -7]);
$c1d1Visit = \CDC\Flowchart::addVisitToFlowchart($studyId, $draftVersion, 'C1D1', 20, ['day_nominal' => 1]);

if ($scrVisit['success'] && $c1d1Visit['success']) {
    $scrVisitFcId = $scrVisit['flow_chart_id'];
    $c1d1VisitFcId = $c1d1Visit['flow_chart_id'];

    // 4. Add forms to Screening visit (structure for 'DM', 'ELIG_ARMA' defined in cdc_form_fields for $draftVersion)
    \CDC\Flowchart::addFormToVisit($scrVisitFcId, 'DM', 10, '__COMMON__');
    \CDC\Flowchart::addFormToVisit($scrVisitFcId, 'ELIG_ARMA', 20, 'ArmA');
    \CDC\Flowchart::addFormToVisit($scrVisitFcId, 'ELIG_ARMB', 20, 'ArmB');

    // 5. Add forms to C1D1 visit
    \CDC\Flowchart::addFormToVisit($c1d1VisitFcId, 'VS', 10, '__COMMON__');
    \CDC\Flowchart::addFormToVisit($c1d1VisitFcId, 'TRT_A', 20, 'ArmA');
    \CDC\Flowchart::addFormToVisit($c1d1VisitFcId, 'TRT_B', 20, 'ArmB');
}

// 6. Operator reviews. Now, publish it.
$publishResult = \CDC\Flowchart::publishFlowchartVersion($studyId, $draftVersion, "Protocol_v1.0");
if ($publishResult['success']) {
    \bX\Log::logInfo("Protocol_v1.0 is PUBLISHED for study $studyId.");
}

// --- LATER, AN AMENDMENT ---
// 7. Create new DRAFT for amendment, copying from published v1.0
$amendmentDraft = "Protocol_v1.1-DRAFT";
$copyResult = \CDC\Flowchart::createFlowchartVersion($studyId, $amendmentDraft, "Amendment 1", "Protocol_v1.0");
// Now modify $amendmentDraft using add/update/remove methods...
// Then publish $amendmentDraft as "Protocol_v1.1_Amd1".
```


---