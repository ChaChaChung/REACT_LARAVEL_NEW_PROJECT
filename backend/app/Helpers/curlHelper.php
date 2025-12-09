<?php

namespace App\Helpers;

class curlHelper
{
    // HTTP 請求方法
    const POST = 'POST';
    const GET = 'GET';

    /**
     * 取得 API 伺服器 URL
     */
    private static function getServer()
    {
        return config('chacha.hn.api_url');
    }

    /**
     * 發送 POST 請求
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
     * 發送 GET 請求
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
