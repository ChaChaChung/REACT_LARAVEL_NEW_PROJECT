<?php

namespace App\Tools\Gash;

/**
 * 加解密
 * 3DES encryption/decryption for GASH payment integration
 */
class Cryptography
{
    private $key = "";
    private $iv = "";

    /**
     * Constructor
     * 
     * @param string $key
     * @param string $iv
     * @throws \InvalidArgumentException
     */
    public function __construct($key, $iv)
    {
        if (empty($key) || empty($iv)) {
            throw new \InvalidArgumentException('Key and IV must not be empty');
        }
        $this->key = $key;
        $this->iv = $iv;
    }

    /**
     * Encrypt value using 3DES
     * 
     * @param string $value
     * @return string
     */
    public function encrypt($value)
    {
        $iv = base64_decode($this->iv);
        $key = base64_decode($this->key);
        $value = $this->PaddingPKCS7($value);
        $ret = openssl_encrypt($value, "DES-EDE3-CBC", $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING, $iv);
        $ret = base64_encode($ret);
        return $ret;
    }

    /**
     * Apply PKCS7 padding
     * 
     * @param string $data
     * @return string
     */
    private function PaddingPKCS7($data)
    {
        $block_size = 8;
        $padding_char = $block_size - (strlen($data) % $block_size);
        $data .= str_repeat(chr($padding_char), $padding_char);
        return $data;
    }
}
