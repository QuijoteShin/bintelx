# `CDC\Study\Setup` - Study Configuration Orchestration

**File:** `custom/cdc/Business/Study/Setup.php`

## 1. Purpose

The `Setup` class, residing within the `CDC\Study` namespace, acts as an **orchestrator** for the configuration and setup processes related to a specific clinical study. While other classes (`Study`, `CRF`, `Flowchart`) manage their specific entities, `Setup` provides higher-level methods to execute multi-step configuration tasks, such as defining all the forms for a study or configuring a specific form by linking all its fields in the correct order. It primarily *uses* methods from other CDC business classes to achieve its goals.

## 2. Dependencies

* `bX\Log`: For detailed logging of the setup process.
* `bX\Profile`: To retrieve the acting user's ID (`account_id` or `profile_id`).
* `CDC\Study`: To validate the existence of the target study.
* `CDC\CRF`: To link CRF fields (`field_name`) to form domains (`form_domain`) within the study context.

## 3. Database Interaction

This class primarily interacts with the database *indirectly* by calling methods in `CDC\Study` and `CDC\CRF`. Its main role is workflow and orchestration, not direct data manipulation.

## 4. Core Static Methods

### `configureForm(string $studyId, array $data): array`

* **Purpose:** Configures a single form (`form_domain`) for a specific study. It takes a list of fields and their desired order/options and uses `CDC\CRF::addFormField` to create these links in the database (`cdc_form_fields`). This is a key step in defining *how* a form should look and behave for a particular study.
* **Parameters:**
    * `$studyId` (string, **required**): The public/protocol identifier for the study (e.g., 'PROT-001').
    * `$data` (array, **required**): An associative array containing the form configuration details:
        * `'form_domain'` (string, **required**): The identifier for the form to be configured (e.g., 'VS').
        * `'fields'` (array, **required**): An associative array where:
            * *Keys* are the `field_name`s (e.g., 'VSPERF', 'VSDTC').
            * *Values* are arrays containing configuration for that field within this form:
                * `'order'` (int, **required**): The display order for the field.
                * `'options'` (array, optional): An array passed directly to `CRF::addFormField` for `is_mandatory`, `attributes_override_json`, `section_name`, etc.
* **Returns:** `['success' => bool, 'message' => string]` - Summarizes the success or failure of configuring all provided fields for the form.

## 5. Example Usage

```php
<?php
use CDC\Study\Setup;

// Define the structure for the 'VS' form
$vsFields = [
    'VSPERF'        => ['order' => 10],
    'VSDTC'         => ['order' => 20],
    'VSORRES_SYSBP' => ['order' => 30, 'options' => ['section_name' => 'Blood Pressure']],
    'VSORRES_DIABP' => ['order' => 40, 'options' => ['section_name' => 'Blood Pressure']],
];

// Prepare the data packet
$vsConfigData = [
    "form_domain" => "VS", 
    "fields"      => $vsFields
];

// Configure the 'VS' form for study 'PROT-001'
$setupResult = Setup::configureForm('PROT-001', $vsConfigData);

// Check the result
if ($setupResult['success']) {
    \bX\Log::logInfo("VS Form setup completed for PROT-001.");
} else {
    \bX\Log::logError("VS Form setup failed for PROT-001: " . $setupResult['message']);
}

?>
```

```PHP
// Assuming bX\Profile::$profile_id is set, otherwise pass $actorId

$studyId = 'PROT-001';
$formDomainVS = 'VS';

$vsFields = [
    'VSPERF'        => ['order' => 10],
    'VSDTC'         => ['order' => 20],
    'VSORRES_SYSBP' => ['order' => 30, 'options' => ['section_name' => 'Blood Pressure', 'is_mandatory' => true]],
    'VSORRES_DIABP' => ['order' => 40, 'options' => ['section_name' => 'Blood Pressure', 'is_mandatory' => true]],
    'VSORRES_PULSE' => ['order' => 50, 'options' => ['section_name' => 'Pulse']],
    'VSORRES_TEMP'  => ['order' => 60, 'options' => ['section_name' => 'Temperature']],
    'VSPOS'         => ['order' => 70, 'options' => ['is_mandatory' => false]],
];

$dataToConfigure = [
    "form_domain" => $formDomainVS,
    "fields"      => $vsFields
];

$setupResult = \CDC\Setup::configureForm($studyId, $dataToConfigure);
print_r($setupResult);
```