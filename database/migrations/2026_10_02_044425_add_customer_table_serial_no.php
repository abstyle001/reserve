<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCustomerTableSerialNo extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 历史包袱：043842 建表迁移里已包含 serial_no 列，
        // 本迁移在存量库上执行过、在干净库(migrate:fresh)会重复加列。
        // 这里做幂等保护：列已存在则跳过，对已执行本迁移的库无影响（不会重跑）。
        if (Schema::hasColumn('customers', 'serial_no')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->integer('serial_no')->nullable()->comment('序列号');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('customers', 'serial_no')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('serial_no');
        });
    }
}
