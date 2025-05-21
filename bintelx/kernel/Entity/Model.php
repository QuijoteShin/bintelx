<?php # bintelx/kernel/Entity/Model.php
namespace bX\Entity;

class Model {
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
      'entity_model_id'       => ['type' => 'int',     'auto_increment' => true, 'not null' => true],
      'entity_id'             => ['type' => 'int',     'default' => 0],
      'comp_id'               => ['type' => 'int',     'default' => null],
      'comp_branch_id'        => ['type' => 'int',     'default' => null],
      'snapshot_id'           => ['type' => 'bigint',  'default' => 0],
      'entity_model_type'     => ['type' => 'string',  'default' => ''],
      'entity_model_prime'    => ['type' => 'smallint', 'default' => 0],
      'entity_model_name'     => ['type' => 'string',  'default' => ''],
      'entity_model_value'    => ['type' => 'string',  'default' => ''],
      'entity_model_value2'   => ['type' => 'string',  'default' => ''],
      'entity_model_value3'   => ['type' => 'string',  'default' => ''],
      'entity_model_status'   => ['type' => 'string',  'default' => 'active'],
    ];
  }

  /**
   * Crea o actualiza un registro en la base de datos.
   * @param array $model ['entity_model_id'=>'...', 'entity_id'=>'...', ...]
   * @return int
   */
  public static function save(array $model): int {
    $existingId = $model['entity_model_id'] ?? 0;
    if ($existingId > 0) {
      // Actualizar registro existente
      $query = "UPDATE `entity_model`
                      SET 
                          entity_id = :entity_id,
                          comp_id = :comp_id,
                          comp_branch_id = :comp_branch_id,
                          snapshot_id = :snapshot_id,
                          entity_model_type = :entity_model_type,
                          entity_model_prime = :entity_model_prime,
                          entity_model_name = :entity_model_name,
                          entity_model_value = :entity_model_value,
                          entity_model_value2 = :entity_model_value2,
                          entity_model_value3 = :entity_model_value3,
                          entity_model_status = :entity_model_status
                      WHERE entity_model_id = :entity_model_id";
      $params = [
        ':entity_model_id'      => $existingId,
        ':entity_id'            => $model['entity_id'] ?? 0,
        ':comp_id'              => $model['comp_id'] ?? null,
        ':comp_branch_id'       => $model['comp_branch_id'] ?? null,
        ':snapshot_id'          => $model['snapshot_id'] ?? 0,
        ':entity_model_type'    => $model['entity_model_type'] ?? '',
        ':entity_model_prime'   => $model['entity_model_prime'] ?? 0,
        ':entity_model_name'    => $model['entity_model_name'] ?? '',
        ':entity_model_value'   => $model['entity_model_value'] ?? '',
        ':entity_model_value2'  => $model['entity_model_value2'] ?? '',
        ':entity_model_value3'  => $model['entity_model_value3'] ?? '',
        ':entity_model_status'  => $model['entity_model_status'] ?? 'active',
      ];
      \bX\CONN::nodml($query, $params);
      return $existingId;
    } else {
      // Insertar nuevo registro
      $query = "INSERT INTO `entity_model`
                      (entity_id, comp_id, comp_branch_id, snapshot_id, entity_model_type, 
                       entity_model_prime, entity_model_name, entity_model_value, 
                       entity_model_value2, entity_model_value3, entity_model_status)
                      VALUES
                      (:entity_id, :comp_id, :comp_branch_id, :snapshot_id, :entity_model_type, 
                       :entity_model_prime, :entity_model_name, :entity_model_value, 
                       :entity_model_value2, :entity_model_value3, :entity_model_status)";

      $params = [
        ':entity_id'            => $model['entity_id'] ?? 0,
        ':comp_id'              => $model['comp_id'] ?? null,
        ':comp_branch_id'       => $model['comp_branch_id'] ?? null,
        ':snapshot_id'          => $model['snapshot_id'] ?? 0,
        ':entity_model_type'    => $model['entity_model_type'] ?? '',
        ':entity_model_prime'   => $model['entity_model_prime'] ?? 0,
        ':entity_model_name'    => $model['entity_model_name'] ?? '',
        ':entity_model_value'   => $model['entity_model_value'] ?? '',
        ':entity_model_value2'  => $model['entity_model_value2'] ?? '',
        ':entity_model_value3'  => $model['entity_model_value3'] ?? '',
        ':entity_model_status'  => $model['entity_model_status'] ?? 'active',
      ];
      $result = \bX\CONN::nodml($query, $params);

      if (!$result['success']) {
        throw new \Exception("No se pudo insertar el modelo de entidad.");
      }
      // Retorna el nuevo ID (MySQL: lastInsertId)
      return $result['last_id'];
    }
  }

  /**
   * Lee un registro de la tabla `entity_model`
   * @param int $id
   * @return bool
   */
  public function read(int $id): bool
  {
    if (is_null($id)) return false;

    $query = "SELECT * FROM `entity_model` WHERE entity_model_id = :id";
    $this->data_raw = \bX\CONN::dml($query, [':id' => $id]);

    if (!empty($this->data_raw)) {
      $this->data = $this->formatData($this->data_raw[0]);
      return true;
    }
    return false;
  }

  /**
   * Obtiene todos los modelos asociados a una entidad específica
   * @param int $entityId
   * @return array
   */
  public function getByEntityId(int $entityId): array
  {
    $query = "SELECT * FROM `entity_model` WHERE entity_id = :entity_id";
    $results = \bX\CONN::dml($query, [':entity_id' => $entityId]);

    $models = [];
    foreach ($results as $row) {
      $models[] = $this->formatData($row);
    }
    return $models;
  }

  /**
   * Obtiene el modelo principal de una entidad (entity_model_prime = 1)
   * @param int $entityId
   * @return array|null
   */
  public function getPrimary(int $entityId): ?array
  {
    $groups = [];
    $query = "SELECT * FROM `entity_model` WHERE entity_id = :entity_id AND entity_model_prime = 1";

    \bX\CONN::dml($query, [':entity_id' => $entityId], function ($row) use (&$groups) {
      $type = $row['entity_model_type'];
      if (!isset($groups[$type])) {
        $groups[$type] = [];
      }
      $groups[$type][] = $this->formatData($row);
    });

    return !empty($groups) ? $groups : [];
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
   * Elimina un registro de la tabla `entity_model`
   * @param int $id
   * @return bool
   */
  public static function delete(int $id): bool {
    $query = "UPDATE `entity_model` SET entity_model_status = 'inactive' WHERE entity_model_id = :id";
    $result = \bX\CONN::nodml($query, [':id' => $id]);

    return $result['success'];
  }
}
