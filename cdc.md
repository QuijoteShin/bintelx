# Proyecto CDC sobre Bintelx

## 1. Introducción  
La **app Clinical Data Capture (CDC)** es una solución headless, orientada a investigación médica, que gestiona formularios (CRF) y datos clínicos versionados con estricta trazabilidad.

## 2. Plataforma: Bintelx Agnóstico  
- **Headless**: núcleo sin UI, expone servicios vía endpoints.  
- **Capa de datos**: usa las clases Bintelx **CONN**, **FILES** y **DataCaptureService** (`bX\DataCaptureService`).  
- **Autocarga de custom**: cualquier `custom/.../*.endpoint.php` se registra automáticamente en el router.

## 3. Estándares y Cumplimiento  
- **ALCOA+**: datos Attributable, Legible, Contemporáneo, Original, Exacto y más.  
- **CDISC**: nomenclatura de campos basada en estándares CDISC (p. ej. VS, DM, AE).

## 4. Dependencia de DataCaptureService  
- Contextos **mínimos** para cada llamada:
  ```php
  [
    'BNX_PAC_ID'  => 'P0001',   // ID interno CDC  
    'FORM_DOMAIN' => 'VS'       // Tipo de formulario CDISC (p. ej. Signos Vitales)
  ]
