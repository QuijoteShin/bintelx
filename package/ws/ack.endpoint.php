<?php # custom/ws/ack.endpoint.php
namespace ws;

use bX\Router;
use bX\Response;
use bX\Log;
use bX\Channel\MessagePersistence;

/**
 * @endpoint   /ws/ack
 * @method     WS
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Acknowledges message delivery (confirms receipt)
 * @body       (JSON) {"message_id": "msg_...", "type": "ack" or "ack_digest"}
 * @tag        WebSocket
 */
Router::register(['POST'], 'ack', function(...$params) {
    $authTable = $_SERVER['WS_AUTH_TABLE'];
    $fd = $_SERVER['WS_FD'];

    $messageId = $_POST['message_id'] ?? null;
    $ackLevel = $_POST['ack_level'] ?? 'client'; # client o app
    $ackData = $_POST['ack_data'] ?? null;

    if (!$authTable->exists((string)$fd)) {
        return Response::error('Authentication required', 401);
    }

    $user = $authTable->get((string)$fd);
    $profileId = $user['profile_id'];
    $accountId = $user['account_id'];

    if (!$messageId) {
        return Response::error('message_id is required', 400);
    }

    # Validar ack_level
    if (!in_array($ackLevel, ['client', 'app'])) {
        return Response::error('Invalid ack_level. Use: client, app', 400);
    }

    # Registrar ACK
    $success = MessagePersistence::recordAck(
        $messageId,
        $profileId,
        $accountId,
        $ackLevel,
        $ackData
    );

    if ($success) {
        Log::logDebug("Message ACK recorded", [
            'message_id' => $messageId,
            'profile_id' => $profileId,
            'ack_level' => $ackLevel
        ]);
    }

    return Response::json([
        'type' => 'ack',
        'message_id' => $messageId,
        'ack_level' => $ackLevel,
        'success' => $success
    ]);
}, ROUTER_SCOPE_PUBLIC);
