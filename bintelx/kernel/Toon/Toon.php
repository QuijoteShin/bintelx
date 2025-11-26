<?php
# kernel/Toon/Toon.php
namespace bX\Toon;
use bX\Toon\Encoder;
use bX\Toon\Decoder;

class Toon {
  public static function encode($data, $options = []) {
    $encoder = new bX\ToonEncoder($options);
    return $encoder->encode($data);
  }

  public static function decode($toonString, $options = []) {
    $decoder = new bX\ToonDecoder($options);
    return $decoder->decode($toonString);
  }
}
