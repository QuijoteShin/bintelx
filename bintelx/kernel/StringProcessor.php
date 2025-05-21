<?php

namespace bX;

class StringProcessor {
  public static function toEng($string) {
    $transliterator = Transliterator::create('Any-Latin; Latin-ASCII');
    return $transliterator->transliterate($string);
  }
}