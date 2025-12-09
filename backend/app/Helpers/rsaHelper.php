<?php

namespace App\Helpers;

use Exception;

class rsaHelper
{
    /**
     * 格式化公鑰
     * @return string 格式化後的公鑰
     */
    private static function formatPublicKey()
    {
        // 取得公鑰
        $publicKey = config('chacha.hn.merchant_public_key');
        if (empty($publicKey)) {
            throw new Exception('Public key is not configured');
        }

        // 移除空白字符
        $publicKey = preg_replace('/\s+/', '', $publicKey);
        
        // 如果已經有 BEGIN/END 標記，直接返回
        if (strpos($publicKey, '-----BEGIN') !== false) {
            return $publicKey;
        }
        
        // 添加 BEGIN/END 標記
        $formattedKey = chunk_split($publicKey, 64, "\n");
        return "-----BEGIN PUBLIC KEY-----\n" . 
               $formattedKey . 
               "-----END PUBLIC KEY-----";
    }
    
    /**
     * 格式化私鑰
     * @return string 格式化後的私鑰
     */
    private static function formatPrivateKey()
    {
        // 取得私鑰
        $privateKey = config('chacha.hn.merchant_private_key');
        if (empty($privateKey)) {
            throw new Exception('Private key is not configured');
        }

        // 移除空白字符
        $privateKey = preg_replace('/\s+/', '', $privateKey);
        
        // 如果已經有 BEGIN/END 標記，直接返回
        if (strpos($privateKey, '-----BEGIN') !== false) {
            return $privateKey;
        }
        
        // 添加 BEGIN/END 標記
        $formattedKey = chunk_split($privateKey, 64, "\n");
        return "-----BEGIN RSA PRIVATE KEY-----\n" . 
               $formattedKey . 
               "-----END RSA PRIVATE KEY-----";
    }

    /**
     * 使用公鑰加密
     * @param array $data 要加密的資料
     * @return string Base64 編碼的加密資料
     * @throws Exception
     */
    public static function encrypt($data)
    {
        // 格式化公鑰
        $publicKey = self::formatPublicKey();
        
        // 載入公鑰
        $key = openssl_pkey_get_public($publicKey);
        if (!$key) {
            throw new Exception('無法載入公鑰: ' . openssl_error_string());
        }

        // 將要加密的資料轉換為 JSON
        $data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 取得金鑰位元數
        $keyDetails = openssl_pkey_get_details($key);
        $keySize = $keyDetails['bits'];

        // 計算單次加密最大長度
        // RSA (PKCS1 Padding) 可加密長度 = (金鑰位元數 / 8) - 11 bytes
        $maxEncryptSize = ($keySize / 8) - 11;

        $encryptedTotal = '';

        // 將資料切片
        $dataChunks = str_split($data, $maxEncryptSize);

        foreach ($dataChunks as $chunk) {
            $partialEncrypted = '';
            // 針對每一小片進行加密
            $result = openssl_public_encrypt($chunk, $partialEncrypted, $key, OPENSSL_PKCS1_PADDING);

            if (!$result) {
                openssl_free_key($key);
                throw new Exception('分段加密失敗: ' . openssl_error_string());
            }

            // 將加密後的二進位片段拼接起來
            $encryptedTotal .= $partialEncrypted;
        }

        openssl_free_key($key);
        
        // 最後統一轉 Base64
        return base64_encode($encryptedTotal);
    }
    
    /**
     * 使用私鑰解密
     * @param string $encryptedData Base64 編碼的加密資料
     * @return string 解密後的資料
     * @throws Exception
     */
    public static function decrypt($encryptedData)
    {
        // 格式化私鑰
        $privateKey = self::formatPrivateKey();
        
        // 載入私鑰
        $key = openssl_pkey_get_private($privateKey);
        if (!$key) {
            throw new Exception('無法載入私鑰: ' . openssl_error_string());
        }
        
        // 解密
        $decrypted = '';
        $encrypted = base64_decode($encryptedData);
        $result = openssl_private_decrypt($encrypted, $decrypted, $key, OPENSSL_PKCS1_PADDING);
        
        openssl_free_key($key);
        
        if (!$result) {
            throw new Exception('解密失敗: ' . openssl_error_string());
        }
        
        return $decrypted;
    }
    
    /**
     * 使用私鑰簽名
     * @param string $data 要簽名的數據
     * @param string $privateKey 私鑰（PEM 格式）
     * @return string Base64 編碼的簽名
     * @throws Exception
     */
    public static function sign($data, $privateKey)
    {
        // 格式化私鑰
        $privateKey = self::formatPrivateKey($privateKey);
        
        // 載入私鑰
        $key = openssl_pkey_get_private($privateKey);
        if (!$key) {
            throw new Exception('無法載入私鑰: ' . openssl_error_string());
        }
        
        // 簽名
        $signature = '';
        $result = openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
        
        openssl_free_key($key);
        
        if (!$result) {
            throw new Exception('簽名失敗: ' . openssl_error_string());
        }
        
        return base64_encode($signature);
    }
    
    /**
     * 使用公鑰驗證簽名
     * @param string $data 原始數據
     * @param string $signature Base64 編碼的簽名
     * @param string $publicKey 公鑰（PEM 格式）
     * @return bool 驗證結果
     * @throws Exception
     */
    public static function verify($data, $signature, $publicKey)
    {
        // 格式化公鑰
        $publicKey = self::formatPublicKey($publicKey);
        
        // 載入公鑰
        $key = openssl_pkey_get_public($publicKey);
        if (!$key) {
            throw new Exception('無法載入公鑰: ' . openssl_error_string());
        }
        
        // 驗證簽名
        $signature = base64_decode($signature);
        $result = openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256);
        
        openssl_free_key($key);
        
        if ($result === -1) {
            throw new Exception('驗證失敗: ' . openssl_error_string());
        }
        
        return $result === 1;
    }
}
