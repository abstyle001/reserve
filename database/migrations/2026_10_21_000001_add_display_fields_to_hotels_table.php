<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddDisplayFieldsToHotelsTable extends Migration
{
    /**
     * C 端（/fly）展示字段：商圈 / 标签 / 评分 / 点评数。
     *
     * 全部 nullable —— 老数据不回填也能跑，后台表单按需补填。
     * 价格不加列：起价由 room_types.price 聚合派生（见 App\Services\Fly\HotelSearchService）。
     *
     * review_count 用 nullable 而非 default 0，是为了区分「没填」和「0 条评价」：
     * 没填时前台整行点评信息不渲染，填 0 则显示「0 条点评」。
     */
    public function up()
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('district', 50)->nullable()->after('city')->comment('商圈/区县，卡片位置行');
            $table->string('tags', 255)->nullable()->after('district')->comment('C 端标签，半角逗号分隔');
            $table->decimal('score', 2, 1)->nullable()->after('star_level')->comment('展示评分 0.0-5.0');
            $table->unsignedInteger('review_count')->nullable()->after('score')->comment('点评条数');

            // C 端搜索的固定谓词是「城市 + 营业中」，已有的单列 city 索引选择性不够。
            // city 上的单列索引保留（后台列表页仍按城市筛），这里只补复合索引。
            $table->index(['city', 'status'], 'hotels_city_status_idx');
        });
    }

    public function down()
    {
        // 索引与列分两次 Schema::table 调用：Laravel 8 在 sqlite 上把 dropIndex 和
        // dropColumn 塞进同一个 Blueprint 时，DBAL 的表重建会先带走索引，
        // 随后的 DROP INDEX 报 "no such index"（实测复现）。
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS hotels_city_status_idx');
        } else {
            Schema::table('hotels', function (Blueprint $table) {
                $table->dropIndex('hotels_city_status_idx');
            });
        }

        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn(['district', 'tags', 'score', 'review_count']);
        });
    }
}
