<?php

namespace App\Http\Controllers;

use App\Helpers\curlHelper;
use App\Helpers\rsaHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HNController extends Controller
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
            $encryptedData = rsaHelper::encrypt($cryptoData);

            // API 請求參數
            $data = [
                'data' => $encryptedData,
                'merchantCode' => config('chacha.hn.merchant_code'),
                'lang' => 'zh-TW',
            ];

            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
                'data' => $result->data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
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
            $result = curlHelper::curlPost($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
                'data' => $result->data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
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
            $result = curlHelper::curlPost($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
                'data' => $result->data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
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
            'batchId' => (string) time() . substr(strval(rand(10000, 19999)), 1, 4),
            'currencyCode' => $request->input('currencyCode'),
            'amount' => (string) $request->input('amount'),
            'changeType' => (string) $request->input('changeType'),  // 1 轉入 2 轉出
            'loginName' => $request->input('loginName'),
        );

        try {
            // 使用 RSA 公鑰加密資料
            $encryptedData = rsaHelper::encrypt($cryptoData);

            // API 請求參數
            $data = [
                'data' => $encryptedData,
                'merchantCode' => config('chacha.hn.merchant_code'),
            ];

            // 發送 GET 請求
            $result = curlHelper::curlGet($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得用戶餘額
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function getUserBlance(Request $request)
    {
        // API 請求 URL
        $apiUrl = '/merchantToApi/getUserBlance';

        // 要加密的資料
        $cryptoData = array(
            'merchantCode' => config('chacha.hn.merchant_code'),
            'loginName' => $request->input('loginName'),
        );

        try {
            // 使用 RSA 公鑰加密資料
            $encryptedData = rsaHelper::encrypt($cryptoData);

            // API 請求參數
            $data = [
                'data' => $encryptedData,
                'merchantCode' => config('chacha.hn.merchant_code'),
            ];

            // 發送 GET 請求
            $result = curlHelper::curlGet($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
                'data' => $result->data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
