<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Exception;

class Tools extends Controller
{
    // HTTP 請求方法
    const POST = 'POST';
    const GET = 'GET';

    /**
     * 取得公鑰
     */
    private static function getPublicKey()
    {
        $publicKey = config('chacha.hn.merchant_public_key');
        if (empty($publicKey)) {
            throw new Exception('Public key is not configured');
        }
        
        // 確保公鑰格式正確
        if (strpos($publicKey, '-----BEGIN PUBLIC KEY-----') === false) {
            $publicKey = "-----BEGIN PUBLIC KEY-----\n" . 
                         chunk_split($publicKey, 64, "\n") . 
                         "-----END PUBLIC KEY-----";
        }
        
        $key = openssl_pkey_get_public($publicKey);
        if (!$key) {
            throw new Exception('Public key format error: ' . openssl_error_string());
        }
        
        return $key;
    }

    /**
     * 取得私鑰
     */
    private static function getPrivateKey()
    {
        $privateKey = config('chacha.hn.merchant_private_key');
        if (empty($privateKey)) {
            throw new Exception('Private key is not configured');
        }
        
        // 確保私鑰格式正確
        if (strpos($privateKey, '-----BEGIN') === false) {
            $privateKey = "-----BEGIN RSA PRIVATE KEY-----\n" . 
                          chunk_split($privateKey, 64, "\n") . 
                          "-----END RSA PRIVATE KEY-----";
        }
        
        $key = openssl_pkey_get_private($privateKey);
        if (!$key) {
            throw new Exception('Private key format error: ' . openssl_error_string());
        }
        
        return $key;
    }

    /**
     * 使用公鑰加密資料
     * @param string|array $data 要加密的資料
     * @return string Base64 編碼的加密資料
     * @throws Exception
     */
    public static function rsaEncrypt($data)
    {
        $publicKey = self::getPublicKey();

        if (!$publicKey) {
            throw new Exception('Unable to load public key');
        }

        // 如果是陣列，轉換為 JSON
        if (is_array($data)) {
            $data = json_encode($data);
        }

        $encrypted = '';
        $result = openssl_public_encrypt($data, $encrypted, $publicKey);

        openssl_free_key($publicKey);

        if (!$result) {
            throw new Exception('Encryption failed: ' . openssl_error_string());
        }

        return base64_encode($encrypted);
    }

    /**
     * 使用私鑰解密資料
     * @param string $encryptedData Base64 編碼的加密資料
     * @param bool $returnArray 是否返回陣列
     * @return string|array 解密後的資料
     * @throws Exception
     */
    public static function rsaDecrypt($encryptedData, $returnArray = false)
    {
        $privateKey = self::getPrivateKey();

        if (!$privateKey) {
            throw new Exception('Unable to load private key');
        }

        $encryptedData = base64_decode($encryptedData);
        $decrypted = '';
        $result = openssl_private_decrypt($encryptedData, $decrypted, $privateKey);

        openssl_free_key($privateKey);

        if (!$result) {
            throw new Exception('Decryption failed: ' . openssl_error_string());
        }

        if ($returnArray) {
            return json_decode($decrypted, true);
        }

        return $decrypted;
    }

    /**
     * 取得 API 伺服器 URL
     */
    private static function getServer()
    {
        return config('chacha.hn.api_url');
    }

    /**
     * 發送 POST 請求 (使用 cURL)
     * @param string $url 請求的 URL
     * @param array $data 發送的資料
     * @param int $timeout 超時時間（秒，預設 30）
     * @return object 回應的物件
     */
    public static function curlPost($url, $data, $timeout = 30)
    {
        $ch = curl_init();

        // 轉換資料為 JSON
        $jsonData = is_array($data) ? json_encode($data) : $data;

        // 預設 headers
        $headers = ['Content-Type: application/json'];

        // 設定 cURL 選項
        curl_setopt($ch, CURLOPT_URL, self::getServer() . $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // 執行請求
        $response = curl_exec($ch);

        $response = json_decode($response);

        curl_close($ch);

        // 如果 $response 是 null 或其他類型，創建一個新的物件
        if (!isset($response) || empty($response)) {
            $temp = new \stdClass();
            $temp->response = $response;
            $response = $temp;
        }

        return $response;
    }

    /**
     * 發送 GET 請求 (使用 cURL)
     * @param string $url 請求的 URL
     * @param array $data 發送的資料
     * @return object 回應的物件
     */
    public static function curlGet($url, $data)
    {
        // 將 data 轉換為 URL 查詢參數
        $queryString = http_build_query($data);
        $fullUrl = self::getServer() . $url . '?' . $queryString;
        $curl = curl_init($fullUrl);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($curl);

        $response = json_decode($response);
        curl_close($curl);

        return $response;
    }
}
