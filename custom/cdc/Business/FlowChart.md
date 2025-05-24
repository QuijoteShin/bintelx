# `CDC\Flowchart` - Study Flowchart and Visit Management

**File:** `custom/cdc/Business/Flowchart.php`

## 1. Purpose

The `Flowchart` class is responsible for managing the **planned schedule of visits** and the **activities (primarily forms/domains, potentially branch-specific) within those visits** for a clinical study. It interacts primarily with the `cdc_flow_chart` and `cdc_flow_chart_item` tables.

This class allows for:
* Defining visits within a specific, versioned flowchart for a study.
* Associating `form_domain`s (representing CRFs) to these planned visits, specifying their order, and indicating if they are common to all study branches or specific to a particular `branch_code`.
* Retrieving the complete flowchart (schedule of assessments) for a given study, flowchart version, and optionally, a specific `branch_code`.
* Managing the active status of different flowchart versions.

## 2. Dependencies

* `bX\CONN`: For all database interactions.
* `bX\Log`: For logging operations and errors.
* `CDC\Study`: To validate study existence and retrieve `study_internal_id`.
* (Implicitly) `CDC\CRF`: The `form_domain`s managed are expected to have their internal structures defined via `CRF::addFormField` (in `cdc_form_fields`).

## 3. Database Tables

* **`cdc_flow_chart`**: Stores definitions for each visit within a specific `study_internal_id_ref` and `flow_chart_version`. Each row typically represents one planned visit.
* **`cdc_flow_chart_item`**: Stores the specific forms (`form_domain`) or activities planned for each entry in `cdc_flow_chart`. **Crucially, this table includes a `branch_code` column** to specify if an item is common to all branches or specific to one.

## 4. Key Concepts

* **`flow_chart_version`**: A string identifier (e.g., "v1.0", "Protocol Amendment 2") that groups a complete set of visit definitions for a study under a specific protocol version.
* **`branch_code`**: An identifier (e.g., "ArmA", "CohortX", or a special value like "__COMMON__" or `NULL` for items applicable to all branches) used within `cdc_flow_chart_item` to specify branch-level variations in forms per visit.
* **Visit (`cdc_flow_chart` record)**: Represents a single planned encounter or timepoint in the study (e.g., "Screening", "Week 4 Visit"). Identified by `visit_num` or `visit_name` within a `flow_chart_version`.
* **Visit Item (`cdc_flow_chart_item` record)**: Represents a specific activity, usually a form identified by `form_domain`, that is scheduled for a specific visit and `branch_code`.

## 5. Core Static Methods (Updated)

### `addVisitToFlowchart(string $studyId, string $flowchartVersion, array $visitDetails, string $actorUserId): array`

* **Purpose:** Adds a new planned visit to a specific flowchart version for a study. Creates a record in `cdc_flow_chart`. This visit definition is generally common across branches; branch-specific forms are defined later.
* **Parameters:**
    * `$studyId` (string, **required**): The public ID of the study.
    * `$flowchartVersion` (string, **required**): The version identifier for this flowchart.
    * `$visitDetails` (array, **required**): Associative array with visit properties:
        * `'visit_name'` (string, **required**): Descriptive name (e.g., "Screening Visit").
        * `'visit_num'` (string, **required**): Unique shorter identifier within the `flowchartVersion` (e.g., "SCR", "V1"). Essential for `UNIQUE KEY`.
        * `'order_num'` (int, optional, default: 0): Sequence of this visit.
        * `'day_nominal'` (int, optional): Nominal day of the visit.
        * `'day_min'` (int, optional): Minimum day for visit window.
        * `'day_max'` (int, optional): Maximum day for visit window.
        * `'description'` (string, optional): Further description.
        * `'is_active'` (bool, optional, default: true): If this visit definition is active.
    * `$actorUserId` (string, **required**): ID of the user performing the action.
* **Returns:** `['success' => bool, 'flow_chart_id' => int|null, 'message' => string]` (`flow_chart_id` is the PK of the newly created `cdc_flow_chart` record).
* **Note:** Should handle idempotency using `INSERT ... ON DUPLICATE KEY UPDATE` based on `study_internal_id_ref`, `flow_chart_version`, `visit_num`.

### `addFormToVisit(int $flowChartId, string $formDomain, int $itemOrder, ?string $branchCode = "__COMMON__", array $options = [], string $actorUserId): array`

* **Purpose:** Links a `form_domain` to a specific planned visit (identified by `$flowChartId`), associating it with a `branch_code` (or marking it as common to all). Creates/updates a record in `cdc_flow_chart_item`.
* **Parameters:**
    * `$flowChartId` (int, **required**): The `flow_chart_id` (PK from `cdc_flow_chart`) of the visit.
    * `$formDomain` (string, **required**): The identifier of the form/domain (e.g., 'VS', 'DM').
    * `$itemOrder` (int, **required**): The order of this form/item within the visit for the specified branch.
    * `$branchCode` (string, optional, default: "__COMMON__"): The specific branch this form applies to for this visit. A special value like "__COMMON__" (or `NULL` in DB) indicates it applies to all branches for this visit.
    * `$options` (array, optional): Associative array for `cdc_flow_chart_item` properties:
        * `'item_title'` (string, optional): User-friendly title. Defaults to `formDomain`.
        * `'item_type'` (string, optional, default: 'FORM'): e.g., 'FORM', 'PROCEDURE'.
        * `'is_mandatory'` (bool, optional, default: true): If this form is mandatory.
        * `'details_json'` (string|array, optional): Visit-specific instructions.
    * `$actorUserId` (string, **required**): ID of the user performing the action.
* **Returns:** `['success' => bool, 'flow_chart_item_id' => int|null, 'message' => string]`
* **Note:** Should handle idempotency using `INSERT ... ON DUPLICATE KEY UPDATE` based on `flow_chart_id_ref`, `form_domain`, `branch_code`.

### `getFlowchartDetails(string $studyId, string $flowchartVersion, ?string $targetBranchCode = null): array`

* **Purpose:** Retrieves the schedule of visits and the applicable forms/items for a specific study, flowchart version, and optionally, a target `branch_code`. If `$targetBranchCode` is provided, items specific to that branch AND common items are returned. If `null`, only common items might be returned, or behavior needs further definition (e.g., return all items for all branches, grouped).
* **Parameters:**
    * `$studyId` (string, **required**): The public ID of the study.
    * `$flowchartVersion` (string, **required**): The version of the flowchart.
    * `$targetBranchCode` (string, optional): The specific branch for which to retrieve the effective schedule. If not provided, might retrieve for a "common" or "all branches" view.
* **Returns:** `['success' => bool, 'flowchart' => array|null, 'message' => string]`
    * `flowchart`: An array of visit objects. Each visit object contains its details and an `items` sub-array. The `items` will be filtered based on `$targetBranchCode` (including items where `cdc_flow_chart_item.branch_code` matches `$targetBranchCode` OR is `'__COMMON__'`/`NULL`).
        ```json
        // Conceptual structure for a specific targetBranchCode
        {
            "flow_chart_id": 1,
            "visit_name": "Screening", // ... other cdc_flow_chart fields ...
            "items": [ // Filtered for targetBranchCode + __COMMON__
                {
                    "flow_chart_item_id": 101,
                    "form_domain": "DM",
                    "branch_code": "__COMMON__", // ... other fields ...
                },
                {
                    "flow_chart_item_id": 102,
                    "form_domain": "AE_ARM_A_SPECIFIC",
                    "branch_code": "ArmA", // This item only shows if targetBranchCode is 'ArmA'
                }
            ]
        }
        ```

### `setActiveFlowchartVersion(string $studyId, string $flowchartVersion, string $actorUserId): array`

* **Purpose:** Sets a specific `flowchartVersion` as active for a study (and deactivates others for the same study).
* **Parameters:**
    * `$studyId` (string, **required**).
    * `$flowchartVersion` (string, **required**).
    * `$actorUserId` (string, **required**).
* **Returns:** `['success' => bool, 'message' => string]`
* **Note:** Must be transactional.

## 6. Example Usage (Conceptual - During Study Setup with Branches)

```php
<?php
use CDC\Flowchart;

$actor = 'STUDY_DESIGNER';
$studyId = 'PROT-ONCO-001';
$currentVersion = 'v1.0-Draft';

// 1. Define common Screening Visit
$scrVisit = Flowchart::addVisitToFlowchart($studyId, $currentVersion, ['visit_name' => 'Screening', 'visit_num' => 'SCR'], $actor);
if ($scrVisit['success']) {
    $scrVisitId = $scrVisit['flow_chart_id'];
    // Common forms for Screening
    Flowchart::addFormToVisit($scrVisitId, 'DM', 10, '__COMMON__', [], $actor);
    Flowchart::addFormToVisit($scrVisitId, 'MH', 20, '__COMMON__', [], $actor);

    // Form specific to Arm A during Screening
    Flowchart::addFormToVisit($scrVisitId, 'ELIG_ARMA', 30, 'ArmA', [], $actor);
    // Form specific to Arm B during Screening
    Flowchart::addFormToVisit($scrVisitId, 'ELIG_ARMB', 30, 'ArmB', [], $actor); // Same order, different branch
}

// 2. Define Cycle 1 Day 1 Visit
$c1d1Visit = Flowchart::addVisitToFlowchart($studyId, $currentVersion, ['visit_name' => 'Cycle 1 Day 1', 'visit_num' => 'C1D1'], $actor);
if ($c1d1Visit['success']) {
    $c1d1VisitId = $c1d1Visit['flow_chart_id'];
    // Common forms
    Flowchart::addFormToVisit($c1d1VisitId, 'VS', 10, '__COMMON__', [], $actor);
    Flowchart::addFormToVisit($c1d1VisitId, 'AE', 20, '__COMMON__', [], $actor);

    // Treatment form for Arm A
    Flowchart::addFormToVisit($c1d1VisitId, 'TRT_A_DOSE', 30, 'ArmA', [], $actor);
    // Treatment form for Arm B
    Flowchart::addFormToVisit($c1d1VisitId, 'TRT_B_DOSE', 30, 'ArmB', [], $actor);
}

// To retrieve for a patient in ArmA:
// $armAFlowchart = Flowchart::getFlowchartDetails($studyId, $currentVersion, 'ArmA');
?>