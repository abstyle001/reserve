<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomTypeBlock;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * 房量计划（按晚停售）：后台给房型设置「未来某晚停售 N 间」，
 * C 端房量口径随之扣减 —— 这是后台与 /fly 日期筛选联动的唯一入口。
 *
 * 口径：可订间数 = 非停用房间数 − 重叠有效订单间数 − 区间内各晚停售间数的最大值。
 * 表结构是一晚一行（room_type_blocks），表单的日期区间在 store 里展开成每晚一行。
 */
class RoomInventoryController extends Controller
{
    /** 展示窗口与表单区间的最长晚数 */
    const MAX_NIGHTS = 62;

    /** 展示窗口默认晚数（今天 + 13 晚） */
    const DEFAULT_WINDOW_NIGHTS = 14;

    public function index(Request $request, Hotel $hotel, RoomType $roomType)
    {
        $this->checkBelongsToHotel($hotel, $roomType);

        $today = Carbon::now(config('fly.timezone'))->startOfDay();
        $start = $this->parseWindowStart($request->input('start'), $today);

        $sellable = Room::query()
            ->sellable()
            ->where('room_type_id', $roomType->id)
            ->count();

        $nights = $this->nightRows($roomType, $start, self::DEFAULT_WINDOW_NIGHTS, $today, $sellable);

        $prev = $start->copy()->subDays(self::DEFAULT_WINDOW_NIGHTS)->gte($today)
            ? $start->copy()->subDays(self::DEFAULT_WINDOW_NIGHTS)->toDateString()
            : null;
        $next = $start->copy()->addDays(self::DEFAULT_WINDOW_NIGHTS)->toDateString();

        $formStart = $start->toDateString();
        $formEnd   = $start->copy()->addDays(self::DEFAULT_WINDOW_NIGHTS - 1)->toDateString();

        return view('room-types.inventory', compact(
            'hotel', 'roomType', 'nights', 'start', 'prev', 'next', 'formStart', 'formEnd', 'sellable'
        ));
    }

    public function store(Request $request, Hotel $hotel, RoomType $roomType)
    {
        $this->checkBelongsToHotel($hotel, $roomType);

        $data = $this->validateData($request);

        $start = Carbon::parse($data['start_date'], config('fly.timezone'))->startOfDay();
        $end   = Carbon::parse($data['end_date'], config('fly.timezone'))->startOfDay();

        $nights = 0;
        for ($cursor = $start->copy(); $cursor->lt($end); $cursor->addDay()) {
            // upsert：同一天重复设置就是覆盖，不需要先查后写
            RoomTypeBlock::updateOrCreate(
                [
                    'room_type_id' => $roomType->id,
                    'night_date'   => $cursor->toDateString(),
                ],
                [
                    'closed_rooms' => (int) $data['closed_rooms'],
                    'remark'       => isset($data['remark']) ? $data['remark'] : null,
                ]
            );
            $nights++;
        }

        return redirect()->route('hotels.room-types.inventory', [$hotel, $roomType, 'start' => $start->toDateString()])
            ->with('success', '已设置 ' . $nights . ' 晚的停售计划');
    }

    public function destroy(Hotel $hotel, RoomType $roomType, RoomTypeBlock $block)
    {
        $this->checkBelongsToHotel($hotel, $roomType);

        if ((int) $block->room_type_id !== (int) $roomType->id) {
            abort(404);
        }

        $block->delete();

        return back()->with('success', '已恢复 ' . $block->night_date . ' 的停售间数');
    }

    /**
     * 每晚一行：已订间数（重叠有效订单）/ 停售间数 / 可订间数 / 备注。
     * 2 条 SQL（订单 + 停售），在 PHP 里把订单区间展开到每一晚（窗口 ≤ 62 晚，可接受）；
     * 可售房间总数由调用方带出。
     */
    private function nightRows(RoomType $roomType, Carbon $start, $nights, Carbon $today, $sellable)
    {
        $end = $start->copy()->addDays($nights);

        $orders = Booking::query()
            ->active()
            ->where('room_type_id', $roomType->id)
            ->where('check_in', '<', $end->toDateString())
            ->where('check_out', '>', $start->toDateString())
            ->get(['check_in', 'check_out', 'room_count']);

        $blockMap = [];
        $blocks = RoomTypeBlock::query()
            ->where('room_type_id', $roomType->id)
            ->between($start->toDateString(), $end->toDateString())
            ->get();
        foreach ($blocks as $block) {
            $blockMap[$block->night_date] = $block;
        }

        $rows = [];
        $weekdays = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
        for ($cursor = $start->copy(); $cursor->lt($end); $cursor->addDay()) {
            $date = $cursor->toDateString();

            $occupied = 0;
            foreach ($orders as $order) {
                // 半开区间：晚 ∈ [check_in, check_out)
                if ($order->check_in <= $date && $order->check_out > $date) {
                    $occupied += (int) $order->room_count;
                }
            }

            $closed = isset($blockMap[$date]) ? (int) $blockMap[$date]->closed_rooms : 0;
            $remaining = max(0, $sellable - $occupied - $closed);

            $rows[] = [
                'date'      => $date,
                'weekday'   => $weekdays[(int) $cursor->dayOfWeek],
                'is_today'  => $date === $today->toDateString(),
                'occupied'  => $occupied,
                'closed'    => $closed,
                'remaining' => $remaining,
                'block'     => isset($blockMap[$date]) ? $blockMap[$date] : null,
            ];
        }

        return $rows;
    }

    private function parseWindowStart($input, Carbon $today)
    {
        if (is_string($input) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
            $parsed = Carbon::parse($input, config('fly.timezone'))->startOfDay();
            if ($parsed->gte($today)) {
                return $parsed;
            }
        }

        return $today->copy();
    }

    private function validateData(Request $request)
    {
        $today = Carbon::now(config('fly.timezone'))->toDateString();

        $validator = Validator::make($request->all(), [
            'start_date'   => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $today],
            'end_date'     => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'closed_rooms' => 'required|integer|min:1|max:255',
            'remark'       => 'nullable|string|max:500',
        ], [
            'start_date.required'        => '开始日期不能为空',
            'start_date.date_format'     => '开始日期格式必须是 YYYY-MM-DD',
            'start_date.after_or_equal'  => '开始日期不能早于今天',
            'end_date.required'          => '结束日期不能为空',
            'end_date.date_format'       => '结束日期格式必须是 YYYY-MM-DD',
            'end_date.after_or_equal'    => '结束日期不能早于开始日期',
            'closed_rooms.required'      => '停售间数不能为空',
            'closed_rooms.integer'       => '停售间数必须是整数',
            'closed_rooms.min'           => '停售间数最少为 1',
            'closed_rooms.max'           => '停售间数不能超过 255',
            'remark.max'                 => '备注不能超过 500 个字符',
        ]);

        $validator->after(function ($validator) {
            if (!$validator->errors()->hasAny(['start_date', 'end_date'])) {
                $start = Carbon::parse($validator->attributes()['start_date']);
                $end   = Carbon::parse($validator->attributes()['end_date']);
                if ($end->diffInDays($start) > self::MAX_NIGHTS) {
                    $validator->errors()->add('end_date', '一次最多设置 ' . self::MAX_NIGHTS . ' 晚，请拆成多段提交');
                }
            }
        });

        return $validator->validate();
    }

    private function checkBelongsToHotel(Hotel $hotel, RoomType $roomType)
    {
        if ((int) $roomType->hotel_id !== (int) $hotel->id) {
            abort(404);
        }
    }
}
