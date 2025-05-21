Claro, aquí tienes el texto en formato Markdown corregido:

# Proyecto CDC sobre Bintelx

## 1. Introducción
La **app Clinical Data Capture (CDC)** es una solución headless, orientada a investigación médica, que gestiona formularios (CRF) y datos clínicos versionados con estricta trazabilidad.

---

## 2. Plataforma: Bintelx Agnóstico
- **Headless**: núcleo sin UI, expone servicios vía endpoints.
- **Capa de datos**: usa las clases Bintelx **CONN**, **FILES** y **DataCaptureService** (`bX\DataCaptureService`).
- **Autocarga de custom**: cualquier `custom/.../*.endpoint.php` se registra automáticamente en el router.

---

## 3. Estándares y Cumplimiento
- **ALCOA+**: datos Attributable, Legible, Contemporáneo, Original, Exacto y más.
- **CDISC**: nomenclatura de campos basada en estándares CDISC (p. ej. VS, DM, AE).

---

## 4. Dependencia de DataCaptureService
- Contextos **mínimos** para cada llamada:
  ```php
  [
    'BNX_PAC_ID'  => 'P0001',    // ID interno CDC
    'FORM_DOMAIN' => 'VS'        // Tipo de formulario CDISC (p. ej. Signos Vitales)
  ]
  ```
**Regla**: cambiar contexto crea “tabla” nueva en DataCaptureService → se pierde historial.

**Versionado**: todos los cambios pasan por `saveRecord()`, que crea nueva versión en `capture_data_version` y actualiza el “hot record”.

---

## 5. Tablas propias CDC (MySQL 8.1)
- `cdc_form_instance`: relaciona estudio, paciente, visita, dominio y versión de formulario.
- `cdc_field_query`: queries o aclaraciones clínicas asociadas a campos tras finalización.

---

## 6. Flujo de negocio (CDCService)
- `getOrCreateFormInstance(studyId, patientId, visitNum, formDomain, actor)`
- `saveFormData(formInstanceId, fieldsData[], actor)`
  - si status='OPEN' → graba y versiona; incrementa `form_version`.
  - si status='FINALIZED' → sólo permite `addFieldQuery()`.
- `finalizeFormInstance(formInstanceId, actor)` → bloquea valores, pasa a FINALIZED.
- `addFieldQuery(formInstanceId, fieldName, queryText, actor)` → registra aclaración.
- `getFormData(formInstanceId)` → obtiene valores “hot” + metadata.
- `getFieldAudit(formInstanceId, fieldName)` → historial de versiones.

---

## 7. Estructura de `custom/cdc`
```bash
custom/cdc/
├─ crf.endpoint.php         # Rutas CRUD CRF (defineCaptureField)
├─ audittrail.endpoint.php  # Ruta para audit trail de un campo
├─ studies.endpoint.php     # Gestión de estudios/visitas
└─ Business/
   ├─ CRF.php              # Lógica de definición y versionado
   ├─ AuditTrail.php       # Lógica de recuperación de historial
   └─ Studies.php          # Lógica de instancias (estudio–paciente–visita)
```
Bintelx lee todos los `*.endpoint.php` y monta rutas según `api.md`/`endpoint.md`.

---

## 8. Tecnología
- PHP 8.1
- MySQL 8.1 (charset `utf8mb4_0900_ai_ci`, columnas JSON si aplica)
- **CONN**: gestiona conexiones, transacciones y streaming de resultados con callbacks para memoria eficiente.
- **FILES**: manejo de archivos y logs.
```
