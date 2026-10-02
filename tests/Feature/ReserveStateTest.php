<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SerialGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReserveStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_key_is_required()
    {
        $response = $this->getJson('/api/reserve/state?key=');

        $response->assertStatus(422)
            ->assertJsonPath('code', 422)
            ->assertJsonPath('message', '参数校验失败')
            ->assertJsonStructure(['errors' => ['key']]);
    }

    public function test_returns_default_state_when_queue_has_no_generator()
    {
        $response = $this->getJson('/api/reserve/state?key=' . urlencode('empty-queue'));

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.queue_key', 'empty-queue')
            ->assertJsonPath('data.batch_no', 0)
            ->assertJsonPath('data.current_no', 0)
            ->assertJsonPath('data.active_count', 0)
            ->assertJsonPath('data.need_reset', 1)
            ->assertJsonPath('data.released_total', 0);
    }

    public function test_returns_active_count_after_taking_a_number()
    {
        $response = $this->postJson('/api/reserve', ['key' => 'counter-1']);
        $response->assertOk()->assertJsonPath('code', 0);

        $state = $this->getJson('/api/reserve/state?key=' . urlencode('counter-1'));

        $state->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.batch_no', 1)
            ->assertJsonPath('data.current_no', 1)
            ->assertJsonPath('data.active_count', 1)
            ->assertJsonPath('data.remaining', 1)
            ->assertJsonPath('data.released_total', 0)
            ->assertJsonPath('data.need_reset', 0);
    }

    public function test_released_total_counts_only_current_batch()
    {
        $key = 'counter-2';

        // 第一批：取 2 号、放 1 号
        $this->postJson('/api/reserve', ['key' => $key]);
        $this->postJson('/api/reserve', ['key' => $key]);

        $first = Customer::where('queue_key', $key)->where('serial_no', 1)->first();
        $this->deleteJson('/api/reserve', [
            'key' => $key,
            'batch_no' => $first->batch_no,
            'serial_no' => $first->serial_no,
        ])->assertOk()->assertJsonPath('code', 0);

        // 让第一批办结，再开第二批
        $rest = Customer::where('queue_key', $key)->where('serial_no', 2)->first();
        $this->deleteJson('/api/reserve', [
            'key' => $key,
            'batch_no' => $rest->batch_no,
            'serial_no' => $rest->serial_no,
        ])->assertOk()->assertJsonPath('code', 0);

        // 第二批取 1 号
        $this->postJson('/api/reserve', ['key' => $key]);

        $state = $this->getJson('/api/reserve/state?key=' . urlencode($key));

        // 关键断言：已放号只统计第二批，第一批的 1 次放号不能被算进来
        $state->assertOk()
            ->assertJsonPath('data.batch_no', 2)
            ->assertJsonPath('data.current_no', 1)
            ->assertJsonPath('data.active_count', 1)
            ->assertJsonPath('data.released_total', 0);
    }

    public function test_state_reflects_after_last_number_is_released()
    {
        $key = 'counter-3';

        $this->postJson('/api/reserve', ['key' => $key]);

        $customer = Customer::where('queue_key', $key)->first();
        $this->deleteJson('/api/reserve', [
            'key' => $key,
            'batch_no' => $customer->batch_no,
            'serial_no' => $customer->serial_no,
        ])->assertOk()->assertJsonPath('code', 0);

        $this->assertDatabaseHas('serial_generator', [
            'queue_key' => $key,
            'active_count' => 0,
            'need_reset' => 1,
        ]);

        $this->getJson('/api/reserve/state?key=' . urlencode($key))
            ->assertOk()
            ->assertJsonPath('data.active_count', 0)
            ->assertJsonPath('data.remaining', 0)
            ->assertJsonPath('data.released_total', 1)
            ->assertJsonPath('data.need_reset', 1);
    }

    public function test_state_is_read_only_and_does_not_change_active_count()
    {
        $key = 'counter-4';

        $this->postJson('/api/reserve', ['key' => $key]);
        $this->postJson('/api/reserve', ['key' => $key]);

        $this->getJson('/api/reserve/state?key=' . urlencode($key));
        $this->getJson('/api/reserve/state?key=' . urlencode($key));
        $this->getJson('/api/reserve/state?key=' . urlencode($key));

        $this->assertDatabaseHas('serial_generator', [
            'queue_key' => $key,
            'current_no' => 2,
            'active_count' => 2,
            'need_reset' => 0,
        ]);
    }
}
