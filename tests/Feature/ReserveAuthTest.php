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

    public function test_login_lands_on_the_travel_homepage()
    {
        $this->assertSame('/fly', \App\Providers\RouteServiceProvider::HOME);

        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/fly');
    }

    /**
     * HOME 只管「直接登录」的落点：被 guest 中间件拦下的人登录后要回原本要去的地方，
     * 否则操作员每次都得从首页再点一次大屏。
     */
    public function test_login_returns_to_the_screen_that_bounced_the_operator()
    {
        $user = User::factory()->create();

        $this->get('/reserve')->assertRedirect('/login');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/reserve');
    }
}
