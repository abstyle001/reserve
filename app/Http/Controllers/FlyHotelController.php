<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Services\Fly\HotelSearchService;
use Illuminate\Http\Request;

/**
 * 云途旅行酒店详情（/fly/hotels/{hotel}）。
 *
 * 房型 + 指定日期区间的可订间数由 AvailabilityService 统一口径计算，
 * 与搜索列表页、下单页完全一致。
 */
class FlyHotelController extends Controller
{
    private $search;

    public function __construct(HotelSearchService $search)
    {
        $this->search = $search;
    }

    public function show(Request $request, Hotel $hotel)
    {
        // 下架酒店对 C 端不存在：404 而不是「已售罄」，避免泄露仍在维护的信息
        if ((int) $hotel->status !== Hotel::STATUS_OPEN) {
            abort(404);
        }

        $filters = $this->search->normalize($request->all());
        $detail  = $this->search->roomTypesFor($hotel, $filters['check_in'], $filters['check_out']);
        $card    = $this->search->formatCard($hotel, [$hotel->id => $detail['agg']], 0, $filters);
        $cities  = $this->search->cities();

        // api 给的是 axios baseURL('/api') 之下的相对路径，只列 store 会打的接口；
        // cities 在详情页只是拿来算「看同城」链接，不重查。
        // today 必须给：本地夹日期（setDates）要知道「今天」是哪天，
        // 全局 app.timezone 是 UTC，直接 now() 会让中国用户早上 8 点前少一天。
        $flyConfig = [
            'hotel'  => $card,
            'detail' => [
                'check_in'  => $filters['check_in'],
                'check_out' => $filters['check_out'],
                'nights'    => $filters['nights'],
                'today'     => $filters['today'],
                'items'     => $detail['items'],
            ],
            'cities' => $cities,
            'api'    => [
                'roomTypes' => '/fly/hotels/' . (int) $hotel->id . '/room-types',
            ],
        ];

        return view('fly.hotel', [
            'hotel'     => $hotel,
            'filters'   => $filters,
            'card'      => $card,
            'cities'    => $cities,
            'roomTypes' => $detail['items'],
            'flyConfig' => $flyConfig,
        ]);
    }
}
