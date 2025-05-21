<?php

##
# $routeTree = new RouteTree($userRoles);
# $condensedTree = $routeTree->getCondensedTree();
##

class AuthRouteTree {
  private $userRoutes = [];

  public function __construct(array $userRoles) {
    $this->getUserRoutes($userRoles);
  }

  private function getUserRoutes(array $userRoles) {
    // Obtener todas las rutas asociadas con los roles del usuario
    // y almacenarlas en el arreglo $this->userRoutes
    // ...

    // Ordenar las rutas según su dependencia en el DAG
    usort($this->userRoutes, function ($a, $b) {
      return $a['dag'] <=> $b['dag'];
    });
  }

  public function getCondensedTree(): array {
    $tree = [];

    foreach ($this->userRoutes as $route) {
      // Crear una ruta en el árbol si aún no existe
      if (!isset($tree[$route['URI']])) {
        $tree[$route['URI']] = [
            'app' => $route['app'],
            'name' => $route['name'],
            'URI' => $route['URI'],
            'method' => $route['method'],
            'dag' => $route['dag'],
            'backend_method' => $route['backend_method'],
            'access' => 0,
            'modify' => 0,
            'export' => 0
        ];
      }

      // Sumar los niveles de acceso, modificación y exportación
      // de todos los roles del usuario
      $tree[$route['URI']]['access'] += $route['access'];
      $tree[$route['URI']]['modify'] += $route['modify'];
      $tree[$route['URI']]['export'] += $route['export'];
    }

    return $tree;
  }
}
