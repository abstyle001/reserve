<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// 预约取号 / 放号大屏控制台（单页），登录后可用
Route::get('/reserve', function () {
    return view('reserve.index', [
        'queueOptions' => config('reserve.queue_options'),
    ]);
})->middleware('auth')->name('reserve.index');

// 移动端排队查询（只读）：扫码或手动输入号码，查看自己前面还有几人
Route::get('/m', function () {
    return view('mobile.index', [
        'queueOptions' => config('reserve.queue_options'),
    ]);
})->name('mobile.index');

// 取号 / 放号（URI 仍是 /api/reserve，前端 axios baseURL=/api 无需改动）。
// 放在 web.php 而不是 api.php 的原因：写接口要求登录态，而 api 中间件组没有 session；
// 直接给 api.php 路由叠 ['web','auth'] 会踩 Laravel 中间件优先级排序的坑
// （App\Http\Middleware\EncryptCookies 是子类，不在 $middlewarePriority 名单里，
// StartSession 会被排到它前面，session 读不到解密后的 cookie，写接口永远 401）。
// web 组自带 session + CSRF，axios 会自动从 XSRF cookie 带 X-XSRF-TOKEN 头。
// 未登录 → 401 JSON（expectsJson 时）；CSRF 过期 → 419；前端识别后跳回登录页。
Route::post('/api/reserve', [\App\Http\Controllers\Api\ReserveController::class, 'add'])
    ->middleware('auth');
Route::delete('/api/reserve', [\App\Http\Controllers\Api\ReserveController::class, 'remove'])
    ->middleware('auth');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth'])->name('dashboard');

Route::get('/upload', function () {
    return '<form method="post" action="/upload" enctype="multipart/form-data">
        '.csrf_field().'
        <input type="file" name="file">
        <button type="submit">上传</button>
    </form>';
});

Route::post('/upload', function(Request $request){
    $path = $request->file('file')->store('uploads');
    return "文件上传成功，路径：".$path."<br> 访问地址：".Storage::url($path);
});

require __DIR__.'/auth.php';

