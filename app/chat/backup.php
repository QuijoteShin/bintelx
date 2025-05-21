<?php

\bx\Chat;

use Swoole\Coroutine;

class ChatBackup
{
  private string $backupFile;

  public function __construct(string $backupFile = 'chat_backup.txt')
  {
    $this->backupFile = $backupFile;
  }

  public function backupAsync(string $message): void
  {
    Coroutine::create(function () use ($message) {
      $timestamp = date('Y-m-d H:i:s');
      $backupLine = "[{$timestamp}] {$message}\n";
      file_put_contents($this->backupFile, $backupLine, FILE_APPEND);
    });
  }
}
