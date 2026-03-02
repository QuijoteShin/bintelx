<?php
# bintelx/kernel/RoleTemplateService.php
namespace bX;

use bX\Entity\Graph;

/**
 * RoleTemplateService - Auto-asignación de roles por tipo de relación
 *
 * Define qué roles se asignan automáticamente cuando se crea una relación
 * de cierto tipo (owner, collaborator, customer, member, supplier)
 *
 * Prioridad de templates:
 *   1. scope_entity_id específico (template de la empresa)
 *   2. scope_entity_id = GLOBAL_TENANT_ID (template global del sistema)
 *
 * Uso:
 *   # Al crear una relación owner, auto-asignar roles configurados
 *   RoleTemplateService::applyTemplates($profileId, $entityId, 'owner', $scopeId);
 *
 * @package bX
 */
class RoleTemplateService
{
    /**
     * Get role templates for a relation_kind
     * Returns roles from scope-specific templates first, then global templates
     *
     * @param string $relationKind The relation type (owner, member, technician...)
     * @param int|null $scopeEntityId Optional scope to check for overrides
     * @return array Array of role_code strings
     */
    public static function getTemplateRoles(string $relationKind, ?int $scopeEntityId = null): array
    {
        $roles = [];
        $params = [':kind' => $relationKind];

        $sql = "SELECT role_code, package_code, scope_entity_id
                FROM role_templates
                WHERE relation_kind = :kind
                  AND is_active = 1";

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $sql = Tenant::applySql($sql, 'scope_entity_id', $opts, $params);
        $sql .= " ORDER BY " . Tenant::priorityOrderBy('scope_entity_id', $opts) . ", priority DESC";

        $rows = CONN::dml($sql, $params);

        if (empty($rows)) {
            return [];
        }

        # If scope-specific templates exist, use only those (skip global)
        $globalIds = Tenant::globalIds();
        $hasSpecificScope = false;
        foreach ($rows as $row) {
            if (!in_array((int)$row['scope_entity_id'], $globalIds, true)) {
                $hasSpecificScope = true;
                break;
            }
        }

        foreach ($rows as $row) {
            if ($hasSpecificScope && in_array((int)$row['scope_entity_id'], $globalIds, true)) {
                continue;
            }

            # Si tiene package_code → expandir a roles individuales
            if (!empty($row['package_code'])) {
                $expanded = RolePackageService::expand($row['package_code'], $scopeEntityId);
                foreach ($expanded['roles'] as $expandedRole) {
                    $roles[] = $expandedRole;
                }
            }

            # Si tiene role_code directo → agregar
            if (!empty($row['role_code'])) {
                $roles[] = $row['role_code'];
            }
        }

        return array_unique($roles);
    }

    /**
     * Apply role templates to a profile for a scope
     * Creates profile_roles entries for each role in the template
     *
     * @param int $profileId The profile receiving the roles
     * @param int $entityId The entity the roles apply to
     * @param string $relationKind The relation type triggering auto-assignment
     * @param int|null $scopeEntityId Tenant scope
     * @param int|null $actorProfileId Who is creating these (for audit)
     * @return array ['applied' => [...], 'skipped' => [...]]
     */
    public static function applyTemplates(
        int $profileId,
        int $entityId,
        string $relationKind,
        ?int $scopeEntityId = null,
        ?int $actorProfileId = null
    ): array {
        $roles = self::getTemplateRoles($relationKind, $scopeEntityId);

        if (empty($roles)) {
            return ['applied' => [], 'skipped' => []];
        }

        $applied = [];
        $skipped = [];
        $actor = $actorProfileId ?? Profile::ctx()->profileId;

        foreach ($roles as $roleCode) {
            # Check if role already assigned
            $exists = CONN::dml(
                "SELECT 1 FROM profile_roles
                 WHERE profile_id = :pid
                   AND role_code = :role
                   AND scope_entity_id = :scope
                   AND status = 'active'
                 LIMIT 1",
                [':pid' => $profileId, ':role' => $roleCode, ':scope' => $scopeEntityId]
            );

            if (!empty($exists)) {
                $skipped[] = $roleCode;
                continue;
            }

            # Create the role assignment in profile_roles
            $result = CONN::nodml(
                "INSERT INTO profile_roles
                 (profile_id, role_code, scope_entity_id, source_package_code, status, created_by_profile_id, updated_by_profile_id)
                 VALUES
                 (:pid, :role, :scope, :pkg, 'active', :actor, :actor)",
                [
                    ':pid' => $profileId,
                    ':role' => $roleCode,
                    ':scope' => $scopeEntityId,
                    ':pkg' => null,
                    ':actor' => $actor
                ]
            );

            if ($result['success']) {
                $applied[] = $roleCode;
            } else {
                Log::logWarning("RoleTemplate: Failed to apply role {$roleCode} to profile {$profileId}", [
                    'error' => $result['error'] ?? 'Unknown'
                ]);
            }
        }

        if (!empty($applied)) {
            # Invalidar cache de roles del perfil afectado
            Cache::delete('global:profile:roles', (string)$profileId);
            Cache::notifyChannel('global:profile:roles', (string)$profileId);

            Log::logInfo("RoleTemplate: Applied roles to profile {$profileId} for scope {$scopeEntityId}", [
                'trigger_relation' => $relationKind,
                'roles' => $applied
            ]);
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Create a new role template
     *
     * @param string $relationKind
     * @param string $roleCode
     * @param int|null $scopeEntityId NULL for global template
     * @param int $priority Higher = applied first
     * @return array ['success' => bool, 'template_id' => int|null, 'error' => string|null]
     */
    public static function createTemplate(
        string $relationKind,
        string $roleCode,
        ?int $scopeEntityId = null,
        int $priority = 0
    ): array {
        # Guard: synthetic roles no se pueden usar en templates
        if (str_starts_with($roleCode, 'sys.pkg.')) {
            return ['success' => false, 'template_id' => null, 'error' => "Synthetic roles (sys.pkg.*) cannot be used in templates"];
        }

        # Validate role exists
        $roleExists = CONN::dml(
            "SELECT 1 FROM roles WHERE role_code = :code AND status = 'active' LIMIT 1",
            [':code' => $roleCode]
        );

        if (empty($roleExists)) {
            return ['success' => false, 'template_id' => null, 'error' => "Role '{$roleCode}' not found or inactive"];
        }

        $result = CONN::nodml(
            "INSERT INTO role_templates
             (relation_kind, role_code, scope_entity_id, priority, is_active, created_by_profile_id)
             VALUES (:kind, :role, :scope, :priority, 1, :actor)
             ON DUPLICATE KEY UPDATE
                is_active = 1,
                priority = VALUES(priority),
                updated_by_profile_id = VALUES(created_by_profile_id)",
            [
                ':kind' => $relationKind,
                ':role' => $roleCode,
                ':scope' => $scopeEntityId,
                ':priority' => $priority,
                ':actor' => Profile::ctx()->profileId
            ]
        );

        if (!$result['success']) {
            return ['success' => false, 'template_id' => null, 'error' => $result['error'] ?? 'Database error'];
        }

        return ['success' => true, 'template_id' => $result['last_id'] ?: null, 'error' => null];
    }

    /**
     * Delete (deactivate) a role template
     *
     * @param string $relationKind
     * @param string $roleCode
     * @param int|null $scopeEntityId
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function deleteTemplate(
        string $relationKind,
        string $roleCode,
        ?int $scopeEntityId = null
    ): array {
        $sql = "UPDATE role_templates
                SET is_active = 0, updated_by_profile_id = :actor
                WHERE relation_kind = :kind
                  AND role_code = :role
                  AND scope_entity_id = :scope";
        $params = [
            ':kind' => $relationKind,
            ':role' => $roleCode,
            ':scope' => $scopeEntityId,
            ':actor' => Profile::ctx()->profileId
        ];

        $result = CONN::nodml($sql, $params);

        return [
            'success' => $result['success'],
            'error' => $result['error'] ?? null
        ];
    }

    /**
     * List all templates for a scope (or global)
     *
     * @param int|null $scopeEntityId NULL = global only, value = scope + global
     * @return array
     */
    public static function listTemplates(?int $scopeEntityId = null): array
    {
        $params = [];

        $sql = "SELECT rt.*, r.role_label
                FROM role_templates rt
                LEFT JOIN roles r ON r.role_code = rt.role_code
                WHERE rt.is_active = 1";

        $opts = !empty(Tenant::globalIds()) ? ['force_scope' => true] : [];
        if ($scopeEntityId > 0) $opts['scope_entity_id'] = $scopeEntityId;

        $sql = Tenant::applySql($sql, 'rt.scope_entity_id', $opts, $params);
        $sql .= " ORDER BY rt.relation_kind, " . Tenant::priorityOrderBy('rt.scope_entity_id', $opts) . ", rt.priority DESC";

        return CONN::dml($sql, $params) ?? [];
    }

    /**
     * Get available relation kinds (for UI dropdowns)
     *
     * @return array
     */
    public static function getRelationKinds(): array
    {
        # Los 5 relation_kinds válidos — alineados con Graph::VALID_KINDS
        return [
            Graph::KIND_OWNER => 'Propietario',
            Graph::KIND_COLLABORATOR => 'Colaborador',
            Graph::KIND_CUSTOMER => 'Cliente',
            Graph::KIND_MEMBER => 'Miembro',
            Graph::KIND_SUPPLIER => 'Proveedor',
        ];
    }
}
