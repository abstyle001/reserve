<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 大屏页面级鉴权：/reserve 登录才可用；/m 与只读接口保持公开（顾客扫码免登录）
 */
class ReserveAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserve_screen_redirects_guests_to_login()
    {
        $this->get('/reserve')->assertRedirect('/login');
    }

    public function test_reserve_screen_is_accessible_after_login()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/reserve')->assertOk();
    }

    public function test_mobile_and_readonly_apis_stay_public()
    {
        $this->get('/m')->assertOk();
        $this->getJson('/api/reserve/state?key=guest')->assertOk()
            ->assertJsonPath('code', 0);
        $this->getJson('/api/reserve/position?key=guest&serial_no=1')->assertOk()
            ->assertJsonPath('code', 0);
    }

    public function test_home_redirect_lands_on_reserve_screen()
    {
        $this->assertSame('/reserve', \App\Providers\RouteServiceProvider::HOME);
    }
}
