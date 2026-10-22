<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeImgToTextOnHotelsTable extends Migration
{
    /**
     * img 存多张图片路径的 JSON 数组，string(255) 不够放，改成 text。
     *
     * @return void
     */
    public function up()
    {
        Schema::table('hotels', function (Blueprint $table)
        {
            $table->text('img')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('hotels', function (Blueprint $table)
        {
            $table->string('img')->nullable()->change();
        });
    }
}
