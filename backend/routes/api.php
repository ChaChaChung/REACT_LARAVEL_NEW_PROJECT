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
    Route::post('/signUp', [FGController::class, 'signUp']);
    Route::post('/checkPlayerExists', [FGController::class, 'checkPlayerExists']);
});

Route::prefix('gash')->group(function () {
    Route::post('/deposit', [GashController::class, 'deposit']);
    Route::post('/return', [GashController::class, 'return']);
    Route::post('/callback', [GashController::class, 'callback']);
    Route::get('/checkOrder/{coid}', [GashController::class, 'checkOrder']);
});