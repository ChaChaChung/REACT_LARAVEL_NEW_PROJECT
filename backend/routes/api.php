<?php

use App\Http\Controllers\GashController;
use App\Http\Controllers\HNController;
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

Route::post('/requestHomeUrl', [HNController::class, 'requestHomeUrl']);
Route::post('/getGameRecordDetail', [HNController::class, 'getGameRecordDetail']);
Route::post('/getBalanceReportLogList', [HNController::class, 'getBalanceReportLogList']);
Route::post('/walletInAndOut', [HNController::class, 'walletInAndOut']);
Route::post('/getUserBlance', [HNController::class, 'getUserBlance']);

Route::prefix('gash')->group(function () {
    Route::post('/deposit', [GashController::class, 'deposit']);
    Route::post('/return', [GashController::class, 'return']);
    Route::post('/callback', [GashController::class, 'callback']);
    Route::get('/checkOrder/{coid}', [GashController::class, 'checkOrder']);
});