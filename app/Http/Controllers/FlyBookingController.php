<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Services\Fly\AvailabilityService;
use App\Services\Fly\BookingRejectedException;
use App\Services\Fly\HotelSearchService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 下单闭环（P5）：/fly/hotels/{hotel}/book 渲染表单，/fly/bookings 写订单。
 *
 * 全程 SSR 表单 POST + redirect，不发 JSON 写请求：C 端顾客不登录，但必须有
 * session + CSRF，所以两条路由都在 web.php 的 web 组里、且都不加 auth 中间件。
 *
 * 房量口径只走 AvailabilityService，本页展示的 remaining 与提交时复核的是同一函数；
 * 模板里绝不做减法。
 */
class FlyBookingController extends Controller
{
    private $search;
    private $availability;

    public function __construct(HotelSearchService $search, AvailabilityService $availability)
    {
        $this->search = $search;
        $this->availability = $availability;
    }

    public function create(Request $request, Hotel $hotel)
    {
        if ((int) $hotel->status !== Hotel::STATUS_OPEN) {
            abort(404);
        }

        $filters = $this->search->normalize($request->all());
        $detail = $this->search->roomTypesFor($hotel, $filters['check_in'], $filters['check_out']);
        $items = $detail['items'];

        return view('fly.book', [
            'hotel'     => $hotel,
            'filters'   => $filters,
            'card'      => $this->search->formatCard($hotel, [$hotel->id => $detail['agg']], 0, $filters),
            'roomTypes' => $items,
            'selected'  => $this->pickRoomType($items, $request->input('room_type_id')),
            'maxRooms'  => (int) config('fly.max_rooms'),
            // 每次渲染一个新 key：双击提交被唯一索引挡下，刷新页面又能重新下单
            'idempotencyKey' => bin2hex(random_bytes(16)),
        ]);
    }

    public function store(Request $request)
    {
        $now = Carbon::now(config('fly.timezone'));
        $today = $now->toDateString();
        $maxNights = (int) config('fly.max_nights');
        $maxRooms = (int) config('fly.max_rooms');
        $maxAhead = (int) config('fly.max_days_ahead');

        $validator = Validator::make($request->all(), [
            'hotel_id'         => ['required', 'integer'],
            'room_type_id'     => ['required', 'integer'],
            'check_in'         => ['required', 'date', 'after_or_equal:' . $today, 'before_or_equal:' . $now->copy()->addDays($maxAhead)->toDateString()],
            'check_out'        => ['required', 'date', 'after:check_in'],
            'room_count'       => ['required', 'integer', 'min:1', 'max:' . $maxRooms],
            'guest_name'       => ['required', 'string', 'max:30'],
            'guest_phone'      => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
            'remark'           => ['nullable', 'string', 'max:200'],
            'idempotency_key'  => ['required', 'string', 'max:64'],
        ], [
            'hotel_id.required'        => '缺少酒店信息，请回详情页重新选择',
            'room_type_id.required'    => '请选择房型',
            'check_in.required'        => '请选择入住日期',
            'check_in.date'            => '入住日期格式不正确',
            'check_in.after_or_equal'  => '入住日期不能早于今天（' . $today . '）',
            'check_in.before_or_equal' => '最多只能预订 ' . $maxAhead . ' 天内的房间',
            'check_out.required'       => '请选择离店日期',
            'check_out.date'           => '离店日期格式不正确',
            'check_out.after'          => '离店日期必须晚于入住日期',
            'room_count.required'      => '请选择预订间数',
            'room_count.integer'       => '预订间数必须是整数',
            'room_count.min'           => '至少预订 1 间',
            'room_count.max'           => '一次最多预订 ' . $maxRooms . ' 间',
            'guest_name.required'      => '请填写入住人姓名',
            'guest_name.max'           => '入住人姓名不能超过 30 个字',
            'guest_phone.required'     => '请填写手机号',
            'guest_phone.regex'        => '请输入 11 位大陆手机号',
            'remark.max'               => '备注不能超过 200 个字',
            'idempotency_key.required' => '表单已失效，请刷新页面重新提交',
        ]);

        // 晚数上限没法用 date 规则表达（要的是两字段之差），单独补一条
        $validator->after(function ($validator) use ($request, $maxNights) {
            $nights = Booking::nightsBetween($request->input('check_in'), $request->input('check_out'));
            if ($nights > $maxNights) {
                $validator->errors()->add('check_out', '一次最多预订 ' . $maxNights . ' 晚，请缩短入住天数');
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withInput()->withErrors($validator);
        }

        $input = $validator->validated();

        try {
            $booking = DB::transaction(function () use ($input) {
                return $this->createBooking($input);
            });

            return redirect()->to($this->resultUrl($booking))
                ->with('flyFlash', '预订成功！房量已为您保留，订单号 ' . $booking->order_no . '。');
        } catch (BookingRejectedException $e) {
            return redirect()->back()->withInput()->withErrors(['submit' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            // 唯一键冲突只可能是同一个 idempotency_key 撞进来的（双击 / 网络重试），
            // 回查到订单就当成功。限定 QueryException，别让代码里的 Error 也被当成「下单成功」。
            if ($e instanceof QueryException) {
                $existing = Booking::where('idempotency_key', $input['idempotency_key'])->first();

                if ($existing) {
                    return redirect()->to($this->resultUrl($existing));
                }
            }

            return redirect()->back()->withInput()
                ->withErrors(['submit' => '下单失败，请稍后重试；多次失败请联系客服 95010。']);
        }
    }

    /**
     * 事务体。所有失败一律抛 BookingRejectedException，由上层转回表单提示。
     */
    private function createBooking(array $input)
    {
        $hotel = Hotel::find($input['hotel_id']);

        if (!$hotel || (int) $hotel->status !== Hotel::STATUS_OPEN) {
            throw new BookingRejectedException('该酒店已暂停营业，暂时无法预订');
        }

        // 房型必须属于这家酒店：防篡改 room_type_id 跨酒店下单（同 RoomTypeController 的归属校验）
        $roomType = RoomType::query()
            ->where('id', $input['room_type_id'])
            ->where('hotel_id', $hotel->id)
            ->first();

        if (!$roomType) {
            throw new BookingRejectedException('所选房型不存在，请回详情页重新选择');
        }

        $checkIn = Booking::normalizeDate($input['check_in']);
        $checkOut = Booking::normalizeDate($input['check_out']);
        $count = (int) $input['room_count'];

        $remaining = $this->availability->remainingFor($roomType, $checkIn, $checkOut);

        if ($remaining < $count) {
            throw new BookingRejectedException($remaining > 0
                ? '该房型在所选日期仅剩 ' . $remaining . ' 间，请减少间数或更换日期'
                : '该房型已售罄，请更换日期或房型');
        }

        $rooms = $this->availability->allocateRooms($roomType, $checkIn, $checkOut, $count);

        if (count($rooms) < $count) {
            throw new BookingRejectedException('房量刚刚被订完，请刷新页面重试');
        }

        $nights = Booking::nightsBetween($checkIn, $checkOut);
        $price = (float) $roomType->price;

        return Booking::create([
            'order_no'        => $this->uniqueOrderNo(),
            'hotel_id'        => $hotel->id,
            'room_type_id'    => $roomType->id,
            'room_id'         => $rooms[0],
            'room_ids'        => $rooms,
            'check_in'        => $checkIn,
            'check_out'       => $checkOut,
            'nights'          => $nights,
            'room_count'      => $count,
            'price'           => $price,
            'total_amount'    => $price * $nights * $count,
            'guest_name'      => $input['guest_name'],
            'guest_phone'     => $input['guest_phone'],
            'remark'          => isset($input['remark']) ? $input['remark'] : null,
            'status'          => Booking::STATUS_BOOKED,
            'source'          => Booking::SOURCE_FLY,
            'idempotency_key' => $input['idempotency_key'],
        ]);
    }

    /**
     * 默认房型：URL 指定的房型（可能已被删或不属于本酒店）→ 回落到首个可售房型，
     * 全售罄时回落到第一个房型以展示售罄态，而不是白屏。
     */
    private function pickRoomType(array $items, $requestedId)
    {
        foreach ($items as $item) {
            if ((int) $item['id'] === (int) $requestedId) {
                return $item;
            }
        }

        foreach ($items as $item) {
            if (!$item['sold_out']) {
                return $item;
            }
        }

        return isset($items[0]) ? $items[0] : null;
    }

    /**
     * 订单号只 6 位随机数，同一天理论上会撞。先查再插（省一次异常），
     * 真撞了由唯一索引兜底 → 上层 catch 里转成「下单失败请重试」。
     */
    private function uniqueOrderNo()
    {
        for ($i = 0; $i < 5; $i++) {
            $orderNo = Booking::generateOrderNo();

            if (!Booking::where('order_no', $orderNo)->exists()) {
                return $orderNo;
            }
        }

        throw new BookingRejectedException('系统繁忙，请稍后重试');
    }

    /** 结果页 = 订单详情页，凭证是手机号（C 端订单没有 user_id） */
    private function resultUrl(Booking $booking)
    {
        return route('fly.orders.show', $booking) . '?phone=' . rawurlencode((string) $booking->guest_phone);
    }
}
