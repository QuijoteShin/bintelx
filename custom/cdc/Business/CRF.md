# `CDC\CRF` - Clinical Form Management

**File:** `custom/cdc/Business/CRF.php`

## 1. Purpose

The `CRF` (Case Report Form) class serves as a crucial intermediary layer within the CDC (Clinical Data Capture) module. Its primary responsibility is to manage the **definition and structure of clinical forms** by bridging the gap between:

1.  The **CDC Application's high-level concepts** (Studies, Flowcharts, Domains, Forms, and field order).
2.  The **`bX\DataCaptureService`'s low-level storage** of individual data field definitions (`CaptureDefinition`).

It provides functionalities to define individual CRF fields within the 'CDC_APP' context and, most importantly, to retrieve the complete, ordered **schema** for a specific clinical form or domain as it applies to a particular study. This allows the UI/Frontend to dynamically render forms and ensures data capture adheres to the study's specific design.

## 2. Dependencies

* `bX\CONN`: For database interactions with CDC metadata tables.
* `bX\Log`: For logging errors and informational messages.
* `bX\DataCaptureService`: For defining and retrieving field definitions.
* `CDC\Study`: For validating study existence and retrieving `study_internal_id`.
* **CDC Database Tables:**
    * `cdc_study`: To link forms/fields to studies.
    * `capture_definition`: To store/retrieve base field definitions (via DCS).
    * `cdc_form_fields` ( **Hypothetical/Required** ): A table to link a `form_domain` to multiple `field_name`s *within a study*, defining their `item_order` and other form-specific attributes (like `is_mandatory`, `attributes_json_override`). *This table is essential for `getFormSchema` to function as described.*
    * `cdc_flow_chart` / `cdc_flow_chart_item`: May be consulted to determine which form/version applies in a specific visit context (though `getFormSchema` might focus on the *definition* rather than the *application*).

## 3. Key Concepts

* **CRF Field / `CaptureDefinition`**: A single, uniquely named data point (e.g., 'VSORRES_SYSBP') defined in `DataCaptureService` under the 'CDC_APP' application. It has a base `data_type`, `label`, and `attributes_json`.
* **Form Domain (`form_domain`)**: A logical grouping of CRF Fields, often aligned with CDISC domains (e.g., 'VS', 'DM', 'AE'). It acts as the primary identifier for a "form".
* **Form Schema**: The complete structural definition of a `form_domain` for a specific study. It includes the list of its `field_name`s, their `CaptureDefinition` details (label, type, attributes), and their specific **display order** and other form-context attributes (like 'mandatory').

## 4. Core Static Methods

### `defineCRFField(string $fieldName, string $dataType, string $label, array $attributes = []): array`
`$actorProfileId` Should be captured from `\bx\Profile::$account_id` and not by parameters
* **Purpose:** Creates or updates a base field definition (`CaptureDefinition`) specifically for the 'CDC_APP'. This acts as a simplified wrapper around `DataCaptureService::defineCaptureField`.
* **Parameters:**
    * `$fieldName` (string, **required**): The unique, CDISC-like name for the field (e.g., 'VSORRES_SYSBP').
    * `$dataType` (string, **required**): The base data type ('VARCHAR', 'NUMERIC', 'DATE', 'BOOLEAN').
    * `$label` (string, **required**): The user-friendly label for the field.
    * `$attributes` (array, optional): An associative array for `attributes_json` (description, UI hints, validation, etc.).
    * `$actorProfileId` (string, **required**): ID of the user performing the action.
* **Returns:** `['success' => bool, 'definition_id' => int|null, 'message' => string]` - Mirrors the return from `DataCaptureService`.

### `addFormField(string $studyId, string $formDomain, string $fieldName, int $itemOrder, array $options = []): array`
`$actorProfileId` Should be captured from `\bx\Profile::$account_id` and not by parameters
* **Purpose:** Links an existing CRF Field (`fieldName`) to a specific Form Domain (`formDomain`) for a particular Study (`studyId`), defining its order and any form-specific overrides. Populates the `cdc_form_fields` table.
* **Parameters:**
    * `$studyId` (string, **required**): The public ID of the study.
    * `$data[form_domain` (string, **required**): The identifier for the form/domain (e.g., 'VS').
    * `$data[form_label]` (string, **required**): The public label for the form/section/title (e.g., 'Vital Signs').
* `$data[field_name]` (string, **required**): The `fieldName` of the CRF Field to add.
  * `$data[field_id]` (string, **required**): The original `fieldId` to bring attributes if exist.
  * `$data[item_order]` (int, **required**): The sequence number for this field within the form.
  * `$data[options]` (array, optional): Associative array with additional link-specific data:
      * `'is_mandatory'` (bool, optional, default: true): If the field is required in this form.
      * `'attributes_override_json'` (string|array, optional): JSON/Array to *override or extend* base attributes for this form context.
      * `'section_name'` (string, optional): A sub-grouping within the form.
  * `$actorProfileId` (string, **required**): ID of the user performing the action.
* **Returns:** `['success' => bool, 'form_field_id' => int|null, 'message' => string]`

### `getFormSchema(string $studyId, string $formDomain): array`

* **Purpose:** Retrieves the complete, ordered schema for a specified `formDomain` within the context of a given `studyId`. This is the primary method used by the frontend to render forms.
* **Parameters:**
    * `$studyId` (string, **required**): The public ID of the study.
    * `$formDomain` (string, **required**): The identifier for the form/domain.
* **Returns:** `['success' => bool, 'schema' => array|null, 'message' => string]`
    * `schema`: An **ordered** array. Each element represents a field:

```JSON
[
  {
    "field_name": "VSPERF",
    "item_order": 10,
    "label": "Did VS Occur?",
    "data_type": "VARCHAR",
    "is_mandatory": true,
    "attributes": { "control_type": "RADIO_GROUP", "datalist_source": "s1:Y=Yes|N=No", ... },
    "section_name": "Visit Details"
  },
  {
    "field_name": "VSDTC",
    "item_order": 20,
    "label": "VS Date/Time",
    "data_type": "VARCHAR",
    "is_mandatory": true,
    "attributes": { "control_type": "DATETIME_PICKER", ... },
    "section_name": "Visit Details"
  },
  ...
]
```

## Example Usage
```PHP
<?php
use CDC\CRF;
use CDC\Study;

$actor = 'SETUP_ADMIN';
$studyId = 'PROT-001';
$formDomainVS = 'VS';

// 1. Ensure Study Exists (using Study class)
// Study::createStudy([...], $actor);

// 2. Define Base CRF Fields
CRF::defineCRFField('VSPERF', 'VARCHAR', 'Did VS Occur?', ['control_type' => 'RADIO_GROUP', 'datalist_source' => 's1:Y=Yes|N=No'], $actor);
CRF::defineCRFField('VSDTC', 'VARCHAR', 'VS Date/Time', ['control_type' => 'DATETIME_PICKER'], $actor);
CRF::defineCRFField('VSORRES_SYSBP', 'NUMERIC', 'Systolic BP (Result)', ['min' => 0, 'max' => 400], $actor);

// 3. Link Fields to the 'VS' Form for 'PROT-001', defining order
CRF::addFormField($studyId, $formDomainVS, 'VSPERF', 10, [], $actor);
CRF::addFormField($studyId, $formDomainVS, 'VSDTC', 20, [], $actor);
CRF::addFormField($studyId, $formDomainVS, 'VSORRES_SYSBP', 30, [], $actor);

// 4. Retrieve the Schema to build the UI
$schemaResult = CRF::getFormSchema($studyId, $formDomainVS);

if ($schemaResult['success']) {
    echo "<h1>Form: $formDomainVS</h1>";
    echo "<form>";
    foreach ($schemaResult['schema'] as $field) {
        echo "<div>";
        echo "<label>" . htmlspecialchars($field['label']) . ($field['is_mandatory'] ? ' *' : '') . "</label><br/>";
        // --- Logic to render input based on data_type and attributes ---
        echo "<input type='text' name='" . htmlspecialchars($field['field_name']) . "' />";
        echo "</div>";
    }
    echo "</form>";
} else {
    echo "Error retrieving schema: " . $schemaResult['message'];
}

?>
```

---

##  cómo obtener un formulario con los datos ya cargados que un usuario guardó previamente?

```php
$schemaResult = CRF::getFormSchema('PROT-001', 'VS');
$schema = $schemaResult['success'] ? $schemaResult['schema'] : [];

# obtener los datos
$applicationName = 'CDC_APP';
$contextKeyValues = [
    'BNX_ENTITY_ID' => 'PXYZ007',
    'FORM_DOMAIN' => 'VS'
];
// Necesitas la lista de field_names del schema
$fieldNames = array_column($schema, 'field_name');

$dataResult = \bX\DataCaptureService::getRecord(
    $applicationName,
    $contextKeyValues,
    $fieldNames
);
$dataValues = $dataResult['success'] ? $dataResult['data'] : [];
```

## Fusionar Schema y Datos
```php
$formToRender = [];
foreach ($schema as $fieldSchema) {
    $fieldName = $fieldSchema['field_name'];
    // Busca el valor correspondiente en $dataValues
    $value = $dataValues[$fieldName]['value'] ?? null; // Usar null si no hay dato guardado

    // Añade el valor al schema del campo
    $fieldSchema['value'] = $value;
    $formToRender[] = $fieldSchema;
}
```

## Exemplo de envio de datos desde UI
```JSON
{
  "studyId": "PROT-001",
  "bnxEntityId": "PXYZ007",
  "visitNum": "SCREENING",
  "formDomain": "VS",
  "formData": {
    "VSPERF": "Y",
    "VSDTC": "2025-05-23T21:30:00",
    "VSORRES_SYSBP": 128,
    "VSORRES_DIABP": 85
  }
}
```
### Backend (Endpoint - ej. isf.endpoint.php): Recibe y Procesa ⚙️
```php
$contextKeyValues = [
    'BNX_ENTITY_ID' => $receivedData['bnxEntityId'], // 'PXYZ007'
    'FORM_DOMAIN'   => $receivedData['formDomain']   // 'VS'
];

$fieldsData = [];
foreach ($receivedData['formData'] as $fieldName => $value) {
    $fieldsData[] = [
        'field_name' => $fieldName,
        'value'      => $value
        // Opcionalmente,  'reason'  si viene del frontend por GCP
    ];
}

$actorUserId = \bX\Profile::$account_id ?? 'UNKNOWN_USER';
$reason = "Data entry/update for {$receivedData['formDomain']}";

$saveResult = \bX\DataCaptureService::saveRecord(
    'CDC_APP',           // Siempre 'CDC_APP' para tu módulo
    $contextKeyValues,
    $fieldsData,
    $actorUserId,
    $reason
);

```

### Backend: Vincula y Responde 🔗
Verifica el Resultado: Comprueba si `$saveResult['success']` es `true`.

Si es Exitoso:
- Obtén el `context_group_id` de `$saveResult`.
- Actualiza `cdc_form_instance`: Guarda este `context_group_id` en el registro `cdc_form_instance` correspondiente. 
¡Este es el vínculo clave!
- El UI podria cambiar el estado del `cdc_form_instance` (ej. de 'DRAFT' a 'OPEN').
- Envía una respuesta de éxito a la UI. 

Si Falla:
- Registra el error (`bx\Log::logError`).
- Envía una respuesta de error a la UI.

## En resumen:

- `getFormSchema` lee la definición del formulario.
- `defineCRFField` y addFormField guardan la definición del formulario.
- `DataCaptureService::saveRecord` guarda los datos introducidos por el usuario en el formulario.
- `DataCaptureService::getRecord` lee los datos introducidos por el usuario.
- `getFormSchema` depende de que los datos de definición (`defineCRFField`, `addFormField`) se hayan guardado antes, pero 
   no guarda ni lee los datos de instancia (los valores que llena el usuario).