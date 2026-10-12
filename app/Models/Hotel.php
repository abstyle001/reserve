<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hotel extends Model
{
    const STATUS_OPEN = 1;   // 营业
    const STATUS_CLOSED = 0; // 停用

    public static $statusLabels = [
        self::STATUS_OPEN   => '营业',
        self::STATUS_CLOSED => '停用',
    ];

    protected $fillable = [
        'name',       // 酒店名称
        'address',    // 地址
        'phone',      // 联系电话
        'star_level', // 星级 1-5
        'status',     // 状态 1营业 0停用
        'remark',     // 备注
    ];

    protected $casts = [
        'star_level' => 'integer',
        'status'     => 'integer',
    ];

    public function roomTypes()
    {
        return $this->hasMany(RoomType::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function getStatusLabelAttribute()
    {
        return isset(self::$statusLabels[$this->status])
            ? self::$statusLabels[$this->status]
            : '未知';
    }
}
