# `bX\DataCaptureService` - Versionado Agnóstico de Datos

**Archivo:** `bintelx/kernel/DataCaptureService.php`

## Propósito

El `DataCaptureService` proporciona un mecanismo genérico y agnóstico a la aplicación para capturar y versionar puntos de datos individuales. Permite que diferentes aplicaciones Bintelx (por ejemplo, un EDC, un sistema de gestión de pedidos, un sistema de laboratorio) almacenen sus datos específicos de manera estructurada, auditable y con control de versiones, sin imponer esquemas específicos del dominio al propio servicio.

Se centra en los conceptos de:

* **`CaptureDefinition`**: Define el *tipo* de datos que se pueden capturar (por ejemplo, 'AETERM' para un término de Evento Adverso, 'ITEM_PRICE' para el precio de un artículo). Incluye metadatos como el tipo de dato base y atributos para UI/validación.
* **`ContextGroup`**: Una agrupación lógica para un conjunto de puntos de datos relacionados que forman un único "registro" o "instancia" dentro de una aplicación (por ejemplo, todos los campos que pertenecen a un Evento Adverso específico, o un resultado de prueba de laboratorio específico).
* **`CaptureData`**: El valor actual ("caliente") de un campo específico dentro de un grupo de contexto.
* **`CaptureDataVersion`**: Un registro histórico de todos los cambios realizados en el valor de un campo específico.

## Características Clave

* **Versionado Atómico:** Cada cambio de valor de un campo individual se rastrea como una nueva versión.
* **Agnóstico a la Aplicación:** Puede ser utilizado por cualquier aplicación construida sobre Bintelx. Las aplicaciones definen su propio `application_name` y contexto.
* **Agrupación Contextual:** Los puntos de datos se agrupan mediante un contexto definido por la aplicación (a través de `contextKeyValues`), permitiendo estructuras de registro flexibles.
* **Pistas de Auditoría:** Facilita pistas de auditoría detalladas para cualquier dato capturado.
* **Guardados Transaccionales:** Asegura que todos los campos para una llamada `saveRecord` dada se guarden atómicamente con sus versiones.
* **Metadatos Extensibles:** Utiliza `attributes_json` en `CaptureDefinition` para almacenar información rica sobre los campos (etiquetas, descripciones, reglas de UI, validaciones, etc.).

## Métodos Estáticos Principales

### `defineCaptureField(string $applicationName, array $fieldDefinition, string $actorUserId): array`

* **Propósito:** Define un nuevo tipo de campo capturable para una aplicación o actualiza uno existente. Popula la tabla `capture_definition`.
* **Parámetros:**
    * `$applicationName`: El nombre único de la aplicación.
    * `$fieldDefinition`: Array asociativo con las propiedades del campo:
        * `'field_name'` (string, **requerido**): Nombre único del campo (ej: 'VSPOS').
        * `'data_type'` (string, **requerido**): Tipo base (VARCHAR, NUMERIC, DATE, BOOLEAN).
        * `'label'` (string, opcional): Etiqueta para UI (ej: "Posición del Sujeto").
        * `'attributes_json'` (string|array, opcional): JSON (o array que se convertirá a JSON) para metadatos: descripción, tipo de control UI, listas de datos, validaciones, etc.
        * `'is_active'` (bool, opcional, defecto: true): Si la definición está activa.
        * `'description'` (string, opcional): Se puede pasar aquí o dentro de `attributes_json`.
    * `$actorUserId`: ID del usuario/sistema que define/modifica el campo.
* **Retorna:** `['success' => bool, 'definition_id' => int|null, 'message' => string]`
* **Nota:** Los cambios en las definiciones también se versionan en `capture_definition_version`.

### `saveRecord(string $applicationName, array $contextKeyValues, array $fieldsData, string $actorUserId, ?string $defaultChangeReason = null, ?string $defaultSignatureType = null, ?string $defaultEventType = null): array`

* **Propósito:** Guarda o actualiza uno o más valores de campo para una aplicación y contexto dados. Cada valor de campo se versiona.
* **Parámetros:**
    * `$applicationName`: El nombre único de la aplicación.
    * `$contextKeyValues`: Array asociativo que define el contexto único (ej: `['STUDY_ID' => 'P001', 'USUBJID' => 'S01-P007']`).
    * `$fieldsData`: Array de campos. Cada elemento es un array: `['field_name' => string, 'value' => mixed, 'reason' => ?string, 'eventType' => ?string, 'signatureType' => ?string]`. Los campos `reason`, `eventType` y `signatureType` son opcionales y **anulan** los valores por defecto para ese campo específico.
    * `$actorUserId`: ID del usuario/sistema que realiza el guardado.
    * `$defaultChangeReason` (opcional): Razón por defecto para el cambio.
    * `$defaultSignatureType` (opcional): Tipo de firma por defecto.
    * `$defaultEventType` (opcional): Tipo de evento por defecto (ej: 'INITIAL_ENTRY', 'CORRECTION').
* **Retorna:** `['success' => bool, 'message' => string, 'context_group_id' => int|null, 'saved_fields_info' => array|null]`
    * `saved_fields_info`: Un array que contiene información detallada para cada campo guardado: `['field_name' => ..., 'capture_data_id' => ..., 'version_id' => ..., 'sequential_version_num' => ...]`.

### `getRecord(string $applicationName, array $contextKeyValues, array $fieldNames = null): array`

* **Propósito:** Recupera los valores actuales ("calientes") y la metadata de definición para los campos especificados dentro de una aplicación y contexto dados. **Devolverá las definiciones incluso si no hay datos capturados para ellas.**
* **Parámetros:**
    * `$applicationName`: El nombre de la aplicación.
    * `$contextKeyValues`: El contexto que identifica la instancia de datos.
    * `$fieldNames` (opcional): Un array de `field_name` específicos a recuperar. Si es nulo, intenta recuperar todos los campos definidos para la aplicación en ese contexto.
* **Retorna:** `['success' => bool, 'data' => array|null, 'message' => string]`
    * `data`: Un array asociativo donde cada clave es un `fieldName` y cada valor es *otro array* con detalles: `['value' => mixed, 'label' => string|null, 'data_type' => string, 'attributes' => array, 'current_version_num' => int|null, 'data_last_updated_at' => string|null, '_capture_data_id' => int|null, '_current_version_id' => int|null]`.

### `getAuditTrailForField(string $applicationName, array $contextKeyValues, string $fieldName): array`

* **Propósito:** Recupera el historial completo de versiones para un único campo especificado dentro de un contexto dado.
* **Parámetros:**
    * `$applicationName`: El nombre de la aplicación.
    * `$contextKeyValues`: El contexto que identifica la instancia de datos.
    * `$fieldName`: El `field_name` específico para el cual obtener la pista de auditoría.
* **Retorna:** `['success' => bool, 'trail' => array|null, 'message' => string]`
    * `trail`: Un array de registros de versión, cada uno conteniendo detalles como `version_id`, `sequential_version_num`, `value_at_version`, `changed_at`, `changed_by_user_id`, `change_reason`, `signature_type`, `event_type`.

## Esquema de Base de Datos (Conceptual)

* **`capture_definition`**: Almacena metadatos sobre cada tipo de campo único por aplicación (`definition_id`, `application_name`, `field_name`, `label`, `data_type`, `attributes_json`, `is_active`).
* **`context_group`**: Representa una agrupación lógica o "instancia de registro" (`context_group_id`, `application_name`).
* **`context_group_item`**: Almacena los pares clave-valor definidos por la aplicación que componen un contexto específico, vinculados a `context_group` (`context_group_item_id`, `context_group_id_ref`, `context_key`, `context_value`).
* **`capture_data`**: Almacena el valor actual ("caliente") de un campo dentro de un contexto (`capture_data_id`, `definition_id_ref`, `context_group_id_ref`, `field_value_varchar`, `field_value_numeric`, `current_version_id_ref`, `current_sequential_version_num`).
* **`capture_data_version`**: Almacena cada versión histórica del valor de un campo (`version_id`, `capture_data_id_ref`, `sequential_version_num`, valores versionados, metadatos de auditoría como `changed_at`, `changed_by_user_id`, `change_reason`, `event_type`, `signature_type`).
* **`capture_definition_version`**: Almacena el historial de versiones de los cambios en los registros de `capture_definition`.

## Ejemplo de Uso (Conceptual - Actualizado)

```php
<?php
// --- Definiendo un tipo de campo (una vez, típicamente durante la configuración) ---
$actor = \bX\Profile::$account_id ?: 'SETUP_ADMIN'; // Or a system user ID
\bX\DataCaptureService::defineCaptureField(
    'SALES_APP',
    [
        'field_name' => 'ITEM_DISCOUNT_PERCENT',
        'label' => 'Descuento %',
        'data_type' => 'NUMERIC',
        'attributes_json' => [
            'description' => 'Discount percentage applied to a sales order item',
            'min' => 0,
            'max' => 100,
            'step' => 0.1
        ]
    ],
    $actor
);
\bX\DataCaptureService::defineCaptureField(
    'SALES_APP',
    [
        'field_name' => 'ITEM_SKU',
        'label' => 'SKU',
        'data_type' => 'VARCHAR',
        'attributes_json' => [ 'description' => 'Product Stock Keeping Unit' ]
    ],
    $actor
);
// ... definir otros campos ...

// --- Guardando datos para un artículo específico de orden de venta ---
$applicationName = 'SALES_APP';
$context = [
    'SALES_ORDER_NUMBER' => 'SO12345',
    'LINE_ITEM_ID' => 'LI002'
];
$fieldsToSave = [
    ['field_name' => 'ITEM_SKU', 'value' => 'PROD-XYZ'],
    ['field_name' => 'ITEM_QUANTITY', 'value' => 5],
    // Podemos añadir una razón específica para este campo
    ['field_name' => 'ITEM_DISCOUNT_PERCENT', 'value' => 10.5, 'reason' => 'Special promotion applied.']
];
$actorUserId = \bX\Profile::$account_id ?: 'SALES_REP_01';
$defaultReason = "Initial entry for new order line.";

$result = \bX\DataCaptureService::saveRecord($applicationName, $context, $fieldsToSave, $actorUserId, $defaultReason);

if ($result['success']) {
    echo "Order line item data saved. Context Group ID: " . $result['context_group_id'];
    // $result['saved_fields_info'] contendría detalles de los campos guardados
} else {
    echo "Error: " . $result['message'];
}

// --- Recuperando datos actuales para ese artículo ---
$currentDataResult = \bX\DataCaptureService::getRecord($applicationName, $context, ['ITEM_DISCOUNT_PERCENT', 'ITEM_SKU']);
if ($currentDataResult['success']) {
    // $currentDataResult['data']['ITEM_DISCOUNT_PERCENT']['value'] sería 10.5
    // $currentDataResult['data']['ITEM_DISCOUNT_PERCENT']['label'] sería 'Descuento %'
    print_r($currentDataResult['data']);
}

// --- Recuperando pista de auditoría para el descuento ---
$auditTrailResult = \bX\DataCaptureService::getAuditTrailForField($applicationName, $context, 'ITEM_DISCOUNT_PERCENT');
if ($auditTrailResult['success']) {
    // $auditTrailResult['trail'] contiene todas las versiones de ITEM_DISCOUNT_PERCENT
    print_r($auditTrailResult['trail']);
}
?>
```

## Usae Example for Clinical Trials
```php
<?php // Ejemplo CDC con DataCaptureService actualizado

if (!isset(bX\Profile::$account_id) || bX\Profile::$account_id === 0) {
    bX\Profile::$account_id = 'SCRIPT_USER'; // O un ID de usuario simulado
}

echo "--- Ejemplo CDC: Definición y Captura de Signos Vitales (Usando DataCaptureService Actualizado) ---\n";

$applicationName = 'CDC_APP';
$studySetupActor = bX\Profile::$account_id ?: 'CDC_CONFIG_ADMIN';

echo "Definiendo campos para Signos Vitales...\n";

// Definiciones usando el formato de array (como en el .php)
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSPERF', 'label' => '¿Se realizó VS?', 'data_type' => 'VARCHAR',
        'attributes_json' => [ 'description' => 'Indica si se realizó la toma de signos vitales.', 'control_type' => 'RADIO_GROUP', 'datalist_source' => 's1:Y=Sí|N=No' ]
    ],
    $studySetupActor
);
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSDTC', 'label' => 'Fecha/Hora de Toma de VS', 'data_type' => 'VARCHAR',
        'attributes_json' => [ 'description' => 'Fecha y Hora (ISO 8601)', 'control_type' => 'DATETIME_PICKER' ]
    ],
    $studySetupActor
);
bX\DataCaptureService::defineCaptureField(
    $applicationName,
    [
        'field_name' => 'VSORRES_SYSBP', 'label' => 'P.A. Sistólica (Resultado)', 'data_type' => 'NUMERIC',
        'attributes_json' => [ 'description' => 'Presión Arterial Sistólica - Resultado Original', 'min' => 0, 'max' => 400 ]
    ],
    $studySetupActor
);
// ... (Definir todos los demás campos de manera similar) ...
echo "Definición de campos completada.\n\n";

// --- Guardando datos ---
echo "Guardando registro de Signos Vitales para un sujeto...\n";
$dataEntryActor = bX\Profile::$account_id ?: 'CDC_USER_002';
$reasonForEntry = "Toma de rutina, Visita Selección.";
$vsContext = [ 'BNX_PATIENT_ID' => 'PXYZ007', 'DOMAIN' => 'VS' ];
$vitalSignsDataToSave = [
    ['field_name' => 'VSPERF', 'value' => 'Y'],
    ['field_name' => 'VSDTC', 'value' => date('Y-m-d\TH:i:sP')],
    ['field_name' => 'VSORRES_SYSBP', 'value' => 122],
    // ... (Guardar todos los demás campos) ...
];
$saveResult = bX\DataCaptureService::saveRecord($applicationName, $vsContext, $vitalSignsDataToSave, $dataEntryActor, $reasonForEntry);
// ... (Manejar resultado) ...

// --- Recuperando datos ---
echo "Recuperando datos específicos de Signos Vitales...\n";
$fieldsToRetrieve = ['VSDTC', 'VSORRES_SYSBP', 'VSPERF'];
$currentVsDataResult = bX\DataCaptureService::getRecord($applicationName, $vsContext, $fieldsToRetrieve);

if ($currentVsDataResult['success'] && !empty($currentVsDataResult['data'])) {
    echo "Datos recuperados:\n";
    foreach ($currentVsDataResult['data'] as $fieldName => $fieldData) {
        // AHORA $fieldData es un array ['value' => ..., 'label' => ..., 'attributes' => ..., etc.]
        echo "  Campo: $fieldName\n";
        echo "    Label: " . ($fieldData['label'] ?? 'N/A') . "\n";
        echo "    Valor: " . ($fieldData['value'] ?? 'N/A') . "\n"; // << Acceder a ['value']
        echo "    Tipo Dato: " . ($fieldData['data_type'] ?? 'N/A') . "\n";
        echo "    Versión Num: " . ($fieldData['current_version_num'] ?? 'N/A') . "\n";
    }
    echo "\n";
} else {
    // ... (Manejar error) ...
}

// --- Recuperando pista de auditoría ---
echo "Recuperando pista de auditoría para Presión Arterial Sistólica...\n";
$auditTrailResult = bX\DataCaptureService::getAuditTrailForField($applicationName, $vsContext, 'VSORRES_SYSBP');
// ... (Manejar resultado como antes, ya estaba bastante alineado) ...

echo "--- Fin del Ejemplo ---\n";
?>
```