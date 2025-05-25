# `CDC\CRF` - Clinical Form Management

**File:** `custom/cdc/Business/CRF.php`
**Namespace:** `CDC`

## 1. Purpose

The `CRF` (Case Report Form) class serves as a crucial intermediary layer within the CDC (Clinical Data Capture) module. Its primary responsibility is to manage the **definition and structure of clinical forms** by bridging the gap between:

1.  The **CDC Application's high-level concepts** (Studies, Flowcharts with specific `flow_chart_version`s, Domains, Forms, and field order).
2.  The **`bX\DataCaptureService`'s low-level storage** of individual data field definitions (`CaptureDefinition`).

It provides functionalities to define individual CRF fields within the 'CDC_APP' context (via `defineCRFField`). Crucially, it links these fields to a specific study's `form_domain` **for a particular `flow_chart_version`**, defining their order and presentation attributes (via `addFormField` which populates `cdc_form_fields`). Its key retrieval method, `getFormSchema`, allows the UI/Frontend to dynamically render forms based on these versioned definitions.

## 2. Dependencies

* `bX\CONN`: For database interactions with CDC metadata tables (primarily `cdc_form_fields`).
* `bX\Log`: For logging errors and informational messages.
* `bX\Profile`: Used internally by methods to capture the `actorUserId`.
* `bX\DataCaptureService`: For defining and retrieving base field definitions (`capture_definition`).
* `CDC\Study`: For validating study existence and retrieving `study_internal_id`.
* **CDC Database Tables:**
  * `cdc_study`: To link form field structures to studies.
  * `capture_definition`: To store/retrieve base field definitions (managed via `DataCaptureService`).
  * `cdc_form_fields` (**Required & Versioned**): This table is populated by `addFormField` and read by `getFormSchema`. It links a `form_domain` to multiple `field_name`s *within a study **and for a specific `flow_chart_version`***, defining their `item_order`, `section_name`, `is_mandatory`, and `attributes_override_json`.

## 3. Key Concepts

* **CRF Field / `CaptureDefinition`**: A single, uniquely named data point (e.g., 'VSORRES_SYSBP') defined in `DataCaptureService` under the 'CDC_APP' application. It has a base `data_type`, `label`, and `attributes_json`. Managed by `defineCRFField`.
* **Form Domain (`form_domain`)**: A logical grouping of CRF Fields (e.g., 'VS').
* **Form Field Link (`cdc_form_fields` record)**: A record linking a specific `field_name` to a `form_domain` for a `study_id` **and `flow_chart_version`**, defining its `item_order`, `section_name`, etc. Managed by `addFormField`.
* **Form Schema**: The complete structural definition of a `form_domain` for a specific `study_id` **and `flow_chart_version`**, as retrieved by `getFormSchema`. This ensures that the form structure presented is exactly as defined for that version of the protocol.

## 4. Core Static Methods

*Actor ID for all methods creating/modifying data is obtained internally via `\bX\Profile`.*

### `defineCRFField(string $fieldName, string $dataType, string $label, array $attributes = []): array`

* **Purpose:** Creates or updates a base field definition (`CaptureDefinition`) in `DataCaptureService` for the 'CDC_APP'.
* **Parameters:**
  * `$fieldName` (string, **required**).
  * `$dataType` (string, **required**): ('VARCHAR', 'NUMERIC', 'DATE', 'BOOLEAN').
  * `$label` (string, **required**).
  * `$attributes` (array, optional): For `attributes_json`.
* **Returns:** `['success' => bool, 'definition_id' => int|null, 'message' => string]`.

### `addFormField(string $studyId, string $flowchartVersion, string $formDomain, string $fieldName, int $itemOrder, array $options = []): array`

* **Purpose:** Links an existing CRF Field (`fieldName`) to a `formDomain` for a particular `studyId` **and `flowchartVersion`**. Populates `cdc_form_fields`.
* **Parameters:**
  * `$studyId` (string, **required**).
  * `$flowchartVersion` (string, **required**): The specific flowchart/protocol version this form field definition applies to.
  * `$formDomain` (string, **required**).
  * `$fieldName` (string, **required**).
  * `$itemOrder` (int, **required**).
  * `$options` (array, optional):
    * `'is_mandatory'` (bool, optional, default: true).
    * `'attributes_override_json'` (string|array, optional).
    * `'section_name'` (string, optional).
* **Returns:** `['success' => bool, 'form_field_id' => int|null, 'message' => string]` (PK from `cdc_form_fields`).

### `getFormSchema(string $studyId, string $flowchartVersion, string $formDomain): array`

* **Purpose:** Retrieves the complete, ordered schema for a `formDomain` within a `studyId`, **as defined for the specified `flowchartVersion`**. Queries `cdc_form_fields` (filtered by `flowchartVersion`) and `capture_definition`.
* **Parameters:**
  * `$studyId` (string, **required**).
  * `$flowchartVersion` (string, **required**): The specific flowchart/protocol version.
  * `$formDomain` (string, **required**).
* **Returns:** `['success' => bool, 'schema' => array|null, 'message' => string]`
  * `schema`: Ordered array of field definitions, reflecting the structure for that `flowchartVersion`. Example JSON structure:
      ```json
      [
        {
          "field_name": "VSPERF",
          "item_order": 10,
          "label": "Did VS Occur?",
          "data_type": "VARCHAR",
          "is_mandatory": true,
          "attributes": { "control_type": "RADIO_GROUP", "datalist_source": "s1:Y=Yes|N=No", "...more attributes..." },
          "section_name": "Visit Details"
        },
        {
          "field_name": "VSDTC",
          "item_order": 20,
          "label": "VS Date/Time",
          "data_type": "VARCHAR",
          "is_mandatory": true,
          "attributes": { "control_type": "DATETIME_PICKER", "...more attributes..." },
          "section_name": "Visit Details"
        }
      ]
      ```

## 5. Example Usage (Study Setup)

```php
// Assume necessary classes (CDC\CRF, CDC\Study) are available
// use CDC\CRF;
// use CDC\Study;

// Actor ID would typically be fetched from \bX\Profile by the CRF methods
// $actor = 'SETUP_ADMIN'; // For conceptual clarity in example

$studyId = 'PROT-001';
$formDomainVS = 'VS';
$currentFlowchartVersion = 'PROT_V1.0-DRAFT'; // Working on this draft version

// 1. Ensure Study Exists (Conceptual - actual call might differ)
// if (!\CDC\Study::getStudyDetails($studyId)['success']) {
//     \CDC\Study::createStudy(['study_id' => $studyId, 'study_title' => 'Test Study']);
// }

// 2. Define Base CRF Fields (actor obtained internally by defineCRFField)
\CDC\CRF::defineCRFField('VSPERF', 'VARCHAR', 'Did VS Occur?', ['control_type' => 'RADIO_GROUP', 'datalist_source' => 's1:Y=Yes|N=No']);
\CDC\CRF::defineCRFField('VSDTC', 'VARCHAR', 'VS Date/Time', ['control_type' => 'DATETIME_PICKER']);
\CDC\CRF::defineCRFField('VSORRES_SYSBP', 'NUMERIC', 'Systolic BP (Result)', ['min' => 0, 'max' => 400]);

// 3. Link Fields to the 'VS' Form for 'PROT-001' AND for this specific flowchartVersion
// (actor obtained internally by addFormField)
\CDC\CRF::addFormField($studyId, $currentFlowchartVersion, $formDomainVS, 'VSPERF', 10, ['section_name' => 'Visit Information', 'is_mandatory' => true]);
\CDC\CRF::addFormField($studyId, $currentFlowchartVersion, $formDomainVS, 'VSDTC', 20, ['section_name' => 'Visit Information']);
\CDC\CRF::addFormField($studyId, $currentFlowchartVersion, $formDomainVS, 'VSORRES_SYSBP', 30, ['section_name' => 'Vital Signs Results']);

// 4. Retrieve the Schema (e.g., for UI generation or validation logic)
$schemaResult = \CDC\CRF::getFormSchema($studyId, $currentFlowchartVersion, $formDomainVS);

if ($schemaResult['success']) {
    // Example: UI Rendering Logic (Conceptual)
    echo "<h1>Form: $formDomainVS for Study: $studyId (Version: $currentFlowchartVersion)</h1>";
    echo "<form>";
    $currentSection = null;
    foreach ($schemaResult['schema'] as $field) {
        if ($currentSection !== $field['section_name'] && !empty($field['section_name'])) {
            if ($currentSection !== null) echo "</fieldset>"; // Close previous section
            echo "<fieldset><legend>" . htmlspecialchars($field['section_name']) . "</legend>";
            $currentSection = $field['section_name'];
        }
        echo "<div>";
        echo "<label for='" . htmlspecialchars($field['field_name']) . "'>" . htmlspecialchars($field['label']) . ($field['is_mandatory'] ? ' *' : '') . "</label><br/>";
        // --- Actual UI rendering would be more complex, based on data_type and attributes ---
        echo "<input type='text' id='" . htmlspecialchars($field['field_name']) . "' name='" . htmlspecialchars($field['field_name']) . "' />";
        echo "</div>";
    }
    if ($currentSection !== null) echo "</fieldset>"; // Close last section
    echo "</form>";
} else {
    echo "Error retrieving schema: " . $schemaResult['message'];
}
```


## 6. Common Workflows Involving CRF and Data Services

### 6.1. Displaying a Form with Pre-filled Data

To display a form (e.g., 'VS' for patient 'PXYZ007' in study 'PROT-001', under flowchart version 'v1.0_Main') with any previously saved data:

1.  **Determine Context:** From UI/request: `studyId`, `bnxEntityId`, `visitNumActual`, `formDomain`.
2.  **Get Active Configuration:**
  * Determine `currentFlowchartVersion` (e.g., "v1.0_Main") and `currentBranchCodeActual` (e.g., "ArmA") for the patient & visit. This logic typically resides in the `ISF` or controller layer, possibly using `CDC\Study` or `CDC\Flowchart` helper methods.
3.  **Get Form Schema for the Active Configuration:**
    ```php
    // Assuming $studyId, $currentFlowchartVersion, $formDomain are known
    $schemaResult = \CDC\CRF::getFormSchema($studyId, $currentFlowchartVersion, $formDomain);
    $schema = $schemaResult['success'] ? $schemaResult['schema'] : [];
    if (empty($schema)) { 
        // Handle error: schema for this version/domain not found or empty
        // \bX\Log::logError("Schema not found for $studyId, $currentFlowchartVersion, $formDomain");
        // return appropriate error to UI;
    }
    ```

4.  **Get Saved Data from `DataCaptureService`:**
    ```php
    // Assuming $schema is populated, $bnxEntityId and $formDomain are known
    $applicationName = \CDC\CRF::CDC_APPLICATION_NAME; 
    $contextKeyValues = [
        'BNX_ENTITY_ID' => $bnxEntityId, // Target patient
        'FORM_DOMAIN'   => $formDomain   // Target form domain
    ];
    $fieldNames = array_column($schema, 'field_name');

    $dataResult = \bX\DataCaptureService::getRecord(
        $applicationName,
        $contextKeyValues,
        $fieldNames 
    );
    $dataValues = $dataResult['success'] ? $dataResult['data'] : [];
    ```

5.  **Merge Schema and Data for UI Rendering:**
    ```php
    // Assuming $schema and $dataValues are populated
    $formToRender = [];
    foreach ($schema as $fieldSchema) {
        $fieldName = $fieldSchema['field_name'];
        $value = null; 
        if (isset($dataValues[$fieldName]) && array_key_exists('value', $dataValues[$fieldName])) {
            $value = $dataValues[$fieldName]['value'];
        }
        $fieldSchema['value'] = $value; 
        $formToRender[] = $fieldSchema;
    }
    // Now $formToRender contains the ordered schema with pre-filled values
    // This array is passed to the UI templating engine.
    ```

### 6.2. Saving Form Data

When a user submits form data from the UI (typically as JSON):

1.  **Example Data Sent from UI to Backend Endpoint (e.g., `isf.endpoint.php`):**
    ```json
    {
      "studyId": "PROT-001",
      "bnxEntityId": "PXYZ007",
      "visitNumActual": "SCREENING", 
      "formDomain": "VS",
      "flowChartVersion": "v1.0_Main", 
      "branchCodeActual": "ArmA",      
      "formData": {
        "VSPERF": "Y",
        "VSDTC": "2025-05-23T21:30:00Z",
        "VSORRES_SYSBP": 128,
        "VSORRES_DIABP": 85
      }
    }
    ```

2.  **Backend Endpoint Logic (Conceptual - within an ISF or FormInstance context):**
    ```php
    // Assume $receivedData contains the parsed JSON from the request body
    // Assume $actorUserId is obtained internally, e.g., from \bX\Profile::$account_id
    
    // Step 1: (Typically done by ISF/FormInstance manager - CDC\FormInstance.php)
    // Get or Create cdc_form_instance. This step is crucial for linking and status management.
    // It records $receivedData['flowChartVersion'] and $receivedData['branchCodeActual'] on the instance.
    /*
    $formInstanceResult = \CDC\FormInstance::getOrCreateFormInstance(
        $receivedData['studyId'],
        $receivedData['bnxEntityId'],
        $receivedData['visitNumActual'],
        $receivedData['formDomain'],
        $receivedData['flowChartVersion'],
        $receivedData['branchCodeActual'] 
        // actorUserId is internal
    );
    if (!$formInstanceResult['success']) { /* Handle error */ }
    $formInstanceId = $formInstanceResult['form_instance_id'];
    */

    // Step 2: Prepare context and data for DataCaptureService
    $contextKeyValues = [
        'BNX_ENTITY_ID' => $receivedData['bnxEntityId'],
        'FORM_DOMAIN'   => $receivedData['formDomain']
    ];

    $fieldsDataForDCS = [];
    if (is_array($receivedData['formData'])) {
        foreach ($receivedData['formData'] as $fieldName => $value) {
            $fieldsDataForDCS[] = [
                'field_name' => $fieldName,
                'value'      => $value
                // Optionally add 'reason', 'eventType' if provided from UI (GCP)
            ];
        }
    }
    
    $changeReason = "Data entry/update for form " . $receivedData['formDomain'];
    $actorUserIdForDCS = \bX\Profile::$account_id ?? 'UNKNOWN_USER'; // Example

    // Step 3: Call DataCaptureService to save data
    $saveResult = \bX\DataCaptureService::saveRecord(
        \CDC\CRF::CDC_APPLICATION_NAME, 
        $contextKeyValues,
        $fieldsDataForDCS,
        $actorUserIdForDCS, 
        $changeReason
    );

    // Step 4: Link and Respond 
    // (This logic would typically be within CDC\FormInstance::saveFormInstanceData or CDC\ISF methods)
    if ($saveResult['success']) {
        // Update cdc_form_instance with $saveResult['context_group_id']
        // Example: \CDC\FormInstance::updateDCSLink($formInstanceId, $saveResult['context_group_id']);
        
        // Optionally update form instance status (e.g., DRAFT -> OPEN)
        // \CDC\FormInstance::updateFormInstanceStatus($formInstanceId, 'OPEN');

        // Return success to UI: echo json_encode(['success' => true, 'message' => 'Data saved.']);
    } else {
        // Log error: \bX\Log::logError("Failed to save DCS data: " . $saveResult['message']);
        // Return error to UI: echo json_encode(['success' => false, 'message' => $saveResult['message']]);
    }
    ```

## Summary of Data Flow Responsibilities

* **Study Setup Time (Defining the "What" and "How"):**
  * `CRF::defineCRFField` -> Interacts with `DataCaptureService` to define base field metadata.
  * `CRF::addFormField` (now taking `$flowchartVersion`) -> Populates `cdc_form_fields` (which now includes `flow_chart_version`) to define how a base field is used within a specific study's `form_domain` for a particular protocol version.
  * `Flowchart` methods -> Populate `cdc_flow_chart` & `cdc_flow_chart_item` to define *when* and *for which branch* a `form_domain` is expected within a `flow_chart_version`.
* **Data Entry Time (Capturing the "Instance Data"):**
  * UI/Endpoint uses `Flowchart::getFlowchartDetails` (with `studyId`, `flowchartVersion`, `branchCode`) to know which `form_domain`s are expected for a visit/branch.
  * UI/Endpoint uses `CRF::getFormSchema` (with `studyId`, `flowchartVersion`, `form_domain`) to get the correct *versioned structure* of the form to display.
  * UI/Endpoint uses `DataCaptureService::getRecord` (often via `FormInstance::getFormData`) to pre-fill existing data.
  * User submits new/updated data.
  * Endpoint (likely via `FormInstance::saveFormInstanceData` or `ISF` methods) calls `DataCaptureService::saveRecord` to store the actual patient data.
  * Endpoint/Business Logic updates `cdc_form_instance`, ensuring it records the `context_group_id` from `DataCaptureService` and the `flow_chart_version` & `branch_code_actual` active at the time of capture.