<?php # bintelx/kernel/ChannelContext.php
namespace bX;

# Estado compartido del Channel Server — se setea en onWorkerStart, vive lo que vive el worker
# Las Swoole\Table son memoria compartida entre workers (lecturas atómicas)
class ChannelContext
{
    public static ?\Swoole\WebSocket\Server $server = null;
    public static ?\Swoole\Table $channelsTable = null;
    public static ?\Swoole\Table $authTable = null;

    # Detecta si el proceso actual es el Channel Server
    public static function isChannel(): bool {
        return self::$server !== null;
    }

    # WS FD per-coroutine (aislado entre requests concurrentes del mismo worker)
    public static function getWsFd(): ?int {
        if (!self::isChannel()) return null;
        $ctx = \Swoole\Coroutine::getContext();
        return $ctx->ws_fd ?? null;
    }

    # Push seguro — verifica conexión + try/catch
    public static function safePush(int $fd, string $json): bool {
        if (!self::$server || !self::$server->isEstablished($fd)) return false;
        try {
            return self::$server->push($fd, $json);
        } catch (\Throwable $e) {
            Log::logWarning("safePush failed", ['fd' => $fd, 'error' => $e->getMessage()]);
            return false;
        }
    }

    # Targeting unificado: broadcast, fd, profile, scope, device, channel
    public static function pushTo(string $target, mixed $id, array $message): int {
        # Inyectar msg_id para dedup en frontend
        if (!isset($message['msg_id'])) {
            $message['msg_id'] = substr(md5(uniqid('', true) . mt_rand()), 0, 12);
        }
        if (!isset($message['timestamp'])) {
            $message['timestamp'] = time();
        }
        $payload = json_encode($message);
        $sent = 0;

        # Push a canal (channelsTable) — key format: "{channel}\x00{fd}"
        if ($target === 'channel') {
            if (!self::$channelsTable) return 0;
            $prefix = $id . "\x00";
            foreach (self::$channelsTable as $key => $row) {
                if (str_starts_with($key, $prefix)) {
                    $fd = (int)substr($key, strlen($prefix));
                    if (self::safePush($fd, $payload)) $sent++;
                }
            }
            return $sent;
        }

        # Push por authTable (broadcast, fd, profile, scope, device)
        if (!self::$authTable) return 0;

        if ($target === 'fd') {
            # Push directo a un FD
            if (self::safePush((int)$id, $payload)) $sent++;
            return $sent;
        }

        foreach (self::$authTable as $fd => $row) {
            $match = match($target) {
                'broadcast' => true,
                'profile' => $row['profile_id'] === (int)$id,
                'scope' => $row['scope_entity_id'] === (int)$id,
                'device' => $row['device_hash'] === (string)$id,
                default => false
            };
            if ($match && self::safePush((int)$fd, $payload)) {
                $sent++;
            }
        }
        return $sent;
    }

    # Helper: obtener FDs por scope (workspace)
    public static function getFdsByScope(int $scopeEntityId): array {
        $fds = [];
        if (!self::$authTable) return $fds;
        foreach (self::$authTable as $fd => $row) {
            if ($row['scope_entity_id'] === $scopeEntityId) {
                $fds[] = (int)$fd;
            }
        }
        return $fds;
    }
}
