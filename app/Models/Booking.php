<?php

namespace App\Models;

use Carbon\Carbon;
use DateTime;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * C 端预订订单（/fly 下单产生）。
 *
 * 日期字段一律以 'Y-m-d' 字符串存库，由本模型的修改器强制归一 —— 不要给
 * check_in / check_out 加 date cast，Eloquent 会写成 'Y-m-d H:i:s'，
 * 那会让 scopeOverlapping 的边界判定（check_out > :in）算错。
 */
class Booking extends Model
{
    const STATUS_BOOKED      = 1; // 已预订（占用房量）
    const STATUS_CANCELLED   = 2; // 已取消（不占用）
    const STATUS_CHECKED_IN  = 3; // 已入住（占用；本期不写入，为核销预留）
    const STATUS_CHECKED_OUT = 4; // 已退房（不占用）

    public static $statusLabels = [
        self::STATUS_BOOKED      => '已预订',
        self::STATUS_CANCELLED   => '已取消',
        self::STATUS_CHECKED_IN  => '已入住',
        self::STATUS_CHECKED_OUT => '已退房',
    ];

    const SOURCE_FLY   = 'fly';
    const SOURCE_ADMIN = 'admin';

    public static $sourceLabels = [
        self::SOURCE_FLY   => '云途前台',
        self::SOURCE_ADMIN => '后台补录',
    ];

    protected $fillable = [
        'order_no',
        'hotel_id',
        'room_type_id',
        'room_id',
        'room_ids',
        'check_in',
        'check_out',
        'nights',
        'room_count',
        'price',
        'total_amount',
        'guest_name',
        'guest_phone',
        'remark',
        'status',
        'source',
        'idempotency_key',
        'cancelled_at',
    ];

    protected $casts = [
        'hotel_id'     => 'integer',
        'room_type_id' => 'integer',
        'room_id'      => 'integer',
        'room_ids'     => 'array', // 本次占用的全部房间 id
        'nights'       => 'integer',
        'room_count'   => 'integer',
        'price'        => 'decimal:2',
        'total_amount' => 'decimal:2',
        'status'       => 'integer',
        'cancelled_at' => 'datetime',
    ];

    /**
     * 计入房量占用的状态：已预订 + 已入住。已取消 / 已退房 不占用。
     */
    public static function activeStatuses()
    {
        return [self::STATUS_BOOKED, self::STATUS_CHECKED_IN];
    }

    // ---------------------------------------------------------------- 修改器

    public function setCheckInAttribute($value)
    {
        $this->attributes['check_in'] = static::normalizeDate($value);
    }

    public function setCheckOutAttribute($value)
    {
        $this->attributes['check_out'] = static::normalizeDate($value);
    }

    // ---------------------------------------------------------------- 访问器

    public function getStatusLabelAttribute()
    {
        return isset(self::$statusLabels[$this->status])
            ? self::$statusLabels[$this->status]
            : '未知';
    }

    public function getSourceLabelAttribute()
    {
        return isset(self::$sourceLabels[$this->source])
            ? self::$sourceLabels[$this->source]
            : (string) $this->source;
    }

    /** 姓名脱敏：张三 -> 张*，用于查单列表页 */
    public function getGuestNameMaskedAttribute()
    {
        $name = (string) $this->guest_name;
        $len  = mb_strlen($name);

        if ($len <= 1) {
            return $name;
        }

        return mb_substr($name, 0, 1) . str_repeat('*', $len - 1);
    }

    /** 手机号脱敏：138****8000 */
    public function getGuestPhoneMaskedAttribute()
    {
        $phone = (string) $this->guest_phone;

        if (strlen($phone) !== 11) {
            return $phone;
        }

        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    /**
     * 下单时间文案。app.timezone 是 UTC，直接 format 会给中国用户少 8 小时，
     * C 端展示一律走 config('fly.timezone')（与 generateOrderNo 的号段同一基准）。
     */
    public function getBookedAtTextAttribute()
    {
        return $this->localTime($this->created_at);
    }

    public function getCancelledAtTextAttribute()
    {
        return $this->localTime($this->cancelled_at);
    }

    /** 金额文案：整数不带小数，非整数最多两位（与 getReviewTextAttribute 的裁剪思路一致） */
    public function getAmountTextAttribute()
    {
        return static::moneyText($this->total_amount);
    }

    public function getUnitPriceTextAttribute()
    {
        return static::moneyText($this->price);
    }

    // ----------------------------------------------------------------- 关系

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    // ---------------------------------------------------------------- scope

    public function scopeActive($query)
    {
        return $query->whereIn('status', self::activeStatuses());
    }

    /**
     * 与 [checkIn, checkOut) 有任意一晚重叠的订单。
     *
     * 刻意不用 whereDate()：
     *   1) SQLiteGrammar::whereDate 编译成 date(col) = date(?)，函数包裹列 -> 索引失效；
     *   2) MySQL 上 date 列与 datetime 值比较会做隐式转换，边界行为不可控。
     * 这里做纯字符串比较，'Y-m-d' 的字典序 == 时间序，前提是值已由修改器归一。
     */
    public function scopeOverlapping($query, $checkIn, $checkOut)
    {
        return $query
            ->where('check_in', '<', static::normalizeDate($checkOut))
            ->where('check_out', '>', static::normalizeDate($checkIn));
    }

    public function scopeForPhone($query, $phone)
    {
        return $query->where('guest_phone', $phone);
    }

    public function scopeForHotel($query, $hotelId)
    {
        return $query->where('hotel_id', (int) $hotelId);
    }

    /**
     * 后台订单检索：订单号 / 手机号 / 入住人姓名三字段模糊匹配。
     * 日期一律不做 like（存的是 'Y-m-d'，用户输 10-21 命中不了，不如让他用状态筛选）。
     */
    public function scopeKeyword($query, $keyword)
    {
        $like = '%' . Hotel::escapeLike($keyword) . '%';

        return $query->where(function ($q) use ($like) {
            $q->where('order_no', 'like', $like)
              ->orWhere('guest_phone', 'like', $like)
              ->orWhere('guest_name', 'like', $like);
        });
    }

    // ---------------------------------------------------------------- 工具

    /**
     * 把 UTC 存储的时间点转成 C 端时区的展示串。空值给「—」而不是报错。
     */
    private function localTime($moment)
    {
        if (!$moment instanceof DateTimeInterface) {
            return '—';
        }

        return Carbon::instance($moment)
            ->setTimezone(config('fly.timezone'))
            ->format('Y-m-d H:i');
    }

    /** 金额串：3520.00 -> 3,520；3520.50 -> 3,520.5 */
    private static function moneyText($value)
    {
        $text = number_format((float) $value, 2, '.', ',');

        return rtrim(rtrim($text, '0'), '.');
    }

    /**
     * 把任意日期输入归一成 'Y-m-d'。解析失败时原样返回，交由上层校验规则报错。
     */
    public static function normalizeDate($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = trim((string) $value);
        $head = substr($text, 0, 10);
        $date = DateTime::createFromFormat('Y-m-d', $head);

        return ($date && $date->format('Y-m-d') === $head) ? $head : $text;
    }

    /**
     * 半开区间 [checkIn, checkOut) 的晚数。非法或倒挂返回 0。
     */
    public static function nightsBetween($checkIn, $checkOut)
    {
        $in  = static::normalizeDate($checkIn);
        $out = static::normalizeDate($checkOut);

        if (!$in || !$out) {
            return 0;
        }

        $a = DateTime::createFromFormat('Y-m-d', $in);
        $b = DateTime::createFromFormat('Y-m-d', $out);

        if (!$a || !$b) {
            return 0;
        }

        $days = (int) $a->diff($b)->days;

        return $b > $a ? $days : 0;
    }

    /**
     * 对外订单号：YT + yymmdd + 6 位随机数字。
     * 唯一性由 bookings_order_no_unique 索引保证，冲突时由调用方重试。
     *
     * 日期段取 C 端时区基准（config('fly.timezone')）而非裸 date()：
     * app.timezone 是 UTC，凌晨下的单会打上「昨天」的号，前台/客服对不上。
     */
    public static function generateOrderNo()
    {
        $day = Carbon::now(config('fly.timezone'))->format('ymd');

        return 'YT' . $day . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
