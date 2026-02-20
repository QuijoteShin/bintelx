<?php # package/_internal/rbac-init.endpoint.php
namespace _internal;

use bX\Router;
use bX\Response;
use bX\ACL;
use bX\CONN;
use bX\Log;
use bX\Args;
use bX\Entity\Graph;

# Inicializa un perfil como owner de una entidad
# Crea/reactiva relación owner + aplica packages via ACL
# POST /api/_internal/rbac-init { profile_id, entity_id }
Router::register(['POST'], 'rbac-init', function() {
    $profileId = (int)(Args::ctx()->opt['profile_id'] ?? 0);
    $entityId = (int)(Args::ctx()->opt['entity_id'] ?? 0);

    if ($profileId <= 0 || $entityId <= 0) {
        return Response::json(['error' => 'profile_id and entity_id required'], 400);
    }

    # Verificar que el perfil y la entidad existen
    $profile = CONN::dml(
        "SELECT profile_id, profile_name FROM profiles WHERE profile_id = :pid LIMIT 1",
        [':pid' => $profileId]
    );
    if (empty($profile)) {
        return Response::json(['error' => "Profile {$profileId} not found"], 404);
    }

    $entity = CONN::dml(
        "SELECT entity_id, primary_name FROM entities WHERE entity_id = :eid LIMIT 1",
        [':eid' => $entityId]
    );
    if (empty($entity)) {
        return Response::json(['error' => "Entity {$entityId} not found"], 404);
    }

    # Desactivar relaciones existentes sin scope (legacy bug)
    CONN::nodml(
        "UPDATE entity_relationships
         SET status = 'inactive'
         WHERE profile_id = :pid AND entity_id = :eid AND scope_entity_id IS NULL",
        [':pid' => $profileId, ':eid' => $entityId]
    );

    # Crear relación owner via Graph (dispara ACL::applyTemplates en transacción)
    $result = Graph::createIfNotExists(
        [
            'profile_id' => $profileId,
            'entity_id' => $entityId,
            'relation_kind' => 'owner'
        ],
        ['scope_entity_id' => $entityId]
    );

    Log::logInfo("RBAC init: owner relationship for profile {$profileId} → entity {$entityId}", $result);

    # Verificar roles resultantes
    $roles = [];
    CONN::dml(
        "SELECT role_code, scope_entity_id, source_package_code
         FROM profile_roles
         WHERE profile_id = :pid AND scope_entity_id = :scope AND status = 'active'",
        [':pid' => $profileId, ':scope' => $entityId],
        function ($row) use (&$roles) {
            $roles[] = $row;
        }
    );

    return Response::json([
        'success' => true,
        'profile' => $profile[0]['profile_name'],
        'entity' => $entity[0]['primary_name'],
        'relationship' => $result,
        'roles_in_scope' => $roles
    ]);
}, ROUTER_SCOPE_SYSTEM);
