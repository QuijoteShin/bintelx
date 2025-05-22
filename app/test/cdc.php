<?php // app/test/cdc.php

require_once '../../bintelx/WarmUp.php';
new \bX\Args();

\bX\Log::$logToUser = true;
\bX\Log::$logLevel = 'DEBUG';

use bX\Router;
use cdc\CRF;

// Simulación de entorno autenticado
$account = new \bX\Account("woz.min..", 'XOR_KEY_2o25');
$profile = new \bX\Profile();
$profile->load(['account_id' => 1]); // Usuario de prueba
$actor = \bX\Profile::$account_id;

// --- Cargar el módulo CDC y sus endpoints ---
$module = 'cdc';
Router::load(
    ["find_str" => \bX\WarmUp::$BINTELX_HOME . '../custom/', 'pattern' => '{*/,}{endpoint,controller}.php'],
    function ($route) use ($module) {
        if (is_file($route['real']) && strpos($route['real'], "/$module/") !== false) {
            require_once $route['real'];
        }
    }
);

// --- Probar creación de campo tipo CRF ---
echo "--- Probando creación de campo CRF ---\n";

$fieldData = [
    'field_name' => 'TEST_DATE',
    'label' => 'Fecha Test',
    'data_type' => 'VARCHAR',
    'attributes_json' => [
        'description' => 'Campo de fecha de prueba para CRF',
        'control_type' => 'DATETIME_PICKER'
    ]
];

$result = CRF::createFieldDefinition($fieldData, $actor);

if ($result['success']) {
    echo "✅ Campo creado exitosamente.\n";
    echo "ID definición: " . ($result['definition_id'] ?? 'null') . "\n";
} else {
    echo "❌ Error al crear campo: " . $result['message'] . "\n";
}

echo "--- Fin de prueba ---\n";
