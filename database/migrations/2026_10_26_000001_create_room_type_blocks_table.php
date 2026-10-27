<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 房量计划（按晚停售）：一行 = 某房型在某晚（night_date）额外停售 closed_rooms 间。
 *
 * 与 bookings 的分工：
 *   - bookings 是「客人订单」，占掉的是未来某区间的间数；
 *   - room_type_blocks 是「后台计划」，酒店把某晚的若干间留作维修 / 团房 / 保留，
 *     不给 C 端订。
 *
 * 库存口径（改这条注释时必须同步 StockSql::remaining() 与 AvailabilityService）：
 *   某房型可订数 = 非停用房间总数 − 区间重叠有效订单间数 − 区间内各晚停售间数的最大值
 *
 * 为什么取「最大值」而不是求和：停售是按晚计的，同一间房连续停 3 晚对一次 3 晚的
 * 订单只挡住 1 间；对 [in, out) 区间取 max(closed_rooms) 是「整段全被停售」的保守下界，
 * 与订单侧 Σ room_count 的保守口径同一风格。单晚查询（默认搜索）则是精确值。
 *
 * 半开区间约定同 bookings：night_date ∈ [check_in, check_out)。
 * night_date 存 Y-m-d 字符串，与 bookings 一样只走纯字符串区间比较，不用 whereDate()。
 */
class CreateRoomTypeBlocksTable extends Migration
{
    public function up()
    {
        Schema::create('room_type_blocks', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('room_type_id')->comment('库存计算单位，同 bookings');
            $table->string('night_date', 10)->comment('停售的晚，Y-m-d，属半开区间 [check_in, check_out)');
            $table->unsignedTinyInteger('closed_rooms')->comment('该晚停售间数，1-255');
            $table->string('remark', 500)->nullable()->comment('停售原因：维修 / 团房保留 等');

            $table->timestamps();

            // 同一房型同一晚只有一行：后台区间表单靠 upsert 覆盖，不产生重复行
            $table->unique(['room_type_id', 'night_date'], 'room_type_blocks_night_unique');
            $table->index('night_date', 'room_type_blocks_night_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('room_type_blocks');
    }
}
