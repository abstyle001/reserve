<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bookings 增加下单账号归属：C 端改为「强制登录下单 + 登录用户查自己的单」，
 * 废弃「手机号即凭证」。历史订单没有可推断的归属账号，一律留 NULL，
 * C 端不可见，仅后台 /bookings 可管理。
 *
 * 不建外键：沿用 bookings 表「无 FK + 控制器级联保护」的既有约定。
 */
class AddUserIdToBookingsTable extends Migration
{
    public function up()
    {
        Schema::table('bookings', function (Blueprint $table) {
            // after('id') 仅 MySQL 生效，sqlite 语法层自动忽略
            $table->unsignedBigInteger('user_id')->nullable()->after('id')
                ->comment('下单登录账号；历史单为 NULL');
            $table->index('user_id', 'bookings_user_idx');
        });
    }

    public function down()
    {
        // sqlite 的 dropColumn 需要 doctrine/dbal（项目未装），回滚只支持 MySQL
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_user_idx');
            $table->dropColumn('user_id');
        });
    }
}
