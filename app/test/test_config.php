<?php
/**
 * Test script to verify .env configuration loading
 * Usage: php test_config.php
 */

require_once '../../bintelx/WarmUp.php';

echo "=== Bintelx Configuration Test ===\n\n";

// Test 1: Check if .env file exists
$envPath = dirname(__DIR__, 2) . '/.env';
echo "1. Checking .env file...\n";
if (file_exists($envPath)) {
    echo "   ✓ .env file found at: {$envPath}\n\n";
} else {
    echo "   ✗ .env file NOT found at: {$envPath}\n\n";
    exit(1);
}

// Test 2: Check if Config class loads
echo "2. Testing Config class...\n";
try {
    $dbHost = \bX\Config::get('DB_HOST');
    echo "   ✓ Config class loaded successfully\n\n";
} catch (Exception $e) {
    echo "   ✗ Config class failed: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 3: Verify database configuration
echo "3. Database Configuration:\n";
$dbConfig = \bX\Config::database();
echo "   Host: {$dbConfig['host']}\n";
echo "   Port: {$dbConfig['port']}\n";
echo "   Database: {$dbConfig['database']}\n";
echo "   Username: {$dbConfig['username']}\n";
echo "   Password: " . (empty($dbConfig['password']) ? '[EMPTY]' : '[SET]') . "\n\n";

// Test 4: Verify JWT configuration
echo "4. JWT Configuration:\n";
$jwtSecret = \bX\Config::get('JWT_SECRET');
$jwtXorKey = \bX\Config::get('JWT_XOR_KEY');
echo "   JWT Secret: " . (empty($jwtSecret) ? '[EMPTY]' : substr($jwtSecret, 0, 3) . '...') . "\n";
echo "   XOR Key: " . (empty($jwtXorKey) ? '[EMPTY]' : substr($jwtXorKey, 0, 3) . '...') . "\n\n";

// Test 5: Verify CORS configuration
echo "5. CORS Configuration:\n";
$corsOrigin = \bX\Config::get('CORS_ALLOWED_ORIGINS');
$corsMethods = \bX\Config::get('CORS_ALLOWED_METHODS');
echo "   Allowed Origins: {$corsOrigin}\n";
echo "   Allowed Methods: {$corsMethods}\n\n";

// Test 6: Verify Timezone configuration
echo "6. Timezone Configuration:\n";
$timezone = \bX\Config::get('DEFAULT_TIMEZONE');
echo "   Default Timezone: {$timezone}\n\n";

// Test 7: Test database connection
echo "7. Testing database connection...\n";
try {
    $dsn = \bX\Config::databaseDSN();
    $testConn = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
    echo "   ✓ Database connection successful\n";
    echo "   DSN: {$dsn}\n\n";
} catch (PDOException $e) {
    echo "   ✗ Database connection failed: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 8: Verify all expected keys exist
echo "8. Checking expected configuration keys...\n";
$requiredKeys = [
    'APP_ENV', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME',
    'JWT_SECRET', 'JWT_XOR_KEY', 'CORS_ALLOWED_ORIGINS', 'DEFAULT_TIMEZONE'
];

$missingKeys = [];
foreach ($requiredKeys as $key) {
    if (!\bX\Config::has($key)) {
        $missingKeys[] = $key;
    }
}

if (empty($missingKeys)) {
    echo "   ✓ All required keys present\n\n";
} else {
    echo "   ✗ Missing keys: " . implode(', ', $missingKeys) . "\n\n";
    exit(1);
}

echo "=================================\n";
echo "✓ All tests passed successfully!\n";
echo "=================================\n";
