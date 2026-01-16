<?php
# bintelx/kernel/RoleTemplateService.php
namespace bX;

/**
 * RoleTemplateService - Auto-asignación de roles por tipo de relación
 *
 * Define qué roles se asignan automáticamente cuando se crea una relación
 * de cierto tipo (owner, technician, doctor, supplier, etc.)
 *
 * Prioridad de templates:
 *   1. scope_entity_id específico (template de la empresa)
 *   2. scope_entity_id = NULL (template global del sistema)
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

        # Build query based on scope
        if ($scopeEntityId) {
            # Get scope-specific templates first, then global as fallback
            $sql = "SELECT role_code, scope_entity_id
                    FROM role_templates
                    WHERE relation_kind = :kind
                      AND is_active = 1
                      AND (scope_entity_id = :scope OR scope_entity_id IS NULL)
                    ORDER BY scope_entity_id IS NULL ASC, priority DESC";
            $params[':scope'] = $scopeEntityId;
        } else {
            # Only global templates
            $sql = "SELECT role_code, scope_entity_id
                    FROM role_templates
                    WHERE relation_kind = :kind
                      AND is_active = 1
                      AND scope_entity_id IS NULL
                    ORDER BY priority DESC";
        }

        $rows = CONN::dml($sql, $params);

        if (empty($rows)) {
            return [];
        }

        # If we have scope-specific templates, use only those
        # Otherwise fall back to global templates
        $hasSpecificScope = false;
        foreach ($rows as $row) {
            if ($row['scope_entity_id'] !== null) {
                $hasSpecificScope = true;
                break;
            }
        }

        foreach ($rows as $row) {
            # If scope-specific exists, skip global templates
            if ($hasSpecificScope && $row['scope_entity_id'] === null) {
                continue;
            }
            $roles[] = $row['role_code'];
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
        $actor = $actorProfileId ?? Profile::$profile_id;

        foreach ($roles as $roleCode) {
            # Check if role already assigned
            $exists = CONN::dml(
                "SELECT 1 FROM profile_roles
                 WHERE profile_id = :pid
                   AND role_code = :role
                   AND (scope_entity_id = :scope OR (scope_entity_id IS NULL AND :scope2 IS NULL))
                   AND status = 'active'
                 LIMIT 1",
                [':pid' => $profileId, ':role' => $roleCode, ':scope' => $scopeEntityId, ':scope2' => $scopeEntityId]
            );

            if (!empty($exists)) {
                $skipped[] = $roleCode;
                continue;
            }

            # Create the role assignment in profile_roles
            $result = CONN::nodml(
                "INSERT INTO profile_roles
                 (profile_id, role_code, scope_entity_id, status, created_by_profile_id, updated_by_profile_id)
                 VALUES
                 (:pid, :role, :scope, 'active', :actor, :actor)",
                [
                    ':pid' => $profileId,
                    ':role' => $roleCode,
                    ':scope' => $scopeEntityId,
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
                ':actor' => Profile::$profile_id
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
                  AND role_code = :role";
        $params = [
            ':kind' => $relationKind,
            ':role' => $roleCode,
            ':actor' => Profile::$profile_id
        ];

        if ($scopeEntityId === null) {
            $sql .= " AND scope_entity_id IS NULL";
        } else {
            $sql .= " AND scope_entity_id = :scope";
            $params[':scope'] = $scopeEntityId;
        }

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

        if ($scopeEntityId) {
            $sql = "SELECT rt.*, r.role_label
                    FROM role_templates rt
                    LEFT JOIN roles r ON r.role_code = rt.role_code
                    WHERE rt.is_active = 1
                      AND (rt.scope_entity_id = :scope OR rt.scope_entity_id IS NULL)
                    ORDER BY rt.relation_kind, rt.scope_entity_id IS NULL, rt.priority DESC";
            $params[':scope'] = $scopeEntityId;
        } else {
            $sql = "SELECT rt.*, r.role_label
                    FROM role_templates rt
                    LEFT JOIN roles r ON r.role_code = rt.role_code
                    WHERE rt.is_active = 1
                      AND rt.scope_entity_id IS NULL
                    ORDER BY rt.relation_kind, rt.priority DESC";
        }

        return CONN::dml($sql, $params) ?? [];
    }

    /**
     * Get available relation kinds (for UI dropdowns)
     *
     * @return array
     */
    public static function getRelationKinds(): array
    {
        # Core relation kinds from Graph.php + common business types
        return [
            'owner' => 'Dueño/Propietario',
            'member' => 'Miembro',
            'technician' => 'Técnico',
            'manager' => 'Gerente/Manager',
            'viewer' => 'Visualizador',
            'doctor' => 'Médico',
            'nurse' => 'Enfermero/a',
            'supplier' => 'Proveedor',
            'client' => 'Cliente',
            'auditor' => 'Auditor',
            'consultant' => 'Consultor'
        ];
    }
}
