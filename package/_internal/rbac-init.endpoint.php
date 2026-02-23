<?php # package/_internal/rbac-init.endpoint.php
namespace _internal;

use bX\Router;
use bX\Response;
use bX\ACL;
use bX\CONN;
use bX\Log;
use bX\Args;
use bX\Entity\Graph;

# ──────────────────────────────────────────────
# POST /api/_internal/rbac-init
# Inicializa un perfil como owner de una entidad.
# Crea/reactiva relación owner + aplica packages via ACL (role_templates).
#
# Body: { "profile_id": int, "entity_id": int }
#
# Scope: ROUTER_SCOPE_SYSTEM — solo Channel Server, nunca FPM.
#
# Auth (2 capas independientes):
#
#   Capa 1 — Acceso al endpoint (Router):
#     El Router permite SCOPE_SYSTEM si cumple UNA de:
#     - X-System-Key header (hash_equals vs secrets/system_secret.secret)
#     - Request desde localhost (127.0.0.1 / ::1)
#     Desde localhost no necesitas X-System-Key. Desde red externa sí.
#
#   Capa 2 — Profile::ctx() para operaciones internas:
#     Graph::createIfNotExists() llama a Tenant::validateForWrite() que
#     requiere isAdmin() = true. isAdmin() checa:
#       - Profile::ctx()->accountId === 1 (system account)
#       - Profile::ctx()->roles['by_role']['system.admin'] no vacío
#     SCOPE_SYSTEM NO carga Profile::ctx() automáticamente.
#     Para que isAdmin() pase, DEBES enviar JWT (Authorization: Bearer <token>)
#     de un usuario con system.admin en el scope del entity_id destino.
#
# Ejemplo:
#   curl -X POST http://localhost:8000/api/_internal/rbac-init \
#     -H 'Content-Type: application/json' \
#     -H 'Authorization: Bearer <JWT_CON_SYSTEM_ADMIN>' \
#     -d '{"profile_id": 107, "entity_id": 2000000000}'
#
# Sin JWT o sin system.admin en scope:
#   → relationship.success = false, "Global tenant scope requires admin"
#
# Flujo interno:
#   1. Valida profile y entity existen
#   2. Desactiva relaciones legacy sin scope_entity_id (NULL)
#   3. Graph::createIfNotExists() en transacción:
#      - INSERT entity_relationships (owner)
#      - ACL::applyTemplates() busca role_templates para 'owner'
#      - Expande packages → roles individuales (con source_package_code)
#      - Crea synthetic role sys.pkg.{code} si el package tiene route_permissions
#   4. Retorna relación + roles resultantes en scope
# ──────────────────────────────────────────────
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
