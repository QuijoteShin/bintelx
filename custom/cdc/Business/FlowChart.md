# `CDC\Flowchart` - Study Flowchart and Visit Management

**File:** `custom/cdc/Business/Flowchart.php`

## 1. Purpose

The `Flowchart` class is responsible for managing the **planned schedule of visits** and the **activities (primarily forms/domains) within those visits** for a clinical study. It interacts primarily with the `cdc_flow_chart` and `cdc_flow_chart_item` tables.

This class allows for:
* Defining visits within a specific, versioned flowchart for a study.
* Associating `form_domain`s (representing CRFs or other activities) to these planned visits, including their order and other visit-specific attributes.
* Retrieving the complete flowchart for a given study and version, which is essential for study setup validation, UI generation (e.g., showing the schedule of assessments), and guiding data entry expectations.
* Managing the active status of different flowchart versions.

## 2. Dependencies

* `bX\CONN`: For all database interactions.
* `bX\Log`: For logging operations and errors.
* `CDC\Study`: To validate study existence and retrieve `study_internal_id`.
* (Implicitly) `CDC\CRF`: While it doesn't call `CRF` methods directly, the `form_domain`s it manages are expected to have their structures defined via `CRF::addFormField`.

## 3. Database Tables

* **`cdc_flow_chart`**: Stores definitions for each visit within a specific `study_internal_id_ref` and `flow_chart_version`. Each row typically represents one planned visit.
* **`cdc_flow_chart_item`**: Stores the specific forms (`form_domain`) or activities planned for each entry in `cdc_flow_chart`, including their order within the visit.

## 4. Key Concepts

* **`flow_chart_version`**: A string identifier (e.g., "v1.0", "Protocol Amendment 2") that groups a complete set of visit definitions for a study under a specific protocol version.
* **Visit (`cdc_flow_chart` record)**: Represents a single planned encounter or timepoint in the study (e.g., "Screening", "Week 4 Visit"). Identified by `visit_num` or `visit_name` within a `flow_chart_version`.
* **Visit Item (`cdc_flow_chart_item` record)**: Represents a specific activity, usually a form identified by `form_domain`, that is scheduled to occur during a specific visit.

## 5. Core Static Methods (Proposed)

### `addVisitToFlowchart(string $studyId, string $flowchartVersion, array $visitDetails, string $actorUserId): array`

* **Purpose:** Adds a new planned visit to a specific flowchart version for a study. Creates a record in `cdc_flow_chart`.
* **Parameters:**
    * `$studyId` (string, **required**): The public ID of the study.
    * `$flowchartVersion` (string, **required**): The version identifier for this flowchart.
    * `$visitDetails` (array, **required**): Associative array with visit properties:
        * `'visit_name'` (string, **required**): Descriptive name (e.g., "Screening Visit").
        * `'visit_num'` (string, optional): Shorter identifier (e.g., "SCR", "V1"). If not provided, might be derived or set to `visit_name`.
        * `'order_num'` (int, optional, default: 0): Sequence of this visit.
        * `'day_nominal'` (int, optional): Nominal day of the visit.
        * `'day_min'` (int, optional): Minimum day for visit window.
        * `'day_max'` (int, optional): Maximum day for visit window.
        * `'description'` (string, optional): Further description.
        * `'is_active'` (bool, optional, default: true): If this visit definition is active.
    * `$actorUserId` (string, **required**): ID of the user performing the action.
* **Returns:** `['success' => bool, 'flow_chart_id' => int|null, 'message' => string]` (`flow_chart_id` is the PK of the newly created `cdc_flow_chart` record).

### `public static function addFormToVisit(int $flowChartId, string $formDomain, int $itemOrder, ?string $branchCode = null, array $options = [], string $actorUserId): array`

* **Purpose:** Links a `form_domain` (representing a CRF) to a specific planned visit (identified by `$flowChartId`). Creates a record in `cdc_flow_chart_item`.
* **Parameters:**
    * `$flowChartId` (int, **required**): The `flow_chart_id` (PK from `cdc_flow_chart`) of the visit to which this form is being added.
    * `$formDomain` (string, **required**): The identifier of the form/domain to add (e.g., 'VS', 'DM').
    * `$itemOrder` (int, **required**): The order of this form/item within the visit.
    * `$options` (array, optional): Associative array for `cdc_flow_chart_item` properties:
        * `'item_title'` (string, optional): User-friendly title for this item in this visit (e.g., "Vital Signs Collection"). Defaults to `formDomain`.
        * `'item_type'` (string, optional, default: 'FORM'): e.g., 'FORM', 'PROCEDURE'.
        * `'is_mandatory'` (bool, optional, default: true): If this form is mandatory for the visit.
        * `'details_json'` (string|array, optional): Visit-specific instructions or minor details (not for form field structure).
    * `$actorUserId` (string, **required**): ID of the user performing the action.
* **Returns:** `['success' => bool, 'flow_chart_item_id' => int|null, 'message' => string]`

### `getFlowchartDetails(string $studyId, string $flowchartVersion): array`

* **Purpose:** Retrieves the complete schedule of visits and the forms/items planned within each visit for a specific study and flowchart version.
* **Parameters:**
    * `$studyId` (string, **required**): The public ID of the study.
    * `$flowchartVersion` (string, **required**): The version of the flowchart to retrieve.
* **Returns:** `['success' => bool, 'flowchart' => array|null, 'message' => string]`
    * `flowchart`: An array of visit objects. Each visit object contains its details from `cdc_flow_chart` and a sub-array `items` containing details from `cdc_flow_chart_item`, ordered by `item_order`.
        ```json
        // Conceptual structure of the 'flowchart' array element
        {
            "flow_chart_id": 1,
            "visit_name": "Screening",
            "visit_num": "SCR",
            "order_num": 1,
            // ... other cdc_flow_chart fields ...
            "items": [
                {
                    "flow_chart_item_id": 101,
                    "form_domain": "DM",
                    "item_title": "Demographics",
                    "item_order": 10,
                    "is_mandatory": true,
                    // ... other cdc_flow_chart_item fields ...
                },
                {
                    "flow_chart_item_id": 102,
                    "form_domain": "VS",
                    "item_title": "Vital Signs",
                    "item_order": 20,
                    "is_mandatory": true,
                }
            ]
        }
        ```

### `setActiveFlowchartVersion(string $studyId, string $flowchartVersion, string $actorUserId): array`

* **Purpose:** Sets a specific `flowchartVersion` as the currently active one for a study. This typically involves setting `is_active = true` for all `cdc_flow_chart` records of this version/study and `is_active = false` for all others.
* **Parameters:**
    * `$studyId` (string, **required**): The public ID of the study.
    * `$flowchartVersion` (string, **required**): The flowchart version to activate.
    * `$actorUserId` (string, **required**): ID of the user performing the action.
* **Returns:** `['success' => bool, 'message' => string]`

## 6. Example Usage (Conceptual - During Study Setup)

```php
<?php
use CDC\Flowchart;
use CDC\Study; // Assume Study::getStudyDetails exists for validation

$actor = 'STUDY_DESIGNER';
$studyId = 'PROT-001';
$currentFlowchartVersion = 'v1.0';

// 1. Define a visit
$visitDetailsScreening = [
    'visit_name' => 'Screening Visit',
    'visit_num' => 'SCR',
    'order_num' => 1,
    'day_nominal' => -7,
    'day_min' => -21,
    'day_max' => -1
];
$addVisitResult = Flowchart::addVisitToFlowchart($studyId, $currentFlowchartVersion, $visitDetailsScreening, $actor);

if ($addVisitResult['success']) {
    $screeningVisitFlowChartId = $addVisitResult['flow_chart_id'];
    echo "Screening visit added. ID: $screeningVisitFlowChartId\n";

    // 2. Add forms/domains to this visit
    Flowchart::addFormToVisit($screeningVisitFlowChartId, 'DM', 10, ['item_title' => 'Demographics'], $actor);
    Flowchart::addFormToVisit($screeningVisitFlowChartId, 'MH', 20, ['item_title' => 'Medical History'], $actor);
    Flowchart::addFormToVisit($screeningVisitFlowChartId, 'VS', 30, ['item_title' => 'Vital Signs'], $actor);
    
    // ... Add other visits and their forms ...
    
    // 3. Set this flowchart version as active
    // Flowchart::setActiveFlowchartVersion($studyId, $currentFlowchartVersion, $actor);

    // 4. Retrieve and view the configured flowchart
    // $flowchartData = Flowchart::getFlowchartDetails($studyId, $currentFlowchartVersion);
    // if ($flowchartData['success']) {
    //     print_r($flowchartData['flowchart']);
    // }
} else {
    echo "Error adding visit: " . $addVisitResult['message'] . "\n";
}
```


