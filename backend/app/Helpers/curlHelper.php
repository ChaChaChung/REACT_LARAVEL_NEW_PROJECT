<?php

namespace App\Helpers;

class curlHelper
{
    // HTTP 請求方法
    const POST = 'POST';
    const GET = 'GET';

    /**
     * 發送 POST 請求
     * @param string $url 請求的 URL
     * @param array $data 發送的資料
     * @return object 回應的物件
     */
    public static function curlPost($url, $data)
    {
        // 初始化 cURL
        $curl = curl_init();

        // 轉換資料為 JSON
        $jsonData = is_array($data) ? json_encode($data) : $data;

        // 預設 headers
        $headers = ['Content-Type: application/json'];

        // 設定 cURL 選項
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

        // 執行請求
        $response = curl_exec($curl);

        // 將回應轉換為 JSON
        $response = json_decode($response);

        // 關閉 cURL 連線
        curl_close($curl);

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

        // 初始化 cURL
        $curl = curl_init();

        // 設定 cURL 選項
        curl_setopt($curl, CURLOPT_URL, $url . '?' . $queryString);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        // 執行請求
        $response = curl_exec($curl);

        // 將回應轉換為 JSON
        $response = json_decode($response);

        // 關閉 cURL 連線
        curl_close($curl);

        return $response;
    }
}
