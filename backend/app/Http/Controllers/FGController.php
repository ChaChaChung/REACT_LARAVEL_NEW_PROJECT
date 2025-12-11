<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\curlHelper;

class FGController extends Controller
{
    private $headers;

    public function __construct()
    {
        $this->headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            "merchantname: " . config('chacha.fg.merchantname'),
            "merchantcode: " . config('chacha.fg.merchantcode'),
        ];
    }

    /**
     * 註冊用戶
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function signUp(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/players';

        // 要傳送的資料
        $data = array(
            'member_code' => $request->input('member_code'),
            'password' => $request->input('password')
        );

        try {
            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data, $this->headers);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 檢查用戶是否存在
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function checkPlayerExists(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/player_names';
        $apiUrl .= '/' . $request->input('member_code');

        // 要傳送的資料
        $data = array();

        try {
            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data, $this->headers);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
