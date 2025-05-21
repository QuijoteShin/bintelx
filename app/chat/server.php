<?php

use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;

require_once 'ChatBackup.php';

$server = new Server("0.0.0.0", 9501, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);;

// Configura certificado SSL
$server->set([
    'ssl_cert_file' => '/var/www/bintelx/bintelx/config/server/dev.local.crt',
    'ssl_key_file' => '/var/www/bintelx/bintelx/config/server/dev.local.key',
]);

$chatRooms = [];
$chatBackup = new ChatBackup();

$server->on('start', function (Server $server) {
  echo "Server start: {$server->host}:{$server->port}\n";
});
$server->on('open', function (Server $server, Request $request) use (&$chatRooms) {
  echo "New connection: {$request->fd}\n";
  $chatRooms[$request->fd] = ['devices' => [$request->fd]];
});

$server->on('message', function (Server $server, Frame $frame) use (&$chatRooms, $chatBackup) {
  $data = json_decode($frame->data, true);
  $action = $data['action'] ?? '';
  $type = $payload['type'] ?? '';

  if ($action === 'join') {
    $device = $data['device'] ?? '';
    if ($device) {
      $chatRooms[$frame->fd]['devices'][] = $device;
    }
  } elseif ($action === 'message') {
    if (!$type || !in_array($type, ['text', 'audio', 'document'])) {
      $server->disconnect($frame->fd, 1003, 'Mensaje no válido');
      return;
    }
    $message = $data['message'] ?? '';
    if ($message) {
      echo "Message from {$frame->fd}: {$message}\n";
      foreach ($chatRooms as $fd => $roomInfo) {
        if (in_array($frame->fd, $roomInfo['devices'])) {
          $server->push($fd, $message);
        }
      }
      $chatBackup->backupAsync($message);
    }
  }
});

$server->on('close', function (Server $server, int $fd) use (&$chatRooms) {
  echo "Connection closed: {$fd}\n";
  unset($chatRooms[$fd]);
});

$server->start();