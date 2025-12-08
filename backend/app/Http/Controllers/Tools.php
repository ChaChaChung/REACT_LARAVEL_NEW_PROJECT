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
        
        // 移除所有空白字符
        $publicKey = preg_replace('/\s+/', '', $publicKey);
        
        // 確保公鑰格式正確
        if (strpos($publicKey, '-----BEGIN') === false) {
            // 將 Base64 字符串分成每行 64 字符
            $formattedKey = chunk_split($publicKey, 64, "\n");
            $publicKey = "-----BEGIN PUBLIC KEY-----\n" . 
                         $formattedKey . 
                         "-----END PUBLIC KEY-----";
        }
        
        $key = openssl_pkey_get_public($publicKey);
        if (!$key) {
            $error = openssl_error_string();
            throw new Exception('Public key format error: ' . $error . "\nKey preview: " . substr($publicKey, 0, 100));
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
     * @param bool $enableChunking 是否啟用分塊加密（如果數據太大）
     * @return string Base64 編碼的加密資料
     * @throws Exception
     */
    public static function rsaEncrypt($data, $enableChunking = false)
    {
        $publicKey = self::getPublicKey();

        if (!$publicKey) {
            throw new Exception('Unable to load public key');
        }

        // 如果是陣列，轉換為 JSON（不包含多餘空格）
        if (is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // 檢查數據長度
        $keyDetails = openssl_pkey_get_details($publicKey);
        $maxLength = floor($keyDetails['bits'] / 8) - 11; // PKCS1 padding 需要 11 字節
        
        if (strlen($data) > $maxLength) {
            if ($enableChunking) {
                // 使用分塊加密
                return self::rsaEncryptChunked($data, $publicKey, $maxLength);
            } else {
                openssl_free_key($publicKey);
                throw new Exception("Data too large for RSA key. Data: " . strlen($data) . " bytes, Max: " . $maxLength . " bytes. Consider using enableChunking=true or request a larger RSA key (2048-bit).");
            }
        }

        $encrypted = '';
        // 明確指定使用 PKCS1 padding (OPENSSL_PKCS1_PADDING 是默認值)
        $result = openssl_public_encrypt($data, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING);

        openssl_free_key($publicKey);

        if (!$result) {
            $error = openssl_error_string();
            throw new Exception('Encryption failed: ' . $error);
        }

        return base64_encode($encrypted);
    }

    /**
     * 分塊加密（當數據太大時使用）
     * ⚠️ 注意：此方法需要對方 API 支持分塊解密
     * @param string $data 要加密的數據
     * @param \OpenSSLAsymmetricKey $publicKey 公鑰資源
     * @param int $maxLength 每塊的最大長度
     * @return string 格式：CHUNKED:{塊數}:{base64塊1}:{base64塊2}:...
     * @throws Exception
     */
    private static function rsaEncryptChunked($data, $publicKey, $maxLength)
    {
        $chunks = str_split($data, $maxLength);
        $encryptedChunks = [];
        
        foreach ($chunks as $chunk) {
            $encrypted = '';
            $result = openssl_public_encrypt($chunk, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING);
            if (!$result) {
                $error = openssl_error_string();
                throw new Exception('Chunk encryption failed: ' . $error);
            }

            $encryptedChunks[] = base64_encode($encrypted);
        }
        
        openssl_free_key($publicKey);
        
        // 返回格式：CHUNKED:塊數:塊1:塊2:...
        return 'CHUNKED:' . count($encryptedChunks) . ':' . implode(':', $encryptedChunks);
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
