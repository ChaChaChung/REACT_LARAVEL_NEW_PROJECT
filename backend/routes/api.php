<?php

use App\Http\Controllers\GashController;
use App\Http\Controllers\HomeController;
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

Route::post('/requestHomeUrl', [HomeController::class, 'requestHomeUrl']);
Route::post('/getGameRecordDetail', [HomeController::class, 'getGameRecordDetail']);
Route::post('/getBalanceReportLogList', [HomeController::class, 'getBalanceReportLogList']);
Route::post('/walletInAndOut', [HomeController::class, 'walletInAndOut']);

Route::prefix('gash')->group(function () {
    Route::post('/deposit', [GashController::class, 'deposit']);
    Route::post('/return', [GashController::class, 'return']);
    Route::post('/callback', [GashController::class, 'callback']);
    Route::get('/checkOrder/{coid}', [GashController::class, 'checkOrder']);
});