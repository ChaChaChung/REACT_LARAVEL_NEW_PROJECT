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
     * 3.1 啟動遊戲
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function launchGame(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/launch_game';

        try {
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
    public function launchFreeGame(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/launch_free_game';

        try {
            // 要傳送的資料
            $data = array(
                'game_code' => $request->input('game_code'),
                'game_type' => 'h5',
                'language' => 'zh-cn',
                'ip' => CommonHelper::getIP(),
                // 'return_url' => $return_url
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
     * 3.3 獲取遊戲列表
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function gameList()
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/games/game_type/h5/language/zh-cn';

        try {
            // 要傳送的資料
            $data = array();

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

        try {
            // 要傳送的資料
            $data = array();

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

        try {
            // 判斷傳入的參數是 open_id 還是 member_code
            if ($request->input('open_id') !== null) {
                $apiUrl .= '/' . $request->input('open_id');
            } else {
                $apiUrl .= '/member_code/' . $request->input('member_code');
            }
    
            // 要傳送的資料
            $data = array();
    
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
     * 3.7 啟動大廳 (用不到)
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function launchLobby(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/launch_lobby';

        try {
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
     * 4.1 分頁採集數據
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function logByPage(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/agent/log_by_page';

        try {
            // hunter: 捕獵 chess: 棋牌 slot: 老虎機 arcade: 水果機
            $apiUrl .= '/gt/' . $request->input('gt');
    
            // 判斷傳入的參數是 page_key 還是 id
            if ($request->input('page_key') !== null) {
                $apiUrl .= '/page_key/' . $request->input('page_key');
            } else if ($request->input('id') !== null) {
                $apiUrl .= '/id/' . $request->input('id');
            }
    
            // 要傳送的資料
            $data = array();
    
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
     * 4.2 帶時間的分頁採集數據 (時間範圍不超過兩天)
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function logByPageWithTime(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/agent/log_by_page';

        try {
            // hunter: 捕獵 chess: 棋牌 slot: 老虎機 arcade: 水果機
            $apiUrl .= '/gt/' . $request->input('gt');
    
            // 判斷傳入的參數是 page_key 還是 id
            if ($request->input('page_key') !== null) {
                $apiUrl .= '/page_key/' . $request->input('page_key');
            } else if ($request->input('id') !== null) {
                $apiUrl .= '/id/' . $request->input('id');
            }
    
            // 判斷 start_time 和 end_time 是否同時存在
            if ($request->input('start_time') !== null && $request->input('end_time') === null) {
                throw new \Exception('End time is required');
            } else if ($request->input('start_time') === null && $request->input('end_time') !== null) {
                throw new \Exception('Start time is required');
            } else if ($request->input('start_time') !== null && $request->input('end_time') !== null) {
                // start_time 和 end_time 都存在，判斷 end_time 與 start_time 的時間差是否不超過兩天
                if ($request->input('end_time') - $request->input('start_time') > 172800) {
                    throw new \Exception('Time range must be less than two days');
                }
                // 將 start_time 和 end_time 加到 API 請求 URL 中
                $apiUrl .= '/start_time/' . $request->input('start_time') . '/end_time/' . $request->input('end_time');
            }
    
            // 要傳送的資料
            $data = array();

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
     * 4.3 v3_1 版分頁採集 chess (結構增加 total_bets)
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function logByPageTotalBets(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3_1/agent/log_by_page';

        try {
            // hunter: 捕獵 chess: 棋牌 slot: 老虎機 arcade: 水果機
            $apiUrl .= '/gt/' . $request->input('gt');
    
            // 判斷傳入的參數是 page_key 還是 id
            if ($request->input('page_key') !== null) {
                $apiUrl .= '/page_key/' . $request->input('page_key');
            } else if ($request->input('id') !== null) {
                $apiUrl .= '/id/' . $request->input('id');
            }
    
            // 判斷 start_time 和 end_time 是否同時存在
            if ($request->input('start_time') !== null && $request->input('end_time') === null) {
                throw new \Exception('End time is required');
            } else if ($request->input('start_time') === null && $request->input('end_time') !== null) {
                throw new \Exception('Start time is required');
            } else if ($request->input('start_time') !== null && $request->input('end_time') !== null) {
                // start_time 和 end_time 都存在，判斷 end_time 與 start_time 的時間差是否不超過兩天
                if ($request->input('end_time') - $request->input('start_time') > 172800) {
                    throw new \Exception('Time range must be less than two days');
                }
                // 將 start_time 和 end_time 加到 API 請求 URL 中
                $apiUrl .= '/start_time/' . $request->input('start_time') . '/end_time/' . $request->input('end_time');
            }
    
            // 要傳送的資料
            $data = array();

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
     * 4.4 分頁採集活動數據
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function logByPageActivity(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/agent/log_by_page/gt/activity';

        try {
            // 判斷傳入的參數是 page_key 還是 id
            if ($request->input('page_key') !== null) {
                $apiUrl .= '/page_key/' . $request->input('page_key');
            } else if ($request->input('id') !== null) {
                $apiUrl .= '/id/' . $request->input('id');
            }

            // 要傳送的資料
            $data = array();

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
     * 4.5 根據時間獲取遊戲總的紀錄數 (時間範圍不能超過一天)
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function logByCount(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/agent/log_by_count';

        try {
            // hunter: 捕獵 chess: 棋牌 slot: 老虎機 arcade: 水果機
            $apiUrl .= '/gt/' . $request->input('gt');

            // 判斷 start_time 和 end_time 是否同時存在
            if ($request->input('start_time') !== null && $request->input('end_time') === null) {
                throw new \Exception('End time is required');
            } else if ($request->input('start_time') === null && $request->input('end_time') !== null) {
                throw new \Exception('Start time is required');
            } else if ($request->input('start_time') === null && $request->input('end_time') === null) {
                throw new \Exception('Start time and end time are required');
            } else if ($request->input('start_time') !== null && $request->input('end_time') !== null) {
                // start_time 和 end_time 都存在，判斷 end_time 與 start_time 的時間差是否不超過一天
                if ($request->input('end_time') - $request->input('start_time') > 86400) {
                    throw new \Exception('Time range must be less than one day');
                }
                // 將 start_time 和 end_time 加到 API 請求 URL 中
                $apiUrl .= '/start_time/' . $request->input('start_time') . '/end_time/' . $request->input('end_time');
            }
    
            // 要傳送的資料
            $data = array();

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
     * 4.6 捕獵排行派彩
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function hunterRankingPayout(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/fish/player_rank';

        try {
            // 將 game_id 加到 API 請求 URL 中
            $apiUrl .= '/game_id/' . $request->input('game_id');
            
            // 判斷傳入的參數是 rank_type 還是 id
            if ($request->input('rank_type') !== null) {
                // 0: 代理帳號對應貨幣類型上週榜 [不區分代理] 1:代理上周榜 2: 代理帳號對應貨幣類型上週挑戰榜 [不區分代理] 3: 代理上周挑戰榜
                $rankTypeArray = array(0, 1, 2, 3);
    
                // 判斷 rank_type 是否在 rankTypeArray 中
                if (!in_array($request->input('rank_type'), $rankTypeArray)) {
                    throw new \Exception('rank_type must be 0, 1, 2 or 3');
                }
    
                // 將 rank_type 加到 API 請求 URL 中
                $apiUrl .= '/rank_type/' . $request->input('rank_type');
    
                // 如果 rank_type 為 1 或 3，則 get_agent 參數才有意義
                if ($request->input('rank_type') === 1 || $request->input('rank_type') === 3) {
                    if ($request->input('get_agent') !== null) {
                        // 0: 拉取代理帳號直属的代理榜 1: 允許總社拉取直属和下級代理的資料，下級代理帳號不允許該項操作
                        $getAgentArray = array(0, 1);
    
                        // 判斷 get_agent 是否在 getAgentArray 中
                        if (!in_array($request->input('get_agent'), $getAgentArray)) {
                            throw new \Exception('get_agent must be 0 or 1');
                        }
                        // 將 get_agent 加到 API 請求 URL 中
                        $apiUrl .= '/get_agent/' . $request->input('get_agent');
                    }
                }
            }
    
            // 要傳送的資料
            $data = array();
    
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
     * 4.8 獲取遊戲詳情頁面跳轉路徑
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function logDetailUrl(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/agent/log_detail_url';

        try {
            // hunter: 捕獵 chess: 棋牌 slot: 老虎機 arcade: 水果機
            $apiUrl .= '/gt/' . $request->input('gt');
            // 將 id 加到 API 請求 URL 中
            $apiUrl .= '/id/' . $request->input('id');
    
            // 判斷參數是否有傳入 member_code
            if ($request->input('member_code') !== null) {
                $apiUrl .= '/member_code/' . $request->input('member_code');
            }
    
            // 將語言加入 API 請求 URL
            $apiUrl .= '/language/zh-cn';
    
            // 要傳送的資料
            $data = array();

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
     * 4.9 獲取捕獵遊戲進出房間金額紀錄
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function logByPageHunterLogout(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/agent/log_by_page/gt/fish_logout';

        try {
            // 判斷傳入的參數是 page_key 還是 id
            if ($request->input('page_key') !== null) {
                $apiUrl .= '/page_key/' . $request->input('page_key');
            } else if ($request->input('id') !== null) {
                $apiUrl .= '/id/' . $request->input('id');
            }
    
            // 要傳送的資料
            $data = array();

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
     * 4.10 獲取玩家匯總數據
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function logByPagePlayerStat(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/agent/log_by_page/gt/player_stat';

        try {
            // 判斷傳入的參數是 page_key 還是 id
            if ($request->input('page_key') !== null) {
                $apiUrl .= '/page_key/' . $request->input('page_key');
            } else if ($request->input('id') !== null) {
                $apiUrl .= '/id/' . $request->input('id');
            }
    
            // 要傳送的資料
            $data = array();
    
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
     * 4.11 v3_1 版獲取玩家匯總數據
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function logByPagePlayerStatGt(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3_1/agent/log_by_page/gt/player_stat';

        try {
            // 判斷傳入的參數是 page_key 還是 id
            if ($request->input('page_key') !== null) {
                $apiUrl .= '/page_key/' . $request->input('page_key');
            } else if ($request->input('id') !== null) {
                $apiUrl .= '/id/' . $request->input('id');
            }
    
            // 要傳送的資料
            $data = array();

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
     * 4.12 獲取 JP 獎池
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function jackpot()
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/jp';

        try {
            // 要傳送的資料
            $data = array();

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
     * 4.13 拉取代理小時匯總數據 (時間範圍不超過兩天)
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function logByPageGtStat(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/agent/log_by_page';

        try {
            // agent_stat: 四類遊戲 agent_hunter_stat: 捕獵 agent_chess_stat: 棋牌 agent_slot_stat: 老虎機 agent_arcade_stat: 水果機
            $apiUrl .= '/gt/' . $request->input('gt');
    
            // 判斷傳入的參數是 page_key 還是 id
            if ($request->input('page_key') !== null) {
                $apiUrl .= '/page_key/' . $request->input('page_key');
            } else if ($request->input('id') !== null) {
                $apiUrl .= '/id/' . $request->input('id');
            }

            // 判斷 start_time 和 end_time 是否同時存在
            if ($request->input('start_time') !== null && $request->input('end_time') === null) {
                throw new \Exception('End time is required');
            } else if ($request->input('start_time') === null && $request->input('end_time') !== null) {
                throw new \Exception('Start time is required');
            } else if ($request->input('start_time') === null && $request->input('end_time') === null) {
                throw new \Exception('Start time and end time are required');
            } else if ($request->input('start_time') !== null && $request->input('end_time') !== null) {
                // start_time 和 end_time 都存在，判斷 end_time 與 start_time 的時間差是否不超過兩天
                if ($request->input('end_time') - $request->input('start_time') > 172800) {
                    throw new \Exception('Time range must be less than two days');
                }
                // 將 start_time 和 end_time 加到 API 請求 URL 中
                $apiUrl .= '/start_time/' . $request->input('start_time') . '/end_time/' . $request->input('end_time');
            }
    
            // 要傳送的資料
            $data = array();

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
     * 5.1 創建用戶
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function signUp(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/players';

        try {
            // 要傳送的資料
            $data = array(
                'member_code' => $request->input('member_code'),
                'password' => $request->input('password')
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
     * 5.2 刪除玩家會話
     * @param Request $request 請求物件
     * @return \Illuminate\Http\JsonResponse 回應物件
     */
    public function clearPlayerSession(Request $request)
    {
        // API 請求 URL
        $apiUrl = config('chacha.fg.api_url') . '/v3/player_sessions';

        try {
            // 判斷傳入的參數是 open_id 還是 member_code
            if ($request->input('open_id') !== null) {
                $apiUrl .= '/' . $request->input('open_id');
            } else {
                $apiUrl .= '/member_code/' . $request->input('member_code');
            }
    
            // 要傳送的資料
            $data = array();

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

        try {
            // 判斷傳入的參數是 open_id 還是 member_code
            if ($request->input('open_id') !== null) {
                $apiUrl .= '/' . $request->input('open_id');
            } else {
                $apiUrl .= '/member_code/' . $request->input('member_code');
            }

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

        try {
            // 判斷傳入的參數是 open_id 還是 member_code
            if ($request->input('open_id') !== null) {
                $apiUrl .= '/' . $request->input('open_id');
            } else {
                $apiUrl .= '/member_code/' . $request->input('member_code');
            }
    
            // 要傳送的資料
            $data = array();

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

        try {
            // 將 member_code 加到 API 請求 URL 中
            $apiUrl .= '/' . $request->input('member_code');
    
            // 要傳送的資料
            $data = array();

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

        try {
            // 將 external_transaction_id 加到 API 請求 URL 中
            $apiUrl .= '/' . $request->input('external_transaction_id');
    
            // 要傳送的資料
            $data = array();

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

        try {
            // 判斷傳入的參數是 open_id 還是 member_code
            if ($request->input('open_id') !== null) {
                $apiUrl .= '/' . $request->input('open_id');
            } else {
                $apiUrl .= '/member_code/' . $request->input('member_code');
            }
    
            // 要傳送的資料
            $data = array();

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
