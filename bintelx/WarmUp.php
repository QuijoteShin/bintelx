<?php # bintelx/WarmUp.php

namespace bX;

error_reporting(E_ALL);

class WarmUp
{
  private $primaryLibraries = ['./kernel/*'];
  private $moduleLibraries = ['./Modules/id*.?/business/*', './Custom/*/business/*'];
  public static $moduleMain;
  public static $BINTELX_HOME = '';
  public static $CUSTOM_PATH = null; # Configurable via .env CUSTOM_PATH

  /**
   * Get the custom modules base path
   * Uses CUSTOM_PATH from .env if set, otherwise defaults to ../custom/
   * @return string Absolute path ending with /
   */
  public static function getCustomPath(): string
  {
    if (self::$CUSTOM_PATH !== null) {
      $path = self::$CUSTOM_PATH;
      # Ensure trailing slash
      return rtrim($path, '/') . '/';
    }
    return self::$BINTELX_HOME . '../custom/';
  }

  public function __construct() {
    self::$BINTELX_HOME = __DIR__ . '/';
    include WarmUp::$BINTELX_HOME . 'kernel/dd.php';
    $this->registerAutoloader();
  }

  private function registerAutoloader() {
    spl_autoload_register(function ($class) {
      $regex= '/^(?:bX\\\)?(?P<class_root>\w+)(?P<class_child>[\\\_\w]*)/';
      preg_match($regex, $class, $matches);
      $class_root = $matches['class_root'];
      $class_child = @$matches['class_child'];
      unset($matches);
      $class_root  = str_replace('\\', '/', $class_root);
      $class_child = str_replace('\\', '/', $class_child);
      $class = str_replace('\\', '/', $class);

      ## kernel - Try PSR-4 style first for deep namespaces (bX\Async\ClassName)
      $psr4Path = str_replace('bX/', '', $class);
      $file = self::$BINTELX_HOME  . "kernel/{$psr4Path}.php";

      ## kernel - fallback to legacy logic
      if (!file_exists($file)) {
        $file = self::$BINTELX_HOME  . "kernel/{$class_root}.php";
      }
      if (!file_exists($file) && !empty($class_child)) {
        $file = self::$BINTELX_HOME  . "kernel/{$class_root}/{$class_child}.php";
      }
      if (!file_exists($file)) {
        $file = self::$BINTELX_HOME  . "kernel/{$class_root}/{$class_root}.php";
      }
      ## custom - uses configurable CUSTOM_PATH from .env
      $customBase = self::getCustomPath();
      if (!file_exists($file) && !empty($class_child)) {
        $child = $class_child;
        $root = strtolower($class_root);
        $file = $customBase . "{$root}/Business/{$class_root}{$child}.php";
      }
      if (!file_exists($file)) {
        $child = $class_child;
        $root = strtolower($class_root);
        $file = $customBase . "{$root}/Business/{$child}.php";
      }
      if (!file_exists($file)) {
        $child = $class_root;
        $root = strtolower($class_root);
        $file = $customBase . "{$root}/Business/{$child}.php";
      }
      ## package - uses lowercase module directory (bX\Payroll\Handler → package/payroll/Business/Handler.php)
      $packageModule = strtolower($class_root);
      if (!file_exists($file) && !empty($class_child)) {
        $file = self::$BINTELX_HOME  . "../package/{$packageModule}/Business{$class_child}.php";
      }
      if (!file_exists($file)) {
        $file = self::$BINTELX_HOME  . "../package/{$packageModule}/Business/{$class_root}.php";
      }

      if (!file_exists($file)) {
        $file = self::$BINTELX_HOME  . "../package/" . self::$moduleMain ."/{$class_root}{$class_child}.php";
      }

      if (file_exists($file)) {
        require_once $file;
      }
    });
  }

  public static function setModuleMain($moduleMain){
    self::$moduleMain = $moduleMain;
  }
}

new \bX\WarmUp();
new \bX\Log();

// Load environment configuration
$envPath = dirname(__DIR__) . '/.env';
\bX\Config::load($envPath);

// Set custom path from environment (allows custom modules from external directory)
$customPath = \bX\Config::get('CUSTOM_PATH');
if ($customPath) {
  \bX\WarmUp::$CUSTOM_PATH = $customPath;
}

// Initialize database connection from environment variables
try {
  $dsn = \bX\Config::databaseDSN();
  $dbConfig = \bX\Config::database();
  \bX\CONN::add($dsn, $dbConfig['username'], $dbConfig['password']);
} catch (\Exception $e) {
  \bX\Log::logError('Database connection failed: ' . $e->getMessage());

  // Fallback to hard-coded values if .env not found (backward compatibility)
  if (!file_exists($envPath)) {
    \bX\Log::logWarning('.env file not found, using hard-coded credentials (DEPRECATED)');
    try {
      \bX\CONN::add('mysql:host=127.0.0.1;port=3306;dbname=bintelx_core;charset=utf8mb4', 'quijote', 'quijotito');
    } catch (\Exception $e2) {
      \bX\Log::logError($e2->getMessage());
    }
  }
}
