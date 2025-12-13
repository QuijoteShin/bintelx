<?php
# package/navigation/navigation.endpoint.php
# Navigation endpoint con lógica de coexistencia routes.json + DB
#
# MULTI-TENANT:
#   - scope_entity_id = NULL → ruta global (kernel)
#   - scope_entity_id = X → ruta específica del tenant X
#   - Prioridad: rutas del scope > rutas globales (mismo path)
#
# FUENTES:
#   - routes.json (módulos): Define rutas técnicas (path, app, moduleName)
#   - navigation_routes (DB): Configura ACL, labels, visibilidad - PRIORIDAD
#   - Herencia ACL: ruta padre configura → hijos heredan salvo config explícita
#
# SAVE OPTIONS:
#   - global: true → guarda como ruta global (scope_entity_id = NULL)
#   - scope_entity_id: X → guarda para scope específico
#   - (default) → usa Profile::$scope_entity_id

use bX\Router;
use bX\Response;
use bX\Profile;
use bX\Log;
use bX\CONN;

Router::register(['GET','POST'], '', function() {
    if (!Profile::isLoggedIn()) {
        return Response::json(['success' => false, 'message' => 'Authentication required'], 401);
    }

    ensureNavigationTable();

    $action = \bX\Args::$OPT['action'] ?? 'fetch';
    $localRoutes = \bX\Args::$OPT['local_routes'] ?? [];

    if ($action === 'save') {
        return handleSaveAction();
    }

    # 1. Base: rutas del frontend (routes.json de cada módulo)
    $routesByPath = [];
    if (is_array($localRoutes)) {
        foreach ($localRoutes as $lr) {
            if (!empty($lr['path'])) {
                $routesByPath[$lr['path']] = [
                    'path' => $lr['path'],
                    'app' => $lr['app'] ?? null,
                    'moduleName' => $lr['moduleName'] ?? null,
                    'prefix' => $lr['prefix'] ?? null,
                    'label' => $lr['label'] ?? null,
                    'hidden' => $lr['hidden'] ?? false,
                    'group' => $lr['group'] ?? null,
                    'required_roles' => [],
                    'order_index' => 0,
                    'source' => 'local'
                ];
            }
        }
    }

    # 2. Cargar configuración DB (tiene prioridad)
    $dbConfig = loadDbNavigationConfig();

    # 3. Aplicar config DB sobre rutas locales
    foreach ($dbConfig as $path => $config) {
        if (isset($routesByPath[$path])) {
            # Ruta existe en local, aplicar config DB
            $routesByPath[$path] = array_merge($routesByPath[$path], [
                'label' => $config['label'] ?? $routesByPath[$path]['label'],
                'hidden' => $config['hidden'] ?? $routesByPath[$path]['hidden'],
                'group' => $config['group'] ?? $routesByPath[$path]['group'],
                'required_roles' => $config['required_roles'] ?? [],
                'order_index' => $config['order_index'] ?? 0,
                'source' => 'db'
            ]);
        }
    }

    # 4. Herencia ACL: rutas hijas heredan de padre si no tienen config explícita
    $routes = array_values($routesByPath);
    $routes = applyAclInheritance($routes, $dbConfig);

    # 5. Filtrar por roles del usuario
    $userRoles = getUserRoles();
    $filtered = filterByRoles($routes, $userRoles);

    # 6. Ordenar
    usort($filtered, function($a, $b) {
        $orderA = $a['order_index'] ?? 0;
        $orderB = $b['order_index'] ?? 0;
        if ($orderA !== $orderB) return $orderA - $orderB;
        return strcmp($a['path'] ?? '', $b['path'] ?? '');
    });

    return Response::json([
        'success' => true,
        'routes' => $filtered,
        'roles' => $userRoles,
        'configured' => !empty($dbConfig)
    ]);
}, ROUTER_SCOPE_PRIVATE);

function handleSaveAction(): array {
    if (!isSysAdmin()) {
        return Response::json(['success' => false, 'message' => 'Only sysadmin can manage navigation'], 403);
    }
    $requestedRoutes = \bX\Args::$OPT['routes'] ?? null;
    if (!is_array($requestedRoutes)) {
        return Response::json(['success' => false, 'message' => 'routes array required'], 400);
    }
    $replace = !empty(\bX\Args::$OPT['replace']);
    $saved = upsertNavigationRoutes($requestedRoutes, $replace);
    return Response::json(['success' => true, 'saved' => $saved]);
}

function applyAclInheritance(array $routes, array $dbConfig): array {
    # Ordenar por profundidad de path (padres primero)
    $pathsWithAcl = [];
    foreach ($dbConfig as $path => $config) {
        if (!empty($config['required_roles'])) {
            $pathsWithAcl[$path] = $config['required_roles'];
        }
    }

    foreach ($routes as &$route) {
        $path = $route['path'] ?? '';

        # Si ya tiene ACL explícito en DB, no heredar
        if (isset($dbConfig[$path]) && !empty($dbConfig[$path]['required_roles'])) {
            continue;
        }

        # Buscar padre con ACL
        $parentAcl = findParentAcl($path, $pathsWithAcl);
        if ($parentAcl !== null) {
            # Heredar ACL del padre (se suman, no reemplazan)
            $existing = $route['required_roles'] ?? [];
            $route['required_roles'] = mergeRoles($existing, $parentAcl);
            $route['acl_inherited_from'] = getParentPath($path, array_keys($pathsWithAcl));
        }
    }
    unset($route);

    return $routes;
}

function findParentAcl(string $childPath, array $pathsWithAcl): ?array {
    # Buscar el padre más cercano que tenga ACL
    $segments = explode('/', trim($childPath, '/'));

    while (count($segments) > 0) {
        array_pop($segments);
        $parentPath = '/' . implode('/', $segments);
        if ($parentPath === '/') $parentPath = '';

        if (isset($pathsWithAcl[$parentPath])) {
            return $pathsWithAcl[$parentPath];
        }

        # También probar con path exacto
        $parentPath = '/' . implode('/', $segments);
        if (isset($pathsWithAcl[$parentPath])) {
            return $pathsWithAcl[$parentPath];
        }
    }

    return null;
}

function getParentPath(string $childPath, array $configuredPaths): ?string {
    $segments = explode('/', trim($childPath, '/'));

    while (count($segments) > 0) {
        array_pop($segments);
        $parentPath = '/' . implode('/', $segments);
        if (in_array($parentPath, $configuredPaths, true)) {
            return $parentPath;
        }
    }

    return null;
}

function mergeRoles(array $existing, array $inherited): array {
    $merged = $existing;
    foreach ($inherited as $role) {
        $roleCode = is_array($role) ? ($role['role'] ?? null) : $role;
        if (!$roleCode) continue;

        $found = false;
        foreach ($merged as $m) {
            $mCode = is_array($m) ? ($m['role'] ?? null) : $m;
            if ($mCode === $roleCode) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $merged[] = $role;
        }
    }
    return $merged;
}

function filterByRoles(array $routes, array $userRoles): array {
    return array_values(array_filter($routes, function($route) use ($userRoles) {
        $required = $route['required_roles'] ?? [];

        # Sin requisitos = público
        if (empty($required)) return true;

        # system.admin tiene acceso a todo
        if (in_array('system.admin', $userRoles, true)) return true;

        # Verificar si usuario tiene alguno de los roles requeridos
        $requiredCodes = array_map(function($r) {
            return is_array($r) ? ($r['role'] ?? null) : $r;
        }, $required);

        return (bool)array_intersect(array_filter($requiredCodes), $userRoles);
    }));
}

function getUserRoles(): array {
    return array_unique(array_filter(array_map(function($a) {
        return $a['roleCode'] ?? null;
    }, Profile::$roles['assignments'] ?? [])));
}

function ensureNavigationTable(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $sql = "CREATE TABLE IF NOT EXISTS navigation_routes (
        nav_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        path VARCHAR(500) NOT NULL UNIQUE,
        label VARCHAR(255) DEFAULT NULL,
        hidden TINYINT(1) DEFAULT 0,
        route_group VARCHAR(100) DEFAULT NULL,
        required_roles_json JSON DEFAULT NULL,
        order_index INT DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    CONN::nodml($sql);
}

function loadDbNavigationConfig(): array {
    $scopeId = Profile::$scope_entity_id ?? null;

    # Traer rutas globales (scope_entity_id IS NULL) + rutas del scope actual
    if ($scopeId) {
        $rows = CONN::dml(
            "SELECT path, label, hidden, route_group, required_roles_json, order_index, scope_entity_id
             FROM navigation_routes
             WHERE is_active = 1
               AND (scope_entity_id IS NULL OR scope_entity_id = :scope)
             ORDER BY scope_entity_id IS NULL ASC, path",
            [':scope' => $scopeId]
        );
    } else {
        # Sin scope, solo rutas globales
        $rows = CONN::dml(
            "SELECT path, label, hidden, route_group, required_roles_json, order_index, scope_entity_id
             FROM navigation_routes
             WHERE is_active = 1
               AND scope_entity_id IS NULL"
        );
    }

    if (empty($rows)) return [];

    $config = [];
    foreach ($rows as $r) {
        $path = $r['path'];
        # Rutas del scope tienen prioridad sobre globales (mismo path)
        if (isset($config[$path]) && $r['scope_entity_id'] === null) {
            continue; # Ya hay config del scope, ignorar global
        }
        $config[$path] = [
            'label' => $r['label'],
            'hidden' => (bool)$r['hidden'],
            'group' => $r['route_group'],
            'required_roles' => normalizeRequiredRoles($r['required_roles_json']),
            'order_index' => (int)$r['order_index'],
            'scope_entity_id' => $r['scope_entity_id']
        ];
    }
    return $config;
}

function upsertNavigationRoutes(array $routes, bool $replace): int {
    $pathsSeen = [];
    $saved = 0;

    # Determinar scope: del request o del perfil actual
    $scopeId = \bX\Args::$OPT['scope_entity_id'] ?? null;
    $isGlobal = isset(\bX\Args::$OPT['global']) && \bX\Args::$OPT['global'] === true;

    # Si es global explícito, scope = NULL; si no, usar scope del perfil
    if ($isGlobal) {
        $scopeId = null;
    } elseif ($scopeId === null) {
        $scopeId = Profile::$scope_entity_id ?? null;
    }

    foreach ($routes as $r) {
        if (empty($r['path'])) continue;

        $path = $r['path'];
        $pathsSeen[] = $path;
        $rolesJson = json_encode(normalizeRequiredRolesForSave($r['required_roles'] ?? []));

        $res = CONN::nodml(
            "INSERT INTO navigation_routes (path, scope_entity_id, label, hidden, route_group, required_roles_json, order_index, is_active)
             VALUES (:p, :s, :l, :h, :g, :r, :o, 1)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                hidden = VALUES(hidden),
                route_group = VALUES(route_group),
                required_roles_json = VALUES(required_roles_json),
                order_index = VALUES(order_index),
                is_active = 1",
            [
                ':p' => $path,
                ':s' => $scopeId,
                ':l' => $r['label'] ?? null,
                ':h' => !empty($r['hidden']) ? 1 : 0,
                ':g' => $r['group'] ?? null,
                ':r' => $rolesJson,
                ':o' => (int)($r['order_index'] ?? 0)
            ]
        );
        if ($res['success']) $saved++;
    }

    if ($replace && !empty($pathsSeen)) {
        # Solo desactivar rutas del mismo scope
        $placeholders = implode(',', array_fill(0, count($pathsSeen), '?'));
        if ($scopeId) {
            CONN::nodml(
                "UPDATE navigation_routes SET is_active = 0
                 WHERE scope_entity_id = ? AND path NOT IN ($placeholders)",
                array_merge([$scopeId], $pathsSeen)
            );
        } else {
            CONN::nodml(
                "UPDATE navigation_routes SET is_active = 0
                 WHERE scope_entity_id IS NULL AND path NOT IN ($placeholders)",
                $pathsSeen
            );
        }
    }

    return $saved;
}

function isSysAdmin(): bool {
    foreach (Profile::$roles['assignments'] ?? [] as $assign) {
        if (($assign['roleCode'] ?? null) === 'system.admin') return true;
    }
    return false;
}

function normalizeRequiredRoles($raw): array {
    if (empty($raw)) return [];
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($decoded)) return [];

    $roles = [];
    foreach ($decoded as $item) {
        if (is_string($item)) {
            $roles[] = ['role' => $item, 'scope' => 'write'];
        } elseif (is_array($item) && !empty($item['role'])) {
            $roles[] = ['role' => $item['role'], 'scope' => $item['scope'] ?? 'write'];
        }
    }
    return $roles;
}

function normalizeRequiredRolesForSave(array $items): array {
    $out = [];
    foreach ($items as $item) {
        if (is_string($item) && $item !== '') {
            $out[] = ['role' => $item, 'scope' => 'write'];
        } elseif (is_array($item) && !empty($item['role'])) {
            $out[] = ['role' => $item['role'], 'scope' => $item['scope'] ?? 'write'];
        }
    }
    return $out;
}
