<?php

namespace App\Http\Controllers;

use App\Helpers\md5Helper;
use App\Helpers\curlHelper;
use Illuminate\Http\Request;

class VGController extends Controller
{
    /**
     * 註冊用戶
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function signUp(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.vg.api_url') . '/vgtransfer/sign-up';

        // 要加密的資料
        $cryptoData = array(
            'agent' => config('chacha.vg.agent'),
            'betlimit' => $request->input('betlimit'),
            'loginname' => $request->input('loginname') . config('chacha.vg.user_suffix'),
        );

        try {
            // 使用 MD5 加密資料
            $encryptedData = md5Helper::generateSignature($cryptoData);

            // API 請求參數
            $data = $cryptoData;
            $data['sign'] = $encryptedData;

            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
                'data' => $result->data,
                'TraceId' => $result->TraceId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 登入遊戲
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function signIn(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.vg.api_url') . '/vgtransfer/sign-in';

        $return_url = 'https://www.google.com';
        $token = '8850c89373135ad9b68603404b5e4a2e';

        // 要加密的資料
        $cryptoData = array(
            'agent' => config('chacha.vg.agent'),
            'language' => 'zh-TW',
            'loginname' => $request->input('loginname'),
            'return_url' => $return_url,
            'token' => $token,
        );

        try {
            // 使用 MD5 加密資料
            $encryptedData = md5Helper::generateSignature($cryptoData);

            // API 請求參數
            $data = $cryptoData;
            $data['sign'] = $encryptedData;

            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
                'data' => $result->data,
                'TraceId' => $result->TraceId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 轉帳功能
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function points(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.vg.api_url') . '/vgtransfer/points';

        // 隨機生成 SID
        $sid = time() . substr(strval(rand(10000, 19999)), 1, 4);

        // 要加密的資料
        $cryptoData = array(
            'agent' => config('chacha.vg.agent'),
            'loginname' => $request->input('loginname'),
            'amount' => $request->input('amount'),
            'sid' => $sid,
            'status' => $request->input('status')
        );

        try {
            // 使用 MD5 加密資料
            $encryptedData = md5Helper::generateSignature($cryptoData);

            // API 請求參數
            $data = $cryptoData;
            $data['sign'] = $encryptedData;

            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'balance' => $result->balance,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 餘額查詢
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function balance(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.vg.api_url') . '/vgtransfer/balance';

        // 要加密的資料
        $cryptoData = array(
            'agent' => config('chacha.vg.agent'),
            'username' => $request->input('username'),
        );

        try {
            // 使用 MD5 加密資料
            $encryptedData = md5Helper::generateSignature($cryptoData);

            // API 請求參數
            $data = $cryptoData;
            $data['sign'] = $encryptedData;

            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
                'balance' => $result->balance,
                'TraceId' => $result->TraceId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 轉帳紀錄
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function pointsLog(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.vg.api_url') . '/vgtransfer/log';

        // 要加密的資料
        $cryptoData = array(
            'agent' => config('chacha.vg.agent'),
            'starttime' => $request->input('starttime'),
            'endtime' => $request->input('endtime'),
            'page_num' => $request->input('page_num'),
            'page_size' => $request->input('page_size'),
            'status' => $request->input('status')
        );

        try {
            // 使用 MD5 加密資料
            $encryptedData = md5Helper::generateSignature($cryptoData);

            // API 請求參數
            $data = $cryptoData;
            $data['sign'] = $encryptedData;

            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
                'data' => $result->data,
                'TraceId' => $result->TraceId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 遊戲結果
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function betRecord(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.vg.api_url') . '/vgtransfer/bet/users';

        // 要加密的資料
        $cryptoData = array(
            'agent' => config('chacha.vg.agent'),
            'starttime' => $request->input('starttime'),
            'endtime' => $request->input('endtime'),
            'page_num' => $request->input('page_num'),
            'page_size' => $request->input('page_size'),
            'status' => $request->input('status')
        );

        try {
            // 使用 MD5 加密資料
            $encryptedData = md5Helper::generateSignature($cryptoData);

            // API 請求參數
            $data = $cryptoData;
            $data['sign'] = $encryptedData;

            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
                'data' => $result->data,
                'TraceId' => $result->TraceId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 玩家限紅
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function betLimit(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.vg.api_url') . '/vgtransfer/bet/limit';

        // 要加密的資料
        $cryptoData = array(
            'agent' => config('chacha.vg.agent'),
            'betlimit' => $request->input('betlimit'),
            'loginname' => $request->input('loginname')
        );

        try {
            // 使用 MD5 加密資料
            $encryptedData = md5Helper::generateSignature($cryptoData);

            // API 請求參數
            $data = $cryptoData;
            $data['sign'] = $encryptedData;

            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
                'TraceId' => $result->TraceId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 限紅列表
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function limitList()
    {
        // API 請求 URL
        $apiUrl = config('chacha.vg.api_url') . '/vgtransfer/bet/limit/list';

        // 要加密的資料
        $cryptoData = array(
            'agent' => config('chacha.vg.agent')
        );

        try {
            // 使用 MD5 加密資料
            $encryptedData = md5Helper::generateSignature($cryptoData);

            // API 請求參數
            $data = $cryptoData;
            $data['sign'] = $encryptedData;

            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
                'data' => $result->data,
                'TraceId' => $result->TraceId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 牌桌列表
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function tableList()
    {
        // API 請求 URL
        $apiUrl = config('chacha.vg.api_url') . '/vgtransfer/table/list';

        // 要加密的資料
        $cryptoData = array(
            'agent' => config('chacha.vg.agent')
        );

        try {
            // 使用 MD5 加密資料
            $encryptedData = md5Helper::generateSignature($cryptoData);

            // API 請求參數
            $data = $cryptoData;
            $data['sign'] = $encryptedData;

            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data);

            return response()->json([
                'code' => $result->code,
                'message' => $result->message,
                'data' => $result->data,
                'TraceId' => $result->TraceId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
