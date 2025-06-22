<?php # bintelx/WarmUp.php

namespace bX;

error_reporting(E_ALL);

class WarmUp
{
  private $primaryLibraries = ['./kernel/*'];
  private $moduleLibraries = ['./Modules/id*.?/business/*', './Custom/*/business/*'];
  public static $moduleMain;
  public static $BINTELX_HOME = '';

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
      ## kernel
      $file = self::$BINTELX_HOME  . "/kernel/{$class_root}.php";
      if (!file_exists($file) && !empty($class_child)) {
        $file = self::$BINTELX_HOME  . "/kernel/{$class_root}/{$class_child}.php";
      }
      if (!file_exists($file)) {
        $file = self::$BINTELX_HOME  . "/kernel/{$class_root}/{$class_root}.php";
      }
      ## custom
      if (!file_exists($file) && !empty($class_child)) {
        $child = $class_child;
        $root = strtolower($class_root);
        $file = self::$BINTELX_HOME  . "../custom/{$root}/Business/{$class_root}{$child}.php";
      }
      if (!file_exists($file)) {
        $child = $class_child;
        $root = strtolower($class_root);
        $file = self::$BINTELX_HOME  . "../custom/{$root}/Business/{$child}.php";
      }
      if (!file_exists($file)) {
        $child = $class_root;
        $root = strtolower($class_root);
        $file = self::$BINTELX_HOME  . "../custom/{$root}/Business/{$child}.php";
      }
      ## package
      if (!file_exists($file) && !empty($class_child)) {
        $file = self::$BINTELX_HOME  . "../package/{$class_root}/Business{$class_child}.php";
      }
      if (!file_exists($file)) {
        $file = self::$BINTELX_HOME  . "../package/{$class_root}/Business/{$class_root}.php";
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
try {
  \bX\CONN::add('mysql:host=127.0.0.1;port=3306;dbname=bnx_labtronic;charset=utf8mb4', 'quijote', 'quijotito');
} catch (\Exception $e) {
  \bX\Log::logError($e->getMessage());
}

# \bX\CONN::add('mysql:host=db.svelte.localhost;dbname=bnx_labtronic', 'quijote', 'quijotito');

