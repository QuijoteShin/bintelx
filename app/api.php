<?php # app/api.php
require_once '../bintelx/WarmUp.php';

   # const timeZoneIANA = Intl.DateTimeFormat().resolvedOptions().timeZone;
   # console.log(timeZoneIANA); // Imprimirá algo como "America/Santiago" HTTP TimeZone
  if(empty($_SERVER["HTTP_X_USER_TIMEZONE"])) {
    $_SERVER["HTTP_X_USER_TIMEZONE"] = \bX\Config::get('DEFAULT_TIMEZONE', 'America/Santiago');
  }
  \bx\CONN::nodml("SET time_zone = '" . $_SERVER["HTTP_X_USER_TIMEZONE"] . "'");

new \bX\Args();

// CORS Configuration from environment
$corsOrigin = \bX\Config::get('CORS_ALLOWED_ORIGINS', 'https://dev.local');
$corsMethods = \bX\Config::get('CORS_ALLOWED_METHODS', 'GET,POST,PATCH,DELETE,OPTIONS');
$corsHeaders = \bX\Config::get('CORS_ALLOWED_HEADERS', 'Origin,X-Auth-Token,X-Requested-With,Content-Type,Accept,Authorization');

header("Access-Control-Allow-Origin: {$corsOrigin}");
header("Access-Control-Allow-Methods: {$corsMethods}");
header("Access-Control-Allow-Headers: {$corsHeaders}");
header("Access-Control-Allow-Credentials: true");
header('Access-Control-Max-Age: 3600');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    exit(0);
}


if ($_SERVER['CONTENT_TYPE'] === 'application/json') {
    $_POST = json_decode(file_get_contents('php://input'), true) ?? [];
}

try {

  # Start up the router
  $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  $method = $_SERVER['REQUEST_METHOD'];
  $route = new \bX\Router($uri, '/api');
  \bX\Log::$logToUser = true;

    $token = $_SERVER["HTTP_AUTHORIZATION"] ?? '';

    # Extraer token si viene con "Bearer " prefix
    if (str_starts_with($token, 'Bearer ')) {
        $token = substr($token, 7);
    }

    if(empty($token) && !empty($_COOKIE["bnxt"])) $token = $_COOKIE["bnxt"];
    if(!empty($token)) {
        // JWT Configuration from environment
        $jwtSecret = \bX\Config::get('JWT_SECRET', 'woz.min..');
        $jwtXorKey = \bX\Config::get('JWT_XOR_KEY', 'XOR_KEY_2o25');

        $account = new \bX\Account($jwtSecret, $jwtXorKey);
        $account_id = $account->verifyToken($token, $_SERVER["REMOTE_ADDR"]);
        if($account_id) {
            $profile = new \bX\Profile();
            if ($profile->load(['account_id' => $account_id])) {
                \bX\Router::$currentUserPermissions = \bX\Profile::getRoutePermissions();
            }
        }
    }
  
  $module = explode('/', $uri)[2] ?? 'default';
  \bX\Router::load(["find_str"=>\bX\WarmUp::$BINTELX_HOME . '../custom/',
      'pattern'=> '{*/,}*{endpoint,controller}.php']
    , function ($routeFileContext) use($module) {
      if(is_file($routeFileContext['real']) && strpos($routeFileContext['real'], "/$module/") !== false) {
        require_once $routeFileContext['real'];
        \bX\Log::logDebug("Router loaded endpoint: {$routeFileContext['real']}");
      }
    }
  );
  \bX\Router::dispatch($method, $uri);

} catch (\ErrorException $e) {
    \bX\Log::logError($e->getMessage(), $e->getTrace());
}
