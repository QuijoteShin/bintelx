<?php # package/ws/pending.endpoint.php
namespace ws;

use bX\Router;
use bX\Response;
use bX\Channel\MessagePersistence;
use bX\ChannelContext;

/**
 * @endpoint   /ws/pending
 * @method     GET
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Gets pending messages for authenticated user
 * @query      channel (optional) - filter by channel
 * @tag        WebSocket
 */
Router::register(['GET'], 'pending', function(...$params) {
    $authTable = ChannelContext::$authTable;
    $fd = $_SERVER['WS_FD'];

    if (!$authTable->exists((string)$fd)) {
        return Response::error('Authentication required', 401);
    }

    $user = $authTable->get((string)$fd);
    $profileId = $user['profile_id'];

    $channel = $_GET['channel'] ?? null;
    $pending = MessagePersistence::getPendingMessages($profileId, $channel);

    return Response::json([
        'type' => 'pending',
        'pending_count' => count($pending),
        'messages' => $pending
    ]);
}, ROUTER_SCOPE_PUBLIC);
