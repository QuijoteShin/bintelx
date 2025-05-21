<?php

namespace bX;

/**
* URL Matcher.
 *
 * This class can be used to match URLs.
 *
Usage
$matcher = new UrlMatcher();
$regex = '/api\/(?P<module_id>\w+)\/edit\/(?P<id>\d{1,2})$/';
$url = 'https://domain.ntd/api/account/edit/123';
print_r($matcher->match($url, $regex));
*/

class UrlMatcher {

  public static function match($url, $regex) {
    $matches = [];
    preg_match('/'.$regex.'/', $url, $matches);
    return \bX\ArrayProcessor::getNamedIndices($matches);
  }
}


