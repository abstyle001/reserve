<?php

namespace App\Services\Fly;

use App\Models\Booking;
use App\Models\Room;
use InvalidArgumentException;

/**
 * 「某房型在某日期区间还剩几间」的 SQL 表达式 —— 全项目唯一出处。
 *
 * 库存口径被三处调用（搜索列表 / 酒店详情 / 下单写接口）。若各写一遍重叠条件，
 * 将来改状态机必漏改，所以集中在这里。
 *
 * 只用 COUNT / SUM / COALESCE 与 'Y-m-d' 字符串区间比较：MySQL 与 SQLite 通用，
 * 不用 DATEDIFF / strftime / IFNULL，也不用 whereDate()（sqlite 会把列包进函数，索引失效）。
 */
class StockSql
{
    /**
     * 允许出现在表达式里的房型主键引用 —— 全部是本类内部拼出的字面量。
     * $typeRef 是拼进 SQL 的、不是绑定的，所以必须白名单校验。
     */
    const TYPE_REFS = ['room_types.id', 'rt2.id'];

    /** hotels 表名同样是拼进 SQL 的字面量，不接受外部传入 */
    const HOTEL_TABLE = 'hotels';

    /**
     * 可订间数表达式 + 绑定。
     *
     * @param  string $checkIn  Y-m-d
     * @param  string $checkOut Y-m-d
     * @param  string $typeRef  白名单内的房型主键引用
     * @return array  [sql, bindings]
     */
    public static function remaining($checkIn, $checkOut, $typeRef = 'room_types.id')
    {
        if (!in_array($typeRef, self::TYPE_REFS, true)) {
            throw new InvalidArgumentException('非法的房型主键引用：' . $typeRef);
        }

        $disabled = (int) Room::STATUS_DISABLED;
        $active   = implode(', ', Booking::activeStatuses());

        // 可售房间数：只排除「停用」—— 在住/打扫中是「此刻」的客房状态，
        // 未来某天的占用由 bookings 的日期区间决定，两者不能混。
        $sql = '(select count(*) from rooms'
            . ' where rooms.room_type_id = ' . $typeRef
            . ' and rooms.status <> ' . $disabled . ')'
            . ' - coalesce((select sum(bookings.room_count) from bookings'
            . ' where bookings.room_type_id = ' . $typeRef
            . ' and bookings.status in (' . $active . ')'
            . ' and bookings.check_in < ?'
            . ' and bookings.check_out > ?), 0)';

        // 半开区间重叠：已存在的入住 < 新离店 且 已存在的离店 > 新入住
        return [$sql, [$checkOut, $checkIn]];
    }

    /**
     * 该酒店「可订房型」中的最低单价，作为卡片起价。无可订房型时为 NULL。
     *
     * 返回的是裸子查询，调用方要自己括起来：selectRaw("hotels.*, ($sql) as min_price", $bind)
     */
    public static function minSellablePrice($checkIn, $checkOut)
    {
        $part = self::remaining($checkIn, $checkOut, 'rt2.id');

        $sql = 'select min(rt2.price) from room_types rt2'
            . ' where rt2.hotel_id = ' . self::HOTEL_TABLE . '.id and (' . $part[0] . ') > 0';

        return [$sql, $part[1]];
    }
}
