<?php
namespace bX;

class Crypto {
    /**
     * Encripta usando XOR repetido. Retorna binario puro (misma longitud que $plain).
     */
    public static function bin_encrypt(string $plain, string $key): string
    {
        $cipher = '';
        $keyLen = strlen($key);
        for ($i = 0, $len = strlen($plain); $i < $len; $i++) {
            $cipher .= $plain[$i] ^ $key[$i % $keyLen];
        }
        return $cipher; // Binario
    }

    /**
     * Desencripta usando XOR repetido. Retorna binario puro (misma longitud que $cipher).
     */
    public static function bin_decrypt(string $cipher, string $key): string
    {
        // Es el mismo proceso de encrypt, ya que XOR es reversible.
        return self::bin_encrypt($cipher, $key);
    }
}
