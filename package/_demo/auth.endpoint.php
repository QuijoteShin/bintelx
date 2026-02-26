<?php # custom/_demo/auth.endpoint.php
namespace _demo;
use bX\Router;
use bX\Response;
use auth\AuthHandler;

/**
 * @endpoint   /api/_demo/login
 * @method     POST
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Authenticates a user and returns a JWT.
 * @body       (JSON) {"username": "...", "password": "..."}
 * @tag        Authentication
 */
Router::register(['POST'], 'login', function(...$params) {
  $result = AuthHandler::login(\bX\Args::ctx()->opt);
  $code = $result['success'] ? 200 : 401;
  return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/_demo/register
 * @method     POST
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Creates a new account (without profile or entity).
 * @body       (JSON) {"username": "...", "password": "..."}
 * @tag        Authentication
 */
Router::register(['POST'], 'register', function(...$params) {
  $result = AuthHandler::register(\bX\Args::ctx()->opt);
  $code = $result['success'] ? 201 : 400;
  return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/_demo/register-company
 * @method     POST
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Registers a company account with 2 entities: person (owner) + organization (company)
 * @body       (JSON) {"username": "...", "password": "...", "personName": "Juan Pérez", "companyName": "Mi Empresa SpA", "personNationalId": "12345678-9", "companyNationalId": "76123456-7", "nationalIsocode": "CL"}
 * @tag        Authentication
 */
Router::register(['POST'], 'register-company', function(...$params) {
  $result = AuthHandler::registerCompany(\bX\Args::ctx()->opt);
  $code = $result['success'] ? 201 : 400;
  return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/_demo/profile/create
 * @method     POST
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Creates a profile and entity for an account.
 * @body       (JSON) {"accountId": 123, "entityName": "Juan Pérez", "entityType": "person", "nationalId": "12345678-9", "nationalIsocode": "CL", "profileName": "My Profile"}
 * @tag        Profile Management
 */
Router::register(['POST'], 'profile/create', function(...$params) {
  $result = AuthHandler::createProfile(\bX\Args::ctx()->opt);
  $code = $result['success'] ? 201 : 400;
  return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_PUBLIC);

/**
 * @endpoint   /api/_demo/profile/relationship
 * @method     POST
 * @scope      ROUTER_SCOPE_PUBLIC (solo para bootstrap/testing)
 * @purpose    Crea una relación profile→entity para scopes
 */
Router::register(['POST'], 'profile/relationship', function(...$params) {
  $result = AuthHandler::createRelationship(\bX\Args::ctx()->opt);
  $code = $result['success'] ? 201 : 400;
  return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/_demo/validate
 * @method     GET, POST
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Validates tokens from Authorization header or POST body JSON {"token": "..."}
 * @tag        Authentication
 */
Router::register(['GET','POST'], 'validate', function(...$params) {
  $result = AuthHandler::validateToken();
  $code = $result['success'] ? 200 : 401;
  return Response::json(['data' => $result], $code);
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/_demo/report
 * @method     GET, POST
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Fetch development tools report and system information.
 * @tag        Development
 */
Router::register(['GET', 'POST'], 'report', function(...$params) {
  $result = AuthHandler::DevToolsReport();
  return Response::json(['data' => $result]);
}, ROUTER_SCOPE_PRIVATE);
