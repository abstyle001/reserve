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

// 预约取号 / 放号大屏控制台（单页）
Route::get('/reserve', function () {
    return view('reserve.index', [
        'queueOptions' => config('reserve.queue_options'),
    ]);
})->name('reserve.index');

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
