<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRoomsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id')->comment('所属酒店');
            $table->unsignedBigInteger('room_type_id')->comment('房型');
            $table->string('room_no', 20)->comment('房号');
            $table->unsignedInteger('floor')->nullable()->comment('楼层');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态 1空闲 2在住 3打扫中 4停用');
            $table->string('remark', 500)->nullable()->comment('备注');
            $table->timestamps();

            $table->unique(['hotel_id', 'room_no'], 'rooms_hotel_room_no_unique');
            $table->index('room_type_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('rooms');
    }
}
