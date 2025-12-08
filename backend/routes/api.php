<?php

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

Route::post('/requestHomeUrl', 'HomeController@requestHomeUrl');
Route::post('/getGameRecordDetail', 'HomeController@getGameRecordDetail');
Route::post('/getBalanceReportLogList', 'HomeController@getBalanceReportLogList');
Route::post('/walletInAndOut', 'HomeController@walletInAndOut');

Route::prefix('gash')->group(function () {
    Route::post('/deposit', 'GashController@deposit');
    Route::post('/return', 'GashController@return');
    Route::post('/callback', 'GashController@callback');
    Route::get('/checkOrder/{coid}', 'GashController@checkOrder');
});