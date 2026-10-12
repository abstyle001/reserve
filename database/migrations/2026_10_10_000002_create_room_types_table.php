<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRoomTypesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id')->comment('所属酒店');
            $table->string('name', 50)->comment('房型名称，如大床房');
            $table->decimal('price', 10, 2)->default(0)->comment('门市价');
            $table->unsignedTinyInteger('capacity')->default(2)->comment('可住人数');
            $table->string('bed_type', 50)->nullable()->comment('床型，如 1.8m大床');
            $table->unsignedInteger('area')->nullable()->comment('面积(㎡)');
            $table->string('description', 500)->nullable()->comment('房型描述');
            $table->timestamps();

            $table->unique(['hotel_id', 'name'], 'room_types_hotel_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('room_types');
    }
}
