#!/usr/bin/env php
<?php # app/migrate_secrets.php
require_once __DIR__ . '/../bintelx/WarmUp.php';

use bX\Config;
use bX\Log;

class SecretMigrator
{
    private string $envPath;
    private string $secretsDir;
    private array $migratedSecrets = [];

    private const SENSITIVE_PATTERNS = [
        'PASSWORD', 'SECRET', 'KEY', 'TOKEN', 'PRIVATE', 'CREDENTIAL'
    ];

    public function __construct()
    {
        $this->envPath = dirname(__DIR__) . '/.env';
        $this->secretsDir = dirname(__DIR__) . '/secrets';
    }

    public function run(): void
    {
        $this->printHeader();

        if (!file_exists($this->envPath)) {
            $this->error(".env file not found at {$this->envPath}");
            exit(1);
        }

        $this->createSecretsDirectory();
        $secrets = $this->findSecretsInEnv();

        if (empty($secrets)) {
            $this->info("No sensitive variables found in .env");
            return;
        }

        $this->printSecretsFound($secrets);

        echo "\nProceed with migration? (y/n): ";
        $confirm = trim(fgets(STDIN));

        if (strtolower($confirm) !== 'y') {
            $this->info("Migration cancelled by user");
            return;
        }

        $this->migrateSecrets($secrets);
        $this->printSummary();
    }

    private function printHeader(): void
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════╗\n";
        echo "║   🔐 Bintelx Secret Migration Tool            ║\n";
        echo "╚════════════════════════════════════════════════╝\n";
        echo "\n";
    }

    private function createSecretsDirectory(): void
    {
        $webGroup = Config::get('SYSTEM_WEB_GROUP', 'www-data');

        if (!is_dir($this->secretsDir)) {
            mkdir($this->secretsDir, 0750, true);
            $this->success("Created secrets directory: {$this->secretsDir}");
        } else {
            $this->info("Using existing secrets directory: {$this->secretsDir}");
        }

        chmod($this->secretsDir, 0750);
        chgrp($this->secretsDir, $webGroup);
        $this->success("Set directory permissions to 0750 (group: {$webGroup})\n");
    }

    private function findSecretsInEnv(): array
    {
        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES);
        $secrets = [];

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);

            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, 'SECRET_PLAIN_') === 0 || strpos($line, 'SECRET_JSON_') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if (preg_match('/^(["\'])(.*)\1$/', $value, $matches)) {
                    $value = $matches[2];
                }

                if ($this->isSensitive($key) && !empty($value)) {
                    $secrets[$key] = [
                        'value' => $value,
                        'line' => $lineNum,
                        'type' => $this->detectType($value)
                    ];
                }
            }
        }

        return $secrets;
    }

    private function isSensitive(string $key): bool
    {
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (stripos($key, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    private function detectType(string $value): string
    {
        $decoded = json_decode($value);
        return (json_last_error() === JSON_ERROR_NONE) ? 'json' : 'plain';
    }

    private function printSecretsFound(array $secrets): void
    {
        echo "Found " . count($secrets) . " sensitive variable(s):\n\n";

        foreach ($secrets as $key => $info) {
            $masked = $this->maskValue($info['value']);
            $type = strtoupper($info['type']);
            echo "  • {$key} [{$type}]\n";
            echo "    Current value: {$masked}\n";
        }
    }

    private function migrateSecrets(array $secrets): void
    {
        echo "\n";
        $this->info("Starting migration...\n");

        $envLines = file($this->envPath, FILE_IGNORE_NEW_LINES);
        $backupPath = $this->envPath . '.backup.' . date('Y-m-d_His');

        copy($this->envPath, $backupPath);
        $this->success("Backed up original .env to: " . basename($backupPath) . "\n");

        foreach ($secrets as $key => $info) {
            $this->migrateSecret($key, $info, $envLines);
        }

        file_put_contents($this->envPath, implode("\n", $envLines) . "\n");
        $this->success("Updated .env file");
    }

    private function migrateSecret(string $key, array $info, array &$envLines): void
    {
        $type = $info['type'];
        $value = $info['value'];
        $webGroup = Config::get('SYSTEM_WEB_GROUP', 'www-data');

        $extension = ($type === 'json') ? 'json' : 'secret';
        $filename = strtolower($key) . '.' . $extension;
        $filepath = $this->secretsDir . '/' . $filename;

        file_put_contents($filepath, $value);
        chmod($filepath, 0640);
        chgrp($filepath, $webGroup);

        $relativeFilepath = 'secrets/' . $filename;
        $prefix = ($type === 'json') ? 'SECRET_JSON_' : 'SECRET_PLAIN_';

        foreach ($envLines as $i => $line) {
            $trimmed = trim($line);

            if (strpos($trimmed, $key . '=') === 0 && strpos($trimmed, '#') !== 0) {
                $envLines[$i] = "# Migrated to file-based secret";
                $envLines[$i] .= "\n# {$line}";
                $envLines[$i] .= "\n{$prefix}{$key}={$relativeFilepath}";
                break;
            }
        }

        $this->success("✓ Migrated {$key}");
        echo "  File: {$relativeFilepath}\n";
        echo "  Permissions: 0640 (group: {$webGroup})\n";

        $this->migratedSecrets[$key] = [
            'file' => $filepath,
            'type' => $type,
            'size' => strlen($value)
        ];
    }

    private function maskValue(string $value): string
    {
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 3) . str_repeat('*', min($len - 6, 20)) . substr($value, -3);
    }

    private function printSummary(): void
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════╗\n";
        echo "║   📊 Migration Summary                         ║\n";
        echo "╚════════════════════════════════════════════════╝\n";
        echo "\n";

        if (empty($this->migratedSecrets)) {
            echo "No secrets were migrated.\n";
            return;
        }

        echo "Migrated " . count($this->migratedSecrets) . " secret(s):\n\n";

        foreach ($this->migratedSecrets as $key => $info) {
            echo "  • {$key}\n";
            echo "    Type: {$info['type']}\n";
            echo "    File: {$info['file']}\n";
            echo "    Size: {$info['size']} bytes\n\n";
        }

        $this->success("Migration completed successfully!\n");

        echo "Next steps:\n";
        echo "  1. Test your application: php app/test/test_config.php\n";
        echo "  2. Verify secrets/ is in .gitignore\n";
        echo "  3. For production, move secrets to /run/secrets/\n\n";
    }

    private function success(string $message): void
    {
        echo "✅ {$message}\n";
    }

    private function info(string $message): void
    {
        echo "ℹ️  {$message}\n";
    }

    private function error(string $message): void
    {
        echo "❌ {$message}\n";
    }
}

try {
    $migrator = new SecretMigrator();
    $migrator->run();
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    Log::logError("Secret migration failed: " . $e->getMessage());
    exit(1);
}
