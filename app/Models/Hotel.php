<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hotel extends Model
{
    const STATUS_OPEN = 1;   // 营业
    const STATUS_CLOSED = 0; // 停用

    public static $statusLabels = [
        self::STATUS_OPEN   => '营业',
        self::STATUS_CLOSED => '停用',
    ];

    protected $fillable = [
        'name',         // 酒店名称
        'city',         // 所在城市
        'district',     // 商圈/区县（C 端卡片位置行）
        'address',      // 地址
        'phone',        // 联系电话
        'star_level',   // 星级 1-5
        'score',        // C 端展示评分 0.0-5.0
        'review_count', // C 端点评条数
        'tags',         // C 端标签，半角逗号分隔
        'status',       // 状态 1营业 0停用
        'remark',       // 备注
        'img',
    ];

    protected $casts = [
        'star_level'   => 'integer',
        'score'        => 'float',
        'review_count' => 'integer',
        'status'       => 'integer',
        'img'          => 'array', // 多张图片路径的 JSON 数组
    ];

    /**
     * 图片访问 URL 列表（public 磁盘，需 php artisan storage:link）
     */
    public function getImgUrlsAttribute()
    {
        $urls = [];
        foreach ((array) $this->img as $path) {
            $urls[] = asset('storage/' . $path);
        }

        return $urls;
    }

    /**
     * 封面首图，无图返回 null（C 端列表页只用这个，不要用 img_urls 解析整个数组）
     */
    public function getCoverUrlAttribute()
    {
        $img = (array) $this->img;

        return isset($img[0]) ? asset('storage/' . $img[0]) : null;
    }

    /**
     * tags 字符串 -> 去空去重后的数组。空标签不渲染。
     */
    public function getTagListAttribute()
    {
        $parts = array_map('trim', explode(',', (string) $this->tags));
        $parts = array_values(array_filter($parts, function ($item) {
            return $item !== '';
        }));

        return array_slice(array_unique($parts), 0, 4);
    }

    /**
     * 评分文案。与静态原型稿口径一致：超赞 / 棒 / 不错 / 一般。
     */
    public function getScoreTextAttribute()
    {
        if ($this->score === null) {
            return null;
        }

        if ($this->score >= 4.8) {
            return '超赞';
        }

        if ($this->score >= 4.5) {
            return '棒';
        }

        if ($this->score >= 4.0) {
            return '不错';
        }

        return $this->score >= 3.0 ? '一般' : null;
    }

    /**
     * 点评数文案，如「2.1万 条点评」。没填 review_count 时返回 null，整行不渲染。
     */
    public function getReviewTextAttribute()
    {
        if ($this->review_count === null) {
            return null;
        }

        $count = (int) $this->review_count;

        if ($count >= 10000) {
            $text = number_format($count / 10000, 1, '.', '');
            $text = rtrim(rtrim($text, '0'), '.');

            return $text . '万 条点评';
        }

        return $count . ' 条点评';
    }

    /**
     * 卡片位置行：商圈 · 地址，缺哪个跳哪个。
     */
    public function getLocTextAttribute()
    {
        $parts = array_values(array_filter([$this->district, $this->address], function ($item) {
            return $item !== null && $item !== '';
        }));

        return implode(' · ', $parts);
    }

    /**
     * C 端只展示营业中的酒店
     */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * 城市精确匹配。不用 like —— 「上海」会命中「上海市」，城市卡与筛选会对不上。
     */
    public function scopeInCity($query, $city)
    {
        return $query->where('city', $city);
    }

    /**
     * 关键词命中名称 / 商圈 / 地址 / 标签四个字段
     */
    public function scopeKeyword($query, $keyword)
    {
        $like = '%' . static::escapeLike($keyword) . '%';

        return $query->where(function ($q) use ($like) {
            $q->where('name', 'like', $like)
              ->orWhere('district', 'like', $like)
              ->orWhere('address', 'like', $like)
              ->orWhere('tags', 'like', $like);
        });
    }

    /**
     * 至少要有一个房型，否则前台无价可show、无房可订
     */
    public function scopeWithRoomTypes($query)
    {
        return $query->whereExists(function ($q) {
            $q->selectRaw('1')
              ->from('room_types as fly_rt')
              ->whereRaw('fly_rt.hotel_id = hotels.id');
        });
    }

    /**
     * 转义 like 的通配符，防用户输入的 % _ 变成通配
     *
     * public：Booking::scopeKeyword 也用它。通配符口径全项目只该有一份，
     * 抄第二份就会有两个地方各自漏掉某个字符的风险。
     */
    public static function escapeLike($value)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);
    }

    public function roomTypes()
    {
        return $this->hasMany(RoomType::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function getStatusLabelAttribute()
    {
        return isset(self::$statusLabels[$this->status])
            ? self::$statusLabels[$this->status]
            : '未知';
    }
}
