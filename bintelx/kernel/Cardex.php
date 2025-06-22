<?php

namespace bX;
/**
 *
$cardex = new Cardex();
$files = $cardex->searchCaseInsensitive('./', '*.php');
print_r($files);

 **/

class Cardex {
  public static function search($path, $pattern, $flags = GLOB_NOSORT | GLOB_BRACE) {
    $path = realpath($path); # WARNING SECURITY RISK
    $ptrn = $path . DIRECTORY_SEPARATOR . $pattern;
    $files = glob($ptrn, $flags);
    if ($files === false) {
      Log::logWarning("Cardex::search - glob() returned an error for pattern: " . $ptrn);
      return [];
    }

    $files = array_map(function ($file) use ($path) {
      $real = realpath($file);
      $real_base = realpath($path);
      $relative = str_replace($real_base, '', $real);
      $relative = ltrim($relative, DIRECTORY_SEPARATOR);
      $module_parts = explode('/', $relative); # "/module_id/" || "/module_id/mod2_id/api"
      $module = '_default';
      if (count($module_parts) > 1) {
        $module = (strtolower($module_parts[0]) === 'custom' && isset($parts[1])) ? $module_parts[1] : $module_parts[0];
      }
      return [
          'real' => $real,
          'relative' => $relative,
          'module'   => $module,
          'module_parts'   => $module_parts,
      ];
    }, $files);

    return array_filter($files, function ($file) {
      return is_file($file['real']);
    });
  }

  public static function searchCaseInsensitive($path, $pattern, $flags = GLOB_NOSORT) {
    $path = realpath($path);
    $files = scandir($path);
    $files = array_filter($files, function ($file) use ($path) {
      return is_file($path . DIRECTORY_SEPARATOR . $file);
    });

    $pattern = strtolower($pattern);
    $files = array_filter($files, function ($file) use ($pattern) {
      return strtolower($file) === $pattern;
    });

    $files = array_map(function ($file) use ($path) {
      return [
          'real' => $path . DIRECTORY_SEPARATOR . $file,
          'relative' => $file,
      ];
    }, $files);

    return $files;
  }
}