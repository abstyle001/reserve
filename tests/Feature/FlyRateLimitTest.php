<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 云途 C 端（/fly）限流：顾客全程不登录，凭证是「订单号 + 手机号」，
 * 不限流就能遍历手机号/订单号探测别人订单、或脚本批量占房。
 *
 * 这里钉三件事：
 *   1) 写路由（下单 / 取消）按 IP+路由名限 10 次/分钟，第 11 次 429；
 *   2) 查单页（/fly/orders）与查单 JSON（/api/fly/bookings）限 10 次/分钟，第 11 次 429；
 *   3) 下单与取消分桶计数，互不挤占。
 *
 * throttle 中间件在控制器之前执行，所以探针不需要合法载荷——
 * 前 N-1 次落到校验失败 / 404 都无所谓，只看第 N 次是否 429。
 * 测试 CACHE_DRIVER=array，限流计数随每个用例的新 app 实例重置。
 */
class FlyRateLimitTest extends TestCase
{
    public function test_booking_store_is_throttled()
    {
        for ($i = 0; $i < 10; $i++) {
            $response = $this->post('/fly/bookings', []);
            $this->assertNotSame(429, $response->status(), '第 ' . ($i + 1) . ' 次下单不应被限流');
        }

        $this->post('/fly/bookings', [])->assertStatus(429);
    }

    public function test_order_cancel_is_throttled()
    {
        for ($i = 0; $i < 10; $i++) {
            $response = $this->post('/fly/orders/1/cancel', []);
            $this->assertNotSame(429, $response->status(), '第 ' . ($i + 1) . ' 次取消不应被限流');
        }

        $this->post('/fly/orders/1/cancel', [])->assertStatus(429);
    }

    public function test_order_pages_are_throttled()
    {
        for ($i = 0; $i < 10; $i++) {
            $response = $this->get('/fly/orders');
            $this->assertNotSame(429, $response->status(), '第 ' . ($i + 1) . ' 次查单不应被限流');
        }

        $this->get('/fly/orders')->assertStatus(429);
    }

    public function test_order_lookup_json_is_throttled()
    {
        for ($i = 0; $i < 10; $i++) {
            $response = $this->getJson('/api/fly/bookings?phone=13700137000');
            $this->assertNotSame(429, $response->status(), '第 ' . ($i + 1) . ' 次查单不应被限流');
        }

        $this->getJson('/api/fly/bookings?phone=13700137000')->assertStatus(429);
    }

    public function test_write_limiter_buckets_are_per_route()
    {
        // 下单桶打满 10 次后，取消桶不受影响（按路由名分桶）
        for ($i = 0; $i < 10; $i++) {
            $this->post('/fly/bookings', []);
        }

        $response = $this->post('/fly/orders/1/cancel', []);
        $this->assertNotSame(429, $response->status(), '下单被限流不应挤占取消的配额');
    }
}
