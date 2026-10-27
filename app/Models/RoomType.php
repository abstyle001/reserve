<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    protected $fillable = [
        'hotel_id',    // 所属酒店
        'name',        // 房型名称
        'price',       // 门市价
        'capacity',    // 可住人数
        'bed_type',    // 床型
        'area',        // 面积(㎡)
        'description', // 房型描述
    ];

    protected $casts = [
        'hotel_id' => 'integer',
        'price'    => 'decimal:2',
        'capacity' => 'integer',
        'area'     => 'integer',
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function roomTypeBlocks()
    {
        return $this->hasMany(RoomTypeBlock::class);
    }
}
