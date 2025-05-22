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
    'BNX_ENTITY_ID'  => 'P0001',    // ID interno CDC
    'FORM_DOMAIN' => 'VS'        // Ej Dominio de formulario CDISC para Signos Vitales)
  ]
  ```
**Regla**: cambiar el indice de $contextKeyValues es como crear una “tabla” nueva en DataCaptureService → se pierde historial.

**Versionado**: todos los cambios pasan por `saveRecord()`, que crea nueva versión en `capture_data_version` y actualiza el “hot record”.

---

## 5. Tablas propias CDC (MySQL 8.1)
- `cdc_study`: datos de estudio como sponsor, lo mas basico y mvc
- `cdc_flow_chart`: Contiene cada la configuracion de visitas y  actividades por visita ( formularios )
- `cdc_isf`: Captura los datos y mantiene la relacion de datos contra  lo solicitado en flowchart de cada visita de cada paciente.
- `cdc_form`: Crea relaccion formulario con campos de datos ( es el EDC primario para formularios)
- `cdc_form_instance`: Cada vez que el medico firma , relaciona el estudio, paciente, visita, formulario de dominio y versión de `DataCaptureService`.
- `cdc_query`: queries o aclaraciones clínicas asociadas a campos tras finalización.

---
## 6 Dominio/Contexto y gurdado
Es solo uan propuesta de lo que creo que es fucniona. se debe evaluar compleetamente el punto 6.

Se debe usar saveRecord que permite guardar distintos datos al mismo tiempo. crea la version con datos para cumplir con GCP.
Sin embargo las relaciones mas profundas relacionadas con el estudio lo debe manejar la app.
Por eso vamos a guardar conjuntos de datos a nivel de seccion. La mayoria de las secciones tendran 1 captura de dato.
En principio una seccion tambien es una captura de datos. que es titulo + sus campo/s

Jerarquia: Studio → Visita → Formulario → dato/s

Una visita tiene crea secciones donde carga los formuarios.
Ej Puede cerar toda una seccion que sea Signos Vitales, y solo cargarle el formulario VS que tiene ya sus campos definidos.

Cuando el operador esta creando una seccion o formulario debe idnicar que a que estudio y branch ( corde del estudio )
pertenece. Asi los formualrios quedaran asociados por CDC al estudio y se podra reutilizar formularios con datos CDISC de otros estudios.
Ademas el ID de los contextoa es entityID de bintelx, el cual es  independiente del SUBJECT ID que se obtiene cuando se randomiza un sujeto.

Cambiar de datos datos versionados es un evento importante y  se hace en logica de negocio de cada modulo en CDC. no dentro de la clase de guardado. Se hara con botones.

## 6.a Dominios Formularios CDISC tipicos
* **DM**: Datos Demográficos (Demographics)
* **MH**: Historia Médica (Medical History)
* **AE**: Eventos Adversos (Adverse Events)
* **VS**: Signos Vitales (Vital Signs)
* **PE**: Examen Físico (Physical Examination)
* **CM**: Medicamentos Concomitantes (Concomitant Medications)

## 6.b Flujo de negocio (CDCService) capturando   
`formDomain` Son las secciones de CDISC de formularios, . 
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
    - si status='FINALIZED' → evento, graba y versiona; incrementa `form_version`. → sólo permite `addFieldQuery()`. 
- `finalizeFormInstance(formInstanceId, actor)` → bloquea valores, pasa a FINALIZED.
- `addFieldQuery(formInstanceId, fieldName, queryText, actor)` → registra aclaración.
- `getFormData(formInstanceId)` → obtiene valores “hot” + metadata.
- `getFieldAudit(formInstanceId, fieldName)` → historial de versiones.


## 7. Estructura de `custom/cdc`
```bash
custom/cdc/
├─ crf.endpoint.php         # Rutas CRUD CRF (defineCaptureField)
├─ audittrail.endpoint.php  # Ruta para audit trail de un campo
├─ studies.endpoint.php     # Gestión de estudios/visitas
├─ isf.endpoint.php     
└─ Business/
   ├─ CRF.php              # Lógica de definición y versionado
   ├─ AuditTrail.php       # Lógica de recuperación de historial
   └─ Studies.php          # Lógica de instancias (estudio–paciente–visita)
   └─ ISF.php                # Lógica de instancias (estudio–paciente–visita)
```
Bintelx lee todos los `*.endpoint.php` y monta rutas según `api.md`/`endpoint.md`.
Un endpoint montado disponibiliza todo el Business para ser usado. 
---

## 8. Tecnología
- PHP 8.1
- MySQL 8.1 (charset `utf8mb4_0900_ai_ci`, columnas JSON si aplica)
- **CONN**: gestiona conexiones, transacciones y streaming de resultados con callbacks para memoria eficiente.
- **FILES**: manejo de archivos y logs.
```
