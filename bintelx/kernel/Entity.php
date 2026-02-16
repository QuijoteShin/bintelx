<?php # bintelx/kernel/Entity.php
namespace bX;

class Entity {
    use CoroutineAware;

    # Per-request state (coroutine-isolated via ctx())
    public int $accountId = 0;
    public int $entityId = 0;
    public int $compId = 0;
    public int $compBranchId = 0;

    private array $data = [];
    private array $data_raw = [];

    public function __construct()
    {
    }

    /**
     * Normaliza un identificador nacional según el país
     * Elimina puntos, guiones, espacios y convierte a mayúsculas
     */
    public static function normalizeNationalId(string $nationalId, string $isocode = ''): string
    {
        $clean = preg_replace('/[^0-9A-Za-z]/', '', $nationalId);
        return strtoupper($clean);
    }

    /**
     * Calcula el identity_hash para matching de entities
     * NOTA: No incluye entity_type - una empresa es una sola entidad
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
     */
    public static function save(array $entity): int {
        $existingId = $entity['entity_id'] ?? 0;
        if ($existingId > 0) {
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
                ':comp_id'            => $entity['comp_id'] ?? self::ctx()->compId,
                ':comp_branch_id'     => $entity['comp_branch_id'] ?? self::ctx()->compBranchId,
                ':snapshot_id'        => $entity['snapshot_id'] ?? 0,
                ':entity_type'        => strtolower($entity['entity_type'] ?? ''),
                ':entity_name'        => $entity['entity_name'] ?? null,
                ':entity_idn'         => $entity['entity_idn'] ?? null,
                ':entity_idn_clear'   => $entity['entity_idn_clear'] ?? null,
                ':entity_country'     => $entity['entity_country'] ?? 'CL',
            ];
            CONN::nodml($query, $params);
            return $existingId;
        } else {
            $entityType = strtolower($entity['entity_type'] ?? 'general');
            $nationalId = $entity['entity_idn'] ?? $entity['national_id'] ?? null;
            $nationalIsocode = $entity['entity_country'] ?? $entity['national_isocode'] ?? null;

            $identityHash = null;
            if (!empty($nationalId) && !empty($nationalIsocode)) {
                $identityHash = self::calculateIdentityHash($nationalIsocode, $nationalId);
            }

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
            $result = CONN::nodml($query, $params);

            if (!$result['success']) {
                $errorMsg = $result['error'] ?? 'Unknown error';
                throw new \Exception("No se pudo insertar la entidad: $errorMsg");
            }
            return $result['last_id'];
        }
    }

    /**
     * Lee un registro de la tabla `entity` basado en entityId y opcionalmente comp_branch_id.
     */
    public function read(array $params): bool
    {
        if (empty($params['entity_id']) || empty($params['comp_id'])) {
            return false;
        }

        $query = "SELECT * FROM `entities`
                    WHERE entity_id = :entity_id
                    AND comp_id = :comp_id";

        if (isset($params['comp_branch_id'])) {
            $query .= " AND comp_branch_id = :comp_branch_id";
        }
        $this->data_raw[] = [];
        CONN::dml($query, $params, function($row) {
            $this->data_raw[] = $row;
        });

        if (!empty($this->data_raw)) {
            $this->data = $this->formatData($this->data_raw[0]);
            return $this->data;
        }
        return false;
    }

    /**
     * Obtiene todas las entidades, opcionalmente filtrando por comp_branch_id.
     */
    public function getAll(array $filters = []): array
    {
        $query = "SELECT * FROM `entities` WHERE comp_id = :comp_id";
        $params = [
            ':comp_id' => self::ctx()->compId
        ];

        if (isset($filters['comp_branch_id'])) {
            $query .= " AND comp_branch_id = :comp_branch_id";
            $params[':comp_branch_id'] = $filters['comp_branch_id'];
        }

        CONN::dml($query, $params, function($row) {
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
     */
    private function formatData(array $data): array {
        $formatted = DataUtils::formatData($data, $this->model());
        return $formatted;
    }

    /**
     * Elimina un registro de la tabla `entity`
     */
    public static function delete(int $entityId): bool {
        $query = "UPDATE `entity`
                SET entity_status = 'inactive'
                WHERE entity_id = :entity_id
                    AND comp_id = :comp_id
                    AND comp_branch_id = :comp_branch_id";
        $params = [
            ':entity_id' => $entityId,
            ':comp_id' => self::ctx()->compId,
            ':comp_branch_id' => self::ctx()->compBranchId
        ];
        $result = CONN::nodml($query, $params);

        return $result['success'];
    }

    # used on cli
    public function create(array $entityData): int
    {
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
        CONN::dml($query, $params);
        return CONN::getLastInsertId();
    }
}
