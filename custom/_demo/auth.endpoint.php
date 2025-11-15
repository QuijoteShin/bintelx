<?php # custom/_demo/auth.endpoint.php
namespace _demo;
use bX\Router;
use _demo\AuthHandler;

/**
 * @endpoint   /api/_demo/login
 * @method     POST
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Authenticates a user and returns a JWT.
 * @body       (JSON) {"username": "...", "password": "..."}
 */
Router::register(['POST'], 'login', function(...$params) {
  header('Content-Type: application/json');
  // The business logic is encapsulated in the AuthHandler class
  echo json_encode(["data" => AuthHandler::login(\bX\Args::$OPT)]);
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/_demo/register
 * @method     POST
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Creates a new account (without profile or entity).
 * @body       (JSON) {"username": "...", "password": "..."}
 */
Router::register(['POST'], 'register', function(...$params) {
  header('Content-Type: application/json');
  echo json_encode(["data" => AuthHandler::register(\bX\Args::$OPT)]);
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/_demo/profile/create
 * @method     POST
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Creates a profile and entity for an account.
 * @body       (JSON) {
 *   "accountId": 123,
 *   "entityName": "Juan Pérez",
 *   "entityType": "person",
 *   "nationalId": "12345678-9",
 *   "nationalIsocode": "CL",
 *   "profileName": "My Profile"
 * }
 */
Router::register(['POST'], 'profile/create', function(...$params) {
  header('Content-Type: application/json');
  echo json_encode(["data" => AuthHandler::createProfile(\bX\Args::$OPT)]);
}, ROUTER_SCOPE_PUBLIC);


/**
 * @endpoint   /api/_demo/validate
 * @method     GET
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Validates the token sent in the "Authorization" header and returns the user's profile.
 */
Router::register(['GET','POST'], 'validate', function(...$params) {
  header('Content-Type: application/json');
  // The logic simply returns the profile already loaded by the framework
  echo json_encode(["data" => AuthHandler::validateToken()]);
}, ROUTER_SCOPE_PRIVATE);


/**
 * @endpoint   /api/_demo/report
 * @method     GET, POST
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    fetch some information.
 */
Router::register(['GET', 'POST'], 'report', function(...$params) {
  header('Content-Type: application/json');
  echo json_encode(["data" => AuthHandler::DevToolsReport()]);
}, ROUTER_SCOPE_PRIVATE);
