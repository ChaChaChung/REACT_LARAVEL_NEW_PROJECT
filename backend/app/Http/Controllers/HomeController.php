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

        // 要加密的資料
        $cryptoData = array(
            'merchantCode' => config('chacha.hn.merchant_code'),
            'currencyCode' => $request->input('currencyCode'),
            'amount' => $request->input('amount'),
            'changeType' => $request->input('changeType'),
            'loginName' => $request->input('loginName'),
        );

        // 生成批次 ID
        $batchId = time() . rand(1000, 9999);
        $cryptoData['batchId'] = $batchId;

        try {
            // 使用 RSA 公鑰加密資料
            $encryptedData = Tools::rsaEncrypt($cryptoData);
            Log::alert(json_encode($encryptedData));

            // API 請求參數
            // $data = [
            //     'data' => $encryptedData,
            //     'merchantCode' => config('chacha.hn.merchant_code'),
            //     'lang' => 'zh-TW',
            // ];

            // // 發送 POST 請求
            // $result = Tools::curlPost($apiUrl, $data);

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
