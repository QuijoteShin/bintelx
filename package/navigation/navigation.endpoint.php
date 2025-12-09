<?php # custom/navigation/navigation.endpoint.php
use bX\Router;
use bX\Response;
use bX\Profile;
use bX\Log;
use bX\CONN;

/**
 * Navigation endpoint
 * - action=fetch (default): returns routes filtered by roles. Source preference: DB -> routes.json -> local_routes fallback.
 * - action=save: sysadmin-only. Upserts provided routes into DB. Optional replace=true to deactivate missing ones.
 */
Router::register(['GET','POST'], '', function() {
  if (!Profile::isLoggedIn()) {
    return Response::json(['success' => false, 'message' => 'Authentication required'], 401);
  }

  ensureNavigationTable();

  $action = \bX\Args::$OPT['action'] ?? 'fetch';
  $requestedRoutes = \bX\Args::$OPT['routes'] ?? null; # optional payload from front (configured)
  $localRoutes = \bX\Args::$OPT['local_routes'] ?? null; # candidate routes the front discovered locally

  if ($action === 'save') {
    if (!isSysAdmin()) {
      return Response::json(['success' => false, 'message' => 'Only sysadmin can manage navigation'], 403);
    }
    if (!is_array($requestedRoutes)) {
      return Response::json(['success' => false, 'message' => 'routes array required'], 400);
    }
    $replace = !empty(\bX\Args::$OPT['replace']);
    $saved = upsertNavigationRoutes($requestedRoutes, $replace);
    return Response::json(['success' => true, 'saved' => $saved]);
  }

  $routes = [];
  $dbRoutes = loadDbNavigationRoutes();
  $configured = false;
  if (!empty($dbRoutes)) {
    $routes = $dbRoutes;
    $configured = true;
  } else {
    $jsonPath = __DIR__ . '/routes.json';
    if (is_file($jsonPath)) {
      $content = file_get_contents($jsonPath);
      $decoded = json_decode($content, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $routes = $decoded;
        $configured = true;
      } else {
        Log::logWarning('navigation.endpoint: invalid routes.json');
      }
    }
  }

  # If still empty, fall back to local routes reported by front (mark as unconfigured)
  $usedLocalFallback = false;
  if (empty($routes) && is_array($localRoutes)) {
    $routes = $localRoutes;
    $usedLocalFallback = true;
  }

  # Enrich routes missing app/moduleName/prefix with local metadata
  if (is_array($routes) && is_array($localRoutes)) {
    $localByPath = [];
    foreach ($localRoutes as $lr) {
      if (!empty($lr['path'])) {
        $localByPath[$lr['path']] = $lr;
      }
    }
    foreach ($routes as &$r) {
      if (!empty($r['path']) && isset($localByPath[$r['path']])) {
        $lr = $localByPath[$r['path']];
        $r['app'] = $r['app'] ?? ($lr['app'] ?? null);
        $r['moduleName'] = $r['moduleName'] ?? ($lr['moduleName'] ?? null);
        $r['prefix'] = $r['prefix'] ?? ($lr['prefix'] ?? null);
      }
    }
    unset($r);
  }

  $userRoles = array_unique(array_filter(array_map(function($a) {
    return $a['roleCode'] ?? null;
  }, Profile::$roles['assignments'] ?? [])));

  $filtered = array_values(array_filter($routes, function($route) use ($userRoles) {
    $required = $route['required_roles'] ?? [];
    if (empty($required)) return true;
    if (in_array('system.admin', $userRoles, true)) return true;
    $requiredCodes = array_map(function($r){ return is_array($r) ? ($r['role'] ?? null) : $r; }, $required);
    return (bool)array_intersect(array_filter($requiredCodes), $userRoles);
  }));

  # compute unconfigured locals (not in routes)
  $unconfigured = [];
  if (is_array($localRoutes) && !empty($localRoutes)) {
    $configuredPaths = array_column($routes, 'path');
    foreach ($localRoutes as $lr) {
      if (empty($lr['path'])) continue;
      if (!in_array($lr['path'], $configuredPaths, true)) {
        $unconfigured[] = $lr;
      }
    }
  }

  return Response::json([
    'success' => true,
    'routes' => $filtered,
    'roles' => $userRoles,
    'configured' => $configured,
    'unconfigured' => $unconfigured
  ]);
}, ROUTER_SCOPE_PRIVATE);

function ensureNavigationTable(): void {
  $sql = "CREATE TABLE IF NOT EXISTS navigation_routes (
            nav_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            path VARCHAR(500) NOT NULL UNIQUE,
            label VARCHAR(255) NOT NULL,
            route_group VARCHAR(100) DEFAULT NULL,
            required_roles_json JSON DEFAULT NULL,
            order_index INT DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            source VARCHAR(50) DEFAULT 'db',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by_profile_id BIGINT UNSIGNED NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by_profile_id BIGINT UNSIGNED NULL
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  CONN::nodml($sql);
}

function loadDbNavigationRoutes(): array {
  $rows = CONN::dml("SELECT path,label,route_group,app,module_name,prefix,required_roles_json,order_index FROM navigation_routes WHERE is_active=1 ORDER BY order_index, label");
  if (empty($rows)) return [];
  return array_map(function($r) {
    $roles = normalizeRequiredRoles($r['required_roles_json'] ?? null);
    return [
      'path' => $r['path'],
      'label' => $r['label'],
      'group' => $r['route_group'],
      'app' => $r['app'] ?? null,
      'moduleName' => $r['module_name'] ?? null,
      'prefix' => $r['prefix'] ?? null,
      'required_roles' => $roles,
      'order_index' => (int)$r['order_index'],
      'source' => 'db'
    ];
  }, $rows);
}

function upsertNavigationRoutes(array $routes, bool $replace): int {
  $now = date('Y-m-d H:i:s');
  $pathsSeen = [];
  $saved = 0;
  foreach ($routes as $r) {
    if (empty($r['path'])) continue;
    $path = $r['path'];
    $pathsSeen[] = $path;
    $label = $r['label'] ?? $path;
    $group = $r['group'] ?? null;
    $orderIndex = isset($r['order_index']) ? (int)$r['order_index'] : 0;
    $roles = normalizeRequiredRolesForSave($r['required_roles'] ?? []);
    $rolesJson = json_encode($roles);
    $res = CONN::nodml(
      "INSERT INTO navigation_routes (path,label,route_group,app,module_name,prefix,required_roles_json,order_index,is_active,source,created_at,updated_at)
         VALUES (:p,:l,:g,:a,:m,:x,:r,:o,1,'db',:c,:c)
       ON DUPLICATE KEY UPDATE
         label = VALUES(label),
         route_group = VALUES(route_group),
         app = VALUES(app),
         module_name = VALUES(module_name),
         prefix = VALUES(prefix),
         required_roles_json = VALUES(required_roles_json),
         order_index = VALUES(order_index),
         is_active = 1,
         source = 'db',
         updated_at = :c",
      [
        ':p' => $path,
        ':l' => $label,
        ':g' => $group,
        ':a' => $r['app'] ?? null,
        ':m' => $r['moduleName'] ?? null,
        ':x' => $r['prefix'] ?? null,
        ':r' => $rolesJson,
        ':o' => $orderIndex,
        ':c' => $now
      ]
    );
    if ($res['success']) $saved++;
  }

  if ($replace && !empty($pathsSeen)) {
    $placeholders = [];
    $params = [];
    foreach ($pathsSeen as $idx => $p) {
      $ph = ':p' . $idx;
      $placeholders[] = $ph;
      $params[$ph] = $p;
    }
    $sql = "UPDATE navigation_routes SET is_active=0 WHERE path NOT IN (" . implode(',', $placeholders) . ")";
    CONN::nodml($sql, $params);
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
  $roles = [];
  if (empty($raw)) return $roles;
  $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
  if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
    return $roles;
  }
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
