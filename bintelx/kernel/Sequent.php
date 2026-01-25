<?php
# bintelx/kernel/Sequent.php
namespace bX;

/**
 * Sequent - Generador de secuencias numéricas únicas por scope/family
 *
 * IMPORTANTE: Todos los métodos reciben UN SOLO argumento tipo ARRAY con keys específicos.
 * NO usar argumentos posicionales.
 *
 * EJEMPLO DE USO CORRECTO:
 * ```php
 * $sequent = Sequent::consume([
 *     'scope_entity_id' => Profile::$scope_entity_id,
 *     'sequent_family' => 'offering_quote',      # Identifica el tipo de secuencia
 *     'sequent_prefix' => 'QT-',                 # Prefijo visible (incluir guión)
 *     'sequent_padding_length' => 5              # QT-00001, QT-00002, etc.
 * ]);
 * $code = $sequent['consume'];  # "QT-00001"
 * ```
 *
 * EJEMPLO INCORRECTO (error común):
 * ```php
 * # ❌ ESTO FALLA - NO usar argumentos posicionales
 * $code = Sequent::consume("WO_CODE", "WO-", 5);
 *
 * # ✅ CORRECTO - usar array asociativo
 * $sequent = Sequent::consume(['scope_entity_id' => $scope, 'sequent_family' => 'WO', ...]);
 * ```
 *
 * KEYS DISPONIBLES:
 * - scope_entity_id (int): Tenant/empresa que controla la secuencia
 * - branch_entity_id (int, opcional): Sub-división (sucursal, proyecto)
 * - sequent_family (string): Categoría lógica (orders, invoices, quotes)
 * - sequent_prefix (string): Prefijo visible en el código (ORD-, INV-)
 * - sequent_padding_length (int): Largo del número con ceros (5 = 00001)
 * - sequent_padding (string, default '0'): Caracter de padding
 * - sequent_increment_by (int, default 1): Incremento por consumo
 *
 * RETORNO de consume():
 * - sequent_id: ID del registro en tabla sequent
 * - sequent_value: Valor numérico actual (ej: 42)
 * - consume: Código formateado (ej: "ORD-00042")
 */
class Sequent
{

  public static function read($params){

    $scopeEntityId  = $params['scope_entity_id']    ?? 0;
    $branchEntityId = $params['branch_entity_id']   ?? 0;
    $family         = $params['sequent_family']     ?? 'default';
    $prefix         = $params['sequent_prefix']     ?? '';
    $incBy          = $params['sequent_increment_by'] ?? 1;
    $padLength      = $params['sequent_padding_length'] ?? 0;
    $padString      = $params['sequent_padding']    ?? '0';

    $sql = "SELECT * FROM sequent
                  WHERE scope_entity_id = :scope_entity_id
                    AND branch_entity_id = :branch_entity_id
                    AND sequent_family = :family
                    AND sequent_prefix = :prefix
                  LIMIT 1";
    return \bX\CONN::dml($sql, [
      ':scope_entity_id' => $scopeEntityId,
      ':branch_entity_id' => $branchEntityId,
      ':family' => $family,
      ':prefix' => $prefix
    ]);
  }
  /**
   * Consume el siguiente valor de la secuencia, creando el registro si no existe.
   * @param array $params [
   *    'scope_entity_id' => int,     # Entity que controla la secuencia (ej: empresa, proyecto)
   *    'branch_entity_id' => int,    # Sub-entity opcional (ej: sucursal, departamento)
   *    'sequent_family' => string,   # Familia de secuencia (orders, invoices, etc)
   *    'sequent_prefix' => string,   # Prefijo (ORD-, INV-, etc)
   *    'sequent_increment_by' => int,
   *    'sequent_padding_length' => int,
   *    'sequent_padding' => string
   * ]
   * @return array [
   *    'sequent_id' => int,
   *    'sequent_value' => int,       # Valor numérico consumido
   *    'consume' => string            # Secuencia formateada (ej: ORD-00001)
   * ]
   */
  public static function consume(array $params): array
  {
    $scopeEntityId  = $params['scope_entity_id']    ?? 0;
    $branchEntityId = $params['branch_entity_id']   ?? 0;
    $family         = $params['sequent_family']     ?? 'default';
    $prefix         = $params['sequent_prefix']     ?? '';
    $incBy          = $params['sequent_increment_by'] ?? 1;
    $padLength      = $params['sequent_padding_length'] ?? 0;
    $padString      = $params['sequent_padding']    ?? '0';

    \bX\CONN::begin();
    try {

      $found = self::read($params);
      // Variables a usar luego
      $sequentId    = 0;
      $lastNumber   = 0;
      $currentValue = 0;

      if (empty($found)) {
        $queryInsert = "INSERT INTO sequent (
                  scope_entity_id, branch_entity_id, sequent_family, sequent_prefix,
                  sequent_last_number, sequent_value, sequent_increment_by,
                  sequent_padding_length, sequent_padding
                  , sequent_created_by, sequent_updated_by
              ) VALUES (
                  :scope_entity_id, :branch_entity_id, :family, :prefix,
                  0, 0, :inc_by,
                  :pad_length, :pad_string, :created_by, :updated_by
              )";
        $insertParams = [
          ':scope_entity_id' => $scopeEntityId,
          ':branch_entity_id' => $branchEntityId,
          ':family' => $family,
          ':prefix' => $prefix,
          ':inc_by' => $incBy,
          ':pad_length' => $padLength,
          ':pad_string' => $padString,
          ':created_by' => \bX\Profile::$profile_id,
          ':updated_by' => \bX\Profile::$profile_id
        ];
        $res = \bX\CONN::nodml($queryInsert, $insertParams);
        if (!$res['success']) {
          throw new \Exception("No se pudo crear el registro en sequent.");
        }
        $found = self::read($params);
        // Se re-lee para obtener los valores
      } else {
      }

      $row = $found[0];
      $sequentId    = $row['sequent_id'];
      $lastNumber   = (int)$row['sequent_last_number'];
      $currentValue = (int)$row['sequent_value'];
      $incBy        = (int)$row['sequent_increment_by']; // Prioriza DB sobre $params
      $padLength    = (int)$row['sequent_padding_length'];
      $padString    = $row['sequent_padding'];

      //    lastNumber era el último, newNumber = lastNumber + incBy
      $newNumber = $currentValue + $incBy;

      // 3) Actualizar sequent_last_number y sequent_value
      $updQuery = "UPDATE sequent
                       SET sequent_last_number = :last_number,
                           sequent_value = :val
                       WHERE sequent_id = :id";
      \bX\CONN::nodml($updQuery, [
        ':last_number' => $currentValue,
        ':val'         => $newNumber,
        ':id'          => $sequentId
      ]);


      //    p.ej. ORD- + "00001"
      $paddedNum = str_pad((string)$newNumber, $padLength, $padString, STR_PAD_LEFT);
      $sequenceString = $prefix . $paddedNum;

      \bX\CONN::commit();
      return [
        'sequent_id' => $sequentId,
        'sequent_value'   => $newNumber,
        'consume'    => $sequenceString
      ];
    } catch (\Exception $e) {
      \bX\CONN::rollback();
      throw $e;
    }
  }
}
