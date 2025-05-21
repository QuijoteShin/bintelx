<?php

namespace bX;

/**
 *
 *  La clase ArrayProcessor. El método searchArray permite buscar en un array multinivel tanto en los índices como en sus valores, y el método mergeArrays permite unir múltiples arrays asociativos.
 *
// usage
$arrayProcessor = new ArrayProcessor();

$array1 = [
    "name" => "John Doe",
    "age" => 30,
    "address" => [
        "street" => "1 Main St",
        "city" => "New York",
        "state" => "NY"
    ]
];

$array2 = [
    "name" => "Jane Doe",
    "age" => 25,
    "address" => [
        "street" => "2 Main St",
        "city" => "Los Angeles",
        "state" => "LA"
    ]
];

$searchResults = $arrayProcessor::search([$array1, $array2], "Main St");
print_r($searchResults);

$mergedArray = $arrayProcessor::merge([$array1, $array2]);
print_r($mergedArray);

 *
 *
 */

class ArrayProcessor {

  public static function exist($value, $array) {
    $found = false;
    try {
      array_walk_recursive($array, function ($v) use (&$found, $value) {
        if(is_array($v) && in_array($value, $v, true)) {
          throw new Exception;
        }
        if ($v === $value) {
          throw new Exception;
        }
      });
    } catch(Exception $exception) {
        $found = true;
      }

    return $found;
  }
  public static function search($array, $search, $keys = false, &$results = []) {
    foreach ($array as $key => $value) {
      if (is_array($value)) {
        self::search($value, $search, $keys, $results);
      } else {
        if ($value == $search || ($keys && $key == $search)) {
          $results[] = $value;
        }
      }
    }
    return $results;
  }

  public static function merge($arrays) {
    $result = [];
    foreach ($arrays as $array) {
      $result = array_merge($result, $array);
    }
    return $result;
  }

  public static function getNamedIndices($array) {
    $result = [];
    foreach ($array as $key => $value) {
      if (!is_numeric($key)) {
        $result[$key] = $value;
      }
    }
    return $result;
  }
}
