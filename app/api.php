<?php # app/api.php
require_once '../bintelx/WarmUp.php';

   # const timeZoneIANA = Intl.DateTimeFormat().resolvedOptions().timeZone;
   # console.log(timeZoneIANA); // Imprimirá algo como "America/Santiago" HTTP TimeZone
  if(empty($_SERVER["HTTP_X_USER_TIMEZONE"])) {
    $_SERVER["HTTP_X_USER_TIMEZONE"] = \bX\Config::get('DEFAULT_TIMEZONE', 'America/Santiago');
  }
  # timezone se aplica via CONN::pdoOptions() MYSQL_ATTR_INIT_COMMAND

\bX\Args::parseRequest();

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


if (($_SERVER['CONTENT_TYPE'] ?? '') === 'application/json') {
    $_POST = \bX\Args::ctx()->opt;
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
        $jwtSecret = \bX\Config::required('JWT_SECRET');
        $jwtXorKey = \bX\Config::required('JWT_XOR_KEY');

        $account = new \bX\Account($jwtSecret, $jwtXorKey);
        $account_id = $account->verifyToken($token, $_SERVER["REMOTE_ADDR"]);
        if($account_id) {
            # Extract claims from JWT payload
            $scopeEntityId = 0;
            $deviceHash = '';
            try {
                $jwt = new \bX\JWT($jwtSecret, $token);
                $payload = $jwt->getPayload(); # [METADATA, {id, profile_id, scope_entity_id, device_hash, iat, exp}]
                $userPayload = $payload[1] ?? [];
                $scopeEntityId = (int)($userPayload['scope_entity_id'] ?? 0);
                $deviceHash = $userPayload['device_hash'] ?? '';
            } catch (\Exception $e) {
                \bX\Log::logWarning("Failed to extract JWT claims: " . $e->getMessage());
            }

            $profile = new \bX\Profile();
            if ($profile->load(['account_id' => $account_id])) {
                # Validate scope from JWT against ACL
                if ($scopeEntityId > 0 && !\bX\Profile::canAccessScope($scopeEntityId)) {
                    \bX\Log::logError('SECURITY: JWT_SCOPE_MISMATCH', [
                        'account_id' => $account_id,
                        'profile_id' => \bX\Profile::ctx()->profileId,
                        'jwt_scope' => $scopeEntityId,
                        'device_hash' => $deviceHash,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    $scopeEntityId = \bX\Profile::ctx()->entityId;
                }
                \bX\Profile::ctx()->scopeEntityId = $scopeEntityId;


                \bX\Log::logDebug("Profile loaded with scope_entity_id: $scopeEntityId");
            }
        }
    }
  
  $module = explode('/', $uri)[2] ?? 'default';

  # Load endpoints CASCADE: package (system) → custom (override via CUSTOM_PATH)
  \bX\Router::load([
    "find_str" => [
      'package' => \bX\WarmUp::$BINTELX_HOME . '../package/',
      'custom' => \bX\WarmUp::getCustomPath()
    ],
    'pattern'=> '{*/,}*{endpoint,controller}.php'
  ], function ($routeFileContext) use($module) {
      if(is_file($routeFileContext['real']) && strpos($routeFileContext['real'], "/$module/") !== false) {
        require_once $routeFileContext['real'];
        \bX\Log::logDebug("Router loaded endpoint: {$routeFileContext['real']}");
      }
    }
  );

  \bX\Router::dispatch($method, $uri);

} catch (\Throwable $e) {
    \bX\Log::logError($e->getMessage(), $e->getTrace());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Server error. Check logs for details.']);
}
