<?php

use App\Http\Controllers\GashController;
use App\Http\Controllers\HNController;
use App\Http\Controllers\VGController;
use App\Http\Controllers\FGController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('hn')->group(function () {
    Route::post('/requestHomeUrl', [HNController::class, 'requestHomeUrl']);
    Route::post('/getGameRecordDetail', [HNController::class, 'getGameRecordDetail']);
    Route::post('/getBalanceReportLogList', [HNController::class, 'getBalanceReportLogList']);
    Route::post('/walletInAndOut', [HNController::class, 'walletInAndOut']);
    Route::post('/getUserBlance', [HNController::class, 'getUserBlance']);
});

Route::prefix('vg')->group(function () {
    Route::post('/signUp', [VGController::class, 'signUp']);
    Route::post('/signIn', [VGController::class, 'signIn']);
    Route::post('/points', [VGController::class, 'points']);
    Route::post('/balance', [VGController::class, 'balance']);
    Route::post('/pointsLog', [VGController::class, 'pointsLog']);
    Route::post('/betRecord', [VGController::class, 'betRecord']);
    Route::post('/betLimit', [VGController::class, 'betLimit']);
    Route::get('/limitList', [VGController::class, 'limitList']);
    Route::get('/tableList', [VGController::class, 'tableList']);
});

Route::prefix('fg')->group(function () {
    Route::post('/launchGame', [FGController::class, 'launchGame']); // 3.1 啟動遊戲
    Route::post('/launchFreeGame', [FGController::class, 'launchFreeGame']); // 3.2 啟動試玩遊戲
    Route::post('/gameList', [FGController::class, 'gameList']); // 3.3 獲取遊戲列表
    Route::post('/appDownloadQRCode', [FGController::class, 'appDownloadQRCode']); // 3.4 APP 大廳下載二維碼
    Route::post('/appLoginQRCode', [FGController::class, 'appLoginQRCode']); // 3.5 APP 登入二維碼
    Route::post('/launchLobby', [FGController::class, 'launchLobby']); // 3.7 啟動大廳

    Route::post('/logByPage', [FGController::class, 'logByPage']); // 4.1 分頁採集數據
    Route::post('/logByPageWithTime', [FGController::class, 'logByPageWithTime']); // 4.2 帶時間的分頁採集數據 (時間範圍不超過兩天)
    Route::post('/logByPageTotalBets', [FGController::class, 'logByPageTotalBets']); // 4.3 v3_1 版分頁採集 chess (結構增加 total_bets)
    Route::post('/logByPageActivity', [FGController::class, 'logByPageActivity']); // 4.4 分頁採集活動數據
    Route::post('/getGameLogCount', [FGController::class, 'getGameLogCount']); // 4.5 根據時間獲取遊戲總的紀錄數 (時間範圍不能超過一天)
    Route::post('/hunterRankingPayout', [FGController::class, 'hunterRankingPayout']); // 4.6 捕獵排行派彩
    Route::post('/logDetailUrl', [FGController::class, 'logDetailUrl']); // 4.8 獲取遊戲詳情頁面跳轉路徑
    Route::post('/logByPageHunterLogout', [FGController::class, 'logByPageHunterLogout']); // 4.9 獲取捕獵遊戲進出房間金額紀錄
    Route::post('/logByPagePlayerStat', [FGController::class, 'logByPagePlayerStat']); // 4.10 獲取玩家匯總數據
    Route::post('/logByPagePlayerStatGt', [FGController::class, 'logByPagePlayerStatGt']); // 4.11 v3_1 版獲取玩家匯總數據
    Route::post('/jackpot', [FGController::class, 'jackpot']); // 4.12 獲取 JP 獎池
    Route::post('/logByPageGtStat', [FGController::class, 'logByPageGtStat']); // 4.13 拉取代理小時匯總數據 (時間範圍不超過兩天)

    Route::post('/signUp', [FGController::class, 'signUp']); // 5.1 註冊用戶
    Route::post('/clearPlayerSession', [FGController::class, 'clearPlayerSession']); // 5.2 刪除玩家會話
    Route::post('/points', [FGController::class, 'points']); // 5.3 存取玩家籌碼
    Route::post('/balance', [FGController::class, 'balance']); // 5.4 查詢玩家籌碼
    Route::post('/checkPlayerExists', [FGController::class, 'checkPlayerExists']); // 5.5 檢查用戶是否存在
    Route::post('/pointsLog', [FGController::class, 'pointsLog']); // 5.6 查詢玩家籌碼記錄
    Route::post('/checkUnsettled', [FGController::class, 'checkUnsettled']); // 5.7 查詢未結算籌碼
});

Route::prefix('gash')->group(function () {
    Route::post('/deposit', [GashController::class, 'deposit']);
    Route::post('/return', [GashController::class, 'return']);
    Route::post('/callback', [GashController::class, 'callback']);
    Route::get('/checkOrder/{coid}', [GashController::class, 'checkOrder']);
});