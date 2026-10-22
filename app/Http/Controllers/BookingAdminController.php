<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Http\Request;

/**
 * 后台订单管理（/bookings，需登录）。
 *
 * 只有列表 / 详情 / 取消三件事，没有新建：后台补录一单要同时做房间分配、房量复核和
 * 日期校验，那是第二条写路径，本期不在范围内（见方案 §3.3）。
 *
 * 取消与 C 端 FlyOrderController::cancel 用同一条条件 UPDATE，不是「读-改-写」：
 * sqlite 下 lockForUpdate 是语法级 no-op，只有 where status=已预订 的 UPDATE 本身是
 * 原子的，顾客和前台客服同时点也只有一人改到行。两处都必须走 Booking::STATUS_BOOKED
 * 这个条件，别在后台这边图省事直接 $booking->save()。
 */
class BookingAdminController extends Controller
{
    const PER_PAGE = 15;

    public function index(Request $request)
    {
        $status = $request->input('status', '');
        $keyword = trim((string) $request->input('q', ''));
        $hotelId = (int) $request->input('hotel_id', 0);

        $query = Booking::query()
            ->with(['hotel:id,name,city', 'roomType:id,name'])
            ->orderByDesc('id');

        if ($status !== '' && isset(Booking::$statusLabels[(int) $status])) {
            $query->where('status', (int) $status);
        }

        if ($hotelId > 0) {
            $query->forHotel($hotelId);
        }

        if ($keyword !== '') {
            $query->keyword($keyword);
        }

        $bookings = $query->paginate(self::PER_PAGE)->withQueryString();

        // 聚合一条 SQL 同时拿「占用房量的单数」和「这些单的成交额」，
        // 只用 COUNT / SUM / COALESCE：mysql（本地）与 sqlite（测试）都认。
        $agg = Booking::query()
            ->active()
            ->selectRaw('COUNT(*) AS agg_count, COALESCE(SUM(total_amount), 0) AS agg_amount')
            ->first();

        $stats = [
            'total'          => Booking::count(),
            'active'         => (int) $agg->agg_count,
            'cancelled'      => Booking::query()->where('status', Booking::STATUS_CANCELLED)->count(),
            'active_amount'  => (float) $agg->agg_amount,
        ];

        return view('bookings.index', [
            'bookings' => $bookings,
            'stats'    => $stats,
            'hotels'   => Hotel::orderBy('id')->get(['id', 'name', 'city']),
            'status'   => (string) $status,
            'keyword'  => $keyword,
            'hotelId'  => $hotelId,
        ]);
    }

    public function show(Booking $booking)
    {
        $booking->load(['hotel', 'roomType']);

        return view('bookings.show', [
            'booking' => $booking,
            'rooms'   => $this->roomRows($booking),
        ]);
    }

    public function cancel(Booking $booking)
    {
        $released = Booking::query()
            ->where('id', $booking->id)
            ->where('status', Booking::STATUS_BOOKED)
            ->update([
                'status'       => Booking::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

        if ($released === 0) {
            return back()->with('error', '该订单当前是「' . $booking->fresh()->status_label . '」，不能重复取消。');
        }

        return back()->with('success', '订单 ' . $booking->order_no . ' 已取消，占用的 ' . $booking->room_count . ' 间房量已释放。');
    }

    /**
     * 订单实际分配到的房间。room_ids 为空时返回空集合（老数据 / 未分配），
     * 不能把空数组塞进 whereIn —— MySQL 会生成 `in ()` 直接语法报错。
     */
    private function roomRows(Booking $booking)
    {
        $ids = array_values(array_filter((array) $booking->room_ids, function ($id) {
            return (int) $id > 0;
        }));

        if ($ids === []) {
            return collect();
        }

        return Room::query()
            ->whereIn('id', $ids)
            ->orderBy('room_no')
            ->get();
    }
}
