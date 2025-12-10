<?php

namespace App\Helpers;

class md5Helper
{
    /**
     * 生成簽名
     * @param array $params 參數陣列（不包含 sign）
     * @return string 簽名字串
     */
    public static function generateSignature($params)
    {
        // 移除空值
        $params = array_filter($params, function($value) {
            return $value !== null && $value !== '';
        });

        // 按照鍵名排序（字母順序）
        ksort($params);

        // 組合參數字串（只取值，不含 key）
        $stringToBeSigned = '';
        foreach ($params as $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $stringToBeSigned .= $value;
        }

        // 取得 API Key
        $apiKey = config('chacha.vg.api_key');

        // 將 API Key 加在字串最後面
        $stringToBeSigned .= $apiKey;

        return md5($stringToBeSigned);
    }

    /**
     * 驗證簽名
     * @param array $params 參數陣列
     * @param string $signature 要驗證的簽名
     * @param string|null $apiKey API 密鑰（可選）
     * @return bool 簽名是否正確
     */
    public static function verifySignature($params, $signature, $apiKey = null)
    {
        $generatedSignature = self::generateSignature($params, $apiKey);
        return $generatedSignature === $signature;
    }
}
