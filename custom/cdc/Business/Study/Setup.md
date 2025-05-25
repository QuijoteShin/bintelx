# `CDC\Study\Setup` - Study Configuration Orchestration

**File:** `custom/cdc/Business/Study/Setup.php`
**Namespace:** `CDC\Study` (o `CDC` si prefieres una estructura más plana para `Setup`)

## 1. Purpose

The `Setup` class, residing within the `CDC\Study` (or `CDC`) namespace, acts as an **orchestrator** for the configuration and setup processes related to a specific clinical study. While other classes (`Study`, `CRF`, `Flowchart`) manage their specific entities, `Setup` provides higher-level methods to execute multi-step configuration tasks. Its primary current function is to configure the detailed structure of a form (`form_domain`) for a specific study **and a specific `flow_chart_version`** by linking all its fields in the correct order, thereby populating `cdc_form_fields`. It primarily *uses* methods from other CDC business classes.

## 2. Dependencies

* `bX\Log`: For detailed logging of the setup process.
* `bX\Profile`: Used internally to retrieve the `actorUserId`.
* `CDC\Study`: To validate the existence of the target study.
* `CDC\CRF`: To link CRF fields (`field_name`) to form domains (`form_domain`) within the study context, **for a specific `flow_chart_version`**.
* `CDC\Flowchart`: (Potentially) To ensure the provided `flow_chart_version` is in a 'DRAFT' status before allowing form configuration.

## 3. Database Interaction

This class primarily interacts with the database *indirectly* by calling methods in `CDC\Study` and `CDC\CRF`. Its main role is workflow and orchestration, not direct data manipulation.

## 4. Core Static Methods

*Actor ID for methods creating/modifying data is obtained internally via `\bX\Profile`.*

### `configureForm(string $studyId, string $flowchartVersion, string $formDomain, array $fieldsToConfigure): array`

* **Purpose:** Configures the detailed field structure for a single `form_domain` within a specific `studyId` **and for a given `flowchartVersion`**. It iterates through a list of fields, calling `CDC\CRF::addFormField` for each to populate `cdc_form_fields` specific to that `flowchartVersion`. This method should typically be used when the target `flowchartVersion` is in a 'DRAFT' state.
* **Parameters:**
    * `$studyId` (string, **required**): The public/protocol identifier for the study.
    * `$flowchartVersion` (string, **required**): The specific flowchart/protocol version for which this form structure is being defined.
    * `$formDomain` (string, **required**): The identifier for the form being configured (e.g., 'VS').
    * `$fieldsToConfigure` (array, **required**): An associative array where:
        * *Keys* are the `field_name`s (e.g., 'VSPERF', 'VSDTC').
        * *Values* are arrays containing configuration for that field within this form:
            * `'item_order'` (int, **required**): The display order for the field.
            * `'options'` (array, optional): An array passed directly to `CRF::addFormField` for `is_mandatory`, `attributes_override_json`, `section_name`, etc.
* **Returns:** `['success' => bool, 'message' => string, 'details' => array]`
    * `details`: An array containing results for each field processed, e.g., `['fieldName' => 'VSPERF', 'success' => true, 'form_field_id' => 123]`.
* **Internal Logic Example:**
    1.  Validate `$studyId` (using `CDC\Study::getStudyDetails`).
    2.  (Recommended) Validate `$flowchartVersion` status (e.g., ensure it's 'DRAFT' via `CDC\Flowchart::getFlowchartVersionStatusDetails`).
    3.  Loop through `$fieldsToConfigure`:
        * Call `\CDC\CRF::addFormField($studyId, $flowchartVersion, $formDomain, $fieldName, $fieldConfig['item_order'], $fieldConfig['options'] ?? [])`.
        * Collect results.

## 5. Example Usage

```php
// Assume necessary classes are available
// use CDC\Study\Setup;
// use bX\Profile;
// use bX\Log;

// Assume \bX\Profile::$account_id is set for the actor

$studyId = 'PROT-001';
$draftFlowchartVersion = 'PROT_V1.0-DRAFT'; // Configuring for this DRAFT version
$formDomainVS = 'VS';

$vsFieldsToConfigure = [
    'VSPERF'        => ['item_order' => 10, 'options' => ['section_name' => 'Visit Details', 'is_mandatory' => true]],
    'VSDTC'         => ['item_order' => 20, 'options' => ['section_name' => 'Visit Details']],
    'VSORRES_SYSBP' => ['item_order' => 30, 'options' => ['section_name' => 'Blood Pressure Results']],
    'VSORRES_DIABP' => ['item_order' => 40, 'options' => ['section_name' => 'Blood Pressure Results']],
];

// Configure the 'VS' form structure for study 'PROT-001' and version 'PROT_V1.0-DRAFT'
$setupResult = \CDC\Study\Setup::configureForm($studyId, $draftFlowchartVersion, $formDomainVS, $vsFieldsToConfigure);

if ($setupResult['success']) {
    \bX\Log::logInfo("VS Form structure configured successfully for study $studyId, version $draftFlowchartVersion.");
    // print_r($setupResult['details']);
} else {
    \bX\Log::logError("VS Form structure configuration failed for study $studyId, version $draftFlowchartVersion: " . $setupResult['message']);
}
```

---

