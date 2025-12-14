<?php
# bintelx/kernel/Tenant.php
namespace bX;

/**
 * Tenant - Multi-tenant helper
 *
 * Centralizes tenant logic for queries and security.
 * - Users with system.admin: access all tenants
 * - Users with tenant: access only their tenant
 * - Users without tenant: access only data without tenant (NULL)
 *
 * Usage:
 *   $scope = Tenant::resolve($options);
 *   $sql .= Tenant::whereClause('er.scope_entity_id', $options);
 *   $params = array_merge($params, Tenant::params($options));
 *
 * @package bX
 */
class Tenant
{
    # Field name constant - change here if schema changes
    public const FIELD = 'scope_entity_id';

    # Cache for admin check
    private static ?bool $isAdminCache = null;

    /**
     * Check if current user is system.admin (bypass tenant restrictions)
     */
    public static function isAdmin(): bool
    {
        if (self::$isAdminCache !== null) {
            return self::$isAdminCache;
        }

        # account_id 1 is always admin
        if (Profile::$account_id === 1) {
            self::$isAdminCache = true;
            return true;
        }

        # Check for system.admin role
        self::$isAdminCache = !empty(Profile::$roles['by_role']['system.admin'] ?? []);
        return self::$isAdminCache;
    }

    /**
     * Reset admin cache (call after login/scope change)
     */
    public static function resetCache(): void
    {
        self::$isAdminCache = null;
    }

    /**
     * Get current tenant scope
     *
     * Priority:
     * 1. $options['scope_entity_id'] if provided
     * 2. Profile::$scope_entity_id from session
     *
     * @param array $options
     * @return int|null NULL means user has no tenant (can only see NULL data)
     */
    public static function resolve(array $options = []): ?int
    {
        # Explicit override in options
        if (isset($options['scope_entity_id'])) {
            $val = (int)$options['scope_entity_id'];
            return $val > 0 ? $val : null;
        }

        # From session context
        $val = Profile::$scope_entity_id;
        return $val > 0 ? $val : null;
    }

    /**
     * Check if user can access a specific scope
     *
     * @param int|null $targetScope The scope to check access for
     * @param array $options Optional override
     * @return bool
     */
    public static function canAccess(?int $targetScope, array $options = []): bool
    {
        # Admin can access everything
        if (self::isAdmin()) {
            return true;
        }

        $userScope = self::resolve($options);

        # User without tenant can only access NULL data
        if ($userScope === null) {
            return $targetScope === null;
        }

        # User with tenant can access their tenant or NULL data
        return $targetScope === null || $targetScope === $userScope;
    }

    /**
     * Generate WHERE clause for tenant filtering
     *
     * @param string $column Column name (e.g., 'er.scope_entity_id')
     * @param array $options Options with optional scope_entity_id
     * @param string $paramName Parameter name (default: ':_tenant_scope')
     * @return string SQL fragment (includes leading AND)
     */
    public static function whereClause(
        string $column,
        array $options = [],
        string $paramName = ':_tenant_scope'
    ): string {
        # Admin bypass - no restriction
        if (self::isAdmin()) {
            return '';
        }

        $scope = self::resolve($options);

        # User without tenant: can only see NULL data
        if ($scope === null) {
            return " AND {$column} IS NULL";
        }

        # User with tenant: can see their tenant data
        return " AND {$column} = {$paramName}";
    }

    /**
     * Generate parameters for tenant filtering
     *
     * @param array $options Options with optional scope_entity_id
     * @param string $paramName Parameter name (default: ':_tenant_scope')
     * @return array Parameters to merge into query params
     */
    public static function params(
        array $options = [],
        string $paramName = ':_tenant_scope'
    ): array {
        # Admin bypass or NULL scope - no params needed
        if (self::isAdmin()) {
            return [];
        }

        $scope = self::resolve($options);

        if ($scope === null) {
            return [];
        }

        return [$paramName => $scope];
    }

    /**
     * Validate scope for write operations
     *
     * @param array $options
     * @return array ['valid' => bool, 'scope' => int|null, 'error' => string|null]
     */
    public static function validateForWrite(array $options = []): array
    {
        $scope = self::resolve($options);

        # Admin can write to any scope (but should specify one)
        if (self::isAdmin()) {
            return [
                'valid' => true,
                'scope' => $scope,
                'error' => null
            ];
        }

        # User without tenant cannot write scoped data
        if ($scope === null) {
            return [
                'valid' => false,
                'scope' => null,
                'error' => 'No tenant scope available'
            ];
        }

        return [
            'valid' => true,
            'scope' => $scope,
            'error' => null
        ];
    }

    /**
     * Get scope value for INSERT operations
     *
     * @param array $options
     * @return int|null
     */
    public static function forInsert(array $options = []): ?int
    {
        return self::resolve($options);
    }

    /**
     * Apply tenant filter to a base SQL query
     * Convenience method for simple cases
     *
     * @param string $sql Base SQL (should end before WHERE or have WHERE already)
     * @param string $column Tenant column
     * @param array $options
     * @param array &$params Reference to params array to merge into
     * @return string Modified SQL
     */
    public static function applySql(
        string $sql,
        string $column,
        array $options,
        array &$params
    ): string {
        $params = array_merge($params, self::params($options));
        return $sql . self::whereClause($column, $options);
    }

    /**
     * Build a complete scoped query helper
     * Returns both SQL fragment and params
     *
     * @param string $column
     * @param array $options
     * @return array ['sql' => string, 'params' => array]
     */
    public static function filter(string $column, array $options = []): array
    {
        return [
            'sql' => self::whereClause($column, $options),
            'params' => self::params($options)
        ];
    }
}
