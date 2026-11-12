<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Services\Fly\HotelSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * 云途旅行（/fly）只读 JSON 接口（cities / hotels / room-types 共 3 条）。
 *
 * 浏览公开：无 session、无锁、不开事务（与 reserve 的 state/position 同规则）。
 * 写操作（下单 / 取消）不放这里，走 web.php 的 Blade 表单 —— 见 AGENTS.md §5 的中间件排序坑。
 * 查单 JSON 已随「手机号即凭证」一起下线：订单归属登录用户，走 web.php 的 /fly/orders。
 *
 * 响应外壳与 ReserveController 逐字同构：{code, message, data, errors}
 * 0 成功 / 1 业务失败 / 422 校验失败 / 500 兜底。
 */
class FlyReadController extends Controller
{
    private $search;

    public function __construct(HotelSearchService $search)
    {
        $this->search = $search;
    }

    /**
     * GET /api/fly/cities — 热门目的地
     */
    public function cities(Request $request)
    {
        try {
            $data = ['items' => $this->search->cities()];
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 500,
                'message' => '查询城市失败：' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'code' => 0,
            'message' => '查询成功',
            'data' => $data,
        ]);
    }

    /**
     * GET /api/fly/hotels?city&check_in&check_out&q&sort&page
     */
    public function hotels(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'city'      => 'nullable|string|max:50',
                'q'         => 'nullable|string|max:50',
                'check_in'  => 'nullable|date_format:Y-m-d',
                'check_out' => 'nullable|date_format:Y-m-d',
                'sort'      => 'nullable|string|max:20',
                'page'      => 'nullable|integer|min:1',
            ],
            [
                'city.max'         => '城市不能超过 50 个字符',
                'q.max'            => '关键词不能超过 50 个字符',
                'check_in.date_format'  => '入住日期格式应为 2026-10-21',
                'check_out.date_format' => '离店日期格式应为 2026-10-21',
                'page.integer'     => '页码必须是数字',
                'page.min'         => '页码不能小于 1',
            ]
        );

        if ($validator->fails()) {
            return response()->json(
                [
                    'code' => 422,
                    'message' => '参数校验失败',
                    'errors' => $validator->errors(),
                ],
                422
            );
        }

        try {
            $data = $this->search->search($request->all());
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 500,
                'message' => '查询酒店失败：' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'code' => 0,
            'message' => '查询成功',
            'data' => $data,
        ]);
    }

    /**
     * GET /api/fly/hotels/{hotel}/room-types?check_in&check_out — 详情页房量
     */
    public function roomTypes(Request $request, Hotel $hotel)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'check_in'  => 'nullable|date_format:Y-m-d',
                'check_out' => 'nullable|date_format:Y-m-d',
            ],
            [
                'check_in.date_format'  => '入住日期格式应为 2026-10-21',
                'check_out.date_format' => '离店日期格式应为 2026-10-21',
            ]
        );

        if ($validator->fails()) {
            return response()->json(
                [
                    'code' => 422,
                    'message' => '参数校验失败',
                    'errors' => $validator->errors(),
                ],
                422
            );
        }

        // 下架酒店对 C 端不可见：模型绑定能查到，但内容不能出
        if ((int) $hotel->status !== Hotel::STATUS_OPEN) {
            return response()->json([
                'code' => 1,
                'message' => '酒店不存在或已下架',
            ]);
        }

        $filters = $this->search->normalize($request->all());

        try {
            $roomTypes = $this->search->roomTypesFor($hotel, $filters['check_in'], $filters['check_out']);

            $data = [
                'hotel'     => $this->search->formatCard($hotel, [$hotel->id => $roomTypes['agg']], 0, $filters),
                'check_in'  => $filters['check_in'],
                'check_out' => $filters['check_out'],
                'nights'    => $filters['nights'],
                'items'     => $roomTypes['items'],
            ];
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 500,
                'message' => '查询房型失败：' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'code' => 0,
            'message' => '查询成功',
            'data' => $data,
        ]);
    }
}
