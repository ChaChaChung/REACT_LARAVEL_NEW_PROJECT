<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\curlHelper;
use App\Helpers\commonHelper;

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
     * 5.1 創建用戶
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
     * 5.2 刪除玩家會話
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function clearPlayerSession(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/player_sessions';

        // 判斷傳入的參數是 open_id 還是 member_code
        if ($request->input('open_id') !== null) {
            $apiUrl .= '/' . $request->input('open_id');
        } else {
            $apiUrl .= '/member_code/' . $request->input('member_code');
        }

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

    /**
     * 5.3 存取玩家籌碼
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function points(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/player_uchips';

        // 判斷傳入的參數是 open_id 還是 member_code
        if ($request->input('open_id') !== null) {
            $apiUrl .= '/' . $request->input('open_id');
        } else {
            $apiUrl .= '/member_code/' . $request->input('member_code');
        }

        try {
            // 驗證參數
            if ($request->input('amount') === null) {
                throw new \Exception('Amount is required');
            } else if ($request->input('amount') > 100000000) {
                throw new \Exception('Amount must be less than 100000000');
            }

            $externaltransactionid = time() . substr(strval(rand(10000, 19999)), 1, 4);

            // 要傳送的資料
            $data = array(
                'amount' => $request->input('amount'),
                'externaltransactionid' => $externaltransactionid,
            );
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
     * 5.4 查詢玩家籌碼
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function balance(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/player_chips';

        // 判斷傳入的參數是 open_id 還是 member_code
        if ($request->input('open_id') !== null) {
            $apiUrl .= '/' . $request->input('open_id');
        } else {
            $apiUrl .= '/member_code/' . $request->input('member_code');
        }

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

    /**
     * 5.5 檢查用戶是否存在
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

    /**
     * 5.6 驗證存取玩家籌碼狀態
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function pointsLog(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/player_uchips_check';
        $apiUrl .= '/' . $request->input('external_transaction_id');

        // 要傳送的資料
        $data = array();

        try {
            // 發送 POST 請求
            $result = curlHelper::curlPost($apiUrl, $data, $this->headers);

            // 如果 result 的 code 為 0，代表注單成功
            // 如果 result 的 code 為 208，代表注單正在處理
            // 如果 result 的 code 為 209，代表注單處理失敗
            // 如果 result 的 code 為 119，代表注單不存在

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 5.7 斷線重連查詢玩家所在遊戲是否已經結算
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function checkUnsettled(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/player/unsettled';

        // 判斷傳入的參數是 open_id 還是 member_code
        if ($request->input('open_id') !== null) {
            $apiUrl .= '/' . $request->input('open_id');
        } else {
            $apiUrl .= '/member_code/' . $request->input('member_code');
        }

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

    /**
     * 3.1 啟動遊戲
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function launchGame(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/launch_game';

        // 要傳送的資料
        $data = array(
            'game_code' => $request->input('game_code'),
            'game_type' => 'h5',
            'language' => 'zh-cn',
            'ip' => CommonHelper::getIP(),
            // 'return_url' => $return_url,
            // 'owner_id' => $owner_id,
        );

        // 判斷傳入的參數是 open_id 還是 member_code
        if ($request->input('open_id') !== null) {
            $data['open_id'] = $request->input('open_id');
        } else {
            $data['member_code'] = $request->input('member_code');
        }

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
     * 3.2 啟動試玩遊戲
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function launcFreehGame(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/launch_free_game';

        // 要傳送的資料
        $data = array(
            'game_code' => $request->input('game_code'),
            'game_type' => 'h5',
            'language' => 'zh-cn',
            'ip' => CommonHelper::getIP(),
            // 'return_url' => $return_url
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
     * 3.3 獲取遊戲列表
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function gameList()
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/games/game_type/h5/language/zh-cn';

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

    /**
     * 3.4 APP 大廳下載二維碼
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function appDownloadQRCode()
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/app/download';

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

    /**
     * 3.5 APP 登入二維碼
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function appLoginQRCode(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/app/get_token_qr';

        // 判斷傳入的參數是 open_id 還是 member_code
        if ($request->input('open_id') !== null) {
            $apiUrl .= '/' . $request->input('open_id');
        } else {
            $apiUrl .= '/member_code/' . $request->input('member_code');
        }

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

    /**
     * 3.7 啟動大廳
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function launchLobby(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/launch_lobby';

        // 要傳送的資料
        $data = array(
            'language' => 'zh-cn',
            'lobby_code' => 'chess',
            'ip' => CommonHelper::getIP(),
            // 'owner_id' => $owner_id
        );

        // 判斷傳入的參數是 open_id 還是 member_code
        if ($request->input('open_id') !== null) {
            $data['open_id'] = $request->input('open_id');
        } else {
            $data['member_code'] = $request->input('member_code');
        }

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
