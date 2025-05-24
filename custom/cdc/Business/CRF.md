¡Excelente! Vamos por partes.

Primero: Actualizar cdc.sql

Basado en nuestra discusión sobre los "branches", la modificación principal es en la tabla cdc_flow_chart_item para añadir la columna branch_code y ajustar la clave única.

Aquí tienes el script SQL solo para cdc_flow_chart_item actualizado:

SQL

-- Links forms/domains (items) to specific flowchart visit entries
CREATE TABLE `cdc_flow_chart_item` (
`flow_chart_item_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`flow_chart_id_ref` BIGINT UNSIGNED NOT NULL COMMENT 'FK to cdc_flow_chart (a specific visit entry)',
`form_domain` VARCHAR(50) NOT NULL COMMENT 'CDISC Domain code (e.g., VS, DM, AE). Links conceptually to cdc_form_fields',
`item_title` VARCHAR(255) NULL DEFAULT NULL COMMENT 'User friendly title for this item, e.g., Vital Signs',
`item_type` VARCHAR(50) NOT NULL DEFAULT 'FORM' COMMENT 'Type: FORM, SECTION, PROCEDURE',
`item_order` INT NULL DEFAULT 0 COMMENT 'Order of this form/activity within the visit for the specified branch',
`branch_code` VARCHAR(50) NULL DEFAULT '__COMMON__' COMMENT 'Branch/Arm identifier. "__COMMON__" or NULL for items applicable to all branches of this visit.',
`is_mandatory` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'If this form MUST be filled for the visit and branch',
`details_json` JSON NULL DEFAULT NULL COMMENT 'Optional: Visit-specific instructions for this item/branch, NOT form structure.',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`flow_chart_item_id`),
INDEX `idx_cdc_flow_chart_item_flow_chart_id` (`flow_chart_id_ref`),
UNIQUE KEY `uq_item_visit_domain_branch` (`flow_chart_id_ref`, `form_domain`, `branch_code`) -- Updated unique key
-- ,FOREIGN KEY (`flow_chart_id_ref`) REFERENCES `cdc_flow_chart`(`flow_chart_id`) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Specific forms/activities planned for a visit in a flowchart, branch-aware.';
Cambios Clave en cdc_flow_chart_item:

branch_code VARCHAR(50) NULL DEFAULT '__COMMON__': Añadida la nueva columna. El DEFAULT '__COMMON__' es una convención que podemos usar para indicar que, si no se especifica un branch, el ítem es común a todos. NULL también es una opción válida para "común".
UNIQUE KEY uq_item_visit_domain_branch: Actualizada para incluir branch_code. Esto asegura que un form_domain solo pueda aparecer una vez por branch_code (o como común) dentro de la misma visita (flow_chart_id_ref).
Considera añadir también branch_code_actual a cdc_form_instance para trazabilidad:

SQL

-- En la definición de cdc_form_instance, añadir:
`branch_code_actual` VARCHAR(50) NULL DEFAULT NULL COMMENT 'The specific branch code active when this form instance data was captured/saved.',
-- Y posiblemente añadirlo a algún índice si se consulta frecuentemente.
Segundo: Revisión de CRF.md

Has hecho algunas observaciones y sugerencias muy pertinentes en el CRF.md. Vamos a analizarlas:

$actorProfileId como Parámetro vs. Captura Interna:

$actorProfileId Should be captured from \bX\Profile::$account_id and not by parameters

Totalmente de acuerdo. Es una práctica mucho mejor y más consistente que la clase obtenga el actorUserId (o actorProfileId) internamente desde \bX\Profile::$account_id (o el campo que corresponda en tu Profile). Esto simplifica la firma del método y reduce la posibilidad de errores al pasar el actor.
Acción: Actualizaremos el .md para quitar $actorProfileId de los parámetros de defineCRFField y addFormField y añadiremos una nota indicando que se obtiene internamente.
Parámetros de addFormField:

Has reestructurado los parámetros de addFormField en un array $data con nuevas claves como $data[form_label] y $data[field_id].

$data[form_label]: Este campo es interesante. Actualmente, el "título del formulario tal como aparece en una visita" se gestiona en cdc_flow_chart_item.item_title. La tabla cdc_form_fields (que addFormField poblaría) define la estructura interna de un form_domain para un estudio, no necesariamente su título de presentación en cada visita.
Pregunta: ¿Dónde se guardaría esta $data[form_label]? Si es un título general para el form_domain dentro del estudio, podría ir en cdc_form_fields (necesitaríamos añadir la columna). Si es específico de cómo se muestra en el flowchart, ya lo cubre cdc_flow_chart_item.item_title.
Propuesta: Mantengamos addFormField enfocado en vincular un field_name a un form_domain para un estudio, con su orden y atributos de campo. El título de presentación del formulario en el contexto de una visita se maneja en Flowchart::addFormToVisit (que pobla cdc_flow_chart_item.item_title).
$data[field_id] ("original fieldId to bring attributes if exist"): El fieldName es el identificador único de la CaptureDefinition. Si la idea es copiar o heredar atributos de una CaptureDefinition existente (identificada por este field_id) al crear o vincular una nueva (el field_name en addFormField), esa lógica pertenecería más a defineCRFField (si se crea un nuevo campo basado en una plantilla) o a un sistema de "plantillas de campos" que no hemos discutido aún. addFormField asume que el fieldName ya está definido (o se definirá) en DataCaptureService.
Propuesta: Omitamos $data[field_id] de addFormField por ahora, ya que su función es vincular un field_name (que ya tiene su propia definición de atributos base en DataCaptureService) a la estructura cdc_form_fields. Las sobrescrituras de atributos se manejan con attributes_override_json.
Array $data vs. Parámetros Explícitos: Usar un array $data puede ser útil para muchos parámetros, pero para métodos estáticos con un número manejable de parámetros clave, los parámetros explícitos suelen ser más claros para el desarrollador que usa la API.
Propuesta: Volvamos a los parámetros explícitos para addFormField ($studyId, $formDomain, $fieldName, $itemOrder, $options = []) para mayor claridad.
Impacto de branch_code en CRF.md:

El método getFormSchema(string $studyId, string $formDomain):
Su responsabilidad es devolver la estructura definida de un form_domain para un studyId según la tabla cdc_form_fields.
La tabla cdc_form_fields (como la definimos) no es inherentemente branch-específica. Define la estructura máxima o base de un form_domain para el estudio.
Es la tabla cdc_flow_chart_item la que ahora dice: "Para la visita X, en el branch Y, usa el form_domain Z".
Por lo tanto, getFormSchema probablemente NO necesita un parámetro branchCode. Quien lo llama (ej. la lógica de ISF o Flowchart) primero consultará cdc_flow_chart_item (usando el branchCode del paciente) para saber qué form_domains son relevantes, y luego llamará a getFormSchema para cada uno de esos form_domains.
Acción: No se requieren cambios en la firma de getFormSchema en CRF.md debido a branch_code, pero la documentación de cómo se usa (como tus ejemplos) puede aclararlo.
Tus Adiciones al Final del CRF.md:

Las secciones "cómo obtener un formulario con los datos ya cargados...", "Fusionar Schema y Datos", "Exemplo de envio de datos desde UI", etc., son excelentes y muy valiosas. Son ejemplos de cómo se usa la clase CRF en un flujo completo.
Acción: Las integraremos formalmente en el .md, quizás bajo un nuevo encabezado "6. Patrones de Uso Comunes / Flujos de Trabajo" o expandiendo la sección "5. Example Usage".
Conclusión de la Revisión de CRF.md:

Sí, CRF.md necesita una pequeña actualización para reflejar la captura interna del actorUserId.
Mantendremos la firma de addFormField con parámetros explícitos por claridad y nos enfocaremos en su rol de vincular campos definidos a la estructura cdc_form_fields.
getFormSchema no necesita cambiar su firma debido a branch_code; el filtrado por branch ocurre antes, al determinar qué form_domains solicitar.
Tus ejemplos de flujo son muy buenos y se integrarán.
Ahora, procederé a darte el CRF.md actualizado con estas consideraciones.

Aquí tienes el CRF.md actualizado:

Markdown

# `CDC\CRF` - Clinical Form Management

**File:** `custom/cdc/Business/CRF.php`

## 1. Purpose

The `CRF` (Case Report Form) class serves as a crucial intermediary layer within the CDC (Clinical Data Capture) module. Its primary responsibility is to manage the **definition and structure of clinical forms** by bridging the gap between:

1.  The **CDC Application's high-level concepts** (Studies, Flowcharts, Domains, Forms, and field order).
2.  The **`bX\DataCaptureService`'s low-level storage** of individual data field definitions (`CaptureDefinition`).

It provides functionalities to define individual CRF fields within the 'CDC_APP' context (via `defineCRFField`) and to link these fields to a specific study's form domain, defining their order and presentation attributes (via `addFormField` which populates `cdc_form_fields`). Its key retrieval method, `getFormSchema`, allows the UI/Frontend to dynamically render forms based on these definitions.

## 2. Dependencies

* `bX\CONN`: For database interactions with CDC metadata tables (primarily `cdc_form_fields`).
* `bX\Log`: For logging errors and informational messages.
* `bX\Profile`: Used internally by methods to capture the `actorUserId`.
* `bX\DataCaptureService`: For defining and retrieving base field definitions (`capture_definition`).
* `CDC\Study`: For validating study existence and retrieving `study_internal_id`.
* **CDC Database Tables:**
  * `cdc_study`: To link form field structures to studies.
  * `capture_definition`: To store/retrieve base field definitions (managed via `DataCaptureService`).
  * `cdc_form_fields` (**Required**): This table is populated by `addFormField` and read by `getFormSchema`. It links a `form_domain` to multiple `field_name`s *within a study*, defining their `item_order`, `section_name`, `is_mandatory`, and `attributes_override_json`.

## 3. Key Concepts

* **CRF Field / `CaptureDefinition`**: A single, uniquely named data point (e.g., 'VSORRES_SYSBP') defined in `DataCaptureService` under the 'CDC_APP' application. It has a base `data_type`, `label`, and `attributes_json`. Managed by `defineCRFField`.
* **Form Domain (`form_domain`)**: A logical grouping of CRF Fields, often aligned with CDISC domains (e.g., 'VS', 'DM', 'AE'). It acts as the primary identifier for a "form" structure within a study.
* **Form Field Link (`cdc_form_fields` record)**: A record linking a specific `field_name` to a `form_domain` for a particular `study_id`, defining its `item_order`, `section_name`, `is_mandatory`, and any attribute overrides. Managed by `addFormField`.
* **Form Schema**: The complete structural definition of a `form_domain` for a specific study, as retrieved by `getFormSchema`. It includes an ordered list of its fields, their full `CaptureDefinition` details (label, type, base attributes), and any study/form-specific modifications (order, section, mandatory status, attribute overrides from `cdc_form_fields`).

## 4. Core Static Methods

### `defineCRFField(string $fieldName, string $dataType, string $label, array $attributes = []): array`

* **Purpose:** Creates or updates a base field definition (`CaptureDefinition`) specifically for the 'CDC_APP'. This acts as a simplified wrapper around `DataCaptureService::defineCaptureField`. The `actorUserId` is obtained internally from `\bX\Profile`.
* **Parameters:**
  * `$fieldName` (string, **required**): The unique, CDISC-like name for the field (e.g., 'VSORRES_SYSBP').
  * `$dataType` (string, **required**): The base data type ('VARCHAR', 'NUMERIC', 'DATE', 'BOOLEAN').
  * `$label` (string, **required**): The user-friendly label for the field.
  * `$attributes` (array, optional): An associative array for `attributes_json` (description, UI hints, validation, etc.).
* **Returns:** `['success' => bool, 'definition_id' => int|null, 'message' => string]` - Mirrors the return from `DataCaptureService`.

### `addFormField(string $studyId, string $formDomain, string $fieldName, int $itemOrder, array $options = []): array`

* **Purpose:** Links an existing CRF Field (`fieldName`) to a specific Form Domain (`formDomain`) for a particular Study (`studyId`), defining its order and any form-specific overrides/attributes. Populates the `cdc_form_fields` table. The `actorUserId` is obtained internally from `\bX\Profile`.
* **Parameters:**
  * `$studyId` (string, **required**): The public ID of the study.
  * `$formDomain` (string, **required**): The identifier for the form/domain (e.g., 'VS') to which this field is being added.
  * `$fieldName` (string, **required**): The `fieldName` of the (presumably already defined) CRF Field to add.
  * `$itemOrder` (int, **required**): The sequence number for this field within this `formDomain` for this study.
  * `$options` (array, optional): Associative array with additional link-specific data for `cdc_form_fields`:
    * `'is_mandatory'` (bool, optional, default: true): If the field is required in this form context.
    * `'attributes_override_json'` (string|array, optional): JSON/Array to *override or extend* base attributes from `CaptureDefinition` for this specific field in this form context.
    * `'section_name'` (string, optional): A UI grouping/section title for this field within the form.
* **Returns:** `['success' => bool, 'form_field_id' => int|null, 'message' => string]` (`form_field_id` is the PK from `cdc_form_fields`).

### `getFormSchema(string $studyId, string $formDomain): array`

* **Purpose:** Retrieves the complete, ordered schema for a specified `formDomain` within the context of a given `studyId`. This method queries `cdc_form_fields` and `capture_definition` to assemble the full structure. It's the primary method used by the frontend to render forms.
* **Parameters:**
  * `$studyId` (string, **required**): The public ID of the study.
  * `$formDomain` (string, **required**): The identifier for the form/domain whose schema is requested.
* **Returns:** `['success' => bool, 'schema' => array|null, 'message' => string]`
  * `schema`: An **ordered** array (by `item_order`). Each element represents a field and contains a merged set of its base definition and form-specific attributes:
      ```json
      [
        {
          "field_name": "VSPERF",
          "item_order": 10,
          "label": "Did VS Occur?",
          "data_type": "VARCHAR",
          "is_mandatory": true,
          "attributes": { "control_type": "RADIO_GROUP", "datalist_source": "s1:Y=Yes|N=No", ... }, // Merged attributes
          "section_name": "Visit Details"
        },
        // ... other fields ...
      ]
      ```
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
use CDC\CRF;
use CDC\Study; // Assumed to be used by CRF methods internally or for setup context

// Actor ID would typically be fetched from \bX\Profile by the CRF methods
// $actor = 'SETUP_ADMIN'; // For conceptual clarity in example

$studyId = 'PROT-001';
$formDomainVS = 'VS';

// 1. Ensure Study Exists
// Study::createStudy(['study_id' => $studyId, 'study_title' => 'Test Study'], $actor); // Example

// 2. Define Base CRF Fields (actor obtained internally by defineCRFField)
CRF::defineCRFField('VSPERF', 'VARCHAR', 'Did VS Occur?', ['control_type' => 'RADIO_GROUP', 'datalist_source' => 's1:Y=Yes|N=No']);
CRF::defineCRFField('VSDTC', 'VARCHAR', 'VS Date/Time', ['control_type' => 'DATETIME_PICKER']);
CRF::defineCRFField('VSORRES_SYSBP', 'NUMERIC', 'Systolic BP (Result)', ['min' => 0, 'max' => 400]);

// 3. Link Fields to the 'VS' Form for 'PROT-001', defining order and form-specific attributes
// (actor obtained internally by addFormField)
CRF::addFormField($studyId, $formDomainVS, 'VSPERF', 10, ['section_name' => 'Visit Information', 'is_mandatory' => true]);
CRF::addFormField($studyId, $formDomainVS, 'VSDTC', 20, ['section_name' => 'Visit Information']);
CRF::addFormField($studyId, $formDomainVS, 'VSORRES_SYSBP', 30, ['section_name' => 'Vital Signs Results']);

// 4. Retrieve the Schema (e.g., for UI generation or validation logic)
$schemaResult = CRF::getFormSchema($studyId, $formDomainVS);

if ($schemaResult['success']) {
    // Example: UI Rendering Logic
    echo "<h1>Form: $formDomainVS for Study: $studyId</h1>";
    echo "<form>";
    $currentSection = null;
    foreach ($schemaResult['schema'] as $field) {
        if ($currentSection !== $field['section_name'] && $field['section_name']) {
            if ($currentSection !== null) echo "</fieldset>"; // Close previous section
            echo "<fieldset><legend>" . htmlspecialchars($field['section_name']) . "</legend>";
            $currentSection = $field['section_name'];
        }
        echo "<div>";
        echo "<label for='" . htmlspecialchars($field['field_name']) . "'>" . htmlspecialchars($field['label']) . ($field['is_mandatory'] ? ' *' : '') . "</label><br/>";
        // --- Logic to render input based on data_type and attributes ---
        echo "<input type='text' id='" . htmlspecialchars($field['field_name']) . "' name='" . htmlspecialchars($field['field_name']) . "' />";
        echo "</div>";
    }
    if ($currentSection !== null) echo "</fieldset>"; // Close last section
    echo "</form>";
} else {
    echo "Error retrieving schema: " . $schemaResult['message'];
}
```

---

---

## 6. Common Workflows Involving CRF and Data Services

### 6.1. Displaying a Form with Pre-filled Data

To display a form (e.g., 'VS' for patient 'PXYZ007' in study 'PROT-001') with any previously saved data:

1.  **Get Form Schema:**
    ```php
    $schemaResult = \CDC\CRF::getFormSchema('PROT-001', 'VS');
    $schema = $schemaResult['success'] ? $schemaResult['schema'] : [];
    if (empty($schema)) { 
        // Handle error: schema not found or empty, cannot proceed
        // \bX\Log::logError("Schema not found for PROT-001, VS");
        return; 
    }
    ```

2.  **Get Saved Data from `DataCaptureService`:**
    ```php
    $applicationName = \CDC\CRF::CDC_APPLICATION_NAME; // Use defined constant
    $contextKeyValues = [
        'BNX_ENTITY_ID' => 'PXYZ007', // Target patient
        'FORM_DOMAIN'   => 'VS'       // Target form domain
    ];
    $fieldNames = array_column($schema, 'field_name'); // Get field names from the schema

    $dataResult = \bX\DataCaptureService::getRecord(
        $applicationName,
        $contextKeyValues,
        $fieldNames // Request only fields relevant to this form
    );
    $dataValues = $dataResult['success'] ? $dataResult['data'] : [];
    ```

3.  **Merge Schema and Data for UI Rendering:**
    ```php
    $formToRender = [];
    foreach ($schema as $fieldSchema) {
        $fieldName = $fieldSchema['field_name'];
        $value = null; // Default to null if no data found
        if (isset($dataValues[$fieldName]) && array_key_exists('value', $dataValues[$fieldName])) {
            $value = $dataValues[$fieldName]['value'];
        }
        $fieldSchema['value'] = $value; // Add/update value in the field's schema data
        $formToRender[] = $fieldSchema;
    }
    // Now $formToRender contains the ordered schema with pre-filled values (if any)
    // This array can be passed to a templating engine or UI component for rendering.
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
    <?php
    // Assume $receivedData contains the parsed JSON from the request body
    // Assume $actorUserId is obtained, e.g., from \bX\Profile::$account_id
    
    // Step 1: (Typically done by ISF/FormInstance manager)
    // Get or Create cdc_form_instance. This step is crucial for linking and status management.
    // It would also store/confirm flowChartVersion and branchCodeActual from $receivedData.
    /*
    $formInstanceResult = \CDC\FormInstance::getOrCreateFormInstance(
        $receivedData['studyId'],
        $receivedData['bnxEntityId'],
        $receivedData['visitNumActual'],
        $receivedData['formDomain'],
        $receivedData['flowChartVersion'],
        $actorUserId,
        null, // isfIdRef (if applicable)
        null, // flowChartItemIdRef (if applicable)
        $receivedData['branchCodeActual']
    );
    if (!$formInstanceResult['success']) { 
        // Handle error, return error response to UI
        // exit; 
    }
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
                // Optionally add 'reason', 'eventType' if provided from UI for specific fields (GCP)
            ];
        }
    }
    
    $changeReason = "Data entry/update for form " . $receivedData['formDomain'];

    // Step 3: Call DataCaptureService to save data
    $saveResult = \bX\DataCaptureService::saveRecord(
        \CDC\CRF::CDC_APPLICATION_NAME, // Use defined constant
        $contextKeyValues,
        $fieldsDataForDCS,
        $actorUserId, // Make sure this is defined
        $changeReason
    );

    // Step 4: Link and Respond (This logic would typically be within CDC\FormInstance::saveFormInstanceData or CDC\ISF methods)
    if ($saveResult['success']) {
        // Update cdc_form_instance with $saveResult['context_group_id']
        // This link is crucial. Example:
        // \CDC\FormInstance::updateDCSLink($formInstanceId, $saveResult['context_group_id']);
        
        // Optionally update form instance status (e.g., DRAFT -> OPEN)
        // \CDC\FormInstance::updateFormInstanceStatus($formInstanceId, 'OPEN', $actorUserId);

        // Return success response to UI
        // echo json_encode(['success' => true, 'message' => 'Data saved successfully.']);
    } else {
        // Log error (\bX\Log::logError)
        // Return error response to UI
        // echo json_encode(['success' => false, 'message' => $saveResult['message']]);
    }
    ?>
    ```

## Summary of Data Flow Responsibilities

* **Study Setup Time (Defining the "What" and "How"):**
  * `CRF::defineCRFField` -> Interacts with `DataCaptureService` to define base field metadata.
  * `CRF::addFormField` -> Populates `cdc_form_fields` to define how a base field is used (order, section, mandatory, overrides) within a specific study's `form_domain`.
  * `Flowchart` methods -> Populate `cdc_flow_chart` and `cdc_flow_chart_item` to define *when* and *for which branch* a `form_domain` is expected.
* **Data Entry Time (Capturing the "Instance Data"):**
  * UI/Endpoint uses `CRF::getFormSchema` (to know what fields to display for a `form_domain`).
  * UI/Endpoint uses `Flowchart::getFlowchartDetails` (to know which `form_domain`s are needed for a visit/branch).
  * UI/Endpoint uses `DataCaptureService::getRecord` (often via `FormInstance::getFormData`) to pre-fill existing data.
  * User submits new/updated data.
  * Endpoint (likely via `FormInstance::saveFormInstanceData` or `ISF` methods) calls `DataCaptureService::saveRecord` to store the actual patient data.
  * Endpoint/Business Logic updates `cdc_form_instance` with the `context_group_id` from `DataCaptureService`.

## En resumen:

- `getFormSchema` lee la definición del formulario.
- `defineCRFField` y addFormField guardan la definición del formulario.
- `DataCaptureService::saveRecord` guarda los datos introducidos por el usuario en el formulario.
- `DataCaptureService::getRecord` lee los datos introducidos por el usuario.
- `getFormSchema` depende de que los datos de definición (`defineCRFField`, `addFormField`) se hayan guardado antes, pero 
   no guarda ni lee los datos de instancia (los valores que llena el usuario).