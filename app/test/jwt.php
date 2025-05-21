<?php require_once '../../bintelx/WarmUp.php';
$secretKey = 's3z$!tk27';
$mode = $argv[1];

use \bX\Args;
new Args();
if(isset(Args::$OPT['create'])) {
  $userId = $argv[2];
  $payload = array(date("mdhs"), $userId);
  $jwt = new \bX\JWT(null, $secretKey);
  $jwt->setHeader(array('alg' => 'HS256', 'typ' => 'JWT'));
  $jwt->setPayload($payload);
  $token = $jwt->generateJWT();
  echo "Token created: $token" . PHP_EOL;
} else if (isset(Args::$OPT['process'])) {
  $token = $argv[2];
  $jwt = new \bX\JWT($token, $secretKey);
  $isSignatureValid = $jwt->validateSignature();
  if ($isSignatureValid) {
    $payload = $jwt->getPayload();
    $userId = $payload[1];
    echo "User ID: $userId" . PHP_EOL;
  } else {
    echo "Invalid signature" . PHP_EOL;
  }
} else {
  echo "Invalid mode. Usage: php cli.php create|process" . PHP_EOL;
}