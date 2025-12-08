<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HomeController extends Controller
{
    /**
     * 取得登入長連接
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function requestHomeUrl(Request $request)
    {
        // API 請求 URL
        $apiUrl = '/thirdApi/requestHomeUrl';

        // 要加密的資料
        $cryptoData = array(
            'merchantCode' => config('chacha.hn.merchant_code'),
            'userName' => $request->input('userName'),
            'password' => $request->input('password'),
            'loginOrigin' => $request->input('loginOrigin'),
        );

        try {
            // 使用 RSA 公鑰加密資料
            $encryptedData = Tools::rsaEncrypt($cryptoData);

            // API 請求參數
            $data = [
                'data' => $encryptedData,
                'merchantCode' => config('chacha.hn.merchant_code'),
                'lang' => 'zh-TW',
            ];

            // 發送 POST 請求
            $result = Tools::curlPost($apiUrl, $data);

            return response()->json([
                'message' => $result->message,
                'data' => $result->data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Encryption failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得遊戲紀錄詳情
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function getGameRecordDetail(Request $request)
    {
        // API 請求 URL
        $apiUrl = '/merchantReport/getAllOrder';

        try {
            // API 請求參數
            $data = [
                'merchantCode' => config('chacha.hn.merchant_code'),
                'startTime' => $request->input('startTime'),   // YYYY-MM-DD HH:MM:SS
                'endTime' => $request->input('endTime'),       // YYYY-MM-DD HH:MM:SS
                'pageNumber' => $request->input('pageNumber'), // 頁碼，預設 1
                'pageSize' => $request->input('pageSize'),     // 每頁筆數，預設 10
            ];

            // 發送 POST 請求
            $result = Tools::curlPost($apiUrl, $data);

            return response()->json([
                'message' => $result->message,
                'data' => $result->data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Encryption failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得帳變紀錄
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function getBalanceReportLogList(Request $request)
    {
        // API 請求 URL
        $apiUrl = '/merchantReport/getBalanceReportLogList';

        try {
            // API 請求參數
            $data = [
                'merchantCode' => config('chacha.hn.merchant_code'),
                'startTime' => $request->input('startTime'),   // YYYY-MM-DD HH:MM:SS
                'endTime' => $request->input('endTime'),       // YYYY-MM-DD HH:MM:SS
                'pageNumber' => $request->input('pageNumber'), // 頁碼，預設 1
                'pageSize' => $request->input('pageSize'),     // 每頁筆數，預設 10
            ];

            // 發送 POST 請求
            $result = Tools::curlPost($apiUrl, $data);

            return response()->json([
                'message' => $result->message,
                'data' => $result->data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Encryption failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 轉入/轉出
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function walletInAndOut(Request $request)
    {
        // API 請求 URL
        $apiUrl = '/merchantToApi/walletInAndOut';

        // 生成批次 ID（使用最短的格式 - 時間戳後 5 位 = 5 字符）
        // 注意：如果同一秒內有大量請求，可能會重複，建議改用數據庫序列號
        $batchId = substr(time(), -5);

        // 要加密的資料（使用最短的字段值）
        $cryptoData = array(
            'merchantCode' => config('chacha.hn.merchant_code'),
            'currencyCode' => $request->input('currencyCode'),
            'amount' => (string)$request->input('amount'),  // 確保是字符串
            'changeType' => (string)$request->input('changeType'),  // 1 或 2
            'loginName' => $request->input('loginName'),
            'batchId' => $batchId,
        );
        
        // 檢查數據大小並警告
        $jsonData = json_encode($cryptoData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            // 調試：檢查數據大小
            $jsonData = json_encode($cryptoData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            // 使用 RSA 公鑰加密資料
            $encryptedData = Tools::rsaEncrypt($cryptoData);
            // API 請求參數
            $data = [
                'data' => $encryptedData,
                'merchantCode' => config('chacha.hn.merchant_code'),
            ];

            // // 發送 POST 請求
            $result = Tools::curlGet($apiUrl, $data);
            \Log::alert('result => ' . json_encode($result));

            return response()->json([
                // 'message' => $result->message,
                // 'data' => $result->data,
                'message' => 'success',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Encryption failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
