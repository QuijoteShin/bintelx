# Bintelx Core Architecture - Overview

## Visión General

Bintelx es una plataforma agnóstica de gestión de datos versionados con trazabilidad completa ALCOA+, diseñada para soportar aplicaciones multi-empresa, multi-contexto y multi-dominio sin modificar el schema de base de datos.

## Modelo Conceptual

La arquitectura se basa en tres principios fundamentales:

### 1. Separación de Identidad (Account → Profile → Entity)

**Account (Autenticación)**
- Un login = una persona
- Almacena solo credenciales de seguridad
- Permite Single Sign-On nativo

**Profile (Actor Operativo)**
- Múltiples "sombreros" por Account
- Cada perfil representa un contexto operativo (empresa, estudio, proyecto)
- Revocable sin eliminar la cuenta

**Entity (Base de Datos Vertical)**
- Personas, empresas, proyectos, estudios, dispositivos, etc.
- Cada Entity es dueña de sus datos en el sistema EAV
- Permite modelar cualquier tipo de objeto de negocio

### 2. Permisos Data-Driven (Roles + EntityRelationships)

**Roles Textuales**
- Catálogo de roles reutilizables (`company.warehouse`, `project.manager`, etc.)
- Los módulos preguntan: `hasRole(profile, entity, role_code)`
- Extensible sin modificar código

**EntityRelationships**
- Tabla pivote N:M entre Profiles y Entities
- Soporta ownership, membership, permissions
- Roles activos (requieren selección) vs pasivos (siempre habilitados)

### 3. Datos Versionados (EAV + ALCOA+)

**DataDictionary**
- Catálogo de variables (el "schema" del EAV)
- Agregar campos = agregar filas, no ALTER TABLE

**ContextGroups**
- Eventos o hitos que agrupan cambios de datos
- Separa `subject_entity_id` (de quién es el dato) vs `scope_entity_id` (en qué ámbito se registró)
- Soporta multi-origen (`source_system`, `device_id`)

**DataValues_history**
- Cada versión de cada dato
- `is_active` marca la versión actual (el "puntero caliente")
- Trazabilidad completa: quién, qué, cuándo, por qué, desde dónde

## Casos de Uso Soportados

✓ **Multi-empresa**: Un usuario trabaja en 5 empresas con un solo login
✓ **Roles por proyecto**: Un consultor es manager en Proyecto A y analista en Proyecto B
✓ **Datos clínicos multi-origen**: Presión arterial de clínica + estudio + dispositivo personal, todo en historial unificado del paciente
✓ **Trazabilidad ALCOA+**: Completa para compliance regulatorio
✓ **Revocación sin pérdida**: Desvincular colaborador preserva historial completo
✓ **Flexibilidad total**: Nuevos dominios (ventas, inventario, clínica) sin cambiar schema

## Estructura de Documentación

1. **[01_Identity.md](./01_Identity.md)**: Modelo de identidad (Account, Entity, Profile)
2. **[02_Roles_and_Permissions.md](./02_Roles_and_Permissions.md)**: Sistema de roles y permisos
3. **[03_EAV_and_Versioning.md](./03_EAV_and_Versioning.md)**: Sistema de datos versionados (EAV)
4. **[04_DataCaptureService.md](./04_DataCaptureService.md)**: API de alto nivel para módulos
5. **[ARCHITECTURE_QA.md](./ARCHITECTURE_QA.md)**: Respuestas a preguntas arquitectónicas frecuentes

## Flujo de Trabajo Típico

### Empresa → Colaborador → Cliente → Dato

1. **Setup**: Empresa crea perfil corporativo para colaborador
2. **Asignación de roles**: Colaborador obtiene rol específico (ej: bodeguero, coordinador)
3. **Registro de datos**: Colaborador registra datos de un cliente
4. **Contexto**: Se crea ContextGroup con:
   - `subject_entity_id` = cliente
   - `scope_entity_id` = empresa
   - `profile_id` = colaborador
5. **Datos**: Se guardan en DataValues_history vinculados al contexto
6. **Queries**:
   - Empresa filtra: "todos los datos con mi `scope_entity_id`"
   - Cliente filtra: "todos mis datos" sin importar qué empresa los registró
   - Colaborador filtra: "datos que registré en esta empresa"

### Desvinculación de Colaborador

1. Desactivar Profile corporativo (`status='inactive'`)
2. Desactivar EntityRelationships con la empresa
3. **Historial ALCOA preservado**: Todos los datos registrados permanecen auditables
4. Colaborador puede seguir usando otros perfiles (personal, otras empresas)

## Principios de Diseño

- **No Foreign Keys**: Integridad referencial en aplicación + jobs periódicos
- **utf8mb4_unicode_ci**: Soporte internacional completo
- **bigint unsigned**: Escalabilidad para miles de millones de registros
- **varchar(N) en lugar de TEXT/JSON**: Control de row size para rendimiento
- **Índices estratégicos**: Diseñados para queries específicos (caliente, historial, contexto, actor)

## Arquitectura de la Verdad Única

**Problema**: ¿Cómo mantener dato caliente + historial sincronizados sin tablas de punteros?

**Solución**:
- `is_active` vive dentro de `DataValues_history`
- Dato caliente = `WHERE is_active=TRUE`
- Transacción atómica: `UPDATE is_active=false` + `INSERT is_active=true`
- Índice único `uq_single_active` garantiza una sola versión activa
- Si la transacción falla, rollback automático preserva consistencia

## Servicios de Alto Nivel

### DataCaptureService

Orquesta DataDictionary + ContextGroups + DataValues_history:

```php
// Definir campo (una vez)
DataCaptureService::defineCaptureField([
    'unique_name' => 'CDC_APP.VSORRES_SYSBP',
    'label' => 'Presión Sistólica',
    'data_type' => 'DECIMAL'
], $actorProfileId);

// Guardar datos
DataCaptureService::saveData(
    actorProfileId: 80,
    entityId: 123,  // Paciente
    contextPayload: ['macro_context' => 'EST-XYZ', 'event_context' => 'V1'],
    valuesData: [
        ['variable_name' => 'CDC_APP.VSORRES_SYSBP', 'value' => 120, 'reason' => 'Registro inicial']
    ],
    contextType: 'clinical_study_visit'
);

// Leer dato caliente
$data = DataCaptureService::getHotData($entityId, ['CDC_APP.VSORRES_SYSBP']);

// Auditoría completa
$trail = DataCaptureService::getAuditTrailForVariable($entityId, 'CDC_APP.VSORRES_SYSBP');
```

## Tests de Demostración

Ver carpeta [`/app/test/`](../../app/test/) para tests ejecutables que demuestran:

- Multi-empresa con segregación de datos
- Roles diferentes por proyecto
- Datos clínicos multi-contexto
- Versionado y ALCOA+
- Patrones avanzados (objetos, tickets)

## Referencias

- [target.md](../../target.md): Especificación técnica completa
- [schema.sql](../config/server/schema.sql): Schema de base de datos
- [DataCaptureService.php](../kernel/DataCaptureService.php): Implementación de referencia

---

**Última actualización**: 2025-11-14
