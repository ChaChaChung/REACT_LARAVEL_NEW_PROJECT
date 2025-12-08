<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GashService;

class GashController extends Controller
{
    /**
     * @param GashService $gashService
     */
    public function __construct(private readonly GashService $gashService) {}

    // GASH 儲值
    public function deposit(Request $request)
    {
	    // $curUsername = $request->attributes->get('payload')['username'];
        $result = $this->gashService->deposit('player', $request);

        return response()->json($result);
    }

    // GASH 回傳資料
    public function return(Request $request)
    {
        $result = $this->gashService->callback($request);

        // 從 result 中提取 COID
        $trade_id = $result['data']['COID'] ?? '';

        // 轉址到儲值成功頁面
        return response()->json($trade_id);
    }

    // GASH 主動通知
    public function callback(Request $request)
    {
        $result = $this->gashService->getReturnData($request);

        return response($result, 200, ['Content-Type' => 'text/plain']);
    }

    // GASH 查單
    public function checkOrder($coid)
    {
        $result = $this->gashService->checkOrder($coid);

        return response()->json($result);
    }
}
