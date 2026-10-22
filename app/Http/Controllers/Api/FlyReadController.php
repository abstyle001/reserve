<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Hotel;
use App\Services\Fly\HotelSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * 云途旅行（/fly）只读 JSON 接口。
 *
 * 顾客不登录，所以这里全部公开：无 session、无锁、不开事务（与 reserve 的 state/position 同规则）。
 * 写操作（下单 / 取消）不放这里，走 web.php 的 Blade 表单 —— 见 AGENTS.md §5 的中间件排序坑。
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

    /**
     * GET /api/fly/bookings?phone[&order_no] — 凭手机号查单（C 端无登录态，手机号即凭证）
     */
    public function bookings(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'phone'     => 'required|string|max:20',
                'order_no'  => 'nullable|string|max:32',
            ],
            [
                'phone.required' => '请输入查询手机号',
                'phone.max'      => '手机号格式不正确',
                'order_no.max'   => '订单号不能超过 32 个字符',
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

        $phone = trim((string) $request->input('phone'));
        $orderNo = trim((string) $request->input('order_no', ''));

        try {
            $query = Booking::query()
                ->forPhone($phone)
                ->with(['hotel:id,name,city', 'roomType:id,name'])
                ->orderByDesc('id');

            if ($orderNo !== '') {
                $query->where('order_no', $orderNo);
            }

            $items = [];
            foreach ($query->limit(50)->get() as $booking) {
                $items[] = $this->formatBooking($booking);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 500,
                'message' => '查询订单失败：' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'code' => 0,
            'message' => '查询成功',
            'data' => ['items' => $items, 'phone' => $phone],
        ]);
    }

    private function formatBooking(Booking $booking)
    {
        return [
            'order_no'           => $booking->order_no,
            'status'             => (int) $booking->status,
            'status_label'       => $booking->status_label,
            'check_in'           => $booking->check_in,
            'check_out'          => $booking->check_out,
            'nights'             => (int) $booking->nights,
            'room_count'         => (int) $booking->room_count,
            'price'              => (float) $booking->price,
            'unit_price_text'    => $booking->unit_price_text,
            'total_amount'       => (float) $booking->total_amount,
            'total_amount_text'  => $booking->amount_text,
            'guest_name'         => $booking->guest_name_masked,
            'guest_phone'        => $booking->guest_phone_masked,
            'remark'             => $booking->remark,
            // 时间一律用模型里的 C 端时区访问器，别让 JSON 与 Blade 两份页面各说一个时间
            'created_at'         => $booking->created_at ? $booking->created_at->toDateTimeString() : null,
            'booked_at_text'     => $booking->booked_at_text,
            'cancelled_at_text'  => $booking->cancelled_at_text,
            'can_cancel'         => (int) $booking->status === Booking::STATUS_BOOKED,
            'hotel'              => [
                'id'         => $booking->hotel ? (int) $booking->hotel->id : (int) $booking->hotel_id,
                'name'       => $booking->hotel ? $booking->hotel->name : null,
                'city'       => $booking->hotel ? $booking->hotel->city : null,
                'detail_url' => '/fly/hotels/' . (int) $booking->hotel_id,
            ],
            'room_type'          => [
                'id'   => (int) $booking->room_type_id,
                'name' => $booking->roomType ? $booking->roomType->name : null,
            ],
        ];
    }
}
