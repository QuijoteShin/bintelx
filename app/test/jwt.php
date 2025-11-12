<?php # bintelx/app/test/jwt.php
require_once '../../bintelx/WarmUp.php';
$secretKey = 'woz.min..';
$mode = $argv[1];

use \bX\Args;
new Args();

$userId = $argv[2];
$userId = 1;
$payload = [date("mdhs"), "id" => $userId];
$jwt = new \bX\JWT($secretKey);
$jwt->setHeader(array('alg' => 'HS256', 'typ' => 'JWT'));
$jwt->setPayload($payload);
$token = $jwt->generateJWT();
echo "Token created: $token" . PHP_EOL;

# validate
# $token = $argv[2];
$jwt = new \bX\JWT($secretKey, $token);
$isSignatureValid = $jwt->validateSignature();
if ($isSignatureValid) {
  $payload = $jwt->getPayload();
  $userId = $payload["id"];
  $profile = new \bX\Profile();
  $profile->load(["account_id" => $userId]);
  echo "User ID:" . \bX\Profile::$account_id . PHP_EOL;
} else {
  echo "Invalid signature" . PHP_EOL;
}
