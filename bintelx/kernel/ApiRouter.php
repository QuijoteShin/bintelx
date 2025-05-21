<?php
namespace bX;

class ApiRouter
{
  private static array $routes = [];

  public static function run(string $method, string $uri)
  {
    $uri = strtok($uri, '?');
    foreach (self::$routes as $route) {
      $route['route'] = preg_replace_callback('/{[a-zA-Z0-9_]+}/', function ($matches) {
        return '([a-zA-Z0-9_-]+)';
      }, $route['route']);
      if ($method === $route['method'] && preg_match("#^{$route['route']}$#", $uri, $matches)) {
        array_shift($matches);
        return call_user_func_array($route['callback'], $matches);
      }
    }
    return false;
  }

  public static function add(array|string $methods, string $regex, string|callable $callback): void {
    if (!is_array($methods)) {
      $methods = [$methods];
    }

    self::$routes[] = [
        'methods' => $methods,
        'regex' => $regex,
        'callback' => $callback,
    ];
  }

  public static function dispatch(string $method, string $requested_uri) {
    $matched_routes = [];

    foreach (self::$routes as $route) {
      if (in_array($method, $route['methods'])) {
        preg_match('/^api\/' . $route['regex'] . '$/', $requested_uri, $matches);
        if ($matches) {
          $matched_routes[] = [
              'callback' => $route['callback'],
              'args' => \bX\UrlMatcher::match($matches),
          ];
        }
      }
    }

    foreach ($matched_routes as $route) {
      $callback = $route['callback'];
      $args = $route['args'];

      if (is_string($callback)) {
        $callback_parts = explode('::', $callback);
        if (count($callback_parts) == 2) {
          $class_name = $callback_parts[0];
          $method_name = $callback_parts[1];
          $class_instance = new $class_name();
          call_user_func_array([$class_instance, $method_name], $args);
        }
      } elseif (is_callable($callback)) {
        call_user_func_array($callback, $args);
      }
    }
  }


}