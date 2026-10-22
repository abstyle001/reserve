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

// 云途旅行 C 端（/fly）：酒店预订搜索 + 酒店推荐 + 详情。
// 数据来自 HotelSearchService（SSR 首屏）与 /api/fly/*（局部刷新），两边同一口径。
// ⚠️ 只用 web 组、不加 auth：C 端顾客不登录。写接口（下单/取消）同样放这里，
//    因为 api 组没有 session；只读 GET 一律只在 api.php 注册一次，别在这儿重复。
// 不用 Route::prefix('fly') + get('/')：那样注册出来的 URI 是 "fly/"，匹配不到 /fly。
Route::get('/fly', [\App\Http\Controllers\FlyHomeController::class, 'index'])->name('fly.home');
Route::get('/fly/hotels/{hotel}', [\App\Http\Controllers\FlyHotelController::class, 'show'])->name('fly.hotels.show');

// 下单页 + 写接口：POST /fly/bookings 走 web 组拿 session + CSRF，但同样不加 auth —— 顾客不登录。
// 校验失败/房量不足一律 back() 回表单带中文提示，不返回 JSON。
Route::get('/fly/hotels/{hotel}/book', [\App\Http\Controllers\FlyBookingController::class, 'create'])->name('fly.book');
Route::post('/fly/bookings', [\App\Http\Controllers\FlyBookingController::class, 'store'])->name('fly.bookings.store');

// 订单：/fly/orders 手机号查单 + /fly/orders/{booking} 详情（兼任下单结果页）。
// 取消是 POST（不是 DELETE）：与后台全站表单风格一致，也让浏览器原生表单能直接提交。
Route::get('/fly/orders', [\App\Http\Controllers\FlyOrderController::class, 'index'])->name('fly.orders');
Route::get('/fly/orders/{booking}', [\App\Http\Controllers\FlyOrderController::class, 'show'])->name('fly.orders.show');
Route::post('/fly/orders/{booking}/cancel', [\App\Http\Controllers\FlyOrderController::class, 'cancel'])->name('fly.orders.cancel');

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

// 酒店管理（酒店 / 房型 / 房间），全部要求登录
Route::middleware('auth')->group(function () {
    Route::resource('hotels', \App\Http\Controllers\HotelController::class)->except(['show']);
    Route::resource('hotels.room-types', \App\Http\Controllers\RoomTypeController::class)->except(['show']);
    Route::resource('hotels.rooms', \App\Http\Controllers\RoomController::class)->except(['show']);

    // C 端预订订单：只读列表 + 取消。不用 Route::resource —— 这里没有增删改，
    // resource 会注册出 7 条指向不存在方法的路由，route:list 里全是坑。
    Route::get('bookings', [\App\Http\Controllers\BookingAdminController::class, 'index'])->name('bookings.index');
    Route::get('bookings/{booking}', [\App\Http\Controllers\BookingAdminController::class, 'show'])->name('bookings.show');
    Route::post('bookings/{booking}/cancel', [\App\Http\Controllers\BookingAdminController::class, 'cancel'])->name('bookings.cancel');
});

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

