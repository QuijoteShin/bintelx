<?php # bintelx/kernel/Channel/MessagePersistence.php

namespace bX\Channel;

use bX\CONN;
use bX\Log;

# Sistema de persistencia de mensajes y ACKs multinivel
# Soporta: server ACK, client ACK (doble check azul), app ACK (triple check)
class MessagePersistence
{
    /**
     * Persiste un mensaje para entrega asíncrona
     *
     * @return string message_id
     */
    public static function persistMessage(
        string $channel,
        array $payload,
        ?int $fromProfileId = null,
        ?int $fromAccountId = null,
        string $messageType = 'text',
        string $priority = 'normal',
        ?int $ttlSeconds = null
    ): string {
        $messageId = self::generateMessageId();
        $expiresAt = $ttlSeconds ? date('Y-m-d H:i:s', time() + $ttlSeconds) : null;

        $sql = "INSERT INTO channel_messages
                (message_id, channel, from_profile_id, from_account_id, message_type, payload, priority, expires_at)
                VALUES (:message_id, :channel, :from_profile, :from_account, :type, :payload, :priority, :expires)";

        $result = CONN::nodml($sql, [
            ':message_id' => $messageId,
            ':channel' => $channel,
            ':from_profile' => $fromProfileId,
            ':from_account' => $fromAccountId,
            ':type' => $messageType,
            ':payload' => json_encode($payload),
            ':priority' => $priority,
            ':expires' => $expiresAt
        ]);

        if (!$result['success']) {
            Log::logError("MessagePersistence: Failed to persist message", [
                'channel' => $channel,
                'error' => $result['error'] ?? 'Unknown'
            ]);
            return '';
        }

        # ACK nivel 1: Servidor recibió el mensaje
        self::recordAck($messageId, $fromProfileId, $fromAccountId, 'server');

        Log::logInfo("MessagePersistence: Message persisted", [
            'message_id' => $messageId,
            'channel' => $channel
        ]);

        return $messageId;
    }

    /**
     * Registra un ACK (server, client, app)
     */
    public static function recordAck(
        string $messageId,
        ?int $profileId,
        ?int $accountId,
        string $ackLevel,
        ?array $ackData = null
    ): bool {
        $sql = "INSERT INTO channel_message_acks
                (message_id, profile_id, account_id, ack_level, ack_data)
                VALUES (:message_id, :profile_id, :account_id, :ack_level, :ack_data)
                ON DUPLICATE KEY UPDATE
                    ack_data = VALUES(ack_data),
                    acked_at = CURRENT_TIMESTAMP(6)";

        $result = CONN::nodml($sql, [
            ':message_id' => $messageId,
            ':profile_id' => $profileId,
            ':account_id' => $accountId,
            ':ack_level' => $ackLevel,
            ':ack_data' => $ackData ? json_encode($ackData) : null
        ]);

        if ($result['success']) {
            Log::logDebug("MessagePersistence: ACK recorded", [
                'message_id' => $messageId,
                'profile_id' => $profileId,
                'ack_level' => $ackLevel
            ]);
        }

        return $result['success'];
    }

    /**
     * Obtiene mensajes pendientes para un perfil
     */
    public static function getPendingMessages(int $profileId, ?string $channel = null): array
    {
        # Query directa sin vista - mensajes que el usuario NO ha confirmado con ACK 'client'
        $sql = "SELECT
                    cm.message_id,
                    cm.channel,
                    cm.from_profile_id,
                    cm.from_account_id,
                    cm.message_type,
                    cm.payload,
                    cm.priority,
                    cm.created_at,
                    :profile_id AS recipient_profile_id,
                    MAX(CASE WHEN cma.ack_level = 'server' THEN 1 ELSE 0 END) AS server_ack,
                    MAX(CASE WHEN cma.ack_level = 'client' THEN 1 ELSE 0 END) AS client_ack,
                    MAX(CASE WHEN cma.ack_level = 'app' THEN 1 ELSE 0 END) AS app_ack
                FROM channel_messages cm
                INNER JOIN channel_subscriptions cs
                    ON cs.channel = cm.channel
                    AND cs.profile_id = :profile_id
                    AND cs.status = 'active'
                LEFT JOIN channel_message_acks cma
                    ON cma.message_id = cm.message_id
                    AND cma.profile_id = :profile_id
                WHERE cm.created_at > COALESCE(cs.last_read_at, '1970-01-01')
                    AND (cm.expires_at IS NULL OR cm.expires_at > NOW(6))";

        $params = [':profile_id' => $profileId];

        if ($channel) {
            $sql .= " AND cm.channel = :channel";
            $params[':channel'] = $channel;
        }

        $sql .= " GROUP BY cm.message_id
                  ORDER BY cm.priority DESC, cm.created_at ASC
                  LIMIT 100";

        $messages = [];
        CONN::dml($sql, $params, function($row) use (&$messages) {
            $messages[] = $row;
            return true;
        });

        return $messages;
    }

    /**
     * Obtiene estado de ACKs de un mensaje
     */
    public static function getMessageAcks(string $messageId): array
    {
        $sql = "SELECT profile_id, account_id, ack_level, ack_data, acked_at
                FROM channel_message_acks
                WHERE message_id = :message_id
                ORDER BY acked_at ASC";

        $acks = [];
        CONN::dml($sql, [':message_id' => $messageId], function($row) use (&$acks) {
            $acks[] = [
                'profile_id' => (int)$row['profile_id'],
                'account_id' => (int)$row['account_id'],
                'ack_level' => $row['ack_level'],
                'ack_data' => $row['ack_data'] ? json_decode($row['ack_data'], true) : null,
                'acked_at' => $row['acked_at']
            ];
            return true;
        });

        return $acks;
    }

    /**
     * Crea o actualiza suscripción persistente
     */
    public static function subscribe(
        string $channel,
        int $profileId,
        int $accountId,
        string $type = 'temporary'
    ): bool {
        $sql = "INSERT INTO channel_subscriptions
                (channel, profile_id, account_id, subscription_type, status)
                VALUES (:channel, :profile_id, :account_id, :type, 'active')
                ON DUPLICATE KEY UPDATE
                    subscription_type = VALUES(subscription_type),
                    status = 'active',
                    subscribed_at = CURRENT_TIMESTAMP(6)";

        $result = CONN::nodml($sql, [
            ':channel' => $channel,
            ':profile_id' => $profileId,
            ':account_id' => $accountId,
            ':type' => $type
        ]);

        return $result['success'];
    }

    /**
     * Desuscribe de un canal
     */
    public static function unsubscribe(string $channel, int $profileId): bool
    {
        $sql = "UPDATE channel_subscriptions
                SET status = 'inactive'
                WHERE channel = :channel AND profile_id = :profile_id";

        $result = CONN::nodml($sql, [
            ':channel' => $channel,
            ':profile_id' => $profileId
        ]);

        return $result['success'];
    }

    /**
     * Marca canal como leído hasta ahora
     */
    public static function markAsRead(string $channel, int $profileId): bool
    {
        $sql = "UPDATE channel_subscriptions
                SET last_read_at = CURRENT_TIMESTAMP(6)
                WHERE channel = :channel AND profile_id = :profile_id";

        $result = CONN::nodml($sql, [
            ':channel' => $channel,
            ':profile_id' => $profileId
        ]);

        return $result['success'];
    }

    /**
     * Obtiene canales suscritos de un perfil
     */
    public static function getSubscriptions(int $profileId): array
    {
        $sql = "SELECT channel, subscription_type, status, subscribed_at, last_read_at
                FROM channel_subscriptions
                WHERE profile_id = :profile_id AND status = 'active'";

        $subscriptions = [];
        CONN::dml($sql, [':profile_id' => $profileId], function($row) use (&$subscriptions) {
            $subscriptions[] = $row;
            return true;
        });

        return $subscriptions;
    }

    /**
     * Limpia mensajes expirados
     */
    public static function cleanupExpiredMessages(): int
    {
        $sql = "DELETE FROM channel_messages
                WHERE expires_at IS NOT NULL AND expires_at < NOW(6)";

        $result = CONN::nodml($sql);
        $deleted = $result['affected_rows'] ?? 0;

        if ($deleted > 0) {
            Log::logInfo("MessagePersistence: Cleaned up expired messages", [
                'count' => $deleted
            ]);
        }

        return $deleted;
    }

    /**
     * Genera message_id único
     */
    private static function generateMessageId(): string
    {
        return 'msg_' . uniqid('', true) . '_' . bin2hex(random_bytes(8));
    }
}
