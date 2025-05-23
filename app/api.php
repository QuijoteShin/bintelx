<?php # app/api.php
require_once '../bintelx/WarmUp.php';
new \bX\Args();
header("Access-Control-Allow-Origin: http://svelte.localhost:5173");
header("Access-Control-Allow-Methods: GET,POST,PATCH,DELETE,OPTIONS");
header('Access-Control-Allow-Headers: Origin,X-Auth-Token,X-Requested-With,Content-Type,Accept,Authorization');
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


# Start up the router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$route = new \bX\Router($uri);
\bX\Log::$logToUser = true;


$module = explode('/', $uri)[2];
\bX\Router::load(["find_str"=>\bX\WarmUp::$BINTELX_HOME . '../custom/',
        'pattern'=> '{*/,}{endpoint,controller}.php']
    , function ($route) use($module) {
        if(is_file($route['real'])&& strpos($route['real'], "/$module/") > 1) {
            require_once $route['real'];
        }
    }
);

try {
    $auth = new \bX\Auth("woz.min..", 'XOR_KEY_2o25'); # CHANGE default obfuscation regulary
    $token = $_SERVER["HTTP_AUTHORIZATION"] ?? '';
    if(empty($token) && !empty($_COOKIE["authToken"])) $token = $_COOKIE["authToken"];
    if(!empty($token)) {
        $account_id = $auth->verifyToken($token, $_SERVER["REMOTE_ADDR"]);
        if($account_id) {
            $profile = new \bX\Profile();
            $profile->load(['account_id' => $account_id]);
        }
    }
    \bX\Router::dispatch($method, $uri);
} catch (\ErrorException $e) {
    \bX\Log::logError($e->getMessage(), $e->getTrace());
}