<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 房量计划（按晚停售）：一行 = 某房型在某晚停售 closed_rooms 间。
 * 只影响 C 端房量，不占具体房间号（维修/保留的房间由酒店自己掌握）。
 */
class RoomTypeBlock extends Model
{
    protected $fillable = [
        'room_type_id', // 房型
        'night_date',   // 停售的晚 Y-m-d
        'closed_rooms', // 停售间数
        'remark',       // 原因备注
    ];

    protected $casts = [
        'room_type_id' => 'integer',
        'closed_rooms' => 'integer',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /** 归一成 Y-m-d 字符串：与 bookings 的日期口径一致，纯字符串区间比较 */
    public function setNightDateAttribute($value)
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $this->attributes['night_date'] = $value;

            return;
        }

        $this->attributes['night_date'] = date('Y-m-d', strtotime((string) $value));
    }

    /**
     * 半开区间：晚 ∈ [checkIn, checkOut)。与 Booking::scopeOverlapping 同一套边界约定。
     */
    public function scopeBetween($query, $checkIn, $checkOut)
    {
        return $query
            ->where('night_date', '>=', $checkIn)
            ->where('night_date', '<', $checkOut);
    }
}
