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
        
        // 如果已經有 BEGIN/END 標記，直接回傳
        if (strpos($publicKey, '-----BEGIN')) {
            return $publicKey;
        }
        
        // 加入 BEGIN/END 標記
        $formattedKey = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($publicKey, 64, "\n") . "-----END PUBLIC KEY-----";

        return $formattedKey;
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

        // 初始化加密後的資料
        $encryptedTotal = '';

        // 將資料切片
        $dataChunks = str_split($data, $maxEncryptSize);

        // 將切片後的資料執行迴圈，針對每一小片進行加密
        foreach ($dataChunks as $chunk) {
            // 初始化切片後的加密資料
            $partialEncrypted = '';

            // 針對每一小片進行加密
            $result = openssl_public_encrypt($chunk, $partialEncrypted, $key, OPENSSL_PKCS1_PADDING);

            // 判斷如果加密失敗，拋出例外
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
}
