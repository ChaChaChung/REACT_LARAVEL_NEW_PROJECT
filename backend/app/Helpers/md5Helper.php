<?php

namespace App\Helpers;

class md5Helper
{
    /**
     * 生成 MD5 簽名
     * @param array $params 參數陣列
     * @return string 簽名字串
     */
    public static function encrypt($params)
    {
        // 將參數按照 Key 字母順序排列
        ksort($params);

        // 要加密的字串
        $stringToBeEncrypt = '';

        // 參數陣列執行迴圈
        foreach ($params as $value) {
            // 組合參數字串
            $stringToBeEncrypt .= $value;
        }

        // 取得 API Key
        $apiKey = config('chacha.vg.api_key');

        // 將 API Key 加在字串最後面
        $stringToBeEncrypt .= $apiKey;

        return md5($stringToBeEncrypt);
    }

    /**
     * 驗證簽名
     * @param array $params 參數陣列
     * @param string $signature 要驗證的簽名
     * @return bool 簽名是否正確
     */
    public static function verifySignature($params, $signature)
    {
        // 生成 MD5 簽名
        $generatedSignature = self::encrypt($params);

        return $generatedSignature === $signature;
    }
}
