<?php

namespace App\Http\Controllers;

use App\Services\Fly\HotelSearchService;
use Illuminate\Http\Request;

/**
 * 云途旅行 C 端首页（/fly）。
 *
 * SSR 首屏由这里出数据，Alpine 只做局部刷新（resources/js/fly/）；
 * 关掉 JS 时下面的搜索表单、城市卡、分页全是真链接，功能不退化。
 */
class FlyHomeController extends Controller
{
    private $search;

    public function __construct(HotelSearchService $search)
    {
        $this->search = $search;
    }

    public function index(Request $request)
    {
        $result = $this->search->search($request->all());
        $filters = $result['facets'];
        $cities = $this->search->cities();

        // 注给 body[data-fly-config]，前端 store 用它做首屏水合，不必再发一次请求。
        // api 里的路径是 axios baseURL('/api') 之下的相对路径（与 reserve/mobile 同一约定），
        // 写成 '/api/fly/hotels' 会被拼成 '/api/api/fly/hotels'。
        // 只列 store 真正会打的接口：/api/fly/cities 与 /api/fly/bookings 是对外只读契约
        // （城市清单已随本响应给出，查单页是 SSR），不需要出现在页面配置里。
        $flyConfig = [
            'search'  => $result,
            'cities'  => $cities,
            'api'     => [
                'hotels' => '/fly/hotels',
            ],
        ];

        return view('fly.home', compact('result', 'filters', 'cities', 'flyConfig'));
    }
}
