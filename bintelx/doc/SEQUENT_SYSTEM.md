# Sistema de Correlativos (Sequent)

## Descripción

El sistema `sequent` de Bintelx es un módulo agnóstico para generar números secuenciales (correlativos) para documentos, órdenes, facturas, y cualquier entidad que requiera numeración automática incremental.

## Tabla `sequent`

### Estructura

```sql
CREATE TABLE `sequent` (
    `sequent_id` INT AUTO_INCREMENT PRIMARY KEY,
    `comp_id` int(11) NOT NULL DEFAULT 0,
    `comp_branch_id` int(11) NOT NULL DEFAULT 0,
    `sequent_family` VARCHAR(20) NOT NULL DEFAULT 'N',
    `sequent_prefix` VARCHAR(20) NOT NULL DEFAULT '',
    `sequent_last_number` INT NOT NULL DEFAULT 0,
    `sequent_value` INT NOT NULL DEFAULT 0 COMMENT 'current value',
    `sequent_increment_by` INT NOT NULL DEFAULT 1,
    `sequent_padding_length` INT NOT NULL DEFAULT 0,
    `sequent_padding` VARCHAR(3) NOT NULL DEFAULT '',
    `sequent_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `sequent_created_by` int(11) NOT NULL,
    `sequent_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `sequent_updated_by` int(11) NOT NULL,
    KEY `sequent_prefix` (`sequent_prefix`),
    `sequent_family` (`sequent_family`),
    KEY `comp` (`comp_id`, `comp_branch_id`)
) ENGINE=InnoDB;
```

### Campos

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `sequent_id` | INT | ID único del secuenciador |
| `comp_id` | INT | ID de la empresa (multi-tenant) |
| `comp_branch_id` | INT | ID de la sucursal |
| `sequent_family` | VARCHAR(20) | Familia o categoría del secuenciador (ej: 'ORDER', 'INVOICE', 'QUOTE') |
| `sequent_prefix` | VARCHAR(20) | Prefijo del número (ej: 'ORD-', 'INV-2025-') |
| `sequent_last_number` | INT | Último número generado |
| `sequent_value` | INT | Valor actual del contador |
| `sequent_increment_by` | INT | Incremento (normalmente 1) |
| `sequent_padding_length` | INT | Longitud del padding (ej: 6 para '000001') |
| `sequent_padding` | VARCHAR(3) | Carácter de padding (normalmente '0') |

### Índices

- `sequent_prefix`: Para búsqueda rápida por prefijo
- `sequent_family`: Para agrupar secuencias por familia
- `comp`: Para aislar secuencias por empresa/sucursal

## Casos de Uso

### 1. Numeración de Órdenes

**Requisito**: Generar números de orden como `ORD-000001`, `ORD-000002`, etc.

**Configuración**:
```sql
INSERT INTO sequent (comp_id, comp_branch_id, sequent_family, sequent_prefix,
                     sequent_value, sequent_increment_by, sequent_padding_length,
                     sequent_padding, sequent_created_by, sequent_updated_by)
VALUES (1, 0, 'ORDER', 'ORD-', 0, 1, 6, '0', 1, 1);
```

**Uso**:
```php
$newOrderNumber = Sequent::getNext([
    'comp_id' => 1,
    'comp_branch_id' => 0,
    'sequent_family' => 'ORDER',
    'sequent_prefix' => 'ORD-'
]);
// Resultado: "ORD-000001"
```

### 2. Facturas por Año

**Requisito**: Reiniciar numeración cada año (`INV-2025-00001`, `INV-2026-00001`).

**Configuración** (por año):
```sql
-- Para 2025
INSERT INTO sequent (comp_id, comp_branch_id, sequent_family, sequent_prefix, ...)
VALUES (1, 0, 'INVOICE', 'INV-2025-', 0, 1, 5, '0', 1, 1);

-- Para 2026 (al inicio del año)
INSERT INTO sequent (comp_id, comp_branch_id, sequent_family, sequent_prefix, ...)
VALUES (1, 0, 'INVOICE', 'INV-2026-', 0, 1, 5, '0', 1, 1);
```

### 3. Cotizaciones por Sucursal

**Requisito**: Numeración independiente por sucursal.

**Configuración**:
```sql
-- Sucursal 1
INSERT INTO sequent (comp_id, comp_branch_id, sequent_family, sequent_prefix, ...)
VALUES (1, 1, 'QUOTE', 'COT-SUC1-', 0, 1, 4, '0', 1, 1);

-- Sucursal 2
INSERT INTO sequent (comp_id, comp_branch_id, sequent_family, sequent_prefix, ...)
VALUES (1, 2, 'QUOTE', 'COT-SUC2-', 0, 1, 4, '0', 1, 1);
```

## API PHP Recomendada

### Clase `Sequent`

```php
<?php
namespace bX;

class Sequent {
    /**
     * Obtiene el próximo número en la secuencia.
     * Esta operación es atómica y thread-safe.
     *
     * @param array $criteria ['comp_id', 'comp_branch_id', 'sequent_family', 'sequent_prefix']
     * @param int $actorProfileId ID del perfil que solicita el número
     * @return string El número completo formateado
     * @throws Exception Si no se encuentra la secuencia
     */
    public static function getNext(array $criteria, int $actorProfileId): string {
        if (!CONN::isInTransaction()) {
            CONN::begin();
            $ownTransaction = true;
        } else {
            $ownTransaction = false;
        }

        try {
            // 1. Buscar y bloquear la secuencia (FOR UPDATE)
            $sql = "SELECT sequent_id, sequent_value, sequent_increment_by,
                           sequent_padding_length, sequent_padding, sequent_prefix
                    FROM sequent
                    WHERE comp_id = :comp_id
                      AND comp_branch_id = :comp_branch_id
                      AND sequent_family = :family
                      AND sequent_prefix = :prefix
                    FOR UPDATE";

            $params = [
                ':comp_id' => $criteria['comp_id'],
                ':comp_branch_id' => $criteria['comp_branch_id'],
                ':family' => $criteria['sequent_family'],
                ':prefix' => $criteria['sequent_prefix']
            ];

            $result = CONN::dml($sql, $params);

            if (empty($result)) {
                throw new \Exception("Sequent not found for criteria: " . json_encode($criteria));
            }

            $sequence = $result[0];

            // 2. Calcular nuevo valor
            $newValue = (int)$sequence['sequent_value'] + (int)$sequence['sequent_increment_by'];

            // 3. Actualizar la secuencia
            $updateSql = "UPDATE sequent
                          SET sequent_value = :new_value,
                              sequent_last_number = :new_value,
                              sequent_updated_at = NOW(),
                              sequent_updated_by = :updated_by
                          WHERE sequent_id = :seq_id";

            CONN::nodml($updateSql, [
                ':new_value' => $newValue,
                ':updated_by' => $actorProfileId,
                ':seq_id' => $sequence['sequent_id']
            ]);

            // 4. Formatear el número
            $paddedNumber = str_pad(
                $newValue,
                (int)$sequence['sequent_padding_length'],
                $sequence['sequent_padding'],
                STR_PAD_LEFT
            );

            $formattedNumber = $sequence['sequent_prefix'] . $paddedNumber;

            if ($ownTransaction) {
                CONN::commit();
            }

            Log::logInfo("Sequent: Generated $formattedNumber", $criteria);

            return $formattedNumber;

        } catch (\Exception $e) {
            if ($ownTransaction && CONN::isInTransaction()) {
                CONN::rollback();
            }
            Log::logError("Sequent::getNext failed: " . $e->getMessage(), $criteria);
            throw $e;
        }
    }

    /**
     * Crea una nueva secuencia.
     *
     * @param array $config Configuración de la secuencia
     * @param int $actorProfileId ID del perfil que crea la secuencia
     * @return int El sequent_id creado
     */
    public static function create(array $config, int $actorProfileId): int {
        $sql = "INSERT INTO sequent
                (comp_id, comp_branch_id, sequent_family, sequent_prefix,
                 sequent_value, sequent_increment_by, sequent_padding_length,
                 sequent_padding, sequent_created_by, sequent_updated_by)
                VALUES
                (:comp_id, :comp_branch_id, :family, :prefix,
                 :value, :increment, :padding_length, :padding, :created_by, :updated_by)";

        $result = CONN::nodml($sql, [
            ':comp_id' => $config['comp_id'],
            ':comp_branch_id' => $config['comp_branch_id'] ?? 0,
            ':family' => $config['sequent_family'],
            ':prefix' => $config['sequent_prefix'] ?? '',
            ':value' => $config['sequent_value'] ?? 0,
            ':increment' => $config['sequent_increment_by'] ?? 1,
            ':padding_length' => $config['sequent_padding_length'] ?? 0,
            ':padding' => $config['sequent_padding'] ?? '0',
            ':created_by' => $actorProfileId,
            ':updated_by' => $actorProfileId
        ]);

        if (!$result['success']) {
            throw new \Exception("Failed to create sequent: " . ($result['error'] ?? 'Unknown error'));
        }

        return (int)$result['last_id'];
    }

    /**
     * Obtiene el valor actual sin incrementar (solo lectura).
     *
     * @param array $criteria
     * @return int El valor actual
     */
    public static function getCurrent(array $criteria): int {
        $sql = "SELECT sequent_value
                FROM sequent
                WHERE comp_id = :comp_id
                  AND comp_branch_id = :comp_branch_id
                  AND sequent_family = :family
                  AND sequent_prefix = :prefix";

        $result = CONN::dml($sql, [
            ':comp_id' => $criteria['comp_id'],
            ':comp_branch_id' => $criteria['comp_branch_id'],
            ':family' => $criteria['sequent_family'],
            ':prefix' => $criteria['sequent_prefix']
        ]);

        return $result[0]['sequent_value'] ?? 0;
    }
}
```

## Migración a Nueva Arquitectura

En la nueva arquitectura (target.md), el sistema `sequent` se mantiene **SIN CAMBIOS**, pero se adapta para trabajar con `scope_entity_id` en lugar de `comp_id`:

### Opción 1: Mantener Compatibilidad Legacy

```sql
-- Mantener la tabla actual y mapear comp_id a entity_id
CREATE TABLE `sequent` (
    `sequent_id` INT AUTO_INCREMENT PRIMARY KEY,
    `comp_id` int(11) NOT NULL DEFAULT 0,  -- Mantener para compatibilidad
    `scope_entity_id` BIGINT UNSIGNED NULL,  -- Nuevo campo (opcional)
    `comp_branch_id` int(11) NOT NULL DEFAULT 0,
    -- ... resto de campos igual
);
```

### Opción 2: Nueva Tabla Aislada (Recomendado)

```sql
-- Nueva tabla agnóstica para secuencias
CREATE TABLE `Sequencers` (
    `sequencer_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scope_entity_id` BIGINT UNSIGNED NOT NULL,  -- Empresa, proyecto, estudio, etc.
    `sequence_family` VARCHAR(100) NOT NULL,     -- 'order', 'invoice', 'quote', etc.
    `sequence_prefix` VARCHAR(50) NOT NULL,
    `current_value` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `increment_by` INT UNSIGNED NOT NULL DEFAULT 1,
    `padding_length` INT UNSIGNED NOT NULL DEFAULT 0,
    `padding_char` VARCHAR(3) NOT NULL DEFAULT '0',
    `status` VARCHAR(50) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by_profile_id` BIGINT UNSIGNED NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by_profile_id` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`sequencer_id`),
    UNIQUE KEY `uq_scope_family_prefix` (`scope_entity_id`, `sequence_family`, `sequence_prefix`),
    INDEX `idx_scope` (`scope_entity_id` ASC),
    INDEX `idx_family` (`sequence_family` ASC)
) ENGINE=InnoDB;
```

## Uso en Módulos

### Ejemplo: Módulo de Órdenes

```php
<?php
// En el módulo custom/order/OrderService.php

class OrderService {
    public static function createOrder(array $orderData, int $actorProfileId): array {
        try {
            CONN::begin();

            // 1. Obtener número de orden
            $orderNumber = \bX\Sequent::getNext([
                'comp_id' => $orderData['comp_id'],
                'comp_branch_id' => $orderData['comp_branch_id'],
                'sequent_family' => 'ORDER',
                'sequent_prefix' => 'ORD-'
            ], $actorProfileId);

            // 2. Crear la orden
            $sql = "INSERT INTO `order`
                    (comp_id, comp_branch_id, sequent_value, customer_id,
                     order_type, order_status, order_created_by)
                    VALUES (:comp_id, :comp_branch_id, :order_num, :customer_id,
                            :type, 'pending', :created_by)";

            $result = CONN::nodml($sql, [
                ':comp_id' => $orderData['comp_id'],
                ':comp_branch_id' => $orderData['comp_branch_id'],
                ':order_num' => $orderNumber,
                ':customer_id' => $orderData['customer_id'],
                ':type' => $orderData['order_type'],
                ':created_by' => $actorProfileId
            ]);

            CONN::commit();

            return [
                'success' => true,
                'order_id' => $result['last_id'],
                'order_number' => $orderNumber
            ];

        } catch (\Exception $e) {
            if (CONN::isInTransaction()) {
                CONN::rollback();
            }
            Log::logError("OrderService::createOrder failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
```

## Concurrencia y Thread Safety

El sistema `sequent` es **thread-safe** gracias a:

1. **`SELECT ... FOR UPDATE`**: Bloquea la fila durante la transacción
2. **Transacciones atómicas**: `UPDATE` + incremento ocurren en una sola transacción
3. **Auto-rollback**: Si algo falla, la transacción hace rollback y no se consume el número

### Ejemplo de Concurrencia

```
Thread A:                          Thread B:
BEGIN                              BEGIN
SELECT ... FOR UPDATE (value=10)   SELECT ... FOR UPDATE (BLOQUEADO)
UPDATE sequent_value = 11          (ESPERANDO...)
COMMIT                             (SE DESBLOQUEA)
                                   SELECT ... FOR UPDATE (value=11)
                                   UPDATE sequent_value = 12
                                   COMMIT
```

Resultado: **No hay colisiones**, números únicos garantizados.

## Mejores Prácticas

1. **Siempre usar dentro de transacciones** si se va a crear un registro vinculado
2. **No compartir prefijos** entre diferentes familias
3. **Definir secuencias al inicio** del deployment de cada módulo
4. **Backup antes de reset**: Si necesitas resetear una secuencia, haz backup primero
5. **Auditoría**: El campo `updated_by` permite rastrear quién generó cada número

## Testing

### Script de Prueba

```php
<?php
// test/test_sequent.php

require_once '../bintelx/WarmUp.php';

echo "=== TEST: Sequent System ===\n\n";

// 1. Crear secuencia
$sequentId = \bX\Sequent::create([
    'comp_id' => 999,
    'comp_branch_id' => 0,
    'sequent_family' => 'TEST_ORDER',
    'sequent_prefix' => 'TEST-',
    'sequent_value' => 0,
    'sequent_padding_length' => 5
], 1);

echo "✓ Sequent created: sequent_id=$sequentId\n\n";

// 2. Generar números
for ($i = 1; $i <= 3; $i++) {
    $number = \bX\Sequent::getNext([
        'comp_id' => 999,
        'comp_branch_id' => 0,
        'sequent_family' => 'TEST_ORDER',
        'sequent_prefix' => 'TEST-'
    ], 1);

    echo "  Generado: $number\n";
}

echo "\n✓ Test completado\n";
// Resultado esperado:
//   Generado: TEST-00001
//   Generado: TEST-00002
//   Generado: TEST-00003
```

---

**Última actualización**: 2025-11-14
