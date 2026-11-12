<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * serial_generator 的 batch_no / current_no 建表时是 string，而代码按数值语义使用：
 * max('batch_no') 取历史最大批次、orderByDesc('batch_no') 定位最新批次。
 * 字符串列在 MySQL（VARCHAR）与 SQLite（TEXT 亲和性）上都按字典序比较，
 * 批次号到两位数后 '9' > '10' —— 取最新批次会退回到第 9 批，取 max + 1 会复用已存在的批次号。
 *
 * customers 的 batch_no / serial_no 早已改成 integer（2026_10_02_090100 / 090200），
 * 这里把 serial_generator 补齐，保证「最新批次」与「最大批次」按数值比较。
 */
class ChangeSerialGeneratorNumericColumnsToIntegerTable extends Migration
{
    public function up()
    {
        Schema::table('serial_generator', function (Blueprint $table) {
            $table->integer('batch_no')->nullable()->comment('批次号')->change();
            $table->integer('current_no')->nullable()->comment('当前最大号')->change();
        });
    }

    public function down()
    {
        Schema::table('serial_generator', function (Blueprint $table) {
            $table->string('batch_no')->nullable()->comment('批次号')->change();
            $table->string('current_no')->nullable()->comment('当前最大号')->change();
        });
    }
}
