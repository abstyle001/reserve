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

// 预约系统：查询队列状态（只读，前端 5s 轮询）、移动端排队查询 —— 公开只读
Route::get('/reserve/state', [ReserveController::class, 'state']);
// 移动端排队查询（只读）：按 key + serial_no 查自己的号码状态与前面人数
Route::get('/reserve/position', [ReserveController::class, 'position']);

// 注意：取号 / 放号（POST/DELETE /api/reserve）不在此文件。
// 它们要求 session 登录态，定义在 routes/web.php（同名 URI），见该文件注释。

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
