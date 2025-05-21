**4. `DataCaptureService.php.md`**

# `bX\DataCaptureService` - Agnostic Data Versioning

**File:** `bintelx/kernel/DataCaptureService.php`

## Purpose

The `DataCaptureService` provides a generic, application-agnostic mechanism for capturing and versioning individual data points. It allows different Bintelx applications (e.g., an EDC, an order management system, a lab system) to store their specific data in a structured, auditable, and version-controlled manner without imposing domain-specific schemas on the service itself.

It centers around the concepts of:
*   **`CaptureDefinition`**: Defining the *type* of data that can be captured (e.g., 'AETERM' for an Adverse Event term, 'ITEM_PRICE' for an order item).
*   **`ContextGroup`**: A logical grouping for a set of related data points that form a single "record" or "instance" within an application (e.g., all fields belonging to one specific Adverse Event, or one specific lab test result).
*   **`CaptureData`**: The current ("hot") value of a specific field within a context group.
*   **`CaptureDataVersion`**: A historical log of all changes made to a specific field's value.

## Key Features

*   **Atomic Versioning:** Every individual field value change is tracked as a new version.
*   **Application Agnostic:** Can be used by any application built on Bintelx. Applications define their own `application_name` and context.
*   **Contextual Grouping:** Data points are grouped by an application-defined context (via `contextKeyValues`), allowing flexible record structures.
*   **Audit Trails:** Facilitates detailed audit trails for any piece of captured data.
*   **Transactional Saves:** Ensures that all fields for a given `saveRecord` call are saved atomically with their versions.

## Core Static Methods

### `defineCaptureField(string $applicationName, string $fieldName, string $dataType, ?string $description, string $actorUserId): array`
*   **Purpose:** Defines a new type of field that can be captured by a specific application, or updates an existing one. This populates the `capture_definition` table.
*   **Parameters:**
    *   `$applicationName`: The unique name of the application.
    *   `$fieldName`: The unique name of the field within this application (e.g., 'SYSTOLIC_BP', 'PRODUCT_SKU').
    *   `$dataType`: The general data type (e.g., 'VARCHAR', 'NUMERIC', 'DATE', 'BOOLEAN'). This guides storage.
    *   `$description` (optional): A textual description of the field.
    *   `$actorUserId`: ID of the user/system defining this field.
*   **Returns:** `['success' => bool, 'definition_id' => int|null, 'message' => string]`
*   **Note:** Changes to definitions are also versioned in `capture_definition_version`.

### `saveRecord(string $applicationName, array $contextKeyValues, array $fieldsData, string $actorUserId, ...): array`
*   **Purpose:** Saves or updates one or more field values for a given application and context. Each field's value is versioned.
*   **Parameters:**
    *   `$applicationName`: The application's unique name.
    *   `$contextKeyValues`: Associative array defining the unique context for this data instance (e.g., `['ORDER_ID' => 101, 'ITEM_SEQ' => 2]`). These keys are application-defined.
    *   `$fieldsData`: Array of fields, each `['field_name' => string, 'value' => mixed]`. `field_name` must correspond to a defined field in `capture_definition`.
    *   `$actorUserId`: ID of the user/system performing the save (e.g., `Profile::$account_id`).
    *   `$changeReason` (optional): Reason for the change.
    *   `$signatureType` (optional): Type of signature.
    *   `$eventType` (optional): Type of event (e.g., 'INITIAL_ENTRY', 'CORRECTION').
*   **Returns:** `['success' => bool, 'message' => string, 'context_group_id' => int|null, 'capture_data_ids' => array|null]`
    *   `capture_data_ids` are the IDs of the rows in the `capture_data` table.

### `getRecord(string $applicationName, array $contextKeyValues, array $fieldNames = null): array`
*   **Purpose:** Retrieves the current ("hot") values for specified fields within a given application and context.
*   **Parameters:**
    *   `$applicationName`: The application's name.
    *   `$contextKeyValues`: The context identifying the data instance.
    *   `$fieldNames` (optional): An array of specific `field_name`s to retrieve. If null, all fields for the context are attempted.
*   **Returns:** `['success' => bool, 'data' => array|null, 'message' => string]`
    *   `data` is an associative array `['fieldName1' => 'value1', 'fieldName2' => 'value2']`.

### `getAuditTrailForField(string $applicationName, array $contextKeyValues, string $fieldName): array`
*   **Purpose:** Retrieves the complete version history for a single specified field within a given context.
*   **Parameters:**
    *   `$applicationName`: The application's name.
    *   `$contextKeyValues`: The context identifying the data instance.
    *   `$fieldName`: The specific `field_name` for which to get the audit trail.
*   **Returns:** `['success' => bool, 'trail' => array|null, 'message' => string]`
    *   `trail` is an array of version records, each containing details like `sequential_version_num`, `value_at_version`, `changed_at`, `changed_by_user_id`, etc.

## Database Schema (Conceptual - see initial design for exact DDL)

*   **`capture_definition`**: Stores metadata about each unique field type per application (`definition_id`, `application_name`, `field_name`, `data_type`, `description`).
*   **`context_group`**: Represents a logical grouping or "record instance" (`context_group_id`, `application_name`).
*   **`context_group_item`**: Stores the application-defined key-value pairs that make up a specific context, linked to `context_group` (`context_group_item_id`, `context_group_id_ref`, `context_key`, `context_value`).
*   **`capture_data`**: Stores the current ("hot") value of a field within a context (`capture_data_id`, `definition_id_ref`, `context_group_id_ref`, `field_value_varchar`, `field_value_numeric`, `current_version_id_ref`, `current_sequential_version_num`).
*   **`capture_data_version`**: Stores each historical version of a field's value (`version_id`, `capture_data_id_ref`, `sequential_version_num`, versioned values, audit metadata like `changed_at`, `changed_by_user_id`, `change_reason`).
*   **`capture_definition_version`**: Stores the version history of changes to `capture_definition` records.

## Usage Example (Conceptual - within an application endpoint)

```php
// --- Defining a field type (once, typically during app setup) ---
$actor = \bX\Profile::$account_id; // Or a system user ID
\bX\DataCaptureService::defineCaptureField(
    'SALES_APP', 
    'ITEM_DISCOUNT_PERCENT', 
    'NUMERIC', 
    'Discount percentage applied to a sales order item',
    $actor
);

// --- Saving data for a specific sales order item ---
$applicationName = 'SALES_APP';
$context = [
    'SALES_ORDER_NUMBER' => 'SO12345',
    'LINE_ITEM_ID' => 'LI002'
];
$fieldsToSave = [
    ['field_name' => 'ITEM_SKU', 'value' => 'PROD-XYZ'],
    ['field_name' => 'ITEM_QUANTITY', 'value' => 5],
    ['field_name' => 'ITEM_DISCOUNT_PERCENT', 'value' => 10.5] 
];
$actorUserId = \bX\Profile::$account_id;
$reason = "Initial entry for new order line.";

$result = \bX\DataCaptureService::saveRecord($applicationName, $context, $fieldsToSave, $actorUserId, $reason);

if ($result['success']) {
    echo "Order line item data saved. Context Group ID: " . $result['context_group_id'];
} else {
    echo "Error: " . $result['message'];
}

// --- Retrieving current data for that item ---
$currentDataResult = \bX\DataCaptureService::getRecord($applicationName, $context, ['ITEM_DISCOUNT_PERCENT']);
if ($currentDataResult['success']) {
    // $currentDataResult['data']['ITEM_DISCOUNT_PERCENT'] would be 10.5
}

// --- Retrieving audit trail for the discount ---
$auditTrailResult = \bX\DataCaptureService::getAuditTrailForField($applicationName, $context, 'ITEM_DISCOUNT_PERCENT');
if ($auditTrailResult['success']) {
    // $auditTrailResult['trail'] contains all versions of ITEM_DISCOUNT_PERCENT
}
```

## Usae Example for Clinical Trials
```php
<?php // Ejemplo CDC con DataCaptureService actualizado

if (!isset(bX\Profile::$account_id) || bX\Profile::$account_id === 0) {
    bX\Profile::$account_id = 'SCRIPT_USER'; // O un ID de usuario simulado
}

echo "--- Ejemplo CDC: Definición y Captura de Signos Vitales (Usando DataCaptureService Actualizado) ---\n";

$applicationName = 'CDC_APP'; // Nombre de la aplicación CDC
$studySetupActor = bX\Profile::$account_id ?: 'CDC_CONFIG_ADMIN';

echo "Definiendo campos para Signos Vitales...\n";

// Elementos comunes para la recolección de Signos Vitales
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSPERF',
        'label' => '¿Se realizó VS?',
        'data_type' => 'VARCHAR', // Almacenará 'Y', 'N', 'YES', 'NO' o un código
        'attributes_json' => [ // Usar array, se convertirá a JSON
            'description' => 'Indica si se realizó la toma de signos vitales.',
            'control_type' => 'RADIO_GROUP', // O SELECT_SINGLE
            'datalist_source' => 's1:Y=Sí|N=No' // O 's1:YES|NO'
        ]
    ],
    $studySetupActor
);

bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSDTC',
        'label' => 'Fecha/Hora de Toma de VS',
        'data_type' => 'VARCHAR', // Almacenado como string ISO 8601
        'attributes_json' => [
            'description' => 'Fecha y Hora de la Toma de Signos Vitales (YYYY-MM-DDTHH:MM:SSZ)',
            'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|[+-][0-9]{2}:[0-9]{2})?$',
            'control_type' => 'DATETIME_PICKER'
        ]
    ],
    $studySetupActor
);

bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSPOS',
        'label' => 'Posición del Sujeto',
        'data_type' => 'VARCHAR',
        'attributes_json' => [
            'description' => 'Posición del Sujeto Durante la Medición',
            'control_type' => 'SELECT_SINGLE',
            'datalist_source' => 's1:SENTADO|DE PIE|SUPINO|SEMI-RECLINADO|TRENDELENBURG'
        ]
    ],
    $studySetupActor
);

// Campos para Presión Arterial Sistólica
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSORRES_SYSBP',
        'label' => 'P.A. Sistólica (Resultado)',
        'data_type' => 'NUMERIC',
        'attributes_json' => [
            'description' => 'Presión Arterial Sistólica - Resultado Original',
            'min' => 0,
            'max' => 400
        ]
    ],
    $studySetupActor
);
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSORRESU_SYSBP',
        'label' => 'P.A. Sistólica (Unidad)',
        'data_type' => 'VARCHAR',
        'attributes_json' => [
            'description' => 'Presión Arterial Sistólica - Unidad Original',
            'control_type' => 'SELECT_SINGLE', // Mejor que texto libre para unidades estándar
            'datalist_source' => 's1:mmHg|kPa',
            'default_value' => 'mmHg'
        ]
    ],
    $studySetupActor
);

// Campos para Presión Arterial Diastólica
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSORRES_DIABP',
        'label' => 'P.A. Diastólica (Resultado)',
        'data_type' => 'NUMERIC',
        'attributes_json' => [
            'description' => 'Presión Arterial Diastólica - Resultado Original',
            'min' => 0,
            'max' => 300
        ]
    ],
    $studySetupActor
);
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSORRESU_DIABP',
        'label' => 'P.A. Diastólica (Unidad)',
        'data_type' => 'VARCHAR',
        'attributes_json' => [
            'description' => 'Presión Arterial Diastólica - Unidad Original',
            'control_type' => 'SELECT_SINGLE',
            'datalist_source' => 's1:mmHg|kPa',
            'default_value' => 'mmHg'
        ]
    ],
    $studySetupActor
);

// Campos para Frecuencia del Pulso
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSORRES_PULSE',
        'label' => 'Pulso (Resultado)',
        'data_type' => 'NUMERIC',
        'attributes_json' => [
            'description' => 'Frecuencia del Pulso - Resultado Original',
            'min' => 0,
            'max' => 300
        ]
    ],
    $studySetupActor
);
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSORRESU_PULSE',
        'label' => 'Pulso (Unidad)',
        'data_type' => 'VARCHAR',
        'attributes_json' => [
            'description' => 'Frecuencia del Pulso - Unidad Original',
            'control_type' => 'SELECT_SINGLE',
            'datalist_source' => 's1:latidos/min|bpm', // beats per minute
            'default_value' => 'latidos/min'
        ]
    ],
    $studySetupActor
);

// Campos para Temperatura Corporal
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSORRES_TEMP',
        'label' => 'Temperatura (Resultado)',
        'data_type' => 'NUMERIC',
        'attributes_json' => [
            'description' => 'Temperatura Corporal - Resultado Original',
            'min' => 30.0,
            'max' => 45.0,
            'step' => 0.1 // Para inputs numéricos en HTML
        ]
    ],
    $studySetupActor
);
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'fielde_name' => 'VSORRESU_TEMP',
        'label' => 'Temperatura (Unidad)',
        'data_type' => 'VARCHAR',
        'attributes_json' => [
            'description' => 'Temperatura Corporal - Unidad Original',
            'control_type' => 'SELECT_SINGLE',
            'datalist_source' => 's1:C|F',
            'default_value' => 'C'
        ]
    ],
    $studySetupActor
);
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSLOC_TEMP',
        'label' => 'Temperatura (Ubicación)',
        'data_type' => 'VARCHAR',
        'attributes_json' => [
            'description' => 'Ubicación de la Medición de Temperatura',
            'control_type' => 'SELECT_SINGLE',
            'datalist_source' => 's1:ORAL|AXILAR|TIMPANICA|RECTAL|CUTANEA'
        ]
    ],
    $studySetupActor
);
echo "Definición de campos completada.\n\n";


// --- 2. Guardando datos de Signos Vitales para un Sujeto específico en una Visita ---
echo "Guardando registro de Signos Vitales para un sujeto...\n";
$dataEntryActor = bX\Profile::$account_id ?: 'CDC_USER_002';
$reasonForEntry = "Toma de signos vitales de rutina durante la visita de Selección, según protocolo.";

// Contexto para la medición de Signos Vitales
// La aplicación CDC decide qué tan granular es el contexto.
// Ejemplo para un perfil de paciente, no necesariamente ligado a estudio aún:
$vsContext = [
    'BNX_PATIENT_ID' => 'PXYZ007', // Identificador global único del paciente en BintelX
    'DOMAIN'    => 'VS' // Indica que son VS del perfil general del paciente
];

// Si fuera para un estudio específico:
/*
$vsContext = [
    'BNX_PATIENT_ID'    => 'PXYZ007',
    'STUDY_ID'          => 'PROT-001',
    'USUBJID'           => 'PROT001-SITE01-PXYZ007',
    'VISIT_ID'          => 'SCREENING', // o VISITNUM
    'DOMAIN'            => 'VS'
];
*/

$vitalSignsDataToSave = [
    ['field_name' => 'VSPERF', 'value' => 'Y'], // Sí se realizó
    ['field_name' => 'VSDTC', 'value' => date('Y-m-d\TH:i:sP', strtotime('2025-05-19 09:30:00'))],
    ['field_name' => 'VSPOS', 'value' => 'SEMI-RECLINADO'],

    ['field_name' => 'VSORRES_SYSBP', 'value' => 122],
    ['field_name' => 'VSORRESU_SYSBP', 'value' => 'mmHg'],

    ['field_name' => 'VSORRES_DIABP', 'value' => 78],
    ['field_name' => 'VSORRESU_DIABP', 'value' => 'mmHg'],

    ['field_name' => 'VSORRES_PULSE', 'value' => 70],
    ['field_name' => 'VSORRESU_PULSE', 'value' => 'latidos/min'],

    ['field_name' => 'VSORRES_TEMP', 'value' => 36.9],
    ['field_name' => 'VSORRESU_TEMP', 'value' => 'C'],
    ['field_name' => 'VSLOC_TEMP', 'value' => 'ORAL']
];

// Suponiendo que $applicationName está definido como 'CDC_APP'
$saveResult = bX\DataCaptureService::saveRecord($applicationName, $vsContext, $vitalSignsDataToSave, $dataEntryActor, $reasonForEntry);

if ($saveResult['success']) {
    echo "Datos de Signos Vitales guardados. ID del Grupo de Contexto: " . $saveResult['context_group_id'] . "\n\n";
} else {
    echo "Error al guardar datos de Signos Vitales: " . ($saveResult['message'] ?? "Error desconocido") . "\n\n";
}


// --- 3. Recuperando datos actuales para ese registro de Signos Vitales ---
echo "Recuperando datos específicos de Signos Vitales para el contexto...\n";
$fieldsToRetrieve = ['VSDTC', 'VSORRES_SYSBP', 'VSORRES_PULSE', 'VSPOS', 'VSPERF'];
$currentVsDataResult = bX\DataCaptureService::getRecord($applicationName, $vsContext, $fieldsToRetrieve);

if ($currentVsDataResult['success'] && !empty($currentVsDataResult['data'])) {
    echo "Datos recuperados:\n";
    foreach ($currentVsDataResult['data'] as $fieldName => $fieldData) {
        // $fieldData es ahora un array ['value' => ..., 'label' => ..., 'attributes' => ..., etc.]
        echo "  Campo: $fieldName\n";
        echo "    Label: " . ($fieldData['label'] ?? 'N/A') . "\n";
        echo "    Valor: " . ($fieldData['value'] ?? 'N/A') . "\n";
        echo "    Tipo Dato: " . ($fieldData['data_type'] ?? 'N/A') . "\n";
        echo "    Versión Num: " . ($fieldData['version_num'] ?? 'N/A') . "\n";
        // Puedes imprimir $fieldData['attributes'] si es necesario
    }
    echo "\n";
} else {
    $errMsg = isset($currentVsDataResult['message']) ? $currentVsDataResult['message'] : "Datos no encontrados o vacíos.";
    echo "No se pudieron recuperar los datos de Signos Vitales. Mensaje: " . $errMsg . "\n\n";
}


// --- 4. Recuperando la pista de auditoría para un parámetro específico (ej: Presión Sistólica) ---
echo "Recuperando pista de auditoría para Presión Arterial Sistólica (VSORRES_SYSBP)...\n";
$auditTrailField = 'VSORRES_SYSBP';
$auditTrailResult = bX\DataCaptureService::getAuditTrailForField($applicationName, $vsContext, $auditTrailField);

if ($auditTrailResult['success'] && !empty($auditTrailResult['trail'])) {
    echo "Pista de auditoría para $auditTrailField:\n";
    foreach ($auditTrailResult['trail'] as $entry) {
        echo "  Seq: " . ($entry['sequential_version_num'] ?? 'N/A') .
             ", Valor: " . ($entry['value_at_version'] ?? 'N/A') . // Correcto
             ", Por: " . ($entry['changed_by_user_id'] ?? 'N/A') . // Correcto
             ", Fecha: " . ($entry['changed_at'] ?? 'N/A') . // Correcto
             ", Razón: \"" . ($entry['change_reason'] ?? '') . "\"" .
             ", Evento: " . ($entry['event_type'] ?? '') .
             ", Sig: " . ($entry['signature_type'] ?? '') . "\n";
    }
    echo "\n";
} else {
    $errMsgAudit = isset($auditTrailResult['message']) ? $auditTrailResult['message'] : "Pista no encontrada o vacía.";
    echo "No se pudo recuperar la pista de auditoría para $auditTrailField. Mensaje: " . $errMsgAudit . "\n\n";
}

echo "--- Fin del Ejemplo ---\n";
```