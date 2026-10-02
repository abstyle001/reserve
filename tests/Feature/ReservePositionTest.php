<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 移动端排队查询接口：GET /api/reserve/position?key=xxx&serial_no=N
 *
 * 关键业务推论：新批次只在上一批全部办结后开启，
 * 所以排队中的号码必然在最新批次，接口只按 max(batch_no) 查找。
 */
class ReservePositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_and_serial_no_are_required()
    {
        $this->getJson('/api/reserve/position?key=')
            ->assertStatus(422)
            ->assertJsonPath('code', 422)
            ->assertJsonStructure(['errors' => ['key']]);

        $this->getJson('/api/reserve/position?key=pos-0')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['serial_no']]);

        $this->getJson('/api/reserve/position?key=pos-0&serial_no=abc')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['serial_no']]);
    }

    public function test_not_found_when_queue_never_used()
    {
        $this->getJson('/api/reserve/position?key=pos-1&serial_no=1')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'not_found')
            ->assertJsonPath('data.batch_no', 0)
            ->assertJsonPath('data.ahead_count', 0);
    }

    public function test_ahead_count_decreases_as_front_numbers_finish()
    {
        $key = 'pos-2';

        // 取 1 号、2 号
        $this->postJson('/api/reserve', ['key' => $key])->assertJsonPath('code', 0);
        $this->postJson('/api/reserve', ['key' => $key])->assertJsonPath('code', 0);

        // 2 号视角：前面有 1 人
        $this->getJson("/api/reserve/position?key={$key}&serial_no=2")
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.batch_no', 1)
            ->assertJsonPath('data.ahead_count', 1)
            ->assertJsonPath('data.current_no', 2)
            ->assertJsonPath('data.active_count', 2);

        // 放掉 1 号：2 号前面变 0 人；1 号自己变为已办结
        $this->deleteJson('/api/reserve', [
            'key' => $key,
            'batch_no' => 1,
            'serial_no' => 1,
        ])->assertJsonPath('code', 0);

        $this->getJson("/api/reserve/position?key={$key}&serial_no=2")
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.ahead_count', 0);

        $this->getJson("/api/reserve/position?key={$key}&serial_no=1")
            ->assertOk()
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.ahead_count', 0);
    }

    public function test_serial_from_finished_batch_is_not_found_after_rotation()
    {
        $key = 'pos-3';

        // 第一批：取 1、2 号并全部办结
        $this->postJson('/api/reserve', ['key' => $key]);
        $this->postJson('/api/reserve', ['key' => $key]);
        foreach ([1, 2] as $no) {
            $this->deleteJson('/api/reserve', [
                'key' => $key,
                'batch_no' => 1,
                'serial_no' => $no,
            ])->assertJsonPath('code', 0);
        }

        // 第二批只取 1 号
        $this->postJson('/api/reserve', ['key' => $key])->assertJsonPath('code', 0);

        // 旧批次的 2 号在最新批次里不存在 → not_found
        $this->getJson("/api/reserve/position?key={$key}&serial_no=2")
            ->assertOk()
            ->assertJsonPath('data.status', 'not_found')
            ->assertJsonPath('data.batch_no', 2);

        // 而 1 号命中的是新批次的 1 号 → waiting
        $this->getJson("/api/reserve/position?key={$key}&serial_no=1")
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.batch_no', 2)
            ->assertJsonPath('data.ahead_count', 0);
    }

    public function test_position_is_read_only_and_does_not_change_state()
    {
        $key = 'pos-4';

        $this->postJson('/api/reserve', ['key' => $key]);
        $this->postJson('/api/reserve', ['key' => $key]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson("/api/reserve/position?key={$key}&serial_no=2")->assertOk();
        }

        $this->assertDatabaseHas('serial_generator', [
            'queue_key' => $key,
            'current_no' => 2,
            'active_count' => 2,
            'need_reset' => 0,
        ]);

        $this->assertSame(
            2,
            Customer::where('queue_key', $key)->where('is_finish', 0)->count(),
        );
    }
}
