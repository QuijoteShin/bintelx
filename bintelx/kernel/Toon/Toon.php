<?php
# kernel/Toon/Toon.php
namespace bX\Toon;
use bX\Toon\Encoder;
use bX\Toon\Decoder;

class Toon {
  public static function encode($data, $options = []) {
    $encoder = new Encoder($options);
    return $encoder->encode($data);
  }

  public static function decode($toonString, $options = []) {
    $decoder = new Decoder($options);
    return $decoder->decode($toonString);
  }
}
