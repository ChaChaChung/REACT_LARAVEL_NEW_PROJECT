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