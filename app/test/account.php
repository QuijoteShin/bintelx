<?php // app/test/account.php

require_once '../../bintelx/WarmUp.php';
new \bX\Args();

\bX\Log::$logToUser = true;
\bX\Log::$logLevel = 'DEBUG';

use bX\Router;
$accountService = new \bX\Account("woz.min..", 'XOR_KEY_2o25');
$accountData = $accountService->createAccount("newuser", "securePassword123", true, ['email' => 'newuser@example.com']);
if ($accountData) {
    // User created with account, entity, and profile
    echo "Account ID: {$accountData['account_id']}\n";
    echo "Profile ID: {$accountData['profile_id']}\n";
    echo "Entity ID: {$accountData['entity_id']}\n";
}


# generate Token
$accountIdToUse = "1"; // From DB or after creation
$metadataForToken = ['timestamp' => date("Y-m-d H:i:s")];
$token = $accountService->generateToken($accountIdToUse, $metadataForToken);
// $token will have a payload like: [{"issued_for":"api_access", "timestamp":"..."}, {"id":"123"}]