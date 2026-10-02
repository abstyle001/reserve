<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ReserveController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// 健康检查（公开访问，无需鉴权）
Route::get('/health', [HealthController::class, 'check']);

// Post：列表（公开访问，无需鉴权）
Route::get('/posts', [PostController::class, 'index']);

// Post：创建（公开访问，无需鉴权）
Route::post('/posts', [PostController::class, 'create']);

Route::post('/reserve', [ReserveController::class, 'add']);
Route::delete('/reserve', [ReserveController::class, 'remove']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
