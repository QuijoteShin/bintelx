<?php
namespace bX;

class Sequent
{

  public static function read($params){

    $compId       = $params['comp_id']          ?? 0;
    $branchId     = $params['comp_branch_id']   ?? 0;
    $family       = $params['sequent_family']   ?? 'default';
    $prefix       = $params['sequent_prefix']   ?? '';
    $incBy        = $params['sequent_increment_by'] ?? 1;
    $padLength    = $params['sequent_padding_length'] ?? 0;
    $padString    = $params['sequent_padding']  ?? '0';

    $sql = "SELECT * FROM sequent
                  WHERE comp_id = :comp_id
                    AND comp_branch_id = :branch_id
                    AND sequent_family = :family
                    AND sequent_prefix = :prefix
                  LIMIT 1";
    return \bX\CONN::dml($sql, [
      ':comp_id' => $compId,
      ':branch_id' => $branchId,
      ':family' => $family,
      ':prefix' => $prefix
    ]);
  }
  /**
   * Consume el siguiente valor de la secuencia, creando el registro si no existe.
   * @param array $params [
   *    'comp_id' => int,
   *    'comp_branch_id' => int,
   *    'sequent_family' => string,
   *    'sequent_prefix' => string,
   *    'sequent_increment_by' => int,
   *    'sequent_padding_length' => int,
   *    'sequent_padding' => string
   * ]
   * @return array [
   *    'sequent_id' => int,
   *    'sequence'   => string, // p. ej. ORD-00001
   *    'consume'    => int     // valor numérico consumido
   * ]
   */
  public static function consume(array $params): array
  {
    $compId       = $params['comp_id']          ?? 0;
    $branchId     = $params['comp_branch_id']   ?? 0;
    $family       = $params['sequent_family']   ?? 'default';
    $prefix       = $params['sequent_prefix']   ?? '';
    $incBy        = $params['sequent_increment_by'] ?? 1;
    $padLength    = $params['sequent_padding_length'] ?? 0;
    $padString    = $params['sequent_padding']  ?? '0';

    \bX\CONN::begin();
    try {

      $found = self::read($params);
      // Variables a usar luego
      $sequentId    = 0;
      $lastNumber   = 0;
      $currentValue = 0;

      if (empty($found)) {
        $queryInsert = "INSERT INTO sequent (
                  comp_id, comp_branch_id, sequent_family, sequent_prefix,
                  sequent_last_number, sequent_value, sequent_increment_by,
                  sequent_padding_length, sequent_padding
                  , sequent_created_by, sequent_updated_by
              ) VALUES (
                  :comp_id, :branch_id, :family, :prefix,
                  0, 0, :inc_by,
                  :pad_length, :pad_string, :created_by, :updated_by
              )";
        $insertParams = [
          ':comp_id' => $compId,
          ':branch_id' => $branchId,
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
