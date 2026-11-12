<?php

return [

    /*
    |--------------------------------------------------------------------------
    | C 端云途旅行页（/fly）配置
    |--------------------------------------------------------------------------
    */

    // 「今天」的基准时区。config/app.php 是 UTC，直接用 now() 会让中国用户
    // 早上 8 点前的默认入住日期少一天。只在这里修正，不动全局 app.timezone
    // （改了会把 reserve/customers 的既有时间展示口径一起挪掉）。
    'timezone' => env('FLY_TIMEZONE', 'Asia/Shanghai'),

    // 每页 6 家：正好对上 .fly-hotel-grid 的 3 列 x 2 行
    'per_page' => (int) env('FLY_PER_PAGE', 6),

    // 热门目的地卡片取几个城市（distinct city 按酒店数倒序）
    'city_limit' => (int) env('FLY_CITY_LIMIT', 8),

    // 单次预订的最长晚数 / 最多间数 / 最远可订天数
    'max_nights' => (int) env('FLY_MAX_NIGHTS', 30),
    'max_rooms'  => (int) env('FLY_MAX_ROOMS', 10),
    'max_days_ahead' => (int) env('FLY_MAX_DAYS_AHEAD', 365),

    // 自助取消截止时刻（格式 H:i，基准是上面的 timezone）：入住前一日的这个点之后
    // C 端不能再自助取消，只能联系酒店处理。当天入住的单同样受约束 —— 20:00 订今晚
    // 就已经过点，下单前由 Booking::cancelPolicyText() 告知。后台 /bookings 人工取消不受此限。
    'cancel_deadline_time' => env('FLY_CANCEL_DEADLINE_TIME', '18:00'),

    // 热门搜索 chip，半角逗号分隔（与 RESERVE_QUEUE_OPTIONS 同一套写法）
    'hot_keywords' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('FLY_HOT_KEYWORDS', '外滩江景房,迪士尼周边,温泉酒店,亲子房'))
    ))),

];
