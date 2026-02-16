<?php # custom/channels/channels.endpoint.php
namespace channels;

use bX\Router;
use bX\Response;
use bX\Profile;
use bX\Args;
use bX\CONN;
use bX\Log;

/**
 * @endpoint   /api/channels
 * @method     GET
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Lists channels where user is subscribed
 * @tag        Channels
 */
Router::register(['GET'], 'channels', function(...$params) {
    $userId = Profile::ctx()->profileId;

    if (!$userId) {
        return Response::error('Not authenticated', 401);
    }

    $channels = [];
    CONN::dml("SELECT c.channel_name, c.type, c.is_public, s.status, s.subscribed_at
               FROM sys_channels c
               JOIN sys_channel_subscriptions s ON c.channel_name = s.channel_name
               WHERE s.user_id = :user AND s.status = 'active'
               ORDER BY s.subscribed_at DESC",
        [':user' => $userId],
        function($row) use (&$channels) {
            $channels[] = $row;
            return true;
        }
    );

    return Response::success($channels, 'Channels retrieved');
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/channels/join
 * @method     POST
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Subscribe to a channel (persistent)
 * @body       (JSON) {"channel": "room:finanzas"}
 * @tag        Channels
 */
Router::register(['POST'], 'channels/join', function(...$params) {
    $userId = Profile::ctx()->profileId;
    $channel = Args::ctx()->opt['channel'] ?? null;

    if (!$userId) {
        return Response::error('Not authenticated', 401);
    }

    if (!$channel) {
        return Response::error('Channel name required', 400);
    }

    # Asegurar que el canal existe (o crearlo como temporary)
    CONN::nodml("INSERT INTO sys_channels (channel_name, type)
                 VALUES (:ch, 'temporary')
                 ON DUPLICATE KEY UPDATE channel_name=channel_name",
        [':ch' => $channel]
    );

    # Suscribir usuario
    CONN::nodml("INSERT INTO sys_channel_subscriptions (channel_name, user_id, status)
                 VALUES (:ch, :user, 'active')
                 ON DUPLICATE KEY UPDATE status='active', subscribed_at=NOW(6)",
        [':ch' => $channel, ':user' => $userId]
    );

    Log::logInfo("User subscribed to channel (persistent)", [
        'user_id' => $userId,
        'channel' => $channel
    ]);

    return Response::success([
        'channel' => $channel,
        'subscribed' => true
    ], 'Subscribed successfully');
}, ROUTER_SCOPE_PRIVATE);

/**
 * @endpoint   /api/channels/leave
 * @method     POST
 * @scope      ROUTER_SCOPE_PRIVATE
 * @purpose    Unsubscribe from a channel
 * @body       (JSON) {"channel": "room:finanzas"}
 * @tag        Channels
 */
Router::register(['POST'], 'channels/leave', function(...$params) {
    $userId = Profile::ctx()->profileId;
    $channel = Args::ctx()->opt['channel'] ?? null;

    if (!$userId) {
        return Response::error('Not authenticated', 401);
    }

    if (!$channel) {
        return Response::error('Channel name required', 400);
    }

    CONN::nodml("DELETE FROM sys_channel_subscriptions
                 WHERE channel_name = :ch AND user_id = :user",
        [':ch' => $channel, ':user' => $userId]
    );

    Log::logInfo("User unsubscribed from channel", [
        'user_id' => $userId,
        'channel' => $channel
    ]);

    return Response::success([
        'channel' => $channel,
        'unsubscribed' => true
    ], 'Unsubscribed successfully');
}, ROUTER_SCOPE_PRIVATE);
