<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHotelsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('酒店名称');
            $table->string('address')->nullable()->comment('地址');
            $table->string('phone', 20)->nullable()->comment('联系电话');
            $table->unsignedTinyInteger('star_level')->nullable()->comment('星级 1-5');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态 1营业 0停用');
            $table->string('remark', 500)->nullable()->comment('备注');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('hotels');
    }
}
