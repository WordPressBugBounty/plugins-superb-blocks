<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormEncryption
{
    const PREFIX = 'spbenc:';

    /**
     * Derive a 32-byte key for a specific purpose.
     * Separate purposes (encrypt vs hmac) get separate keys.
     *
     * @param string $purpose
     * @return string 32 raw bytes
     */
    private static function DeriveKey($purpose)
    {
        return hash('sha256', $purpose . AUTH_KEY . AUTH_SALT, true);
    }

    /**
     * Check if the OpenSSL extension is available.
     *
     * @return bool
     */
    public static function IsAvailable()
    {
        return function_exists('openssl_encrypt');
    }

    /**
     * Encrypt a plaintext string.
     * Returns a prefixed base64 string, or the original plaintext
     * if encryption is unavailable or the value is empty.
     *
     * @param string $plaintext
     * @return string
     */
    public static function Encrypt($plaintext)
    {
        if (!self::IsAvailable() || $plaintext === '') {
            return $plaintext;
        }

        $enc_key = self::DeriveKey('encrypt');
        $mac_key = self::DeriveKey('hmac');
        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            return $plaintext;
        }

        $hmac = hash_hmac('sha256', $iv . $ciphertext, $mac_key, true);

        return self::PREFIX . base64_encode($hmac . $iv . $ciphertext);
    }

    /**
     * Decrypt an encrypted value.
     * Returns the plaintext, or false on verification/decryption failure.
     * If the value is not encrypted (no prefix), returns it as-is for backwards compat.
     *
     * @param string $encoded
     * @return string|false
     */
    public static function Decrypt($encoded)
    {
        if (!self::IsEncrypted($encoded)) {
            return $encoded;
        }

        if (!self::IsAvailable()) {
            return false;
        }

        $data = base64_decode(substr($encoded, strlen(self::PREFIX)));
        if ($data === false || strlen($data) < 49) { // 32 hmac + 16 iv + 1 min ciphertext
            return false;
        }

        $hmac = substr($data, 0, 32);
        $iv = substr($data, 32, 16);
        $ciphertext = substr($data, 48);

        $mac_key = self::DeriveKey('hmac');
        $calc_hmac = hash_hmac('sha256', $iv . $ciphertext, $mac_key, true);

        if (!hash_equals($hmac, $calc_hmac)) {
            return false;
        }

        $enc_key = self::DeriveKey('encrypt');
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv);

        return $plaintext !== false ? $plaintext : false;
    }

    /**
     * Check if a value has the encryption prefix.
     *
     * @param string $value
     * @return bool
     */
    public static function IsEncrypted($value)
    {
        return is_string($value) && strpos($value, self::PREFIX) === 0;
    }
}
