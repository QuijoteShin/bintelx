<?php

namespace bX;
/**
 *


// usage
$jwt = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZCI6IjEyMyJ9.0Z1gAaVvgQiB_e0z0WbOvDvB7sU6pHXsKsUgCvU6aJ0";
$secretKey = "secretkey";
$jwtObject = new JWT($jwt, $secretKey);
$payload = $jwtObject->getPayload();
$isSignatureValid = $jwtObject->validateSignature();

if ($isSignatureValid) {
echo "User ID: " . $payload->id;
} else {
echo "Invalid signature";
}

 *
 *
 */

class JWT {
  private $header;
  private $payload;
  private $signature;
  private $secretKey;
  public $binSignature;

  public function __construct(string $secretKey, string $jwt = null) {
    $this->secretKey = $secretKey;
    if ($jwt) {
      $jwtParts = explode('.', $jwt);
      $jwtParts[0] = str_replace('Bearer', '', $jwtParts[0]);
      $this->header = json_decode($this->base64url_decode($jwtParts[0]));
      $this->payload = json_decode($this->base64url_decode($jwtParts[1]));
      $this->signature = $this->base64url_decode($jwtParts[2]);
    }
  }

  public function setSecretKey($secretKey) {
    $this->secretKey = $secretKey;
  }

  public function getSecretKey() {
    return $this->secretKey;
  }

  public function setPayload(Array $payload): void {
    $this->payload = $payload;
  }
  public function getPayload() {
    return $this->payload;
  }

  public function setHeader(Array $header) {
    $this->header = $header;
  }

  public function getHeader() {
    return $this->header;
  }

  public function validateSignature() {
    if ($this->header == null || $this->payload == null || $this->signature == null) {
      return false;
    }
    $header = json_encode($this->header);
    $payload = json_encode($this->payload);
    $header = $this->base64url_encode($header);
    $payload = $this->base64url_encode($payload);
    $binSignature = hash_hmac('sha256', $header . '.' . $payload, $this->secretKey, true);
    if (hash_equals($binSignature, $this->signature)) {
      return $binSignature;
    } else {
      return false;
    }
  }

  public function generateJWT() {
    $this->header = json_encode($this->header);
    $this->payload = json_encode($this->payload);
    $this->header = $this->base64url_encode($this->header);
    $this->payload = $this->base64url_encode($this->payload);
    $this->binSignature = hash_hmac('sha256', $this->header . '.' . $this->payload, $this->secretKey, true);
    $this->signature = $this->base64url_encode($this->binSignature);
    return $this->header . '.' . $this->payload . '.' . $this->signature;
  }

    public function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    public function base64url_decode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
}