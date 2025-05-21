
**1. Plantilla Markdown para un Nuevo Script de Prueba (`generic_test_script.md`)**

```markdown
# Script de Prueba: [Ruta al Nombre del Módulo/Servicio]

**Archivo:** `app/test/[nombre_modulo_o_servicio].php`

## Propósito

Este script sirve como una prueba de integración y un ejemplo de uso para las funcionalidades clave de `[Namespace\NombreClaseServicioPrincipal]`. Su objetivo es demostrar y verificar:
1.  [Funcionalidad Principal 1 - ej. Creación de una entidad específica]
2.  [Funcionalidad Principal 2 - ej. Actualización de datos versionados]
3.  [Funcionalidad Principal 3 - ej. Lectura de datos bajo diferentes contextos]
4.  [Funcionalidad Principal 4 - ej. Manejo de casos de error esperados]
5.  (Opcional) Interacción con otros servicios/módulos si aplica.

El script está diseñado principalmente para ejecutarse desde la línea de comandos (CLI), pero puede adaptarse para pruebas vía HTTP si el servicio se expone a través de endpoints.

## Prerrequisitos

1.  **Entorno BintelX Configurado**: `WarmUp.php` accesible y funcional.
2.  **Conexión a Base de Datos**: `bX\CONN` configurado para la(s) base(s) de datos relevantes.
3.  **Tablas Requeridas**: Todas las tablas de base de datos que `[NombreClaseServicioPrincipal]` y sus dependencias utilizan deben existir y estar correctamente migradas.
4.  **(Opcional) Datos de Prueba Iniciales**: Si el test depende de datos preexistentes (ej. un usuario, una configuración), asegurar que estén disponibles o que el script los cree.
5.  **Clases del Módulo/Servicio**: Las clases PHP (`[NombreClaseServicioPrincipal].php`, modelos, helpers, etc.) deben estar completas y en las ubicaciones correctas para el autoloader.
6.  **(Opcional) Autenticación**: Si las operaciones requieren un usuario autenticado, el script debe manejar el login (usando `bX\Auth` y `bX\Profile`) para obtener un `actorUserId`.

## Estructura Sugerida del Script de Prueba

```php
<?php // app/test/[nombre_modulo_o_servicio].php

// --- INICIALIZACIÓN Y BOOTSTRAP ---
require_once '[ruta_a_bintelx]/WarmUp.php'; // Ajustar ruta
// new \bX\Args(); // Si se usan argumentos CLI

// (Opcional) Simular entorno web/router si es necesario para el servicio
// $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// $method = $_SERVER['REQUEST_METHOD'];
// $route = new \bX\Router($uri);
\bX\Log::$logLevel = 'DEBUG'; // O el nivel deseado para la prueba
\bX\Log::$logToUser = true;

// (Opcional) Autenticación si se requiere un actor
// $auth = new \bX\Auth(...);
// $profile = new \bX\Profile();
// if ($auth->login(['username' => 'test_user', 'password' => '...'])) {
//     $actorUserId = \bX\Profile::$account_id;
//     echo "Usuario de prueba autenticado: " . $actorUserId . "\n";
// } else {
//     echo "FALLO: Autenticación de usuario de prueba fallida. Saliendo.\n";
//     exit(1);
// }
// Si no se requiere login, se puede usar un actorUserId genérico para el script:
$scriptActorId = 'TEST_SCRIPT_ACTOR';

// --- INICIO DE PRUEBAS ---
echo "--- Iniciando Pruebas para [Nombre del Módulo/Servicio] ---\n\n";

// Instanciar la clase del servicio/controlador principal a probar
// $service = new \[Namespace]\[NombreClaseServicioPrincipal]();

// --- CASO DE PRUEBA 1: [Descripción breve del caso] ---
echo "Caso 1: [Descripción]...\n";
try {
    // Preparar datos de entrada para el método a probar
    // $inputData1 = [...];
    // Llamar al método del servicio
    // $result1 = $service->metodoAProbar($inputData1, $scriptActorId); // o $actorUserId
    
    // Verificar el resultado (asserción simple o salida para revisión manual)
    // if ($result1['success'] && !empty($result1['data_id'])) {
    //     echo "  ÉXITO: [Descripción del éxito]. ID: " . $result1['data_id'] . "\n";
    //     // Guardar IDs para usarlos en pruebas subsecuentes si es necesario
    //     // $createdId1 = $result1['data_id'];
    // } else {
    //     echo "  FALLO: [Descripción del fallo]. Mensaje: " . ($result1['message'] ?? 'Error desconocido') . "\n";
    //     // Considerar salir o marcar la prueba como fallida
    // }
} catch (Exception $e) {
    echo "  EXCEPCIÓN en Caso 1: " . $e->getMessage() . "\n";
    // \bX\Log::logError("Excepción en Test Script - Caso 1", ['exception' => $e]);
}
echo "\n";

// --- CASO DE PRUEBA 2: [Descripción breve del caso] ---
// echo "Caso 2: [Descripción]...\n";
// ... (similar al Caso 1) ...
// Si este caso depende del resultado del Caso 1, usar $createdId1
// if (isset($createdId1)) {
//     // ...
// } else {
//     echo "  OMITIDO: Caso 2 depende del éxito del Caso 1.\n";
// }
// echo "\n";

// --- (Más casos de prueba según sea necesario) ---

// --- PRUEBA DE MANEJO DE ERRORES (Opcional pero recomendado) ---
// echo "Caso X: Prueba de [Entrada inválida / Condición de error]...\n";
// try {
    // $invalidInput = [...];
    // $resultX = $service->metodoAProbar($invalidInput, $scriptActorId);
    // if (!$resultX['success']) { // Se espera un fallo
    //     echo "  ÉXITO (Error esperado): Falló como se esperaba. Mensaje: " . $resultX['message'] . "\n";
    // } else {
    //     echo "  FALLO (Error no detectado): Debería haber fallado pero tuvo éxito.\n";
    // }
// } catch (InvalidArgumentException $e) { // Ejemplo de excepción específica esperada
//     echo "  ÉXITO (Excepción esperada): " . $e->getMessage() . "\n";
// } catch (Exception $e) {
//     echo "  EXCEPCIÓN INESPERADA en Caso X: " . $e->getMessage() . "\n";
// }
// echo "\n";


// --- LIMPIEZA (Opcional) ---
// Si el script crea datos que deben eliminarse después de la prueba:
// echo "Limpiando datos de prueba...\n";
// try {
    // Llamar a métodos de borrado o ejecutar SQL directo (con precaución)
    // if (isset($createdId1)) {
    //     // $service->deleteTestData($createdId1);
    // }
//     echo "  Limpieza completada.\n";
// } catch (Exception $e) {
//     echo "  ERROR en Limpieza: " . $e->getMessage() . "\n";
// }

echo "--- Pruebas para [Nombre del Módulo/Servicio] Completadas ---\n";
?>
```

## Ejecución

```bash
php app/test/[nombre_modulo_o_servicio]_test.php
```
*(Asegúrate de que `display_errors` y `error_reporting` estén configurados adecuadamente para ver todos los errores durante la ejecución desde CLI).*

## Puntos a Observar Durante la Prueba

*   **Salida de la Consola**: Verifica los mensajes de "ÉXITO" y "FALLO". Asegúrate de que los resultados (IDs, datos devueltos) sean los esperados.
*   **Base de Datos**: Inspecciona las tablas relevantes para confirmar que los datos se crearon, actualizaron o eliminaron según lo previsto por cada caso de prueba.
*   **Archivos de Log (`../log/`)**: Revisa los logs de BintelX para cualquier error, advertencia o mensaje de debug que el servicio haya podido registrar durante la ejecución de las pruebas.
*   **Cobertura**: Intenta que los casos de prueba cubran los flujos principales, casos límite y manejo de errores comunes del servicio.

## Solución de Problemas Comunes

*   **Errores de PHP (Parse, Fatal, etc.)**: Verifica la sintaxis, las rutas de `require_once`, y la correcta carga de clases.
*   **Errores de Conexión a BD**: Revisa la configuración de `bX\CONN`.
*   **Resultados Inesperados del Servicio**: Usa `var_dump()`, `print_r()`, o la función `dd()` (si está disponible) dentro del script de prueba o temporalmente dentro del código del servicio para inspeccionar variables y el flujo de datos.
*   **Dependencias no Satisfechas**: Asegúrate de que cualquier dato o configuración que el servicio necesite esté presente antes de ejecutar la prueba.

Este script de prueba es una herramienta vital para el desarrollo iterativo y para asegurar la calidad y estabilidad de `[NombreClaseServicioPrincipal]`.
```

---

**2 Nuevo Archivo de Endpoints (`generic_module_endpoints.md`)**

```markdown
# API para el Módulo: [Nombre del Módulo]

**Archivo de Endpoints:** `custom/[nombre_app]/[nombre_modulo]/endpoint.php` (o estructura similar)
**Clase(s) Controlador(as) de Lógica de Negocio:** `[Namespace\Modulo]\NombreControladorController.php` (ej. `Module\CDC\FormsDefinitionController.php`)

## Propósito

Este archivo define los endpoints HTTP para gestionar los recursos y operaciones relacionadas con el módulo `[Nombre del Módulo]` dentro de la aplicación `[Nombre de la Aplicación]`. Se integra con `bX\Router` para el enrutamiento y delega la lógica de negocio a las clases controladoras correspondientes.

## Consideraciones Generales de Diseño de Endpoints

*   **RESTful (Orientado a Recursos)**: Siempre que sea posible, diseñar endpoints alrededor de recursos (ej. `/forms`, `/forms/{id}`).
*   **Métodos HTTP Correctos**:
    *   `GET`: Para recuperar recursos.
    *   `POST`: Para crear nuevos recursos o ejecutar acciones que no son idempotentes.
    *   `PUT`: Para actualizar un recurso completo (reemplazo).
    *   `PATCH`: Para actualizar parcialmente un recurso.
    *   `DELETE`: Para eliminar un recurso.
*   **Scopes de Router**: Asignar el `ROUTER_SCOPE_*` apropiado a cada endpoint para control de acceso.
*   **Validación de Entrada**: La validación de los datos de la solicitud (payload, parámetros de URL) debe realizarse en la clase controladora o en una capa de servicio.
*   **Respuestas HTTP**:
    *   Usar códigos de estado HTTP significativos (200 OK, 201 Created, 400 Bad Request, 401 Unauthorized, 403 Forbidden, 404 Not Found, 500 Internal Server Error, etc.).
    *   Devolver payloads JSON consistentes. Para éxito, incluir los datos relevantes. Para errores, un mensaje claro.
*   **Idempotencia**: `GET`, `PUT`, `DELETE` deben ser idempotentes. `POST` y `PATCH` no necesariamente.
*   **Paginación y Filtrado**: Para endpoints `GET` que devuelven listas, considerar parámetros para paginación (ej. `?page=1&limit=20`) y filtrado (ej. `?status=active`).

## Estructura del Archivo `endpoint.php`

```php
<?php // custom/[nombre_app]/[nombre_modulo]/endpoint.php

use bX\Router;
use [Namespace\Modulo]\NombreControladorController; // Reemplazar con el namespace y clase correctos

// (Opcional) Constantes o configuración específica del módulo para los endpoints
// define('MODULO_X_DEFAULT_LIMIT', 25);

// --- Gestión de Recurso [Nombre del Recurso Principal, ej. Formularios] ---

// Crear un nuevo [Recurso]
Router::register(
    ['POST'], // Método HTTP
    '[nombre_recurso_plural]', // Ruta relativa al módulo (ej. 'forms')
    [NombreControladorController::class, 'create[NombreRecurso]'], // Método del controlador
    ROUTER_SCOPE_WRITE // Permiso requerido (ajustar según necesidad)
);

// Obtener una lista de [Recursos] (con posible paginación/filtrado)
Router::register(
    ['GET'],
    '[nombre_recurso_plural]',
    [NombreControladorController::class, 'list[NombreRecursoPlural]'],
    ROUTER_SCOPE_READ
);

// Obtener un [Recurso] específico por su ID
Router::register(
    ['GET'],
    '[nombre_recurso_plural]/(?P<id>[\w-]+)', // Ruta con parámetro ID (ajustar regex si ID es numérico: \d+)
    [NombreControladorController::class, 'get[NombreRecurso]ById'],
    ROUTER_SCOPE_READ
);

// Actualizar un [Recurso] específico (completo con PUT, o parcial con PATCH)
Router::register(
    ['PUT', 'PATCH'],
    '[nombre_recurso_plural]/(?P<id>[\w-]+)',
    [NombreControladorController::class, 'update[NombreRecurso]'],
    ROUTER_SCOPE_WRITE
);

// Eliminar un [Recurso] específico
Router::register(
    ['DELETE'],
    '[nombre_recurso_plural]/(?P<id>[\w-]+)',
    [NombreControladorController::class, 'delete[NombreRecurso]'],
    ROUTER_SCOPE_WRITE // A menudo requiere permisos elevados
);

// --- Gestión de Sub-Recursos o Acciones Específicas ---
// Ejemplo: Obtener los campos de un formulario específico
// Router::register(
//     ['GET'],
//     'forms/(?P<formId>[\w-]+)/fields', 
//     [NombreControladorController::class, 'getFormFields'],
//     ROUTER_SCOPE_READ
// );

// Ejemplo: Publicar un formulario (acción específica)
// Router::register(
//     ['POST'],
//     'forms/(?P<formId>[\w-]+)/publish', 
//     [NombreControladorController::class, 'publishForm'],
//     ROUTER_SCOPE_WRITE
// );

// --- (Añadir más definiciones de rutas según sea necesario) ---

/**
 * NOTAS PARA EL DESARROLLADOR:
 * 
 * 1. Nomenclatura de Rutas:
 *    - Usar sustantivos en plural para colecciones de recursos (ej. `/forms`).
 *    - Usar identificadores en la ruta para recursos específicos (ej. `/forms/{formId}`).
 *    - Para acciones que no encajan en CRUD, usar verbos o sustantivos descriptivos (ej. `/forms/{formId}/publish`).
 * 
 * 2. Parámetros de Ruta:
 *    - Usar la sintaxis de grupo nombrado de PCRE: `(?P<nombre_parametro>regex_parametro)`.
 *    - Ejemplo: `(?P<id>\d+)` para un ID numérico, `(?P<uuid>[\da-fA-F]{8}-[\da-fA-F]{4}-... )` para un UUID.
 *    - Estos parámetros se pasarán como argumentos a la función callback del controlador.
 * 
 * 3. Payload de la Solicitud (POST, PUT, PATCH):
 *    - Se espera que los datos vengan en el cuerpo de la solicitud, comúnmente como JSON.
 *    - En el controlador, acceder a ellos vía `$_POST` (si `Content-Type: application/json` y `api.php` lo procesa) o `file_get_contents('php://input')` y `json_decode()`.
 *    - `bX\Args::$OPT` también puede contener los datos si `bX\Args` está configurado para procesar JSON input.
 * 
 * 4. Respuestas:
 *    - Establecer `header('Content-Type: application/json');` al inicio del callback.
 *    - Usar `http_response_code()` para el código de estado.
 *    - Devolver `json_encode()` de un array asociativo.
 * 
 * 5. Clases Controladoras:
 *    - La lógica de negocio (validación, interacción con servicios, llamadas a `bX\CONN`) debe residir en las clases Controladoras, no directamente en los callbacks del router.
 *    - Los métodos del controlador deben ser públicos y, si no son estáticos, el router instanciará la clase.
 * 
 * 6. Autenticación y Autorización:
 *    - `bX\Router` maneja la verificación del scope (`ROUTER_SCOPE_*`).
 *    - Para lógica de permisos más granular dentro de un endpoint (ej. ¿puede este usuario editar *este* recurso específico?), el controlador debe implementarla, posiblemente usando `bX\Profile::hasPermission()` o lógica similar.
 */
```

## Documentación de Endpoints Específicos (Ejemplo)

*(Para cada endpoint importante, se debería crear una subsección como esta)*

### Crear un Nuevo Formulario

*   **Endpoint:** `POST /api/[nombre_modulo]/forms`
*   **Descripción:** Crea una nueva definición de formulario base.
*   **Scope Requerido:** `ROUTER_SCOPE_WRITE` (o un scope más específico como `FORM_DESIGNER`)
*   **Request Body (JSON):**
    ```json
    {
        "form_name_code": "DM_V1", // Código único del formulario
        "form_title": "Demographics Form Version 1.0",
        "description": "Collects subject demographic information.",
        // ...otros campos necesarios para `cdc_forms`...
    }
    ```
*   **Respuesta Exitosa (201 Created):**
    ```json
    {
        "success": true,
        "message": "Formulario creado exitosamente.",
        "form_id": 123 // El ID del nuevo formulario creado
    }
    ```
*   **Posibles Errores:**
    *   `400 Bad Request`: Datos de entrada inválidos o faltantes.
    *   `409 Conflict`: Si un formulario con el mismo `form_name_code` ya existe.
    *   `500 Internal Server Error`: Error al guardar en la base de datos.

---
