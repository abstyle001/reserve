<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * C 端订单查询与取消（只认订单号 + 手机号）。
 *
 * 归属凭证是 guest_phone，不是 Breeze 用户：bookings 表没有 user_id，
 * 顾客在 /fly 全程不登录。登录的后台账号反而不该在这里看到订单 —— 那是
 * route('dashboard') 的事。
 *
 * show 同时兼任下单成功后的结果页（PRG：POST 完跳 GET，可收藏、刷新不重复提交）。
 */
class FlyOrderController extends Controller
{
    /** 一个手机号最多列这么多条，防止拿别人的号爬 */
    const LIST_LIMIT = 50;

    public function index(Request $request)
    {
        $phone = trim((string) $request->input('phone', ''));

        // null = 还没查过（只渲染表单）；空集合 = 查过但没有
        $bookings = null;
        $errors = null;

        if ($phone !== '') {
            $validator = Validator::make(
                ['phone' => $phone],
                ['phone' => ['required', 'regex:/^1[3-9]\d{9}$/']],
                ['phone.regex' => '请输入 11 位大陆手机号']
            );

            if ($validator->fails()) {
                $errors = $validator->errors();
            } else {
                $bookings = Booking::query()
                    ->forPhone($phone)
                    ->with(['hotel:id,name,city,status', 'roomType:id,name'])
                    ->orderByDesc('id')
                    ->limit(self::LIST_LIMIT)
                    ->get();
            }
        }

        return view('fly.orders', [
            'phone'    => $phone,
            'bookings' => $bookings,
            'errors'   => $errors,
        ]);
    }

    public function show(Request $request, Booking $booking)
    {
        $phone = trim((string) $request->input('phone', ''));

        // 手机号不符一律 404：用 403 等于对外确认「这个订单号存在」
        if ($phone === '' || $phone !== (string) $booking->guest_phone) {
            abort(404);
        }

        return view('fly.order', [
            'booking'  => $booking,
            'hotel'    => $booking->hotel,
            'roomType' => $booking->roomType,
            'roomNos'  => $this->roomNos($booking),
        ]);
    }

    /**
     * 取消订单。POST + 表单里的 phone 双凭证，取消是软状态流转：
     * 行不删，scopeActive 自动把它剔出占用集合 → 房量立刻释放且历史可查。
     */
    public function cancel(Request $request, Booking $booking)
    {
        $phone = trim((string) $request->input('phone', ''));
        $target = route('fly.orders.show', $booking) . '?phone=' . rawurlencode($phone);

        if ($phone === '' || $phone !== (string) $booking->guest_phone) {
            abort(404);
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
