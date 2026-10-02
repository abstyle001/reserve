<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSerialGeneratorTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('serial_generator', function(Blueprint $table) {
            $table->id();
            $table->string('queue_key')->nullable()->comment('队列key');
            $table->string('batch_no')->nullable()->comment('批次号');
            $table->string('current_no')->nullable()->comment('当前最大号');
            $table->integer('need_reset')->nullable()->comment('是否已经办结');
            $table->integer('active_count')->nullable()->comment('剩余激活数');
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
        Schema::dropIfExists('serial_generator');
    }
}
