<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    const STATUS_VACANT   = 1; // 空闲
    const STATUS_OCCUPIED = 2; // 在住
    const STATUS_CLEANING = 3; // 打扫中
    const STATUS_DISABLED = 4; // 停用

    public static $statusLabels = [
        self::STATUS_VACANT   => '空闲',
        self::STATUS_OCCUPIED => '在住',
        self::STATUS_CLEANING => '打扫中',
        self::STATUS_DISABLED => '停用',
    ];

    protected $fillable = [
        'hotel_id',     // 所属酒店
        'room_type_id', // 房型
        'room_no',      // 房号
        'floor',        // 楼层
        'status',       // 状态
        'remark',       // 备注
    ];

    protected $casts = [
        'hotel_id'     => 'integer',
        'room_type_id' => 'integer',
        'floor'        => 'integer',
        'status'       => 'integer',
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * 可售房间：仅排除「停用」。
     *
     * 空闲/在住/打扫中都属于物理上能卖的房 —— status 表达的是这间房「此刻」的
     * 客房状态，而未来某天的占用由 bookings 的日期区间决定，两者不能混为一谈。
     * 这也是本系统需要日期维度订单表、而不能只靠 rooms.status 算库存的原因。
     */
    public function scopeSellable($query)
    {
        return $query->where('status', '!=', self::STATUS_DISABLED);
    }

    public function getStatusLabelAttribute()
    {
        return isset(self::$statusLabels[$this->status])
            ? self::$statusLabels[$this->status]
            : '未知';
    }
}
