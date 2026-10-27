<?php

namespace App\Services\Fly;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomTypeBlock;

/**
 * 日期维度的房量计算 —— 库存口径的唯一实现处（SQL 版见 StockSql，两处必须一致）。
 *
 * 口径：可订间数 = 该房型未停用的房间数 − 区间重叠的有效订单间数 − 区间内各晚停售间数的最大值。
 * 「停用」是房间自身的状态；「在住 / 打扫中」是此刻的客房状态，与未来某天无关，
 * 所以两者都算可售，未来的占用只由 bookings 的日期区间与 room_type_blocks 的停售计划决定。
 * 停售取 max 不取 sum：每晚一行，同一批房间连续停售多晚时对一次多晚查询只该扣一次。
 */
class AvailabilityService
{
    /**
     * 按房型 id 批量取「可售房间总数」。1 条 SQL。
     *
     * @return array [room_type_id => int]
     */
    public function sellableRoomTotals(array $roomTypeIds)
    {
        if (empty($roomTypeIds)) {
            return [];
        }

        $rows = Room::query()
            ->sellable()
            ->whereIn('room_type_id', $roomTypeIds)
            ->selectRaw('room_type_id, count(*) as agg_rooms')
            ->groupBy('room_type_id')
            ->get();

        return $this->toIdMap($rows, 'agg_rooms');
    }

    /**
     * 按房型 id 批量取「区间内已被占用的间数」。1 条 SQL。
     *
     * @return array [room_type_id => int]
     */
    public function occupiedByRange(array $roomTypeIds, $checkIn, $checkOut)
    {
        if (empty($roomTypeIds)) {
            return [];
        }

        $rows = Booking::query()
            ->active()
            ->overlapping($checkIn, $checkOut)
            ->whereIn('room_type_id', $roomTypeIds)
            ->selectRaw('room_type_id, sum(room_count) as agg_rooms')
            ->groupBy('room_type_id')
            ->get();

        return $this->toIdMap($rows, 'agg_rooms');
    }

    /**
     * 按房型 id 批量取「区间内各晚停售间数的最大值」。1 条 SQL。
     *
     * 半开区间约定同 Booking::scopeOverlapping：晚 ∈ [checkIn, checkOut)。
     * 取 max 不取 sum：同一批房间连续停售多晚，对一次多晚查询只该扣一次。
     *
     * @return array [room_type_id => int]
     */
    public function blockedByRange(array $roomTypeIds, $checkIn, $checkOut)
    {
        if (empty($roomTypeIds)) {
            return [];
        }

        $rows = RoomTypeBlock::query()
            ->whereIn('room_type_id', $roomTypeIds)
            ->between($checkIn, $checkOut)
            ->selectRaw('room_type_id, max(closed_rooms) as agg_rooms')
            ->groupBy('room_type_id')
            ->get();

        return $this->toIdMap($rows, 'agg_rooms');
    }

    /**
     * 一批酒店在某日期区间下的房量明细。3 条 SQL（房型本身由调用方带出）。
     *
     * @param  array  $roomTypes  RoomType 集合或数组，需含 id / hotel_id / price / capacity / bed_type
     * @return array  [hotel_id => ['types' => [房型载荷…], 'remaining_total' => int, 'min_price' => float|null]]
     */
    public function forRoomTypes($roomTypes, $checkIn, $checkOut)
    {
        $grouped = [];
        $ids = [];

        foreach ($roomTypes as $type) {
            $ids[] = (int) $type->id;
            $grouped[(int) $type->hotel_id][] = $type;
        }

        $ids = array_values(array_unique($ids));
        $totals   = $this->sellableRoomTotals($ids);
        $occupied = $this->occupiedByRange($ids, $checkIn, $checkOut);
        $blocked  = $this->blockedByRange($ids, $checkIn, $checkOut);

        $result = [];
        foreach ($grouped as $hotelId => $types) {
            $items = [];
            $remainTotal = 0;
            $minPrice = null;

            foreach ($types as $type) {
                $remaining = $this->remainingOf((int) $type->id, $totals, $occupied, $blocked);

                $items[] = [
                    'id'          => (int) $type->id,
                    'name'        => $type->name,
                    'price'       => (float) $type->price,
                    'price_text'  => number_format((float) $type->price, 0, '.', ''),
                    'capacity'    => (int) $type->capacity,
                    'bed_type'    => $type->bed_type,
                    'area'        => (int) $type->area,
                    'description' => $type->description,
                    'rooms_total' => isset($totals[(int) $type->id]) ? $totals[(int) $type->id] : 0,
                    'remaining'   => $remaining,
                    'sold_out'    => $remaining <= 0,
                ];

                $remainTotal += $remaining;

                // 起价只统计还能订得出的房型，售罄房型不能把起价拉低
                if ($remaining > 0) {
                    $minPrice = $minPrice === null ? (float) $type->price : min($minPrice, (float) $type->price);
                }
            }

            $result[$hotelId] = [
                'types'           => $items,
                'remaining_total' => $remainTotal,
                'min_price'       => $minPrice,
            ];
        }

        return $result;
    }

    /**
     * 单个房型在某区间的可订间数。写路径复核用，3 条 SQL。
     */
    public function remainingFor(RoomType $roomType, $checkIn, $checkOut)
    {
        $totals   = $this->sellableRoomTotals([$roomType->id]);
        $occupied = $this->occupiedByRange([$roomType->id], $checkIn, $checkOut);
        $blocked  = $this->blockedByRange([$roomType->id], $checkIn, $checkOut);

        return $this->remainingOf((int) $roomType->id, $totals, $occupied, $blocked);
    }

    /**
     * 给订单分配具体房间。**必须在 DB::transaction 内调用**，
     * MySQL 下走行锁；sqlite 下 lockForUpdate 是语法级 no-op（SQLiteGrammar::compileLock 返回空串），
     * 靠事务写串行 + PDO::ATTR_TIMEOUT 的 busy_timeout 兜底。
     *
     * @return array 房间 id 数组；数量不足时返回空数组（上层转业务失败）
     */
    public function allocateRooms(RoomType $roomType, $checkIn, $checkOut, $count)
    {
        $count = max(1, (int) $count);

        // 已占用的具体房间：一条订单可能占多间，room_id 只存主房间，
        // 其余在 room_ids（JSON）里，两个字段都要排除，否则会重复分配次房间。
        $taken = [];
        $orders = Booking::query()
            ->active()
            ->overlapping($checkIn, $checkOut)
            ->where('room_type_id', $roomType->id)
            ->get(['room_id', 'room_ids']);

        foreach ($orders as $order) {
            foreach ((array) $order->room_ids as $roomId) {
                if ($roomId) {
                    $taken[] = (int) $roomId;
                }
            }

            if ($order->room_id) {
                $taken[] = (int) $order->room_id;
            }
        }

        $taken = array_values(array_unique($taken));

        $query = Room::query()
            ->sellable()
            ->where('room_type_id', $roomType->id)
            ->orderBy('id');

        if (!empty($taken)) {
            $query->whereNotIn('id', $taken);
        }

        $rooms = $query->lockForUpdate()->limit($count)->pluck('id')->all();

        return count($rooms) === $count ? array_map('intval', $rooms) : [];
    }

    private function remainingOf($roomTypeId, array $totals, array $occupied, array $blocked = [])
    {
        $total   = isset($totals[$roomTypeId]) ? (int) $totals[$roomTypeId] : 0;
        $used    = isset($occupied[$roomTypeId]) ? (int) $occupied[$roomTypeId] : 0;
        $closed  = isset($blocked[$roomTypeId]) ? (int) $blocked[$roomTypeId] : 0;

        return max(0, $total - $used - $closed);
    }

    private function toIdMap($rows, $valueColumn)
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->room_type_id] = (int) $row->{$valueColumn};
        }

        return $map;
    }
}
