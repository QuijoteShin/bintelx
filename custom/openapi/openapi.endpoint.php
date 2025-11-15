<?php # custom/openapi/openapi.endpoint.php
namespace openapi;
use bX\Router;
use openapi\OpenApiHandler;

/**
 * @endpoint   /api/openapi/spec.json
 * @method     GET
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Returns OpenAPI 3.1 specification in JSON format (raw, for tools)
 * @tag        OpenAPI
 */
Router::register(['GET'], 'spec.json', function(...$params) {
  header('Content-Type: application/json');
  echo json_encode(OpenApiHandler::getRawSpec(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/openapi/spec
 * @method     GET
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Returns OpenAPI specification with metadata wrapper
 * @tag        OpenAPI
 */
Router::register(['GET'], 'spec', function(...$params) {
  header('Content-Type: application/json');
  echo json_encode(["data" => OpenApiHandler::generateSpec()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/openapi/ui
 * @method     GET
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Serves Swagger UI interface for interactive API documentation
 * @tag        OpenAPI
 */
Router::register(['GET'], 'ui', function(...$params) {
  header('Content-Type: text/html; charset=utf-8');
  $result = OpenApiHandler::serveSwaggerUI();
  echo $result['html'];
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/openapi/stats
 * @method     GET
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Returns statistics about the API (endpoints count, methods, scopes, tags)
 * @tag        OpenAPI
 */
Router::register(['GET'], 'stats', function(...$params) {
  header('Content-Type: application/json');
  echo json_encode(["data" => OpenApiHandler::getStats()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}, ROUTER_SCOPE_PUBLIC);
