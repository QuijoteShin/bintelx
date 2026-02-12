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
}
