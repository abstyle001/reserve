<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SerialGenerator extends Model
{
    use HasFactory;

    protected $table = 'serial_generator';

    protected $fillable = [
        'queue_key',
        'batch_no',
        'current_no',
        'need_reset',
        'active_count'
    ];

    protected $casts = [
        'queue_key' => 'string',
        'batch_no'   => 'integer',
        'current_no' => 'integer',
        'need_reset' => 'integer',
        'active_count' => 'integer'
    ];
}
