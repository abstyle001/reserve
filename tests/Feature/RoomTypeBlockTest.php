<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomTypeBlock;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 房量计划（room_type_blocks）：后台按晚停售，C 端房量口径随之扣减。
 *
 * 口径：可订间数 = 非停用房间数 − 重叠有效订单间数 − 区间内各晚停售间数的最大值。
 * 这里钉三件事：
 *   1) 停售只作用在停售的晚（半开区间），相邻日期不受影响；
 *   2) 多晚查询取各晚停售的 max，不是 sum —— 同一批房间连停多晚只该扣一次；
 *   3) 后台设置 / 覆盖 / 恢复要实时反映到 C 端的「剩 N 间」与下单校验。
 *
 * 日期相对今天生成（config('fly.timezone')），不硬编码绝对日期。
 */
class RoomTypeBlockTest extends TestCase
{
    use RefreshDatabase;

    /** @var Hotel */
    private $hotel;

    /** @var RoomType */
    private $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::create([
            'name'   => '停售测试酒店',
            'city'   => '上海',
            'status' => Hotel::STATUS_OPEN,
        ]);

        $this->roomType = RoomType::create([
            'hotel_id' => $this->hotel->id,
            'name'     => '停售测试房型',
            'price'    => 500,
            'capacity' => 2,
        ]);

        for ($i = 0; $i < 3; $i++) {
            Room::create([
                'hotel_id'     => $this->hotel->id,
                'room_type_id' => $this->roomType->id,
                'room_no'      => sprintf('%03d', $i + 1),
                'floor'        => 5,
                'status'       => Room::STATUS_VACANT,
            ]);
        }
    }

    private function day($offset)
    {
        return Carbon::now(config('fly.timezone'))->addDays($offset)->toDateString();
    }

    /** 详情页接口里该房型的剩余间数 */
    private function remaining($checkIn, $checkOut)
    {
        $response = $this->getJson('/api/fly/hotels/' . $this->hotel->id . '/room-types'
            . '?check_in=' . $checkIn . '&check_out=' . $checkOut);

        $response->assertStatus(200)->assertJsonPath('code', 0);

        foreach ($response->json('data.items') as $item) {
            if ((int) $item['id'] === (int) $this->roomType->id) {
                return (int) $item['remaining'];
            }
        }

        $this->fail('房型未出现在只读接口返回里');
    }

    private function admin()
    {
        return $this->actingAs(User::factory()->create());
    }

    private function blockNight($date, $closedRooms, $remark = '维修')
    {
        return RoomTypeBlock::create([
            'room_type_id' => $this->roomType->id,
            'night_date'   => $date,
            'closed_rooms' => $closedRooms,
            'remark'       => $remark,
        ]);
    }

    // ================================================================= C 端口径

    public function test_block_reduces_remaining_only_on_blocked_night()
    {
        $this->blockNight($this->day(5), 1);

        // 停售的晚：3 间 − 1 停售 = 2
        $this->assertSame(2, $this->remaining($this->day(5), $this->day(6)));

        // 相邻晚不在停售区间内，不受影响
        $this->assertSame(3, $this->remaining($this->day(6), $this->day(7)));
        $this->assertSame(3, $this->remaining($this->day(4), $this->day(5)));
    }

    public function test_multi_night_query_takes_max_of_blocked_nights_not_sum()
    {
        // 同一批房间连续两晚停售 1 间 / 2 间：两晚查询只该扣 2 而不是 3
        $this->blockNight($this->day(5), 1);
        $this->blockNight($this->day(6), 2);

        $this->assertSame(1, $this->remaining($this->day(5), $this->day(7)));
        $this->assertSame(2, $this->remaining($this->day(5), $this->day(6)));
        $this->assertSame(1, $this->remaining($this->day(6), $this->day(7)));
    }

    public function test_search_list_also_subtracts_blocks()
    {
        $this->blockNight($this->day(5), 3);

        $response = $this->getJson('/api/fly/hotels?city=上海&check_in=' . $this->day(5) . '&check_out=' . $this->day(6))
            ->assertStatus(200)
            ->assertJsonPath('code', 0);

        $this->assertSame(0, $response->json('data.items.0.remaining_total'));
        $this->assertSame(true, $response->json('data.items.0.sold_out'));
    }

    public function test_blocked_inventory_blocks_overselling_but_allows_what_is_left()
    {
        $this->blockNight($this->day(5), 2);
        $this->blockNight($this->day(6), 2);

        $base = [
            'hotel_id'        => $this->hotel->id,
            'room_type_id'    => $this->roomType->id,
            'check_in'        => $this->day(5),
            'check_out'       => $this->day(7),
            'guest_name'      => '张三',
            'guest_phone'     => '13700137000',
            'idempotency_key' => 'block-oversell',
        ];

        // 还剩 1 间可卖，订 2 间必须被拒
        $this->post('/fly/bookings', $base + ['room_count' => 2])
            ->assertStatus(302)
            ->assertSessionHasErrors('submit');
        $this->assertSame(0, Booking::count());

        // 订 1 间可以
        $this->post('/fly/bookings', $base + ['room_count' => 1])->assertStatus(302);
        $this->assertSame(1, Booking::count());
        $this->assertSame(0, $this->remaining($this->day(5), $this->day(7)));
    }

    // ================================================================= 后台管理

    public function test_admin_can_set_plan_and_it_expands_to_night_rows()
    {
        $this->admin()->post(route('hotels.room-types.inventory.store', [$this->hotel, $this->roomType]), [
            'start_date'   => $this->day(10),
            'end_date'     => $this->day(12),
            'closed_rooms' => 2,
            'remark'       => '团房保留',
        ])->assertStatus(302)->assertSessionHas('success');

        $this->assertSame(2, RoomTypeBlock::count(), '区间 [10, 12) 应展开成 2 行');
        $this->assertSame(2, (int) RoomTypeBlock::where('night_date', $this->day(10))->sole()->closed_rooms);
        $this->assertSame(2, (int) RoomTypeBlock::where('night_date', $this->day(11))->sole()->closed_rooms);

        $this->assertSame(1, $this->remaining($this->day(10), $this->day(12)));
    }

    public function test_submitting_same_night_again_overwrites_not_duplicates()
    {
        $this->admin()->post(route('hotels.room-types.inventory.store', [$this->hotel, $this->roomType]), [
            'start_date'   => $this->day(10),
            'end_date'     => $this->day(11),
            'closed_rooms' => 1,
        ])->assertStatus(302);

        $this->admin()->post(route('hotels.room-types.inventory.store', [$this->hotel, $this->roomType]), [
            'start_date'   => $this->day(10),
            'end_date'     => $this->day(11),
            'closed_rooms' => 3,
        ])->assertStatus(302);

        $this->assertSame(1, RoomTypeBlock::count(), '同一晚重复提交必须覆盖而不是新增行');
        $this->assertSame(3, (int) RoomTypeBlock::sole()->closed_rooms);
        $this->assertSame(0, $this->remaining($this->day(10), $this->day(11)));
    }

    public function test_deleting_a_block_releases_inventory()
    {
        $block = $this->blockNight($this->day(5), 2);

        $this->assertSame(1, $this->remaining($this->day(5), $this->day(6)));

        $this->admin()->delete(route('hotels.room-types.inventory.destroy', [$this->hotel, $this->roomType, $block]))
            ->assertStatus(302)->assertSessionHas('success');

        $this->assertSame(0, RoomTypeBlock::count());
        $this->assertSame(3, $this->remaining($this->day(5), $this->day(6)));
    }

    public function test_plan_page_requires_login_and_rejects_cross_hotel_room_type()
    {
        $this->get(route('hotels.room-types.inventory', [$this->hotel, $this->roomType]))
            ->assertRedirect('/login');

        $this->admin()->get(route('hotels.room-types.inventory', [$this->hotel, $this->roomType]))
            ->assertOk()
            ->assertSee('房量计划');

        $otherHotel = Hotel::create(['name' => '别家酒店', 'city' => '杭州', 'status' => Hotel::STATUS_OPEN]);

        $this->admin()->get(route('hotels.room-types.inventory', [$otherHotel, $this->roomType]))
            ->assertNotFound();
    }

    public function test_plan_validation_rejects_past_dates_and_long_ranges()
    {
        // 开始日期早于今天
        $this->admin()->post(route('hotels.room-types.inventory.store', [$this->hotel, $this->roomType]), [
            'start_date'   => $this->day(-1),
            'end_date'     => $this->day(2),
            'closed_rooms' => 1,
        ])->assertSessionHasErrors('start_date');

        // 超过 62 晚
        $this->admin()->post(route('hotels.room-types.inventory.store', [$this->hotel, $this->roomType]), [
            'start_date'   => $this->day(1),
            'end_date'     => $this->day(64),
            'closed_rooms' => 1,
        ])->assertSessionHasErrors('end_date');

        $this->assertSame(0, RoomTypeBlock::count());
    }
}
