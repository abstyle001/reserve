<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\Request;

/**
 * C 端订单查询与取消（登录归属）。
 *
 * 归属凭证是 bookings.user_id：/fly 下单、查单、取消全部强制登录，
 * 列表直接出当前用户的单，详情/取消校验归属。不符一律 404 而不是 403 ——
 * 403 等于对外确认「这个订单号存在」。
 * guest_phone 只是入住人联系方式，不再是任何凭证。
 *
 * show 同时兼任下单成功后的结果页（PRG：POST 完跳 GET，可收藏、刷新不重复提交）。
 */
class FlyOrderController extends Controller
{
    /** 单页最多列这么多条（一个账号的正常订单量远到不了） */
    const LIST_LIMIT = 50;

    public function index(Request $request)
    {
        $bookings = Booking::query()
            ->where('user_id', $request->user()->id)
            ->with(['hotel:id,name,city,status', 'roomType:id,name'])
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        return view('fly.orders', [
            'bookings' => $bookings,
        ]);
    }

    public function show(Request $request, Booking $booking)
    {
        $this->abortUnlessOwner($request, $booking);

        return view('fly.order', [
            'booking'  => $booking,
            'hotel'    => $booking->hotel,
            'roomType' => $booking->roomType,
            'roomNos'  => $this->roomNos($booking),
        ]);
    }

    /**
     * 取消订单。POST + 登录归属校验，取消是软状态流转：
     * 行不删，scopeActive 自动把它剔出占用集合 → 房量立刻释放且历史可查。
     * 自助取消受时限约束（入住前一日 config('fly.cancel_deadline_time')），逾期只能走后台人工取消。
     */
    public function cancel(Request $request, Booking $booking)
    {
        $this->abortUnlessOwner($request, $booking);

        $target = route('fly.orders.show', $booking);

        // 时限只对「已预订」的单拦；已取消/已入住仍由下面的条件 UPDATE 给「无需重复操作」，
        // 免得把状态问题误报成时限问题。
        if ($booking->status === Booking::STATUS_BOOKED && !$booking->can_cancel) {
            return redirect()->to($target)
                ->with('flyFlashError', '已过自助取消截止时间（' . $booking->cancel_deadline_text . '），请联系酒店 95010 处理。');
        }

        // 条件更新代替「读-改-写」：sqlite 下 lockForUpdate 是语法级 no-op，
        // 而 where status=已预订 的 UPDATE 本身是原子的，两人同时点也只有一人改到行。
        $released = Booking::query()
            ->where('id', $booking->id)
            ->where('status', Booking::STATUS_BOOKED)
            ->update([
                'status'       => Booking::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

        if ($released === 0) {
            return redirect()->to($target)
                ->with('flyFlashError', '该订单已取消或已办理入住，无需重复操作。');
        }

        return redirect()->to($target)
            ->with('flyFlash', '订单已取消，房量已释放。');
    }

    /**
     * 归属校验：不是本人的单（含 user_id 为 NULL 的历史单）一律 404，
     * 用 403 等于对外确认「这个订单号存在」。
     */
    private function abortUnlessOwner(Request $request, Booking $booking)
    {
        if ((int) $booking->user_id !== (int) $request->user()->id) {
            abort(404);
        }
    }

    /**
     * 分配到的房号，按房号排序。room_ids 为空（历史数据/后台补录）就不查，
     * 免得生成 `where id in ()`。
     */
    private function roomNos(Booking $booking)
    {
        $ids = array_values(array_filter((array) $booking->room_ids, function ($id) {
            return (int) $id > 0;
        }));

        if (empty($ids)) {
            return [];
        }

        return Room::query()
            ->whereIn('id', $ids)
            ->orderBy('room_no')
            ->pluck('room_no')
            ->all();
    }
}
