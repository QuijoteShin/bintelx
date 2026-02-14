<?php
# bintelx/kernel/Entity/Supplier.php
namespace bX\Entity;

use bX\Entity;
use bX\CONN;
use bX\Profile;
use bX\Tenant;

/**
 * Supplier - Gestión de proveedores
 *
 * Un proveedor es un Entity con relación 'supplier' hacia un scope.
 * Usa identity_hash para matching (sin entity_type en el hash).
 *
 * @package bX\Entity
 */
class Supplier
{
    public const RELATION_KIND = 'supplier';
    public const ENTITY_TYPE = 'organization'; # o 'person' si es persona natural

    /**
     * Crea un proveedor y su relación con el scope actual
     *
     * @param array $data [
     *   'primary_name' => string (required),
     *   'national_id' => string (required),
     *   'national_isocode' => string (default: 'CL'),
     *   'entity_type' => string (default: 'organization')
     * ]
     * @param array $options ['scope_entity_id' => int]
     * @param callable|null $callback
     * @return array ['success', 'entity_id', 'relationship_id', 'identity_hash', 'shadows_count']
     */
    public static function create(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        # Validar campos requeridos
        if (empty($data['primary_name'])) {
            return ['success' => false, 'message' => 'primary_name is required'];
        }
        if (empty($data['national_id'])) {
            return ['success' => false, 'message' => 'national_id is required'];
        }

        # Validar tenant
        $tenant = Tenant::validateForWrite($options);
        if (!$tenant['valid']) {
            return ['success' => false, 'message' => $tenant['error']];
        }

        $nationalIsocode = $data['national_isocode'] ?? 'CL';
        $entityType = $data['entity_type'] ?? self::ENTITY_TYPE;

        # Calcular identity_hash para buscar duplicados
        $identityHash = Entity::calculateIdentityHash($nationalIsocode, $data['national_id']);

        # Buscar si ya existe un entity con este hash en este scope
        $existing = self::findByIdentityHash($identityHash, $options);
        if ($existing) {
            return [
                'success' => false,
                'message' => 'Supplier already exists in this scope',
                'entity_id' => $existing['entity_id'],
                'existing' => true
            ];
        }

        try {
            CONN::begin();

            # Crear entity
            $entityId = Entity::save([
                'entity_type' => $entityType,
                'primary_name' => $data['primary_name'],
                'national_id' => $data['national_id'],
                'national_isocode' => $nationalIsocode,
                'created_by_profile_id' => Profile::$profile_id
            ]);

            # Crear relationship
            $relResult = Graph::create([
                'profile_id' => Profile::$profile_id,
                'entity_id' => $entityId,
                'relation_kind' => self::RELATION_KIND,
                'relationship_label' => $data['primary_name']
            ], $options);

            if (!$relResult['success']) {
                throw new \Exception($relResult['message']);
            }

            CONN::commit();

            # Buscar sombras (otros scopes con mismo proveedor)
            $shadows = Entity::findShadows($identityHash, $entityId);

            return [
                'success' => true,
                'entity_id' => $entityId,
                'relationship_id' => $relResult['relationship_id'],
                'identity_hash' => $identityHash,
                'shadows_count' => count($shadows),
                'message' => 'Supplier created'
            ];

        } catch (\Exception $e) {
            if (CONN::isInTransaction()) {
                CONN::rollback();
            }
            \bX\Log::logError("Supplier::create Exception: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to create supplier. Check logs for details.'];
        }
    }

    /**
     * Busca proveedor por identity_hash en el scope actual
     *
     * @param string $identityHash
     * @param array $options ['scope_entity_id' => int]
     * @return array|null
     */
    public static function findByIdentityHash(string $identityHash, array $options = []): ?array
    {
        $sql = "SELECT e.*, er.relationship_id, er.relation_kind
                FROM entities e
                JOIN entity_relationships er ON e.entity_id = er.entity_id
                WHERE e.identity_hash = :hash
                  AND er.relation_kind = :kind
                  AND er.status = 'active'";
        $params = [
            ':hash' => $identityHash,
            ':kind' => self::RELATION_KIND
        ];

        $sql .= Tenant::whereClause('er.scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        $sql .= " LIMIT 1";

        $result = null;
        CONN::dml($sql, $params, function($row) use (&$result) {
            $result = $row;
            return false;
        });

        return $result;
    }

    /**
     * Busca proveedor por national_id
     *
     * @param array $data ['national_id' => string, 'national_isocode' => string]
     * @param array $options ['scope_entity_id' => int]
     * @return array|null
     */
    public static function findByNationalId(array $data, array $options = []): ?array
    {
        $nationalId = $data['national_id'] ?? '';
        $isocode = $data['national_isocode'] ?? 'CL';

        if (empty($nationalId)) {
            return null;
        }

        $hash = Entity::calculateIdentityHash($isocode, $nationalId);
        return self::findByIdentityHash($hash, $options);
    }

    /**
     * Lista proveedores del scope actual
     *
     * @param array $data ['search' => string, 'limit' => int, 'offset' => int]
     * @param array $options ['scope_entity_id' => int]
     * @param callable|null $callback
     * @return array
     */
    public static function list(
        array $data = [],
        array $options = [],
        ?callable $callback = null
    ): array {
        $search = $data['search'] ?? null;
        $limit = (int)($data['limit'] ?? 50);
        $offset = (int)($data['offset'] ?? 0);

        $sql = "SELECT e.entity_id, e.primary_name, e.national_id, e.national_isocode,
                       e.identity_hash, e.canonical_entity_id, e.status,
                       er.relationship_id, er.relationship_label
                FROM entities e
                JOIN entity_relationships er ON e.entity_id = er.entity_id
                WHERE er.relation_kind = :kind
                  AND er.status = 'active'";
        $params = [':kind' => self::RELATION_KIND];

        $sql .= Tenant::whereClause('er.scope_entity_id', $options);
        $params = array_merge($params, Tenant::params($options));

        if ($search) {
            $sql .= " AND (e.primary_name LIKE :search OR e.national_id LIKE :search2)";
            $params[':search'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
        }

        $sql .= " ORDER BY e.primary_name ASC LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        if ($callback) {
            CONN::dml($sql, $params, $callback);
            return [];
        }

        return CONN::dml($sql, $params) ?? [];
    }
}
