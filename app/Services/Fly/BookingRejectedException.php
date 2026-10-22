<?php

namespace App\Services\Fly;

/**
 * 下单被业务规则拒绝（酒店下架 / 房型越权 / 房量不足）。
 *
 * 刻意继承 \Exception 而不是 \RuntimeException：Illuminate 的 QueryException 挂在
 * RuntimeException 下面，按父类捕获会把数据库故障也当成「房量不足」吞掉。
 * 控制器先 catch 本类转回表单带中文提示，剩下的 \Throwable 才走 500。
 */
class BookingRejectedException extends \Exception
{
}
