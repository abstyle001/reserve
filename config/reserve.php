<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 预约大屏队列选项
    |--------------------------------------------------------------------------
    |
    | 前端顶栏的队列下拉框从这里取列表，用 RESERVE_QUEUE_OPTIONS 环境变量覆盖，
    | 多个队列用英文逗号分隔，例如：RESERVE_QUEUE_OPTIONS="1号窗口,2号窗口,急诊"
    |
    */

    'queue_options' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('RESERVE_QUEUE_OPTIONS', '默认队列'))
    ))),

];
