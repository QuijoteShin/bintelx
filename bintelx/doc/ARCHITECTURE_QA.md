# Bintelx Architecture: Comprehensive Q&A

Este documento responde a las preguntas arquitectónicas fundamentales del sistema Bintelx, demostrando cómo el diseño agnóstico soporta casos de uso complejos como multi-empresa, roles por proyecto, datos clínicos con múltiples contextos, y trazabilidad completa ALCOA+.

---

## 1. ¿Se necesitan todas esas tablas para este sistema, considerando que debe soportar múltiples empresas, dueños y organizaciones internas?

**Respuesta: Sí, todas las tablas son necesarias.**

El sistema está diseñado con **separación de responsabilidades**:

### Tablas de Identidad y Actores
- **`Accounts`**: Autenticación pura. Un mismo usuario puede acceder a múltiples empresas con un solo login.
- **`Entities`**: Representan TODO (personas, empresas, organizaciones internas, proyectos, estudios, bodegas, dispositivos). Cada empresa es una `Entity` de tipo `organization` o `company`.
- **`Profiles`**: Los "sombreros" operativos. Un `Account` puede tener múltiples `Profiles`:
  - Uno personal (mi cuenta personal)
  - Uno corporativo por cada empresa donde trabajo
  - Uno por cada estudio clínico donde participo

### Tablas de Roles y Permisos
- **`Roles`**: Catálogo textual de roles reutilizables (`company.warehouse`, `project.manager`, `study.coordinator`).
- **`EntityRelationships`**: La tabla pivote que conecta `Profile` → `Entity` + `Role`. Permite:
  - Un mismo perfil con roles diferentes en proyectos diferentes
  - Una persona trabajando en múltiples empresas simultáneamente
  - Roles activos (requieren selección explícita) vs pasivos (siempre habilitados)

### Tablas de Datos (EAV)
- **`DataDictionary`**: Define qué datos existen en el sistema (agnóstico de dominio).
- **`ContextGroups`**: Registra el "evento" o "hito" que agrupa cambios de datos, incluyendo:
  - `subject_entity_id`: De quién es el dato (paciente, cliente, activo)
  - `scope_entity_id`: En qué ámbito se registró (empresa, estudio, proyecto)
- **`DataValues_history`**: Almacena cada versión de cada dato con trazabilidad completa.

### ¿Por qué no menos tablas?
Si elimináramos alguna tabla:
- Sin `Entities` separadas, no podríamos modelar empresas, proyectos y personas como objetos de primera clase.
- Sin `Profiles` separados de `Accounts`, un usuario no podría tener múltiples roles en diferentes empresas sin crear múltiples cuentas.
- Sin `ContextGroups.scope_entity_id`, no podríamos filtrar "todos los datos registrados por mi empresa" vs "datos de otras empresas sobre el mismo paciente".

**Conclusión**: El modelo es minimalista pero completo. Cada tabla cumple un propósito específico en el soporte multi-tenant, multi-empresa y multi-contexto.

---

## 2. ¿Es viable el siguiente modelo de organización y perfiles?

### Caso 1: Un dueño crea una organización dentro de la plataforma y se asocia a ella

**Sí, es viable.**

**Flujo:**
1. Usuario crea `Account` (login).
2. Sistema crea automáticamente:
   - `Entity` tipo `person` para el usuario (su ficha personal).
   - `Profile` personal con `relation_kind='owner'` sobre su `Entity` persona.
3. Usuario crea una `Entity` tipo `company` (su empresa).
4. Sistema crea:
   - Segundo `Profile` corporativo vinculado al mismo `Account`.
   - `EntityRelationship` con `relation_kind='owner'` del nuevo perfil sobre la empresa.
   - El `Profile.primary_entity_id` del perfil corporativo apunta a la empresa.

**Resultado:**
- El usuario tiene 2 perfiles: uno personal y uno corporativo.
- Es "dueño" de ambas entidades.
- Puede operar como persona o como empresa según el perfil activo.

### Caso 2: Una empresa crea una organización interna sin pasar por el dueño y luego puede vincularse al dueño

**Sí, es viable.**

**Flujo:**
1. La empresa (ya existente como `Entity`) crea una sub-organización (departamento, sucursal, proyecto interno).
2. Sistema crea:
   - Nueva `Entity` tipo `organization` o `project` para la unidad interna.
   - Opcionalmente, una relación de `parent_entity` (podría modelarse con `EntityRelationships` usando `relation_kind='parent'`).
3. Posteriormente, se puede vincular a un dueño:
   - Crear `EntityRelationship` con `relation_kind='owner'` de un `Profile` específico sobre la organización interna.

**Ejemplo:**
- Empresa "Bintelx Corp" crea "División Salud" como sub-organización.
- Más tarde, Juan (perfil corporativo) es asignado como dueño/responsable de "División Salud".

**Conclusión**: El sistema soporta creación top-down (dueño → empresa) y bottom-up (empresa → unidades internas → asignar dueños).

---

## 3. ¿Es viable usar una tabla de roles textuales donde los módulos definan roles como `company.warehouse` y luego pregunten `hasRole(profile, entity, 'company.warehouse')` para decidir si cargan funcionalidades extra y accesos a rutas?

**Sí, es completamente viable y es el patrón recomendado.**

### Cómo funciona

1. **Definición de roles**: Cada módulo define sus roles en la tabla `Roles`:
   ```sql
   INSERT INTO Roles (role_code, role_label, scope_type, description)
   VALUES ('company.warehouse', 'Maestro Bodeguero', 'warehouse',
           'Administrador de bodega con permisos de ajuste de inventario');
   ```

2. **Asignación de roles**: Se asigna el rol a un perfil sobre una entidad específica vía `EntityRelationships`:
   ```sql
   INSERT INTO EntityRelationships
     (profile_id, entity_id, relation_kind, role_code, grant_mode, status)
   VALUES
     (101, 500, 'permission', 'company.warehouse', 'active', 'active');
   ```
   Donde:
   - `profile_id=101`: El perfil del usuario
   - `entity_id=500`: La bodega específica
   - `role_code='company.warehouse'`: El rol

3. **Verificación en módulos**:
   ```php
   if (hasRole($currentProfileId, $warehouseEntityId, 'company.warehouse')) {
       // Habilitar botones de ajuste de inventario
       // Mostrar rutas de administración
   }
   ```

### Ventajas

- **Data-driven**: Lógica de permisos en datos, no en código.
- **Granular**: Mismo rol puede aplicarse a diferentes entidades (Juan es bodeguero en Bodega A, pero no en Bodega B).
- **Extensible**: Nuevos módulos pueden definir sus propios roles sin tocar el core.
- **Auditable**: Cada asignación de rol se registra con `created_by_profile_id` y `created_at`.

**Conclusión**: Este es exactamente el patrón que el sistema implementa. Los módulos operan contra `role_code` textual y el sistema resuelve permisos dinámicamente.

---

## 4. ¿Qué pasa cuando se trata de una empresa de proyectos y una misma persona tiene un rol distinto en cada proyecto?

**El sistema maneja esto nativamente con múltiples filas en `EntityRelationships`.**

### Escenario

Juan trabaja en una consultora que maneja múltiples proyectos:
- Proyecto A: es Project Manager
- Proyecto B: es Analista
- Proyecto C: es QA

### Modelado

1. **Entidades**:
   - Juan: `Entity` tipo `person` (entity_id=123)
   - Proyecto A: `Entity` tipo `project` (entity_id=201)
   - Proyecto B: `Entity` tipo `project` (entity_id=202)
   - Proyecto C: `Entity` tipo `project` (entity_id=203)

2. **Cuenta y Perfil**:
   - Juan tiene 1 `Account` (account_id=50)
   - Juan tiene 1 `Profile` corporativo (profile_id=80) vinculado a su empresa o a su propia entidad persona

3. **Relaciones de Roles** (en `EntityRelationships`):
   ```sql
   -- Proyecto A: Manager
   (relationship_id=1, profile_id=80, entity_id=201,
    relation_kind='permission', role_code='project.manager', status='active')

   -- Proyecto B: Analista
   (relationship_id=2, profile_id=80, entity_id=202,
    relation_kind='permission', role_code='project.analyst', status='active')

   -- Proyecto C: QA
   (relationship_id=3, profile_id=80, entity_id=203,
    relation_kind='permission', role_code='project.qa', status='active')
   ```

### Verificación de Permisos

Cuando Juan abre el Proyecto A:
```php
hasRole(80, 201, 'project.manager') // true
hasRole(80, 201, 'project.analyst') // false
```

Cuando Juan abre el Proyecto B:
```php
hasRole(80, 202, 'project.manager') // false
hasRole(80, 202, 'project.analyst') // true
```

### Casos de uso adicionales

**Múltiples roles en el mismo proyecto:**
Si Juan es tanto Manager como QA en Proyecto D:
```sql
(profile_id=80, entity_id=204, role_code='project.manager')
(profile_id=80, entity_id=204, role_code='project.qa')
```

**Mismo rol en múltiples proyectos:**
Si Juan es Manager en 5 proyectos, tiene 5 filas en `EntityRelationships` con el mismo `role_code` pero diferentes `entity_id`.

**Conclusión**: El sistema soporta N:M entre perfiles, entidades y roles. Un mismo perfil puede tener roles completamente diferentes según la entidad (proyecto, estudio, empresa) con la que esté interactuando.

---

## 5. Dado el ejemplo de `DataCaptureService`, ¿esa API funcionará correctamente sobre la arquitectura EAV + ContextGroups descrita en el documento?

**Sí, funciona perfectamente. El `DataCaptureService` está diseñado específicamente para esta arquitectura.**

### Flujo completo: Guardado de datos clínicos

**Escenario**: Enfermera registra signos vitales en una visita de estudio clínico.

#### 1. Definición de campos (una vez al inicio)
```php
DataCaptureService::defineCaptureField([
    'unique_name' => 'CDC_APP.VSORRES_SYSBP',
    'label' => 'Presión Sistólica',
    'data_type' => 'DECIMAL',
    'is_pii' => false
], $actorProfileId);
```
Esto inserta/actualiza en `DataDictionary`.

#### 2. Guardado de datos
```php
$result = DataCaptureService::saveData(
    actorProfileId: 80,  // Perfil de la enfermera
    entityId: 123,       // Paciente (Entity tipo person)
    contextPayload: [
        'macro_context' => 'EST-XYZ',    // ID del estudio
        'event_context' => 'V1',         // Visita 1
        'sub_context' => 'SignosVitales' // Formulario
    ],
    valuesData: [
        ['variable_name' => 'CDC_APP.VSORRES_SYSBP', 'value' => 120, 'reason' => 'Registro inicial'],
        ['variable_name' => 'CDC_APP.VSORRES_DIABP', 'value' => 80, 'reason' => 'Registro inicial'],
        ['variable_name' => 'CDC_APP.VSORRES_HR', 'value' => 72, 'reason' => 'Registro inicial']
    ],
    contextType: 'clinical_study_visit'
);
```

#### 3. Acciones internas del servicio

**Paso 1**: Obtener/crear `ContextGroup`
- Busca un contexto existente con:
  - `subject_entity_id=123` (paciente)
  - `macro_context='EST-XYZ'`
  - `event_context='V1'`
  - `sub_context='SignosVitales'`
- Si no existe, crea uno nuevo y guarda `context_group_id`.

**Paso 2**: Por cada variable:
- Resuelve `variable_id` desde `DataDictionary.unique_name`
- Busca versión activa actual (`is_active=true`)
- Si existe:
  - `UPDATE SET is_active=false` (desactiva versión anterior)
  - `INSERT` nueva versión con `version=version+1, is_active=true`
- Si no existe:
  - `INSERT` versión 1 con `is_active=true`

**Paso 3**: Registra en `DataValues_history`:
```sql
INSERT INTO DataValues_history
  (entity_id, variable_id, context_group_id, profile_id, timestamp,
   value_decimal, version, is_active, reason_for_change)
VALUES
  (123, 456, 789, 80, NOW(), 120, 1, TRUE, 'Registro inicial');
```

#### 4. Lectura de datos calientes
```php
$data = DataCaptureService::getHotData(
    entityId: 123,
    variableNames: ['CDC_APP.VSORRES_SYSBP', 'CDC_APP.VSORRES_DIABP']
);

// Retorna:
[
    'CDC_APP.VSORRES_SYSBP' => [
        'value' => 120,
        'version' => 1,
        'last_updated_at' => '2025-11-14 10:30:00',
        'last_updated_by_profile_id' => 80
    ],
    'CDC_APP.VSORRES_DIABP' => [
        'value' => 80,
        'version' => 1,
        ...
    ]
]
```

#### 5. Auditoría completa
```php
$trail = DataCaptureService::getAuditTrailForVariable(
    entityId: 123,
    variableName: 'CDC_APP.VSORRES_SYSBP'
);

// Retorna todas las versiones:
[
    ['version' => 2, 'value' => 118, 'timestamp' => '2025-11-14 11:00',
     'profile_id' => 80, 'reason_for_change' => 'Corrección: error de tipeo'],
    ['version' => 1, 'value' => 120, 'timestamp' => '2025-11-14 10:30',
     'profile_id' => 80, 'reason_for_change' => 'Registro inicial']
]
```

### Compatibilidad con el schema

La implementación de `DataCaptureService.php` ya usa:
- `DataDictionary.unique_name` ✓
- `ContextGroups` con contextos textuales (`macro_context`, `event_context`, `sub_context`) ✓
- `DataValues_history.is_active` para el dato caliente ✓
- `version` incremental ✓
- Transacciones atómicas con `SELECT ... FOR UPDATE` ✓

**Conclusión**: El `DataCaptureService` es la implementación de referencia de cómo usar la arquitectura EAV+ContextGroups. Funciona correctamente y ya está alineado con el schema.

---

## 6. Si una persona va al médico y le toman la presión (dato clínico de la persona) y luego un estudio clínico consume ese dato automáticamente, ¿es viable que el dato quede marcado como "anotado como fuente externa" a nivel de contexto, pero la persona vea en su vista personal un histórico unificado de su presión arterial, agregando todos los contextos?

**Sí, es completamente viable y es uno de los casos de uso centrales de la arquitectura.**

### Escenario

1. **Consulta médica primaria**: 14-nov-2025, Dr. García toma presión a Juan.
2. **Estudio clínico**: El estudio XYZ importa automáticamente ese dato desde el sistema del Dr. García.

### Modelado

#### Registro 1: Consulta médica (origen primario)
```php
DataCaptureService::saveData(
    actorProfileId: 90,  // Perfil del Dr. García
    entityId: 123,       // Juan (paciente)
    contextPayload: [
        'macro_context' => 'CLINIC_GARCIA',
        'event_context' => '2025-11-14',
        'sub_context' => 'ConsultaGeneral',
        'scope_entity_id' => 600  // Entidad de la Clínica García
    ],
    valuesData: [
        ['variable_name' => 'fhir.observation.bp.systolic', 'value' => 120,
         'reason' => 'Toma de presión en consulta']
    ],
    contextType: 'primary_care_visit'
);
```

Esto crea:
- `ContextGroups`:
  - `subject_entity_id=123` (Juan)
  - `scope_entity_id=600` (Clínica García)
  - `context_type='primary_care_visit'`
  - `source_system` NULL (dato original)
- `DataValues_history`:
  - `entity_id=123` (Juan)
  - `variable_id` de `fhir.observation.bp.systolic`
  - `context_group_id` del contexto de la clínica
  - `source_system=NULL`
  - `device_id=NULL`

#### Registro 2: Importación automática al estudio clínico
```php
DataCaptureService::saveData(
    actorProfileId: 95,  // Perfil del sistema de integración
    entityId: 123,       // Juan (mismo paciente)
    contextPayload: [
        'macro_context' => 'EST-XYZ',
        'event_context' => 'V1',
        'sub_context' => 'SignosVitales',
        'scope_entity_id' => 700  // Entidad del Estudio XYZ
    ],
    valuesData: [
        ['variable_name' => 'cdisc.vs.systolic', 'value' => 120,
         'reason' => 'Importado desde Clínica García']
    ],
    contextType: 'external_import'
);
```

Al insertar, se especifica:
```sql
INSERT INTO DataValues_history
  (..., source_system, device_id, ...)
VALUES
  (..., 'external.clinic_garcia', NULL, ...);
```

Y el `ContextGroup` se crea con:
- `subject_entity_id=123` (Juan)
- `scope_entity_id=700` (Estudio XYZ)
- `context_type='external_import'`

### Vista unificada del paciente

#### Query para histórico de presión arterial de Juan
```sql
SELECT
    h.value_decimal AS systolic,
    h.timestamp,
    h.source_system,
    cg.context_type,
    cg.scope_entity_id,
    e.primary_name AS scope_name
FROM DataValues_history h
JOIN ContextGroups cg ON h.context_group_id = cg.context_group_id
JOIN Entities e ON cg.scope_entity_id = e.entity_id
WHERE
    h.entity_id = 123  -- Juan
    AND h.variable_id IN (
        SELECT variable_id FROM DataDictionary
        WHERE unique_name IN ('fhir.observation.bp.systolic', 'cdisc.vs.systolic')
    )
    AND h.is_active = TRUE
ORDER BY h.timestamp DESC;
```

#### Resultado
| systolic | timestamp | source_system | context_type | scope_name |
|----------|-----------|---------------|--------------|------------|
| 120 | 2025-11-14 10:30 | external.clinic_garcia | external_import | Estudio XYZ |
| 120 | 2025-11-14 10:00 | NULL | primary_care_visit | Clínica García |

### Vista del estudio (filtrada por scope)

El estudio solo ve datos en su scope:
```sql
WHERE cg.scope_entity_id = 700  -- Estudio XYZ
```

### Separación de contextos con consolidación

- **Juan (vista personal)**: Ve TODOS los datos con `entity_id=123`, sin importar el `scope_entity_id`.
- **Clínica García**: Ve solo datos con `scope_entity_id=600`.
- **Estudio XYZ**: Ve solo datos con `scope_entity_id=700`.
- **Anotación de origen**: El campo `source_system` distingue dato original vs importado.

**Conclusión**: El sistema soporta:
1. Datos primarios y externos sobre el mismo sujeto
2. Histórico unificado del paciente (agregando todos los contextos)
3. Vistas segregadas por scope (empresa, estudio, clínica)
4. Trazabilidad completa del origen de cada dato

---

## 7. ¿Puedes, a partir del documento, reconstruir (ingeniería inversa) todo lo que se discutió sobre el blueprint de la app para consolidar la arquitectura?

**Sí, el documento `target.md` consolida las discusiones arquitectónicas previas.**

### Blueprint reconstruido

#### A. Separación Account → Profile → Entity

**Problema original**: ¿Cómo permitir que una persona trabaje en múltiples empresas/estudios sin crear múltiples cuentas?

**Solución**:
- `Account`: Autenticación única (1 login = 1 persona)
- `Profile`: Rol operativo (1 account puede tener N perfiles)
- `Entity`: Sujeto de datos (personas, empresas, proyectos, etc.)

**Beneficios**:
- SSO nativo: un login, múltiples contextos operativos
- Auditoría: cada acción se registra con `profile_id`, no `account_id`
- Revocación sin pérdida de historial: desactivar un perfil corporativo no borra el historial ALCOA

#### B. Roles textuales y permisos data-driven

**Problema original**: ¿Cómo evitar hard-coded permissions y soportar roles por módulo?

**Solución**:
- Tabla `Roles` con `role_code` textual
- Tabla `EntityRelationships` para asignar roles a perfiles sobre entidades específicas
- Función `hasRole(profile, entity, role_code)` como API de permisos

**Beneficios**:
- Módulos autocontenidos: cada módulo define sus roles
- Granularidad: mismo rol puede aplicarse a diferentes entidades
- Extensibilidad: nuevos roles sin cambiar core

#### C. EAV para datos agnósticos de dominio

**Problema original**: ¿Cómo almacenar datos de clínica, ventas, inventario sin crear tablas por dominio?

**Solución**:
- `DataDictionary`: catálogo de variables
- `ContextGroups`: eventos que agrupan cambios
- `DataValues_history`: valores versionados con `is_active` como puntero caliente

**Beneficios**:
- Flexibilidad: agregar nuevos campos = agregar filas en `DataDictionary`
- Versionado nativo: cada cambio es una nueva fila
- ALCOA+ automático: `profile_id`, `timestamp`, `reason_for_change`

#### D. Contextos multi-nivel (subject vs scope)

**Problema original**: ¿Cómo diferenciar "de quién es el dato" vs "en qué ámbito se registró"?

**Solución**:
- `ContextGroups.subject_entity_id`: sujeto del dato (paciente, cliente)
- `ContextGroups.scope_entity_id`: ámbito operativo (empresa, estudio)
- `macro_context`, `event_context`, `sub_context`: claves de negocio

**Beneficios**:
- Multi-tenant nativo: filtrar por scope
- Vista unificada del sujeto: filtrar por subject
- Trazabilidad de origen: `source_system`, `device_id`

#### E. Arquitectura de la verdad única

**Problema original**: ¿Cómo evitar tablas de punteros y mantener historial + dato caliente sincronizados?

**Solución**:
- `is_active` vive dentro de `DataValues_history`
- Dato caliente = `WHERE is_active=TRUE`
- Transacción atómica: `UPDATE is_active=false` + `INSERT is_active=true`
- Índice único `uq_single_active` garantiza una sola versión activa

**Beneficios**:
- Una sola fuente de verdad
- Consistencia garantizada por BBDD
- Rollback automático si falla la transacción

### Decisiones de diseño consolidadas

1. **No Foreign Keys**: Integridad en aplicación + jobs periódicos
2. **utf8mb4_unicode_ci**: Soporte internacional completo
3. **bigint unsigned**: Escalabilidad para billones de registros
4. **varchar(N) en lugar de TEXT/JSON**: Control de row size para rendimiento
5. **xxh32 para hashes**: Balance velocidad/colisión
6. **Índices estratégicos**: Diseñados para queries específicos (caliente, historial, contexto, actor)

**Conclusión**: El documento consolida un diseño iterado que resuelve problemas reales de multi-tenant, multi-contexto, versionado y auditoría con un modelo minimalista pero completo.

---

## 8. ¿Cómo se puede redactar de forma coherente la visión del sistema?

### Visión del Sistema Bintelx

**Bintelx es una plataforma agnóstica de gestión de datos versionados con trazabilidad completa ALCOA+, diseñada para soportar aplicaciones multi-empresa, multi-contexto y multi-dominio.**

#### Modelo de Acceso e Identidad

El sistema se basa en una arquitectura de tres capas de identidad:

**1. Account (Autenticación)**
- Punto único de acceso al sistema.
- Una persona = una cuenta, sin importar en cuántas organizaciones participe.
- Almacena solo credenciales de seguridad, sin datos personales o de negocio.

**2. Profile (Actor Operativo)**
- Cada `Account` puede tener múltiples `Profiles`, que representan los distintos "sombreros" o roles operativos de la persona.
- Ejemplos:
  - Perfil personal (control sobre mis propios datos)
  - Perfil corporativo en Empresa A
  - Perfil de investigador en Estudio Clínico XYZ
- Los perfiles son revocables sin eliminar la cuenta ni el historial de acciones.
- Toda acción en el sistema se atribuye a un `profile_id` para cumplir ALCOA.

**3. Entity (Base Vertical de Datos)**
- Cada perfil se ancla a una `Entity`, que actúa como la "base de datos vertical" del sujeto.
- Las entidades representan:
  - Personas (`person`)
  - Empresas y organizaciones (`company`, `organization`)
  - Proyectos y estudios (`project`, `study`)
  - Activos físicos (`warehouse`, `device`, `address`)
- Cada `Entity` es dueña lógica de sus datos en el sistema EAV.

#### Modelo de Permisos y Roles

**Roles activos y pasivos:**
- Los roles se definen en la tabla `Roles` con códigos textuales reutilizables (ej: `company.warehouse`, `project.manager`).
- Se asignan a perfiles mediante `EntityRelationships` sobre entidades específicas.
- **Roles activos**: requieren selección explícita en la UI (ej: "operar como perfil corporativo en Proyecto A").
- **Roles pasivos**: habilitan permisos o funciones adicionales sin cambiar de contexto.

**Verificación de permisos:**
Los módulos preguntan: `hasRole(profile_id, entity_id, role_code)` para decidir accesos y funcionalidades.

**Granularidad:**
- Un mismo perfil puede tener roles diferentes en proyectos/empresas diferentes.
- Un mismo rol puede aplicarse a múltiples entidades.
- Los permisos son data-driven, no hard-coded.

#### Modelo de Datos (EAV + Versionado)

**Principio de diseño:**
- Los datos del sujeto se almacenan en un modelo Entity-Attribute-Value (EAV) completamente versionado.
- Cada cambio es una nueva fila en `DataValues_history`, nunca una actualización in-place.

**Componentes:**
1. **DataDictionary**: Define qué datos existen (variables).
2. **ContextGroups**: Registra eventos que agrupan cambios, con:
   - `subject_entity_id`: de quién es el dato
   - `scope_entity_id`: en qué ámbito se registró (empresa, estudio, dispositivo)
3. **DataValues_history**: Almacena cada versión de cada dato con:
   - `version` incremental
   - `is_active` para marcar la versión actual
   - `profile_id`, `timestamp`, `reason_for_change` para ALCOA+
   - `source_system`, `device_id` para trazabilidad de origen

**Ventajas:**
- Flexibilidad total: nuevos campos = nuevas filas en `DataDictionary`, sin ALTER TABLE.
- Auditoría automática: todo cambio tiene quién, cuándo, por qué y desde dónde.
- Vistas unificadas: un paciente puede ver datos de múltiples empresas/estudios agregados por `entity_id`.
- Vistas segregadas: cada empresa ve solo datos con su `scope_entity_id`.

#### Flujo de Trabajo Típico

**Empresa → Colaborador → Cliente → Dato:**
1. Un colaborador (perfil corporativo) realiza una acción sobre un cliente (entidad persona).
2. Se crea un `ContextGroup` con:
   - `subject_entity_id` = cliente
   - `scope_entity_id` = empresa
   - `profile_id` = colaborador
3. Los datos se guardan en `DataValues_history` vinculados al contexto.
4. La empresa puede filtrar "todos los datos registrados por mis colaboradores" con `scope_entity_id`.
5. El cliente puede ver "todos mis datos" sin importar qué empresa los registró, filtrando por `subject_entity_id`.

**Revocación de colaboradores:**
- Cuando un colaborador deja la empresa, su perfil corporativo se desactiva (`status='inactive'`).
- Sus relaciones con la empresa en `EntityRelationships` se marcan como inactivas.
- **El historial ALCOA se preserva**: todos los datos registrados por ese perfil permanecen inalterados y auditables.
- El colaborador puede seguir usando la plataforma con otros perfiles (personal, otras empresas).

#### Casos de Uso Soportados

- **Multi-empresa**: Un usuario trabaja en 5 empresas con un solo login.
- **Roles por proyecto**: Un consultor es manager en Proyecto A y analista en Proyecto B.
- **Datos clínicos multi-origen**: Presión arterial registrada por clínica primaria + importada a estudio clínico + medida por dispositivo personal, todo visible en historial unificado del paciente.
- **Trazabilidad completa**: "Quién, qué, cuándo, por qué, desde dónde" para cada versión de cada dato.
- **Cumplimiento ALCOA+**: Attributable, Legible, Contemporaneous, Original, Accurate, Complete, Consistent, Enduring, Available.

**Conclusión**: Bintelx separa autenticación, autorización y datos en capas independientes pero integradas, permitiendo una plataforma verdaderamente agnóstica que soporta cualquier dominio de negocio con trazabilidad completa y flexibilidad máxima.

---

## 9. En el flujo **Empresa → Colaborador → Cliente → Dato**, ¿se debería relacionar siempre la organización dueña del registro (scope) aunque clínicamente el sujeto del dato sea el cliente/paciente?

**Sí, absolutamente. Este es el patrón central de la arquitectura multi-tenant.**

### Separación subject vs scope

**`subject_entity_id`**: De quién es el dato (el paciente, el cliente, el activo).
**`scope_entity_id`**: En qué ámbito organizacional se registró el dato (la empresa, el estudio, la clínica).

### Por qué es necesario el scope

1. **Segregación de datos por empresa**: Sin `scope_entity_id`, no podríamos distinguir qué empresa registró qué datos.

2. **Compliance y auditoría**: Las regulaciones (HIPAA, GDPR, GCP) requieren saber qué organización es responsable de cada registro.

3. **Facturación y analytics**: "¿Cuántos pacientes atendió mi empresa este mes?" requiere filtrar por `scope_entity_id`.

4. **Permisos**: Un colaborador de Empresa A no puede ver datos registrados por Empresa B, aunque sean del mismo paciente.

### Ejemplo concreto

**Escenario**: Juan (paciente) recibe atención en dos clínicas diferentes.

#### Registro en Clínica A
```sql
-- ContextGroup
context_group_id=100
subject_entity_id=123  -- Juan (paciente)
scope_entity_id=600    -- Clínica A
profile_id=90          -- Dr. García (perfil en Clínica A)

-- DataValues_history
entity_id=123          -- Juan (subject)
context_group_id=100   -- Vincula al scope de Clínica A
```

#### Registro en Clínica B
```sql
-- ContextGroup
context_group_id=101
subject_entity_id=123  -- Juan (mismo paciente)
scope_entity_id=601    -- Clínica B
profile_id=91          -- Dra. López (perfil en Clínica B)

-- DataValues_history
entity_id=123          -- Juan (subject)
context_group_id=101   -- Vincula al scope de Clínica B
```

### Queries

**Vista de Juan (paciente)**:
```sql
SELECT * FROM DataValues_history
WHERE entity_id = 123;  -- Ve TODO su historial
```

**Vista de Clínica A**:
```sql
SELECT h.* FROM DataValues_history h
JOIN ContextGroups cg ON h.context_group_id = cg.context_group_id
WHERE cg.scope_entity_id = 600;  -- Solo datos registrados por Clínica A
```

**Vista de Clínica B**:
```sql
WHERE cg.scope_entity_id = 601;  -- Solo datos de Clínica B
```

### Conclusión

**Siempre se debe registrar el `scope_entity_id`** en `ContextGroups`, incluso cuando el sujeto del dato (`subject_entity_id`) sea el paciente/cliente. Esto permite:
- Vistas unificadas del sujeto (paciente ve todo)
- Vistas segregadas por empresa (cada clínica ve solo lo suyo)
- Auditoría de responsabilidad organizacional
- Compliance con regulaciones de protección de datos

---

## 10. Para saber todos los datos que un perfil ha registrado dentro de una empresa, ¿basta con filtrar por `profile_id` y por `scope_entity_id` en `ContextGroups` + `DataValues_history`?

**Sí, esa es exactamente la query correcta.**

### Query completa

```sql
SELECT
    h.value_id,
    h.entity_id AS subject_id,
    dd.unique_name AS variable,
    dd.label,
    h.value_string,
    h.value_decimal,
    h.value_datetime,
    h.timestamp,
    h.version,
    h.is_active,
    h.reason_for_change,
    cg.macro_context,
    cg.event_context,
    cg.sub_context
FROM DataValues_history h
JOIN ContextGroups cg ON h.context_group_id = cg.context_group_id
JOIN DataDictionary dd ON h.variable_id = dd.variable_id
WHERE
    cg.scope_entity_id = 700      -- Empresa X
    AND cg.profile_id = 80        -- Colaborador Juan
    AND h.is_active = TRUE        -- Solo versiones actuales
ORDER BY h.timestamp DESC;
```

### Variantes

**Incluir versiones históricas**:
```sql
-- Quitar el filtro AND h.is_active = TRUE
```

**Solo cambios en el último mes**:
```sql
AND h.timestamp >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
```

**Contar registros por colaborador en la empresa**:
```sql
SELECT
    cg.profile_id,
    p.profile_name,
    COUNT(DISTINCT h.entity_id) AS total_subjects,
    COUNT(h.value_id) AS total_records
FROM DataValues_history h
JOIN ContextGroups cg ON h.context_group_id = cg.context_group_id
JOIN Profiles p ON cg.profile_id = p.profile_id
WHERE
    cg.scope_entity_id = 700
    AND h.is_active = TRUE
GROUP BY cg.profile_id, p.profile_name
ORDER BY total_records DESC;
```

### Patrón adicional: datos registrados POR un perfil VS datos SOBRE una entidad

**Datos registrados POR Juan (colaborador) en Empresa X**:
```sql
WHERE cg.profile_id = 80 AND cg.scope_entity_id = 700
```

**Datos SOBRE Juan (como sujeto) registrados por cualquier colaborador de Empresa X**:
```sql
WHERE cg.subject_entity_id = 123 AND cg.scope_entity_id = 700
```

### Conclusión

**Sí, es suficiente** filtrar por `profile_id` + `scope_entity_id` en `ContextGroups` joineado con `DataValues_history`. No se necesitan campos adicionales. El diseño actual ya soporta esta query de forma óptima con los índices existentes:
- `idx_scope_context` en `ContextGroups` (scope_entity_id, context_type, timestamp)
- `idx_context` en `DataValues_history` (context_group_id, is_active)

---

## 11. Cuando una empresa desvincula a un colaborador, ¿es mejor que el `Profile` sea "de la persona" y se le quite la relación con la empresa, o que el `Profile` sea "de la empresa"?

**El modelo recomendado es: `Profile` es del contexto organizacional (de la empresa), y se desactiva al desvincular.**

### Enfoque recomendado: Profile corporativo desactivable

#### Estructura
- **Account**: Pertenece a la persona (permanente).
- **Profile personal**: Pertenece a la persona, vinculado a su `Entity` persona.
- **Profile corporativo**: Creado por/para la empresa, vinculado a la `Entity` empresa.

#### Al contratar
1. Empresa crea un `Profile` para el colaborador:
   ```sql
   INSERT INTO Profiles (account_id, primary_entity_id, profile_name, status)
   VALUES (50, 700, 'Juan - Empleado Empresa X', 'active');
   -- primary_entity_id=700 apunta a la Empresa X
   ```

2. Se crean relaciones en `EntityRelationships`:
   ```sql
   -- Membresía básica
   (profile_id=80, entity_id=700, relation_kind='membership', status='active')

   -- Roles específicos
   (profile_id=80, entity_id=700, relation_kind='permission', role_code='company.warehouse', status='active')
   ```

#### Al desvincular
1. **Desactivar el Profile corporativo**:
   ```sql
   UPDATE Profiles
   SET status = 'inactive', updated_by_profile_id = X
   WHERE profile_id = 80;
   ```

2. **Desactivar relaciones**:
   ```sql
   UPDATE EntityRelationships
   SET status = 'inactive'
   WHERE profile_id = 80 AND entity_id = 700;
   ```

3. **Preservación ALCOA**:
   - TODO el historial en `DataValues_history` permanece inalterado.
   - Todas las acciones registradas con `profile_id=80` siguen siendo auditables.
   - El colaborador ya no puede iniciar sesión con ese perfil (status='inactive').

### Ventajas de este modelo

1. **Trazabilidad completa**: Los datos nunca pierden su `profile_id` de origen.
2. **Revocación limpia**: Desactivar el perfil = revocar todos los accesos sin eliminar historial.
3. **Recontratación**: Si Juan vuelve a la empresa, se puede reactivar el perfil o crear uno nuevo.
4. **Portabilidad personal**: Juan conserva su `Account` y su `Profile` personal para usar la plataforma en otros contextos.

### Modelo alternativo (no recomendado): Profile personal con relaciones desvinculables

En este modelo:
- Juan tiene un solo `Profile` personal.
- La relación con la empresa se modela solo en `EntityRelationships`.

**Problema**: Al desvincular, solo se desactiva la relación, pero el perfil sigue activo. Esto complica:
- Auditoría: "¿Este dato fue registrado como colaborador de la empresa o a título personal?"
- Permisos: Más complejidad para distinguir perfil personal vs corporativo.

### Conclusión

**Recomendación**: Cada contexto organizacional debe tener su propio `Profile`:
- Profile personal: vinculado a la `Entity` persona del usuario.
- Profile corporativo por empresa: vinculado a la `Entity` de cada empresa/organización donde trabaja.

Al desvincular:
1. Desactivar el `Profile` corporativo (`status='inactive'`).
2. Desactivar las relaciones en `EntityRelationships`.
3. El historial ALCOA se preserva completamente.
4. El usuario puede seguir usando la plataforma con otros perfiles (personal, otras empresas).

---

## 12. ¿Con la arquitectura actual ya se cubren estos casos de uso (multi-empresa, roles por proyecto, separación Account/Profile/Entity, CDC, etc.), o es necesario describir tabla por tabla la semántica?

**La arquitectura actual ya cubre todos los casos de uso. La semántica está implícita en el diseño, pero vale la pena documentarla explícitamente para facilitar la comprensión.**

### Casos de uso cubiertos

| Caso de Uso | Cobertura | Mecanismo |
|-------------|-----------|-----------|
| Multi-empresa | ✓ Completo | Múltiples `Profiles` por `Account`, cada uno con `primary_entity_id` distinto |
| Roles por proyecto | ✓ Completo | Múltiples filas en `EntityRelationships` con mismo `profile_id`, distinto `entity_id` y `role_code` |
| Separación Account/Profile/Entity | ✓ Completo | Tres tablas independientes con relaciones claras |
| DataCaptureService | ✓ Completo | API ya implementada sobre EAV + ContextGroups |
| Versionado y ALCOA+ | ✓ Completo | `DataValues_history` con `version`, `is_active`, `profile_id`, `timestamp`, `reason_for_change` |
| Datos multi-origen | ✓ Completo | `source_system`, `device_id`, `context_type` en `ContextGroups` |
| Vista unificada del sujeto | ✓ Completo | Filtrar por `entity_id` (subject) |
| Vista segregada por empresa | ✓ Completo | Filtrar por `scope_entity_id` en `ContextGroups` |
| Revocación sin pérdida de historial | ✓ Completo | Desactivar `Profile` preserva todos los registros con su `profile_id` |

### ¿Es necesario documentar la semántica tabla por tabla?

**Sí, es altamente recomendable para:**

1. **Nuevos desarrolladores**: Comprender rápidamente el propósito de cada tabla.
2. **Integraciones**: Terceros que consuman la API necesitan entender el modelo.
3. **Compliance**: Auditorías regulatorias requieren documentación clara de la arquitectura de datos.
4. **IA y automatización**: Herramientas de generación de código necesitan semántica explícita.

### Documentación ya existente

El proyecto ya tiene:
- `target.md`: Arquitectura completa con semántica de cada tabla ✓
- `bintelx/doc/README.md`: Índice de documentación ✓
- `bintelx/doc/01_Identity.md`: Semántica de Account, Entity, Profile ✓
- `bintelx/doc/02_Roles_and_Permissions.md`: Semántica de Roles, EntityRelationships ✓
- `bintelx/doc/03_EAV_and_Versioning.md`: Semántica de DataDictionary, ContextGroups, DataValues_history ✓
- `bintelx/doc/04_DataCaptureService.md`: API de alto nivel ✓

### Lo que falta (opcional pero útil)

1. **Diagramas ER**: Visualización de relaciones entre tablas.
2. **Ejemplos SQL**: Queries típicos para casos de uso comunes.
3. **Cookbook de patrones**: Recetas para escenarios específicos (ej: "Cómo modelar una sub-organización").

### Conclusión

**La arquitectura actual cubre completamente todos los casos de uso mencionados.** La documentación existente ya describe la semántica tabla por tabla, pero puede complementarse con:
- Este documento de Q&A (ARCHITECTURE_QA.md)
- Tests de demostración (siguiente paso)
- Diagramas visuales (opcional)

---

## 13. En la definición de `ContextGroups` con `subject_entity_id`, `scope_entity_id`, `context_type`, `macro_context`, `event_context` y `sub_context`, ¿es necesario modificar algo más de la arquitectura para soportar los filtros típicos?

**No es necesario modificar la arquitectura. Los índices actuales ya soportan los filtros típicos de forma eficiente.**

### Índices actuales en ContextGroups

```sql
INDEX `idx_profile_id` (`profile_id`)
INDEX `idx_timestamp` (`timestamp`)
INDEX `idx_parent_context` (`parent_context_id`)
INDEX `idx_macro_context` (`macro_context`, `event_context`, `sub_context`)
INDEX `idx_scope_context` (`scope_entity_id`, `context_type`, `timestamp`)
INDEX `idx_subject_context` (`subject_entity_id`, `context_type`, `timestamp`)
```

### Queries típicos y su índice correspondiente

#### 1. Filtrar por empresa y tipo de evento
```sql
SELECT * FROM ContextGroups
WHERE scope_entity_id = 700
  AND context_type = 'clinical_study_visit'
  AND timestamp >= '2025-01-01';
```
**Índice usado**: `idx_scope_context` ✓

#### 2. Filtrar por sujeto (paciente) y tipo
```sql
SELECT * FROM ContextGroups
WHERE subject_entity_id = 123
  AND context_type = 'primary_care_visit'
ORDER BY timestamp DESC;
```
**Índice usado**: `idx_subject_context` ✓

#### 3. Filtrar por contexto de negocio (estudio + visita + formulario)
```sql
SELECT * FROM ContextGroups
WHERE macro_context = 'EST-XYZ'
  AND event_context = 'V1'
  AND sub_context = 'SignosVitales';
```
**Índice usado**: `idx_macro_context` ✓

#### 4. Filtrar por actor (colaborador)
```sql
SELECT * FROM ContextGroups
WHERE profile_id = 80
ORDER BY timestamp DESC;
```
**Índice usado**: `idx_profile_id` ✓

#### 5. Filtrar eventos hijos de un contexto padre
```sql
SELECT * FROM ContextGroups
WHERE parent_context_id = 500;
```
**Índice usado**: `idx_parent_context` ✓

#### 6. Filtrar por origen externo/dispositivo
```sql
SELECT cg.* FROM ContextGroups cg
JOIN DataValues_history h ON cg.context_group_id = h.context_group_id
WHERE h.source_system = 'device.apple_watch'
  AND cg.subject_entity_id = 123;
```
**Índices usados**: `idx_subject_context` + `idx_context` en DataValues_history ✓

### Casos especiales: queries compuestos

#### Todos los datos de una empresa sobre un paciente
```sql
SELECT h.* FROM DataValues_history h
JOIN ContextGroups cg ON h.context_group_id = cg.context_group_id
WHERE cg.subject_entity_id = 123    -- Paciente
  AND cg.scope_entity_id = 700      -- Empresa
  AND h.is_active = TRUE;
```
**Índices**: `idx_subject_context` (ContextGroups) + `idx_context` (DataValues_history)

#### Actividad de un colaborador en un rango de fechas
```sql
SELECT * FROM ContextGroups
WHERE profile_id = 80
  AND scope_entity_id = 700
  AND timestamp BETWEEN '2025-11-01' AND '2025-11-30';
```
**Índice**: `idx_profile_id` + filtro por timestamp

### Modificaciones opcionales (solo si hay problemas de rendimiento)

Si en producción se detectan queries lentas, considerar:

1. **Índice compuesto para auditoría de colaborador por empresa**:
   ```sql
   INDEX `idx_profile_scope` (`profile_id`, `scope_entity_id`, `timestamp`)
   ```

2. **Índice para buscar por source_system en DataValues_history**:
   ```sql
   INDEX `idx_source` (`source_system`, `entity_id`, `timestamp`)
   ```

### Conclusión

**No se necesitan modificaciones adicionales.** Los índices actuales cubren:
- Filtros por scope (empresa, estudio)
- Filtros por subject (paciente, cliente)
- Filtros por contexto de negocio (macro/event/sub)
- Filtros por actor (profile)
- Filtros por tiempo
- Navegación de jerarquías (parent_context)

La arquitectura está optimizada para los queries típicos desde el diseño inicial.

---

## 14. Dado que los roles van a definir accesos y acciones especiales dentro de un módulo, ¿la combinación `Roles` + `EntityRelationships` tal como está descrita es suficiente?

**Sí, es completamente suficiente. El diseño actual soporta todos los patrones de permisos necesarios.**

### Patrón básico de verificación

```php
function hasRole(int $profileId, int $entityId, string $roleCode): bool {
    $sql = "SELECT COUNT(*) as count FROM EntityRelationships
            WHERE profile_id = :pid
              AND entity_id = :eid
              AND role_code = :role
              AND status = 'active'";

    $result = CONN::dml($sql, [
        ':pid' => $profileId,
        ':eid' => $entityId,
        ':role' => $roleCode
    ]);

    return ($result[0]['count'] ?? 0) > 0;
}
```

### Casos de uso soportados

#### 1. Permisos modulares (botones extra en UI)

**Escenario**: Módulo de inventario muestra botón "Ajustar Stock" solo a bodegueros.

```php
// Módulo de inventario
if (hasRole($currentProfileId, $warehouseEntityId, 'company.warehouse')) {
    echo '<button>Ajustar Stock</button>';
}
```

**EntityRelationships**:
```sql
(profile_id=80, entity_id=500, role_code='company.warehouse', status='active')
```

#### 2. Permisos a nivel de ruta (endpoints de API)

**Escenario**: Solo investigadores principales pueden cerrar un estudio.

```php
// Endpoint: POST /api/study/{studyId}/close
if (!hasRole($currentProfileId, $studyId, 'clinical.pi')) {
    return response('Forbidden', 403);
}

// Proceder con cierre de estudio
```

#### 3. Roles heredados por ownership

**Escenario**: El dueño de una organización tiene automáticamente todos los roles sin asignarlos explícitamente.

```php
function hasRoleOrOwnership(int $profileId, int $entityId, string $roleCode): bool {
    // Verificar ownership
    $sqlOwner = "SELECT COUNT(*) FROM EntityRelationships
                 WHERE profile_id = :pid AND entity_id = :eid
                   AND relation_kind = 'owner' AND status = 'active'";

    $isOwner = (CONN::dml($sqlOwner, [':pid' => $profileId, ':eid' => $entityId])[0]['count'] ?? 0) > 0;

    if ($isOwner) return true;

    // Si no es owner, verificar rol específico
    return hasRole($profileId, $entityId, $roleCode);
}
```

#### 4. Roles con grant_mode activo vs pasivo

**Escenario**: Un auditor de sistema tiene permisos pasivos (siempre habilitados) en todas las organizaciones.

```sql
-- Rol pasivo de auditor
INSERT INTO EntityRelationships
  (profile_id, entity_id, relation_kind, role_code, grant_mode, status)
VALUES
  (95, 700, 'permission', 'system.auditor', 'passive', 'active');
```

**Verificación**:
```php
function hasPassiveRole(int $profileId, string $roleCode): bool {
    $sql = "SELECT COUNT(*) FROM EntityRelationships
            WHERE profile_id = :pid
              AND role_code = :role
              AND grant_mode = 'passive'
              AND status = 'active'";

    return (CONN::dml($sql, [':pid' => $profileId, ':role' => $roleCode])[0]['count'] ?? 0) > 0;
}

// En cualquier módulo
if (hasPassiveRole($currentProfileId, 'system.auditor')) {
    // Habilitar vista de auditoría sin necesidad de seleccionar entidad
}
```

#### 5. Roles múltiples en el mismo contexto

**Escenario**: Un investigador es tanto coordinador como encargado de datos en el mismo estudio.

```sql
(profile_id=80, entity_id=700, role_code='study.coordinator', status='active')
(profile_id=80, entity_id=700, role_code='study.data_manager', status='active')
```

**Verificación**:
```php
// Verificar múltiples roles a la vez
function hasAnyRole(int $profileId, int $entityId, array $roleCodes): bool {
    $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
    $sql = "SELECT COUNT(*) FROM EntityRelationships
            WHERE profile_id = ? AND entity_id = ?
              AND role_code IN ($placeholders)
              AND status = 'active'";

    $params = array_merge([$profileId, $entityId], $roleCodes);
    return (CONN::dml($sql, $params)[0]['count'] ?? 0) > 0;
}

if (hasAnyRole($profileId, $studyId, ['study.coordinator', 'study.data_manager'])) {
    // Mostrar dashboard de gestión de estudio
}
```

### Extensiones opcionales (sin modificar schema)

#### Roles con metadatos adicionales
Para roles que requieren configuración extra (ej: límites, delegaciones):
```sql
-- Usar relationship_label para metadatos
(profile_id=80, entity_id=700, role_code='company.warehouse',
 relationship_label='{"max_adjustment": 1000, "zones": ["A", "B"]}')
```

Parsear en aplicación:
```php
$relationship = getEntityRelationship($profileId, $entityId, 'company.warehouse');
$meta = json_decode($relationship['relationship_label'], true);
$maxAdjustment = $meta['max_adjustment'] ?? 0;
```

#### Roles temporales
```sql
-- Usar created_at y un campo custom en relationship_label
(profile_id=80, entity_id=700, role_code='project.temp_access',
 relationship_label='{"expires_at": "2025-12-31"}')
```

Job periódico desactiva roles expirados:
```php
$sql = "UPDATE EntityRelationships
        SET status = 'inactive'
        WHERE status = 'active'
          AND JSON_EXTRACT(relationship_label, '$.expires_at') < NOW()";
```

### Conclusión

**La combinación `Roles` + `EntityRelationships` es suficiente para:**
- Permisos modulares (UI, rutas, acciones)
- Roles por entidad (warehouse, project, study)
- Roles activos vs pasivos
- Ownership implícito
- Roles múltiples en mismo contexto
- Metadatos de rol (vía relationship_label)
- Roles temporales (vía job + metadata)

**No se requieren modificaciones al schema.** Solo se necesita implementar funciones auxiliares como `hasRole()`, `hasRoleOrOwnership()`, `hasPassiveRole()` en la capa de aplicación.

Los módulos pueden confiar completamente en esta arquitectura para decidir accesos y funcionalidades sin necesidad de lógica hardcoded de permisos.

---

## Resumen Ejecutivo

### La arquitectura Bintelx responde a todos los casos de uso planteados

✓ **Multi-empresa**: Soportado vía múltiples `Profiles` por `Account`
✓ **Roles por proyecto**: Soportado vía múltiples filas en `EntityRelationships`
✓ **Separación Account/Profile/Entity**: Implementado como arquitectura de tres capas
✓ **Roles textuales data-driven**: `hasRole(profile, entity, role_code)` como API de permisos
✓ **DataCaptureService**: Implementado y alineado con EAV + ContextGroups
✓ **Datos multi-origen**: Soportado vía `subject_entity_id` vs `scope_entity_id` + `source_system`
✓ **Vistas unificadas y segregadas**: Query por `entity_id` (unificado) o `scope_entity_id` (segregado)
✓ **Revocación sin pérdida de historial**: Desactivar `Profile` preserva trazabilidad ALCOA
✓ **Filtros eficientes**: Índices estratégicos cubren queries típicos
✓ **Sistema de permisos suficiente**: `Roles` + `EntityRelationships` soportan todos los patrones

### No se requieren modificaciones al schema

El diseño actual está completo y optimizado. Solo se necesita:
1. Implementar funciones auxiliares de permisos en la capa de aplicación
2. Crear tests de demostración (siguiente paso)
3. Opcionalmente, diagramas visuales para onboarding de desarrolladores

### Próximo paso: Tests de demostración

Crear aplicaciones de ejemplo que demuestren:
- Setup de entidades (persona, empresa, proyecto)
- Asignación de perfiles y roles
- Guardado y lectura de datos versionados
- Filtros por subject, scope, actor
- Flujo de desvinculación de colaborador
