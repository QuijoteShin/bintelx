<?php # bintelx/kernel/Entity/Correlation.php
namespace bX\Entity;

class Correlation {
  private array $data = [];      // Datos procesados
  private array $data_raw = [];  // Datos sin procesar de la DB

  public function __construct()
  {
  }

  /**
   * Modelo
   */
  public function model(): array
  {
    return [
      'entity_correlation_id'     => ['type' => 'int',     'auto_increment' => true, 'not null' => true],
      'comp_id'                   => ['type' => 'int',     'default' => null],
      'comp_branch_id'            => ['type' => 'int',     'default' => null],
      'snapshot_id'               => ['type' => 'bigint',  'default' => 0],
      'entity_correlation_to'     => ['type' => 'int',     'not null' => true],
      'entity_correlation_type'   => ['type' => 'string',  'default' => ''],
    ];
  }

  /**
   * Crea o actualiza un registro en la base de datos.
   * @param array $correlation ['entity_correlation_id'=>'...', 'entity_correlation_to'=>'...', 'entity_correlation_type'=>'...', ...]
   * @return int
   */
  public static function save(array $correlation): int {
    $existingId = $correlation['entity_correlation_id'] ?? 0;
    if ($existingId > 0) {
      $query = "UPDATE `entity_correlation`
                      SET 
                          comp_id = :comp_id,
                          comp_branch_id = :comp_branch_id,
                          snapshot_id = :snapshot_id,
                          entity_correlation_to = :entity_correlation_to,
                          entity_correlation_type = :entity_correlation_type
                      WHERE entity_correlation_id = :entity_correlation_id";
      $params = [
        ':entity_correlation_id'    => $existingId,
        ':comp_id'                  => $correlation['comp_id'] ?? Entity::ctx()->compId,
        ':comp_branch_id'           => $correlation['comp_branch_id'] ?? Entity::ctx()->compBranchId,
        ':snapshot_id'              => $correlation['snapshot_id'] ?? 0,
        ':entity_correlation_to'    => $correlation['entity_correlation_to'] ?? 0,
        ':entity_correlation_type'  => strtolower($correlation['entity_correlation_type'] ?? ''),
      ];
      \bX\CONN::nodml($query, $params);
      return $existingId;
    } else {
      // Insertar nuevo registro
      $query = "INSERT INTO `entity_correlation`
                      (entity_id, comp_id, comp_branch_id, snapshot_id, entity_correlation_to, entity_correlation_type)
                      VALUES
                      (:entity_id, :comp_id, :comp_branch_id, :snapshot_id, :entity_correlation_to, :entity_correlation_type)";

      $params = [
        ':entity_id'                => $correlation['entity_id'] ?? null,
        ':comp_id'                  => $correlation['comp_id'] ?? Entity::ctx()->compId,
        ':comp_branch_id'           => $correlation['comp_branch_id'] ?? Entity::ctx()->compBranchId,
        ':snapshot_id'              => $correlation['snapshot_id'] ?? 0,
        ':entity_correlation_to'    => $correlation['entity_correlation_to'] ?? 0,
        ':entity_correlation_type'  => strtolower($correlation['entity_correlation_type'] ?? ''),
      ];
      $result = \bX\CONN::nodml($query, $params);

      if (!$result['success']) {
        throw new \Exception("No se pudo insertar la correlación de entidad.");
      }
      // Retorna el nuevo ID (MySQL: lastInsertId)
      return $result['last_id'];
    }
  }

  /**
   * Lee un registro de la tabla `entity_correlation`
   * @param int $id
   * @return bool
   */
  public function read(int $id): bool
  {
    if (is_null($id)) return false;

    $query = "SELECT * FROM `entity_correlation` 
         WHERE entity_correlation_id = :id AND comp_id = :comp_id AND comp_branch_id = :comp_branch_id";
    $this->data_raw = \bX\CONN::dml($query,
      [':id' => $id,
        ':comp_id' => Entity::ctx()->compId,
        ':comp_branch_id' => Entity::ctx()->compBranchId
    ]);

    if (!empty($this->data_raw)) {
      $this->data = $this->formatData($this->data_raw[0]);
      return true;
    }
    return false;
  }

  /**
   * Obtiene todas las correlaciones para una entidad específica
   * @param int $entityId
   * @return array|null
   */
  public function getByEntityId(int $entityId): ?array
  {
    $groups = [];
    $query = "SELECT * FROM `entity_correlation` 
                  WHERE entity_id = :entity_id AND comp_id = :comp_id";

    \bX\CONN::dml($query, [
      ':entity_id' => $entityId,
      ':comp_id' => Entity::ctx()->compId,
      ':comp_branch_id' => Entity::ctx()->compBranchId
    ], function ($row) use (&$groups) {
      $type = $row['entity_correlation_type'];
      if (!isset($groups[$type])) {
        $groups[$type] = [];
      }
      $groups[$type][] = $this->formatData($row);
    });

    return !empty($groups) ? $groups : null;
  }

  /**
   * Obtiene todas las correlaciones agrupadas por tipo para un comp_id específico
   * @return array|null
   */
  public function getAllGroupedByType(array $data): ?array
  {
    $groups = [];
    $query = "SELECT * FROM `entity_correlation` 
                  WHERE entity_id = :entity_id AND comp_id = :comp_id AND comp_branch_id = :comp_branch_id";

    \bX\CONN::dml($query, [
      ':entity_id' => Entity::ctx()->entityId,
      ':comp_id' => Entity::ctx()->compId,
      ':comp_branch_id' => Entity::ctx()->compBranchId
    ], function ($row) use (&$groups) {
      $type = $row['entity_correlation_type'];
      if (!isset($groups[$type])) {
        $groups[$type] = [];
      }
      $groups[$type][] = $this->formatData($row);
    });

    return !empty($groups) ? $groups : null;
  }

  /**
   * Formatea los datos según el modelo
   * @param array $data
   * @return array
   */
  private function formatData(array $data): array {
    $formatted = \bX\DataUtils::formatData($data, $this->model());
    return $formatted;
  }

  /**
   * Elimina un registro de la tabla `entity_correlation`
   * @param int $id
   * @return bool
   */
  public static function delete(int $id): bool {
    $query = "UPDATE `entity_correlation` SET `entity_correlation_status` = 'inactive' WHERE entity_correlation_id = :id";
    $result = \bX\CONN::nodml($query, [':id' => $id]);

    return $result['success'];
  }
}
