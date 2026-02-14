<?php # package/ws/auth.endpoint.php
namespace ws;

use bX\Router;
use bX\Response;
use bX\JWT;
use bX\Config;
use bX\CONN;
use bX\Log;
use bX\ChannelContext;

/**
 * @endpoint   /ws/auth
 * @method     WS
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Authenticates a WebSocket connection using JWT
 * @body       (JSON) {"token": "..."}
 * @tag        WebSocket
 */
Router::register(['POST'], 'auth', function(...$params) {
    $server = ChannelContext::$server;
    $fd = $_SERVER['WS_FD'];
    $authTable = ChannelContext::$authTable;

    $token = $_POST['token'] ?? null;

    if (!$token) {
        return Response::error('Missing authentication token', 400);
    }

    try {
        # Reutilizar lógica de Account::verifyToken()
        $jwtSecret = Config::get('JWT_SECRET');
        $jwtXorKey = Config::get('JWT_XOR_KEY', '');

        $account = new \bX\Account($jwtSecret, $jwtXorKey);
        $accountId = $account->verifyToken($token);

        if (!$accountId) {
            throw new \Exception('Invalid or expired token');
        }

        # Obtener payload completo para extraer profile_id (si existe)
        $jwt = new JWT($jwtSecret, $token);
        $payload = $jwt->getPayload();
        $profileId = $payload[1]['profile_id'] ?? null;
        $entityId = null;

        # Cargar datos del perfil
        if ($profileId !== null) {
            # Token incluye profile_id
            $query = "SELECT primary_entity_id FROM profiles WHERE profile_id = :profile_id LIMIT 1";
            CONN::dml($query, [':profile_id' => (int)$profileId], function($row) use (&$entityId) {
                $entityId = $row['primary_entity_id'] ?? null;
                return false;
            });
        } else {
            # Backward compatibility: buscar profile default
            $query = "SELECT profile_id, primary_entity_id FROM profiles WHERE account_id = :account_id LIMIT 1";
            CONN::dml($query, [':account_id' => (int)$accountId], function($row) use (&$profileId, &$entityId) {
                $profileId = $row['profile_id'] ?? null;
                $entityId = $row['primary_entity_id'] ?? null;
                return false;
            });
        }

        # Preservar device_hash del JWT (source of truth para fingerprint)
        $deviceHash = $payload[1]['device_hash'] ?? '';

        # Guardar en Swoole\Table (compartida entre workers)
        $authTable->set((string)$fd, [
            'token' => $token,
            'account_id' => $accountId,
            'profile_id' => $profileId,
            'device_hash' => $deviceHash
        ]);

        Log::logInfo("WebSocket: User authenticated", ['fd' => $fd, 'account_id' => $accountId, 'profile_id' => $profileId]);

        return Response::json([
            'type' => 'auth',
            'success' => true,
            'user' => [
                'account_id' => $accountId,
                'profile_id' => $profileId,
                'entity_id' => $entityId
            ]
        ]);

        # Enviar digest de notificaciones pendientes (si las hay)
        $buffer = [];
        CONN::dml("SELECT channel, count, payload_preview FROM sys_notification_buffer WHERE user_id = :id ORDER BY priority DESC, created_at ASC",
            [':id' => $profileId],
            function($row) use (&$buffer) {
                $buffer[] = [
                    'channel' => $row['channel'],
                    'count' => (int)$row['count'],
                    'preview' => json_decode($row['payload_preview'], true)
                ];
                return true;
            }
        );

        if (!empty($buffer)) {
            # Enviar digest inmediatamente después de auth
            $digest = json_encode([
                'type' => 'digest',
                'total' => array_sum(array_column($buffer, 'count')),
                'channels' => $buffer,
                'timestamp' => time()
            ]);

            $server->push($fd, $digest);

            Log::logInfo("Digest sent to reconnected user", [
                'user_id' => $profileId,
                'total_messages' => array_sum(array_column($buffer, 'count')),
                'channels' => count($buffer)
            ]);
        }

    } catch (\Exception $e) {
        Log::logWarning("WebSocket: Authentication failed", ['fd' => $fd, 'error' => $e->getMessage()]);

        return Response::error('Invalid or expired token', 401)
            ->withMeta(['type' => 'auth_error']);
    }
}, ROUTER_SCOPE_PUBLIC);
