<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateBookingsTable extends Migration
{
    /**
     * 日期区间订单表：一行 = 一次「某房型在 [check_in, check_out) 订 room_count 间」。
     *
     * 库存口径：
     *   某房型可订数 = 该房型非停用房间总数 − 与查询区间重叠的「有效订单」room_count 之和
     *
     * 半开区间约定（本表最重要的规则）：入住当天算一晚、离店当天不算。
     * 于是「A 的 check_out == B 的 check_in」天然不冲突，边界不需要 +1 天特例。
     * 重叠判定统一走 Booking::scopeOverlapping()，全项目不得再写第二处。
     */
    public function up()
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            // 对外订单号：用户凭 order_no + guest_phone 查单/取消（C 端顾客不登录，无 user_id）
            $table->string('order_no', 32)->unique('bookings_order_no_unique');

            $table->unsignedBigInteger('hotel_id')->comment('冗余：按酒店筛选/统计不用 join 房型');
            $table->unsignedBigInteger('room_type_id')->comment('库存计算单位');
            $table->unsignedBigInteger('room_id')->nullable()->comment('主房间，下单时分配；将来核销用');
            $table->text('room_ids')->nullable()->comment('本次占用的全部房间 id，JSON 数组');

            // 一律存 Y-m-d，由 Booking 的 setCheckInAttribute/setCheckOutAttribute 修改器强制归一。
            // 绝不能改用 date cast：Eloquent 会写成 'Y-m-d H:i:s'，让 check_out > :in 的边界算错。
            $table->date('check_in')->comment('入住日期 Y-m-d');
            $table->date('check_out')->comment('离店日期 Y-m-d，必须晚于 check_in');
            $table->unsignedTinyInteger('nights')->comment('晚数快照：冗余存，避免在 SQL 层做日期差（sqlite/mysql 语法不同）');

            $table->unsignedTinyInteger('room_count')->default(1)->comment('间数');
            $table->decimal('price', 10, 2)->comment('下单时房型单价快照，房型后续改价不影响历史订单');
            $table->decimal('total_amount', 10, 2)->comment('金额快照 = price * nights * room_count');

            $table->string('guest_name', 50)->comment('联系人姓名');
            $table->string('guest_phone', 20)->comment('联系人手机号，查单/取消凭证');
            $table->string('remark', 500)->nullable()->comment('客人备注');

            // 状态机：1已预订 →(预留核销)→ 3已入住 → 4已退房；1/3 → 2已取消。
            // 本期只写入 1 和 2，但占用统计认 (1,3)，这样将来加入住核销时
            // 不改库存口径、不改查询，只多一次状态流转。
            $table->unsignedTinyInteger('status')->default(1)->comment('1已预订 2已取消 3已入住 4已退房');

            $table->string('source', 20)->default('fly')->comment('来源：fly 前台 / admin 后台补录');

            // 幂等键：下单页渲染时生成、hidden 带回。唯一索引挡住「双击提交 / 刷新重提」。
            // MySQL 与 SQLite 的 UNIQUE 索引都允许多个 NULL 并存，所以后台补录可留 NULL。
            $table->string('idempotency_key', 64)->nullable()->unique('bookings_idempotency_unique');
            $table->timestamp('cancelled_at')->nullable()->comment('取消时间');

            $table->timestamps();

            // 重叠查询的主索引：等值列（room_type_id, status）在前，区间列在后。
            $table->index(['room_type_id', 'status', 'check_in', 'check_out'], 'bookings_overlap_idx');
            $table->index(['hotel_id', 'status'], 'bookings_hotel_status_idx');
            $table->index(['guest_phone', 'status'], 'bookings_phone_status_idx');
            $table->index(['room_id', 'status'], 'bookings_room_status_idx');
        });

        // SQLite 支持部分索引（partial index），能把「已取消/已退房」彻底排除在索引之外；
        // MySQL 5.7/8.0 不支持 → 必须按驱动名分支，否则测试环境（sqlite）过、线上（mysql）炸。
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement(
                'CREATE INDEX bookings_active_overlap_idx ON bookings (room_type_id, check_in, check_out)'
                . ' WHERE status IN (1, 3)'
            );
        }
    }

    public function down()
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS bookings_active_overlap_idx');
        }

        Schema::dropIfExists('bookings');
    }
}
