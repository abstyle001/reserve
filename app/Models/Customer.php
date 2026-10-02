<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'queue_key', // 队列标识
        'serial_no', // 序列号
        'batch_no', // 批次号
        'is_finish' // 是否完成，0未完成 1完成
    ];
}
