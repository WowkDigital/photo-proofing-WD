<?php
// api/crypto_helper.php

class VaultCrypto {
    private static $iterations = 10000;
    private static $method = 'aes-256-gcm';

    public static function deriveKey($password, $salt) {
        // Derive a 32-byte (256-bit) key from the password and salt
        return hash_pbkdf2("sha256", $password, $salt, self::$iterations, 32, true);
    }

    public static function encrypt($data, $key) {
        $ivLength = openssl_cipher_iv_length(self::$method);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $tag = ""; // For GCM
        $encrypted = openssl_encrypt($data, self::$method, $key, OPENSSL_RAW_DATA, $iv, $tag);
        
        return [
            'encrypted' => bin2hex($encrypted),
            'iv' => bin2hex($iv),
            'tag' => bin2hex($tag)
        ];
    }

    public static function decrypt($encryptedHex, $ivHex, $tagHex, $key) {
        $encrypted = hex2bin($encryptedHex);
        $iv = hex2bin($ivHex);
        $tag = hex2bin($tagHex);
        
        return openssl_decrypt($encrypted, self::$method, $key, OPENSSL_RAW_DATA, $iv, $tag);
    }
}
