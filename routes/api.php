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

Route::prefix('v1/external')->middleware('absen.api.auth')->group(function () {
    Route::get('/health', [\App\Http\Controllers\Api\AbsenSyncController::class, 'healthCheck']);
    Route::post('/register-from-absen', [\App\Http\Controllers\Api\AbsenSyncController::class, 'registerFromAbsen']);
});

