<?php # bintelx/kernel/Entity.php
namespace bX;

class Entity {
  public static int $account_id = 0;
  public static int $entity_id = 0;
  public static int $comp_id = 0;
  public static int $comp_branch_id = 0;

  private array $data = [];      # Datos procesados
  private array $data_raw = [];  # Datos sin procesar de la DB

  public function __construct()
  {
  }

  /**
   * Normaliza un identificador nacional según el país
   * Elimina puntos, guiones, espacios y convierte a mayúsculas
   *
   * @param string $nationalId Ej: "76.543.210-K", "76543210-k"
   * @param string $isocode Ej: "CL", "MX", "AR"
   * @return string Normalizado: "765432100K"
   */
  public static function normalizeNationalId(string $nationalId, string $isocode = ''): string
  {
      # Quitar todo excepto alfanuméricos
      $clean = preg_replace('/[^0-9A-Za-z]/', '', $nationalId);
      # Uppercase para dígitos verificadores (K en Chile, etc.)
      return strtoupper($clean);
  }

  /**
   * Calcula el identity_hash para matching de entities
   * Permite encontrar sombras del mismo entity real
   *
   * IMPORTANTE: NO incluye entity_type en el hash.
   * Una empresa es UNA sola entidad aunque sea cliente Y proveedor.
   * Las relaciones se manejan en entity_relationships con relation_kind.
   *
   * @param string $nationalIsocode Ej: "CL", "MX"
   * @param string $nationalId Ej: "76.543.210-K" (se normaliza internamente)
   * @return string SHA256 hash de 64 caracteres
   */
  public static function calculateIdentityHash(
      string $nationalIsocode,
      string $nationalId
  ): string {
      $normalized = self::normalizeNationalId($nationalId, $nationalIsocode);
      $payload = strtoupper($nationalIsocode) . '|' . $normalized;
      return hash('sha256', $payload);
  }

  /**
   * Busca entities "sombra" con el mismo identity_hash
   * Útil para discovery de claims
   *
   * @param string $identityHash Hash a buscar
   * @param int|null $excludeEntityId Entity a excluir (el propio)
   * @return array Lista de sombras encontradas
   */
  public static function findShadows(string $identityHash, ?int $excludeEntityId = null): array
  {
      $sql = "SELECT entity_id, primary_name, entity_type, national_id, created_at
              FROM entities
              WHERE identity_hash = :hash
                AND canonical_entity_id IS NULL";
      $params = [':hash' => $identityHash];

      if ($excludeEntityId) {
          $sql .= " AND entity_id != :exclude";
          $params[':exclude'] = $excludeEntityId;
      }

      return CONN::dml($sql, $params) ?? [];
  }

  /**
   * Modelo
   */
  public function model(): array
  {
    return [
      'entity_id'          => ['type' => 'int',     'auto_increment' => true, 'not null' => true],
      'comp_id'            => ['type' => 'int',     'default' => 0],
      'comp_branch_id'     => ['type' => 'int',     'default' => 0],
      'snapshot_id'        => ['type' => 'bigint',  'default' => 0],
      'entity_type'        => ['type' => 'string',  'default' => null],
      'entity_name'        => ['type' => 'string',  'default' => null],
      'entity_idn'         => ['type' => 'string',  'default' => null],
      'entity_idn_clear'   => ['type' => 'string',  'default' => null],
      'entity_country'     => ['type' => 'string',  'default' => 'CL'],
    ];
  }

  /**
   * Crea o actualiza un registro en la base de datos.
   * @param array $entity ['entity_id'=>'...', 'entity_name'=>'...', ...]
   * @return int
   * @throws \Exception
   */
  public static function save(array $entity): int {
    $existingId = $entity['entity_id'] ?? 0;
    if ($existingId > 0) {
      // Actualizar registro existente
      $query = "UPDATE `entities`
                      SET
                          comp_id = :comp_id,
                          comp_branch_id = :comp_branch_id,
                          snapshot_id = :snapshot_id,
                          entity_type = :entity_type,
                          entity_name = :entity_name,
                          entity_idn = :entity_idn,
                          entity_idn_clear = :entity_idn_clear,
                          entity_country = :entity_country
                      WHERE entity_id = :entity_id";
      $params = [
        ':entity_id'          => $existingId,
        ':comp_id'            => $entity['comp_id'] ?? self::$comp_id,
        ':comp_branch_id'     => $entity['comp_branch_id'] ?? self::$comp_branch_id,
        ':snapshot_id'        => $entity['snapshot_id'] ?? 0,
        ':entity_type'        => strtolower($entity['entity_type'] ?? ''),
        ':entity_name'        => $entity['entity_name'] ?? null,
        ':entity_idn'         => $entity['entity_idn'] ?? null,
        ':entity_idn_clear'   => $entity['entity_idn_clear'] ?? null,
        ':entity_country'     => $entity['entity_country'] ?? 'CL',
      ];
      \bX\CONN::nodml($query, $params);
      return $existingId;
    } else {
      # Preparar valores
      $entityType = strtolower($entity['entity_type'] ?? 'general');
      $nationalId = $entity['entity_idn'] ?? $entity['national_id'] ?? null;
      $nationalIsocode = $entity['entity_country'] ?? $entity['national_isocode'] ?? null;

      # Calcular identity_hash si hay national_id
      # NOTA: No incluye entity_type - una empresa es una sola entidad
      $identityHash = null;
      if (!empty($nationalId) && !empty($nationalIsocode)) {
          $identityHash = self::calculateIdentityHash($nationalIsocode, $nationalId);
      }

      # Insertar nuevo registro (TARGET SCHEMA)
      $query = "INSERT INTO `entities`
                      (entity_type, primary_name, national_id, national_isocode,
                       identity_hash, status, created_by_profile_id, updated_by_profile_id)
                      VALUES
                      (:entity_type, :primary_name, :national_id, :national_isocode,
                       :identity_hash, :status, :created_by, :updated_by)";

      $params = [
        ':entity_type'        => $entityType,
        ':primary_name'       => $entity['entity_name'] ?? $entity['primary_name'] ?? null,
        ':national_id'        => $nationalId,
        ':national_isocode'   => $nationalIsocode,
        ':identity_hash'      => $identityHash,
        ':status'             => $entity['status'] ?? 'active',
        ':created_by'         => $entity['created_by_profile_id'] ?? null,
        ':updated_by'         => $entity['updated_by_profile_id'] ?? null,
      ];
      $result = \bX\CONN::nodml($query, $params);

      if (!$result['success']) {
        $errorMsg = $result['error'] ?? 'Unknown error';
        throw new \Exception("No se pudo insertar la entidad: $errorMsg");
      }
      // Retorna el nuevo ID (MySQL: lastInsertId)
      return $result['last_id'];
    }
  }

  /**
   * Lee un registro de la tabla `entity` basado en entityId y opcionalmente comp_branch_id.
   * @param array $params ['entity_id' => int, 'comp_id' => int, 'comp_branch_id' => int (opcional)]
   * @return bool
   */
  public function read(array $params): bool
  {
    if (empty($params['entity_id']) || empty($params['comp_id'])) {
      return false;
    }

    $query = "SELECT * FROM `entities` 
                  WHERE entity_id = :entity_id 
                  AND comp_id = :comp_id";

    // Incluir comp_branch_id si está definido
    if (isset($params['comp_branch_id'])) {
      $query .= " AND comp_branch_id = :comp_branch_id";
    }
    $this->data_raw[] = [];
    \bX\CONN::dml($query, $params, function($row) {
      $this->data_raw[] = $row;
    });

    if (!empty($this->data_raw)) {
      $this->data = $this->formatData($this->data_raw[0]);
      # self::$entity_id = $this->data['entity_id'];
      # self::$comp_id = $this->data['comp_id'];
      # self::$comp_branch_id = $this->data['comp_branch_id'];
      return $this->data;
    }
    return false;
  }

  /**
   * Obtiene todas las entidades, opcionalmente filtrando por comp_branch_id.
   * @param array $filters ['comp_id' => int, 'comp_branch_id' => int (opcional)]
   * @return array
   */
  public function getAll(array $filters = []): array
  {
    $query = "SELECT * FROM `entities` WHERE comp_id = :comp_id";
    $params = [
      ':comp_id' => self::$comp_id
    ];

    if (isset($filters['comp_branch_id'])) {
      $query .= " AND comp_branch_id = :comp_branch_id";
      $params[':comp_branch_id'] = $filters['comp_branch_id'];
    }

    \bX\CONN::dml($query, $params, function($row) {
      $this->data_raw[] = $row;
    });

    $entities = [];
    foreach ($this->data_raw as $row) {
      $entities[] = $this->formatData($row);
    }
    return $entities;
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
   * Elimina un registro de la tabla `entity`
   * @param int $id
   * @return bool
   */
  public static function delete(int  $entityId): bool {
    $query = "UPDATE `entity`
              SET entity_status = 'inactive' 
              WHERE entity_id = :entity_id 
                  AND comp_id = :comp_id 
                  AND comp_branch_id = :comp_branch_id";
    $params = [
      ':entity_id' => $entityId,
      ':comp_id' => self::$comp_id,
      ':comp_branch_id' => self::$comp_branch_id
    ];
    $result = \bX\CONN::nodml($query, $params);

    return $result['success'];
  }

  # used on cli
  public function create(array $entityData): int
  {
    # used on cli
    $query = "INSERT INTO entity (comp_id, comp_branch_id, entity_type, entity_name, entity_idn, entity_country) 
                  VALUES (:comp_id, :comp_branch_id, :entity_type, :entity_name, :entity_idn, :entity_country)";
    $params = [
      ':comp_id'       => $entityData['comp_id'],
      ':comp_branch_id'=> $entityData['comp_branch_id'],
      ':entity_type'   => $entityData['entity_type'],
      ':entity_name'   => $entityData['entity_name'],
      ':entity_idn'    => $entityData['entity_idn'],
      ':entity_country'=> $entityData['entity_country']
    ];
    \bX\CONN::dml($query, $params);
    return \bX\CONN::getLastInsertId();
  }
}
