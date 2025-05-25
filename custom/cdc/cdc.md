# Proyecto CDC sobre Bintelx - (Versión Actualizada)

## 1. Introducción

La **app Clinical Data Capture (CDC)** es una solución headless, orientada a investigación médica, construida sobre el framework Bintelx. Su objetivo es gestionar la definición de estudios clínicos, formularios electrónicos (eCRFs), flujos de captura de datos y, fundamentalmente, asegurar la integridad, trazabilidad y versionado de los datos clínicos conforme a estándares como ALCOA+ y CDISC.

---

## 2. Plataforma: Bintelx Agnóstico

El módulo CDC aprovecha las capacidades centrales de Bintelx:

* **Headless**: El núcleo CDC es una API; no impone una interfaz de usuario (UI), sino que expone servicios vía endpoints HTTP, permitiendo flexibilidad en el frontend.
* **Capa de Datos y Estructuras**: Se apoya en clases Bintelx como `bX\CONN` (conexión a BD), `bX\Log` (logs) y, crucialmente, `bX\DataCaptureService` para el almacenamiento versionado.
* **Autocarga de Custom**: La estructura `custom/cdc/` permite que las clases de negocio (`Business/`) y los endpoints (`*.endpoint.php`) se integren y carguen automáticamente.

---

## 3. Estándares y Cumplimiento

* **ALCOA+**: Diseñado para que los datos capturados sean Atribuibles, Legibles, Contemporáneos, Originales, Exactos (y Completos, Consistentes, Duraderos, Disponibles). El versionado de `DataCaptureService` es clave para esto.
* **CDISC**: Se fomenta el uso de nomenclatura CDISC (o similar) para los `field_name`s (ej. VS, DM, AE, VSORRES_SYSBP) para facilitar la interoperabilidad y estandarización, aunque no es estrictamente obligatorio por el sistema.

---

## 4. Arquitectura General

El CDC emplea una **arquitectura de dos capas principales** para equilibrar la flexibilidad, la estructura clínica y el cumplimiento normativo:

1.  **Capa de Aplicación CDC (`cdc_*` & `Business/`)**: Actúa como el **"Cerebro Clínico"**. Entiende y gestiona el *contexto* y la *estructura* del ensayo: estudios, pacientes, versiones de protocolo (flowcharts), visitas, formularios, roles y flujos de trabajo (Draft, Finalized, Locked, Queries). Define *qué* datos se recogen y *cuándo*.
2.  **Capa de Captura de Datos (`bX\DataCaptureService` - DCS)**: Actúa como la **"Bóveda Segura y Libro Mayor"**. Es un motor genérico y de bajo nivel centrado en *almacenar* puntos de datos individuales (`CaptureData`), *versionar* cada cambio (`CaptureDataVersion`), y mantener una *pista de auditoría* completa.

La vinculación principal entre estas capas se realiza a través de la tabla `cdc_form_instance`, que conecta un evento clínico específico con su `context_group_id` en DCS.

---

## 5. Estrategia de Almacenamiento y Jerarquía de Datos

### 5.1 Tablas Clave de la Capa CDC

Estas tablas gestionan la estructura, relaciones y estado de las entidades del ensayo clínico:

* **`cdc_study`**: Detalles centrales del estudio (ID, título, sponsor, estado).
* **`cdc_flow_chart`**: Define las *visitas planeadas* para un estudio, incluyendo su **versión** (`flow_chart_version`).
* **`cdc_flow_chart_item`**: Define *qué formularios/dominios* (`form_domain`) se planean para cada visita dentro de una versión específica del `cdc_flow_chart`.
* **`cdc_form_fields` (Propuesta/Requerida)**: **Tabla Esencial.** Vincula un `form_domain` a sus `field_name`s específicos *para un estudio*, definiendo el `item_order`, `is_mandatory`, `section_name` y posibles `attributes_override_json`. Es la *definición estructural* de un formulario.
* **`cdc_isf`**: Registra un *evento de visita* real para un paciente, vinculándolo a la visita planeada y la versión del flowchart. Actúa como un contenedor para los formularios de esa visita.
* **`cdc_isf_form_instance_link`**: Tabla de unión que vincula un `cdc_isf` con múltiples `cdc_form_instance`.
* **`cdc_form_instance`**: **El Puente Clave.** Representa una *instancia específica* de un formulario (`form_domain`) llenado por un paciente en una visita. Contiene el estado (`DRAFT`, `FINALIZED`) y el **`data_capture_context_group_id`** que lo enlaza a DCS.
* **`cdc_query`**: Gestiona las queries o aclaraciones sobre datos específicos.

### 5.2 Uso de `DataCaptureService` (DCS)

* **Contexto (`$contextKeyValues`)**: Se usa para identificar unívocamente un conjunto de datos en DCS. El contexto estándar para CDC es:
    ```php
    [
      'BNX_ENTITY_ID'  => 'PXYZ007', // ID Bintelx del Paciente
      'FORM_DOMAIN' => 'VS'         // Dominio del Formulario (e.g., Signos Vitales)
    ]
    ```
  **Regla Crucial**: La *estructura* (las claves) de `$contextKeyValues` para un tipo de registro **no debe cambiar**, ya que define la "tabla" lógica dentro de DCS.
* **Versionado**: Cada llamada a `saveRecord()` que modifica un valor genera una nueva `capture_data_version` y actualiza el `capture_data` (el "hot record").

### 5.3 Jerarquía Completa (Conceptual)
```bash
ESTRUCTURAL (CDC App - El Plan)
├─ cdc_study
│   └─ cdc_flow_chart (version)
│       └─ cdc_flow_chart_item (visit, form_domain)

DEFINICIÓN FORMULARIO (CDC App - El Diseño)
├─ cdc_study
│   └─ cdc_form_fields (form_domain -> field_name, order, section...)

INSTANCIA (CDC App - El Evento)
├─ bnx_entity (Paciente)
└─ cdc_isf (visit_event)
└─ cdc_isf_form_instance_link
└─ cdc_form_instance (form_event, form_domain, status, LINK_TO_DCS)

DATOS (DCS - Los Valores)
└─ context_group (Identificado por LINK_TO_DCS)
├─ context_group_item (BNX_ENTITY_ID = 'PXYZ007')
├─ context_group_item (FORM_DOMAIN = 'VS')
│   └─ capture_data ('VSORRES_SYSBP')
│       └─ capture_data_version (120, 122, 128...)
└─ capture_data ('VSDTC')
└─ capture_data_version (...)
```

---

## 6. Componentes Clave / Clases de Negocio (`Business/`)

* **`CDC\Study`**: Gestiona `cdc_study`. Métodos: `createStudy`, `getStudyDetails`, `updateStudyStatus`.
* **`CDC\CRF`**: Gestiona la definición de campos y la estructura de formularios. Métodos: `defineCRFField`, `addFormField`, `getFormSchema`.
* **`CDC\Study\Setup`**: Orquesta la configuración. Métodos: `configureForm`.
* **`CDC\Flowchart`**: Gestiona `cdc_flow_chart` y `cdc_flow_chart_item`. Métodos para crear/versionar flowcharts y asociar formularios a visitas.
* **`CDC\ISF` / `CDC\FormInstance`**: Gestionan la *captura* y el *estado* de los datos. Métodos para crear ISF/Form Instances, guardar datos (llamando a DCS) y finalizar formularios.
* **`CDC\AuditTrail`**: Proporciona interfaces para recuperar historiales de datos (llamando a `getAuditTrailForField` de DCS).

---

## 7. Flujos de Trabajo Principales

1.  **Configuración del Estudio (Study Setup):**
  * Se crea el estudio (`Study::createStudy`).
  * Se definen los campos base (`CRF::defineCRFField`).
  * Se definen los formularios vinculando campos y orden (`Setup::configureForm` o `CRF::addFormField`).
  * Se crea el flowchart (`Flowchart::createFlowchartVersion`), asociando los `form_domain` a las visitas (`Flowchart::addFormToVisit`).
2.  **Entrada de Datos (Data Entry - Flujo ISF):**
  * Se crea/obtiene un `cdc_isf` para el paciente/visita.
  * Para cada formulario, se crea/obtiene `cdc_form_instance`.
  * La UI llama a `CRF::getFormSchema` para mostrar el formulario.
  * (Opcional) La UI llama a `DCS::getRecord` para mostrar datos existentes.
  * El usuario introduce datos.
  * La UI envía los datos al backend (Endpoint ISF).
  * El backend llama a `DCS::saveRecord` con el contexto (`BNX_ENTITY_ID`, `FORM_DOMAIN`) y los datos **por cada `form_domain`**.
  * El backend actualiza `cdc_form_instance` con el `context_group_id`.
3.  **Finalización y Bloqueo:**
  * Se usan métodos en `ISF` o `FormInstance` para cambiar el estado a `FINALIZED` o `LOCKED`. Esto previene futuras ediciones (excepto queries).
4.  **Revisión y Auditoría:**
  * Se usan `DCS::getRecord` para ver datos actuales y `CDC\AuditTrail` (o `DCS::getAuditTrailForField`) para ver el historial completo.
5.  **Queries:**
  * Se usa una clase `CDC\Query` para añadir/resolver queries vinculadas a `cdc_form_instance` y `field_name`.

---

## 8. Estructura de Directorios (`custom/cdc/`)

```bash
custom/cdc/
├─ README.md              # Este documento
├─ cdc.sql                # Definiciones SQL (cdc_study, cdc_flow_chart, cdc_form_fields, etc.)
├─ Business/
│   ├─ Study.php            # Lógica para cdc_study
│   ├─ CRF.php              # Lógica para definiciones y schemas de formularios
│   ├─ Flowchart.php        # Lógica para cdc_flow_chart y cdc_flow_chart_item
│   ├─ FormInstance.php     # Lógica para cdc_form_instance
│   ├─ ISF.php              # Lógica para cdc_isf y orquestación de guardado
│   ├─ Query.php            # Lógica para cdc_query
│   ├─ AuditTrail.php       # Lógica para recuperar historiales
│   └─ Study/
│      └─ Setup.php         # Lógica de orquestación para configurar estudios
│
└─ *.endpoint.php         # Archivos que exponen la lógica de Business como API
   ├─ studies.endpoint.php
   ├─ crf.endpoint.php
   ├─ flowchart.endpoint.php
   └─ isf.endpoint.php
   # ... etc
```

---

## 9. Tecnología

* PHP 8.1+
* MySQL 8.1+ (`utf8mb4_general_ci`, uso de `JSON` cuando aplique)
* Bintelx Core: `bX\CONN`, `bX\Log`, `bX\DataCaptureService`, `bX\WarmUp`.

---

## 10. Puntos Resueltos y Decisiones Pendientes

Esta sección resume las decisiones tomadas y los puntos que aún requieren definición:

1.  **Gestión de "Secciones" dentro de Formularios:**
  * **Decisión: Resuelto.** Se manejará a través del campo `section_name` en la tabla `cdc_form_fields`, gestionado por `CRF::addFormField`. Su propósito principal es la **agrupación visual en la UI** y será parte del output de `CRF::getFormSchema`. No implica una lógica de almacenamiento separada en DCS.
2.  **Guardado de Múltiples Formularios/Dominios a la Vez:**
  * **Decisión: Resuelto.** La UI *puede* enviar datos de múltiples `form_domain`s en una sola solicitud. Sin embargo, el **backend *debe* procesarlos iterativamente**, realizando una llamada `DataCaptureService::saveRecord` **separada por cada `form_domain`**, ya que cada uno representa un contexto DCS distinto.
3.  **Rol de `cdc_form`:**
  * **Decisión: Descartado.** Se considera **redundante**. Sus funciones son cubiertas por: `form_domain` (string) como identificador, `cdc_form_fields` como definidor de contenido/estructura, y `cdc_flow_chart_item` como asignador a visitas.
4.  **Rol de `details_json` en `cdc_flow_chart_item`:**
  * **Decisión: Descartado para definir campos.** Su uso para definir la estructura de campos se descarta en favor de `cdc_form_fields` por ser más estructurado y consultable.
  * **Posible Uso:** Podría usarse para **metadatos opcionales y específicos de la visita** (ej. "Instrucciones especiales para esta visita"), pero no para la estructura central del formulario.
  * **Jerarquía Validada:** El diseño actual con `cdc_flow_chart`, `cdc_flow_chart_item`, `cdc_form_fields` y DCS **sí permite** la jerarquía Flowchart -> Formulario -> Sección -> Pregunta y **sí permite** la trazabilidad de la versión del protocolo en la captura de datos.
5.  **Manejo de "Branches" / Múltiples Cohortes:**
  * **Pendiente:** El MD original menciona "branch". **Se necesita definir formalmente** cómo se modelará esto en la estructura de la base de datos y cómo afectará la configuración de flowcharts y formularios. ¿Será una columna en `cdc_study` o `cdc_flow_chart`? ¿Implicará versiones diferentes de `cdc_form_fields`?

---

## 11. Info para IA

Vamos a trabajar en el desarrollo del módulo CDC (Clinical Data Capture) para nuestro framework Bintelx. Te he proporcionado acceso a un repositorio Git que contiene varios archivos `.md` con las especificaciones y el diseño conceptual que hemos elaborado hasta ahora, incluyendo:

* `cdc.md`: El README general del proyecto ( this file ) , que describe la arquitectura de dos capas, la estrategia de datos, los componentes clave y los flujos de trabajo principales. **Es crucial que comprendas bien este documento, especialmente las secciones sobre la jerarquía de datos y los puntos resueltos/pendientes.**
* `cdc.sql`: El esquema de base de datos consolidado para las tablas específicas del módulo CDC.
* `.md` individuales para las clases de negocio: `Study.md`, `CRF.md`, `Flowchart.md`, `FormInstance.md`, `ISF.md`. (Definiremos `Query.md` y `AuditTrail.md` a continuación). Estos describen el propósito, dependencias y la interfaz (métodos) de cada clase.
* `CDC_Operator_Workflows.md`: Un documento que describe los casos de uso y flujos de trabajo desde la perspectiva del operador, resaltando requisitos funcionales clave.

**Mi Objetivo Principal para el Módulo CDC:**

Quiero construir un sistema **robusto, granular, con alta atomicidad en sus operaciones y, fundamentalmente, auditable a todos los niveles.** Esto es para un entorno de ensayos clínicos, por lo que el cumplimiento de GxP y la integridad de los datos son primordiales.

**Por Qué Es Tan Importante Seguir las Reglas/Diseño que Hemos Definido:**

Hemos invertido considerable tiempo en refinar la arquitectura para abordar preocupaciones específicas, basadas en experiencia previa con sistemas EDC. Los puntos más críticos que el diseño actual busca resolver son:

1.  **Versionado Dual y Trazabilidad Completa:**
  * **Datos del Paciente:** `bX\DataCaptureService` (DCS) maneja el versionado atómico de cada dato. Esto es fundamental.
  * **Configuración del Estudio (Setup):** Este es un punto de gran importancia para mí. Necesitamos poder reconstruir **exactamente cómo estaba configurado el estudio (flowchart, estructura de formularios, branches) para cualquier `flow_chart_version` publicada.** Esto se logra versionando `cdc_form_fields` junto con `cdc_flow_chart` y `cdc_flow_chart_item` bajo una `flow_chart_version_string`. La `cdc_form_instance` luego "sella" la `flow_chart_version_actual` y `branch_code_actual` al momento de la captura. Esto es vital para auditorías y para entender datos históricos.
2.  **Manejo de Branches (Ramas del Estudio):** El sistema debe permitir definir diferentes caminos (branches) y que los pacientes puedan ser asignados y, si es necesario, cambiados de branch, manteniendo siempre la trazabilidad de qué configuración se siguió para cada dato capturado. La tabla `cdc_patient_study_branch` y el uso de `branch_code` en `cdc_flow_chart_item` y `branch_code_actual` en `cdc_isf` y `cdc_form_instance` son claves.
3.  **Claridad entre Setup y Data Entry:** El flujo DRAFT -> PUBLISHED para las `flow_chart_version` (manejado por `cdc_flowchart_versions_status`) es para asegurar que el setup se pueda trabajar iterativamente sin afectar la captura de datos en versiones publicadas. Las versiones publicadas de la configuración deben ser inmutables.
4.  **Modularidad y Responsabilidades Claras:** La arquitectura de dos capas (CDC App sobre DCS) y la definición de clases de negocio específicas (`Study`, `CRF`, `Flowchart`, `ISF`, `FormInstance`, etc.) buscan una separación clara de responsabilidades.

**Restricciones y Fundamentos:**

* **Headless:** El sistema es un backend con una API. No hay generación de HTML en las clases de negocio.
* **Uso de Componentes Bintelx:** Debemos usar `bX\CONN`, `bX\Log`, y `bX\DataCaptureService` según lo especificado. Para la **identificación del actor (`actorUserId`)**, las clases de negocio CDC deben obtenerlo internamente desde el framework Bintelx (por ejemplo, a través de `\bX\Profile::$account_id` o el identificador de **cuenta de usuario** que esté realizando la acción, no necesariamente un `profile_id` que puede ser compartido o contextual a un sitio). La atribución correcta al individuo/cuenta es crucial.
* **Consistencia:** Mantener la consistencia en la nomenclatura de métodos, parámetros, y estructura de retornos (ej. `['success' => bool, 'data_key' => ..., 'message' => string]`).
* **SQL:** El `cdc.sql` proporcionado es la base de datos con la que deben interactuar las clases CDC. Por favor, no introduzcas Foreign Keys físicas si están comentadas en el script DDL; manejaremos la integridad referencial a nivel de aplicación o las definiremos explícitamente más adelante si es necesario.

**Quiero Alinearme Contigo Desde un Principio:**

He trabajado extensamente para llegar a estas especificaciones. Mi temor es que, si no se siguen de cerca estos `.md` y el `cdc.sql`, podríamos derivar a soluciones que no aborden mis preocupaciones fundamentales sobre el versionado del setup, la auditabilidad y la correcta atribución de acciones, o que reintroduzcan complejidades que ya hemos decidido evitar.

Por favor, antes de proponer una implementación para una clase, asegúrate de haber comprendido completamente su `.md` correspondiente y cómo encaja en el `cdc.md` (README general) y el `CDC_Operator_Workflows.md`. Si tienes dudas o ves una forma *mejor* de lograr el mismo objetivo *dentro de estas restricciones y principios*, por favor, plantéalo para que podamos discutirlo.

**Nuestro Próximo Paso Inmediato Juntos:**

Será definir los `.md` para `CDC\Query` y `CDC\AuditTrail`. Luego, podríamos empezar con la implementación PHP de `Flowchart.php`, ya que su `.md` está bastante completo.

Agradezco tu ayuda para construir un sistema que sea eficiente, robusto y, sobre todo, que cumpla con los altos estándares de integridad y auditoría que requieren los ensayos clínicos."


##