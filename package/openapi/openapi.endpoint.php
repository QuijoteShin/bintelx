<?php # package/openapi/openapi.endpoint.php
namespace openapi;
use bX\Router;
use bX\Response;
use openapi\OpenApiHandler;

/**
 * @endpoint   /api/openapi/spec.json
 * @method     GET
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Returns OpenAPI 3.1 specification (JSON default, TOON optional)
 * @tag        OpenAPI
 */
Router::register(['GET'], 'spec\.(?P<format>json|toon)', function($format = 'json') {
  $spec = OpenApiHandler::getRawSpec();
  return $format === 'toon' ? Response::toon($spec) : Response::json($spec);
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/openapi/spec
 * @method     GET
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Returns OpenAPI specification with metadata wrapper
 * @tag        OpenAPI
 */
Router::register(['GET'], 'spec', function(...$params) {
  # Spec con metadata wrapper
  $result = OpenApiHandler::generateSpec();
  return Response::success($result, 'OpenAPI specification generated successfully');
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/openapi/ui
 * @method     GET
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Serves Swagger UI interface for interactive API documentation
 * @tag        OpenAPI
 */
Router::register(['GET'], 'ui', function(...$params) {
  # Swagger UI HTML
  $result = OpenApiHandler::serveSwaggerUI();
  return Response::raw($result['html'], 'text/html; charset=utf-8');
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/openapi/stats
 * @method     GET
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Returns statistics about the API (endpoints count, methods, scopes, tags)
 * @tag        OpenAPI
 */
Router::register(['GET'], 'stats', function(...$params) {
  # API stats con wrapper
  $stats = OpenApiHandler::getStats();
  return Response::success($stats, 'API statistics retrieved successfully');
}, ROUTER_SCOPE_PUBLIC);
