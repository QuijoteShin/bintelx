# Proyecto CDC sobre Bintelx

## 1. Introducción
La **app Clinical Data Capture (CDC)** es una solución headless, orientada a investigación médica, que gestiona formularios (CRF) y datos clínicos versionados con estricta trazabilidad.

---

## 2. Plataforma: Bintelx Agnóstico
- **Headless**: núcleo sin UI, expone servicios vía endpoints.
- **Capa de datos y estructuras**: Carga automaticamente las clases Bintelx e.g **CONN**, **FileHandler** y **DataCaptureService** (`bX\DataCaptureService`).
- **Autocarga de custom**: cualquier `custom/.../*.endpoint.php` se registra automáticamente en el router.

---

## 3. Estándares y Cumplimiento
- **ALCOA+**: datos Attributable, Legible, Contemporáneo, Original, Exacto y más.
- **CDISC**: nomenclatura de campos basada en estándares CDISC (p. ej. VS, DM, AE).

---

## 4. Uso de Contexto  de DataCaptureService
- Contextos `$contextKeyValues` para cada llamada: e.g
  ```php
  [
    'BNX_ENTITY_ID'  => 50032,    // ID interno CDC
    'FORM_DOMAIN' => 'VS'        // Ej Dominio de formulario CDISC para Signos Vitales)
  ]
  ```
**Regla**: No Cambiar el indice de $contextKeyValues dado que es es como crear una “tabla” nueva en DataCaptureService → se pierde historial.

**Versionado**: todos los cambios pasan por `saveRecord()`, Este que crea nueva versión en `capture_data_version` y actualiza el `capture_data` a.k.a “hot record”.

---

## 5. Tablas propias CDC (MySQL 8.1)
- `cdc_study`: datos de estudio como sponsor, lo mas basico y mvc
- `cdc_flow_chart`: Contiene cada la configuracion de visitas y  actividades por visita ( formularios ) versiona de manera interna dado que los campos se guardan en details_json
- `cdc_isf`: Captura los datos y mantiene la relacion de datos contra  lo solicitado en flowchart de cada visita de cada paciente.
- `cdc_form`: Crea relaccion formulario con campos de datos ( es el EDC primario para formularios)
- `cdc_form_instance`: Cada vez que el medico firma , relaciona el estudio, paciente, visita, formulario de dominio y campos; con con ID y VERSION `DataCaptureService`.
- `cdc_query`: queries o aclaraciones clínicas asociadas a campos tras finalización.

---
## 6 Dominio/Contexto y Guardado
Es solo uan propuesta de lo que creo que es funcional. se debe evaluar Completamente el punto 6.

Se debe usar saveRecord que permite guardar un conjunto de distintos campos relacionados al mismo tiempo asi como tambien un solo campo que pertence a un conjunto. crea la version con datos para cumplir con GCP.
Sin embargo las relaciones mas profundas relacionadas con el estudio lo debe manejar la app.
Por eso vamos a guardar conjuntos de datos a nivel de seccion. La mayoria de las secciones tendran 1 captura de dato.
En principio una seccion tambien es una captura de datos. que es titulo + sus campo/s

Jerarquia: Studio → Visita → Formulario/s → dato/s

- Una visita tiene que crea secciones donde se cargaran los formuarios.
- Los formularios son los grupos de actividades que parecen en el Flowchart. 

Ej Puede cerar toda una seccion que sea Signos Vitales, y solo cargarle el formulario VS que tiene ya sus campos definidos.

Cuando el operador esta creando una seccion o formulario debe idnicar que a que estudio y branch ( corde del estudio )
pertenece. Asi los formualrios quedaran asociados por CDC al estudio y se podra reutilizar formularios con datos CDISC de otros estudios.
Ademas el ID de los contextoa es entityID de bintelx, el cual es  independiente del SUBJECT ID que se obtiene cuando se randomiza un sujeto.

Cambiar el estado de un conjunto de datos o de un dato  versionados
Usar `DRAFT` o no usarlo y cambiar el estado a otro: Es es un evento importante y  se maneja en logica de negocio de cada modulo del  CDC.  Por ejemplo desde el UI con un button. No dentro de la clase de guardado. Se hara con botones.

## 6.a Dominios Formularios CDISC tipicos
`formDomain` Son las secciones de CDISC de formularios, su dominio.
* **DM**: Datos Demográficos (Demographics)
* **MH**: Historia Médica (Medical History)
* **AE**: Eventos Adversos (Adverse Events)
* **VS**: Signos Vitales (Vital Signs)
* **PE**: Examen Físico (Physical Examination)
* **CM**: Medicamentos Concomitantes (Concomitant Medications)

## 6.b Flujo de negocio (CDCService)     
- `getOrCreateFormInstance(studyId, visitId, formDomain, actor)`
- `saveFormData(formInstanceId, fieldsData[], actor)`
  - si status='DRAFT' → graba/HOT
  - si status='OPEN' → graba y versiona; incrementa `form_version`.
  - si status='FINALIZED' → sólo permite `addFieldQuery()`.
- `finalizeFormInstance(formInstanceId, actor)` → bloquea valores, pasa a FINALIZED.
- `getFormData(formInstanceId)` → obtiene valores “hot” + metadata.
- `getFieldAudit(formInstanceId, fieldName)` → historial de versiones.

---

## 6.C Flowchart de visitas u formularios

- `createflowChart(studyId, branchId, visitId, formDomain, actor)`
- `assocForms(flowChartId, visitId, formsWithVersions[], actor)`
  - si status='DRAFT' → graba 
  - si status='FINALIZED' → evento, graba y versiona; incrementa `form_version`.
- `finalizeFormInstance(flowChartId, actor)` → bloquea valores, pasa a FINALIZED.
- `getFormData(flowChartId)` → obtiene valores “hot” + metadata.
- `getFieldAudit(flowChartId, fieldName)` → historial de versiones.

## 6.d Captura de datos en Visita
el concepto es guardar informacion en el Investigator Site File., como si fueran fichas fisicas.
Un buen contexto seria entityId, visitId, flowchartId

Yo imagino esto;
Un Usario envia el formulario de VS de la visita 1 del estudio X en branch 1.
La hoja de VS creada en CRF tiene varios formualrios vs por separado
Formulario de presion.
Formulario de temperatura.

CDC de ISF debera gestionar correctamente que una "respuesta" (dato ISF) es para una "pregunta" ( dato CRF) y deben mantener
El ususario podr amandar uno o mas formularios a la vez en un array asociativo por dominio.

- `saveToISF(studyId, branchId, visitId, flowchartId, actor)`
  - `assocForms(saveToISF, visitId, formDomain, answerToFormsWithVersion[], actor)`
    - si status='DRAFT' → graba
    - si status='FINALIZED' → evento, graba y versiona; incrementa `form_version`. → el  UI ahora solo enviara campos individuales  y  `addFieldQuery()`. 
- `finalizeFormInstance(formInstanceId, actor)` → bloquea valores, pasa a FINALIZED. → Incrementa Versiones.
- `addFieldQuery(formInstanceId, fieldName, queryText, actor)` → registra aclaración.
- `getFormData(formInstanceId)` → obtiene valores “hot” + metadata.
- `getFieldAudit(formInstanceId, fieldName)` → historial de versiones.

### ¿Qué sucede cuando se editan múltiples campos en una sola entrada, como la dosis y la formulación de un medicamento en la hoja CM?

El investigador va hacer cambios unitarios, cada uno con su control de version, sin embargo en Audit Trail va existir un solo registro documentando la transaccion general.

En resumen, para mantener la estandarización y cumplir con las GCP, CDC puede permitir la edición múltiple dentro de un registro y registrarla como una única transacción de cambio. Lo fundamental es que cada modificación individual dentro de esa transacción sea claramente documentada y rastreable, reflejando así la intención y la práctica de los sistemas en papel, pero con las ventajas de la auditoría y la seguridad que ofrecen las plataformas electrónicas.
Se trata de documentar cada cambio de manera clara y atribuible dentro del propio CRF. Por lo tanto, aunque se realicen múltiples ediciones dentro de una misma entrada, estas se documentarían como parte de una única "instancia" de corrección en Audit Trail, siempre que se realicen al mismo tiempo y por la misma persona.

Es muy importante lo anterior, dado que el backend es quien crea AuditTrail se debe permitir que el usuario envie uno o mas campos de un mismo contexto de datos de CDC.


## 7. Estructura de `custom/cdc`
```bash
custom/cdc/
├─ crf.endpoint.php         # Rutas CRUD CRF (defineCaptureField)
├─ audittrail.endpoint.php  # Ruta para audit trail de un campo
├─ studies.endpoint.php     # Gestión de estudios/visitas
├─ isf.endpoint.php     
└─ Business/
   ├─ CRF.php              # Lógica de definición y versionado y uso de cdc_form_instance
   ├─ AuditTrail.php       # Lógica de recuperación de historialt tipo audit trail. 
   └─ Study.php            # Lógica de instancias (estudio–paciente–visita)
   └─ ISF.php              # Homólogo de carpeta de invesitador. GEstiona negocio de instancias (estudio–paciente–visita)
   └─ FlowChart.php        # Mantiene relacion de Formularios del FlowCharts del Estudio Con las visitas. Se Versiona las Visitas en base a `cdc_flow_chart`.`flow_chart_version` varchar(255)
    
```
Bintelx lee todos los `*.endpoint.php` y monta rutas según `api.md`/`endpoint.md`.
Un endpoint montado disponibiliza todo el Business para ser usado. 
---

## 8. Tecnología
- PHP 8.1
- MySQL 8.1 (charset `utf8mb4_general_ci`, columnas JSON si aplica)
- **CONN**: gestiona conexiones, transacciones y streaming de resultados con callbacks para memoria eficiente.
- **FILES**: manejo de archivos y logs.

---

# In Depth Module definition

---

# Clinical Data Capture (CDC) Module for Bintelx

## 1. Overview

The CDC (Clinical Data Capture) module is a custom application built on the Bintelx framework. It's designed as a headless solution for managing clinical trial data, focusing on the definition of studies, electronic Case Report Forms (eCRFs), data capture workflows, and ensuring data integrity and traceability according to standards like ALCOA+ and CDISC.

This module leverages core Bintelx components, particularly:
- **`bX\DataCaptureService`**: For all clinical data storage, ensuring that data is versioned, auditable, and stored agnostically. The CDC module defines its own application context (typically 'CDC_APP') and specific contexts for data points (e.g., based on `BNX_ENTITY_ID`, `FORM_DOMAIN`). The app will need more context but will use its own tables to keep them linked to data context let it be `STUDY_ID`, `VISIT_NUM`, etc.. This is a MUST as de data belongs to the EntityID and not to the study. But a study should fast and  eviciently look up to its colected data of a "entity" 
- **`bX\CONN`**: For all direct database interactions with CDC-specific metadata tables.
- **Bintelx Router**: For exposing business logic via HTTP endpoints. All `custom/cdc/*.endpoint.php` files are automatically registered.
- **Method Parameter Handling**: For methods Identify one primary parameter (the most essential param e.g EntityID or VisitID). Group all other secondary or optional parameters into a single array.

## 2. Core Concepts

- **Study (`cdc_study`)**: Defines the basic parameters of a clinical study.
- **Flowchart (`cdc_flow_chart`, `cdc_flow_chart_item`)**: Describes the schedule of visits and the forms/activities planned for each visit within a study. `cdc_flow_chart` stores the version name of the ammendment (e.g Protocol Version).
- 
- **CRF Fields**: Individual data points are defined using `DataCaptureService::defineCaptureField` under the 'CDC_APP' application name. These definitions follow CDISC naming conventions where applicable (e.g., VSTESTCD, VSORRES).
- **Form Instance (`cdc_form_instance`)**: Represents a specific instance of a data collection unit (e.g., all Vital Signs for a patient at a particular visit). It links to a `DataCaptureService` context group where the actual data values are stored and versioned. It has statuses like 'DRAFT', 'OPEN' (versioned), and 'FINALIZED'.
- **Investigator Site File - ISF (`cdc_isf`)**: Represents a collection of data captured and related to a  Form Instance. Belongs to a patient at a specific visit, potentially grouping multiple forms or domains as defined in the study's flowchart. This is a key table for tracking data entry events.
- **Queries (`cdc_query`)**: Allows for raising and resolving queries on specific data points within a form instance or ISF entry.

## 3. Data Storage Strategy

- **Metadata**: Stored in CDC-specific MySQL tables (e.g., `cdc_study`, `cdc_flow_chart`, `cdc_form_instance`, `cdc_isf`, `cdc_query`). These tables manage the structure, relationships, and status of clinical trial entities. May exist more than one flowchart for "visit 1" but one is_activeto . Must use flow_chart_version`  to disguinguis major version by ammendment. 
- **Clinical Data Values**: All actual clinical data points (e.g., a specific blood pressure reading, a patient's birth date) are stored and versioned using `bX\DataCaptureService`. The CDC module's business logic prepares the appropriate context (e.g., `['BNX_ENTITY_ID' => 50032, 'FORM_DOMAIN' => 'VS']` and use CDC tables link to ['VISIT_NUM' => 'SCREENING']) and data for `DataCaptureService::saveRecord()` calls`.

## 4.1 Directory Structure

- `custom/cdc/`: Root directory for the module.
  - `README.md`: This file.
  - `cdc.sql`: SQL definitions for CDC-specific metadata tables.
  - `Business/`: Contains PHP classes with the core business logic.
    - `CRF.php`: Logic for defining CRF fields and Fields Groups in `DataCaptureService`.
    - `Study.php`: Logic for managing studies.
    - `Flowchart.php`: Logic for managing study flowcharts and its textual amendments version (visits and forms per visit).
    - `FormInstance.php`: Logic for managing instances of forms being filled.
    - `ISF.php`: Logic for managing data capture via Investigator Site File entries.
    - `Query.php`: Logic for managing clinical queries.
    - `AuditTrail.php`: Logic for retrieving data history from `DataCaptureService`.
  - `*.endpoint.php`: Endpoint files that expose functionalities from the `Business/` classes via HTTP API routes.
    - `crf.endpoint.php`
    - `studies.endpoint.php`
    - `flowchart.endpoint.php`
    - `form_instance.endpoint.php`
    - `isf.endpoint.php`
    - `query.endpoint.php`
    - `audittrail.endpoint.php`

## 4.2 Workflow Highlights

1.  **Study Setup**:
  *   Define CRF fields relevant to the study using `CRF::defineCDCField` (via its endpoint).
  *   Create a new study using `Study::createStudy`.
  *   Define the study's flowchart (visits and forms per visit) using `Flowchart.php` methods.
2.  **Data Entry (using ISF workflow as an example)**:
  *   An ISF entry is created for a patient for a specific visit using `ISF::createISFEntry`.
  *   Data for various forms/domains within that ISF entry is saved using `ISF::saveDataToISF`. This method calls `DataCaptureService::saveRecord` with the appropriate context (including `BNX_ENTITY_ID`, `FORM_DOMAIN`), And use CDC tables to link them to `ISF_ID`, etc.
  *   The ISF entry can be finalized using `ISF::finalizeISFEntry`.
3.  **Data Review and Queries**:
  *   Data can be retrieved using `ISF::getISFData` or `FormInstance::getFormInstanceData`.
  *   Audit trails for specific fields can be viewed using `AuditTrail::getFieldAudit`.
  *   Queries can be raised on data points using `Query::addFieldQueryToInstance`.

This module aims to provide a robust and compliant way to manage clinical trial data within the Bintelx ecosystem.

# 5. Data Hierarchy and `DataCaptureService` Integration

The CDC (Clinical Data Capture) module employs a **multi-layered architecture** to manage clinical trial data effectively. This design ensures that both the complex structural and workflow requirements of clinical trials and the GxP-compliant data versioning needs are met. It achieves this by integrating the **CDC Application Layer** (your custom tables and logic) with the core **`bX\DataCaptureService` (DCS)**.

---

## 5.1 Architectural Overview

* **CDC Application Layer (`cdc_*` tables & `Business/` logic):** This layer acts as the **"Clinical Brain"**. It understands and manages the *context* and *structure* of the clinical trial: studies, sites, patients, protocol versions (flowcharts), visit schedules, form definitions, user roles, and operational workflows (Draft, Finalized, Locked, Queries). It defines *what* data needs to be collected and *under what circumstances*.
* **Data Capture Service (`bX\DataCaptureService` - DCS):** This layer acts as the **"Secure Vault & Ledger"**. It is a generic, low-level engine focused *exclusively* on storing individual data points (`CaptureData`), versioning every change (`CaptureDataVersion`), and maintaining a full, auditable trail. It doesn't know *why* it's storing '120', only that it's the value for 'VSORRES_SYSBP' in a specific context.

The power of this design lies in the **synergy** between these two layers, primarily linked via the `cdc_form_instance` table.

---

## 5.2 The Comprehensive Data Hierarchy

The journey of data, from study definition to a single versioned value, traverses these distinct but interconnected levels:

### A. Structural Layer (CDC App - Defining the "Blueprint")

This layer defines the *plan* for the study. It's relatively static but can be versioned (especially the flowchart).

* **`cdc_study`**: The highest level.
    * `study_internal_id`: Primary Key.
    * `study_id`: Public identifier (e.g., 'PROT-001').
    * *Purpose*: Holds core metadata for a specific clinical study.
* **`cdc_flow_chart`**: Defines the *schedule of visits* for a study, crucially including its version.
    * `flow_chart_id`: Primary Key.
    * `study_internal_id_ref`: Links to `cdc_study`.
    * **`flow_chart_version`**: **Key field.** Identifies the protocol amendment or flowchart version (e.g., 'v1.0', 'v2.0-Amendment-3').
    * `visit_name`, `visit_num`: Defines a specific planned visit (e.g., 'Screening', 'Week 4').
    * *Purpose*: Represents a specific *version* of the planned study schedule. You can have multiple `flow_chart_version` records per study.
* **`cdc_flow_chart_item`**: Defines *which forms/domains* are planned for a specific visit within a specific flowchart version.
    * `flow_chart_item_id`: Primary Key.
    * `flow_chart_id_ref`: Links to a specific visit in `cdc_flow_chart`.
    * `flow_chart_version`: Often denormalized here for easier lookup, or derived via `flow_chart_id_ref`.
    * `form_domain`: The CDISC-like code for the form (e.g., 'VS', 'DM', 'AE').
    * *Purpose*: Details the activities/forms expected at each visit, according to a specific protocol version.

### B. Instance Layer (CDC App - Recording "What Actually Happened")

This layer tracks *actual events* happening to *specific patients* within the study. It bridges the structural plan with real-world data capture events.

* **`bnx_entity` (Assumed)**: Represents the unique patient/subject.
* **`cdc_isf` (Investigator Site File Entry)**: Represents a *specific data collection event* for a patient, typically corresponding to a visit.
    * `isf_id`: Primary Key.
    * `study_internal_id_ref`: Links to `cdc_study`.
    * `bnx_entity_id`: Links to the patient.
    * `flow_chart_id_ref`: Links to the *planned* visit this event corresponds to.
    * **`flow_chart_version`**: **Key field.** Records which version of the flowchart was active *when this visit event occurred*.
    * *Purpose*: Acts as a container or log for a patient's visit, anchoring it to a specific point in the protocol.
* **`cdc_isf_form_instance_link`**: A linking table.
    * `isf_id_ref`: Links to `cdc_isf`.
    * `form_instance_id_ref`: Links to `cdc_form_instance`.
    * *Purpose*: Shows that multiple forms were filled during a single ISF/visit event.
* **`cdc_form_instance`**: **THE CRITICAL BRIDGE**. Represents a single form/domain being filled out for a patient during a specific visit event.
    * `form_instance_id`: Primary Key.
    * `study_internal_id_ref`, `bnx_entity_id`: Links to study and patient.
    * `visit_num_actual`: Records the actual visit identifier.
    * `form_domain`: The form being filled (e.g., 'VS').
    * **`flow_chart_version`**: **Key field.** Records the structural version under which *this specific form data* was captured.
    * `status`: Manages workflow ('DRAFT', 'FINALIZED', 'LOCKED').
    * **`data_capture_context_group_id`**: **The Foreign Key to DCS.** This directly links this clinical event to its underlying data in the `DataCaptureService`.
    * *Purpose*: Connects a specific clinical context (Patient X, Visit Y, Form Z, Protocol V) to the generic data storage engine (DCS).

### C. Data Layer (DCS - Storing the "Numbers and Text")

This is the `DataCaptureService`'s realm. It's generic and only concerned with fields, values, contexts, and versions.

* **`context_group`**: A logical grouping within DCS.
    * `context_group_id`: Primary Key. Linked from `cdc_form_instance`.
    * `application_name`: Should be 'CDC_APP'.
    * *Purpose*: Defines *one* unique "record" or "instance" within DCS (e.g., all 'VS' data for 'PXYZ007').
* **`context_group_item`**: Defines the *keys* that make up a `context_group`.
    * `context_group_id_ref`: Links to `context_group`.
    * `context_key`: e.g., 'BNX_ENTITY_ID'.
    * `context_value`: e.g., 'PXYZ007'.
    * *Purpose*: Creates the unique identifier for a context, allowing DCS to find the right `context_group`. (e.g., `BNX_ENTITY_ID='PXYZ007'` AND `FORM_DOMAIN='VS'`).
* **`capture_definition`**: Defines *what* a field is.
    * `definition_id`: Primary Key.
    * `application_name`: 'CDC_APP'.
    * `field_name`: e.g., 'VSORRES_SYSBP'.
    * `data_type`, `label`, `attributes_json`: Metadata about the field.
    * *Purpose*: A dictionary of all possible data fields the CDC app can capture.
* **`capture_data`**: Stores the *current ("hot") value* of a field within a specific context.
    * `capture_data_id`: Primary Key.
    * `definition_id_ref`: Links to `capture_definition` (Which field?).
    * `context_group_id_ref`: Links to `context_group` (Which patient/form?).
    * `field_value_varchar`, `field_value_numeric`: The current value.
    * `current_version_id_ref`: Links to the latest version in `capture_data_version`.
    * *Purpose*: Provides fast access to the most recent data value.
* **`capture_data_version`**: Stores the *complete history* of a field's value.
    * `version_id`: Primary Key.
    * `capture_data_id_ref`: Links back to the `capture_data` record.
    * `sequential_version_num`: 1, 2, 3...
    * `value_at_version`: The value as it was at this point in time.
    * `changed_at`, `changed_by_user_id`, `change_reason`, etc.: The **Audit Trail**.
    * *Purpose*: Ensures full traceability and meets GxP requirements for data history.

---

## 5.3 How It Handles Versioning

This hierarchical approach ensures comprehensive versioning:

1.  **Protocol/Flowchart Versioning:** When a study protocol is amended, a **new `cdc_flow_chart` record** (or set of records) is created with an updated `flow_chart_version`.
2.  **Capture-Time Version Linking:** When a user enters data, the CDC application identifies the **currently active `flow_chart_version`** and stores it in the `cdc_isf` and, critically, in the **`cdc_form_instance`** record.
3.  **Data Versioning:** Every time `DataCaptureService::saveRecord` is called for a field (except perhaps in a 'DRAFT' state, depending on your logic), DCS creates a **new `capture_data_version` record**.
4. **Data Versioning:** Every time `DataCaptureService::saveRecord` is called for a field (except perhaps in a 'DRAFT' state, depending on your logic), DCS creates a **new `capture_data_version` record**.
5.  **Traceability:** To understand any piece of data fully, you query DCS for its value and history. Then, using the `context_group_id`, you link back to `cdc_form_instance` to find out *which `flow_chart_version`* was in effect when that data (and all its historical versions) was captured.

This ensures that you not only know how a *value* changed over time but also how the *study plan* (protocol) might have changed, providing complete context for data review and analysis.




