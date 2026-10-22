<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台订单管理（P7）：列表 / 详情 / 取消。
 *
 * 两条口径必须钉住：
 *   1) 后台看到的是完整姓名与手机号（脱敏只属于 C 端无登录态的查单页）；
 *   2) 取消是软流转，且必须把房量真的还回去 —— 用只读 JSON 接口复核，而不是各算各的。
 */
class FlyBookingAdminTest extends TestCase
{
    use RefreshDatabase;

    private $hotel;
    private $roomType;
    private $rooms;
    private $checkIn;
    private $checkOut;

    protected function setUp(): void
    {
        parent::setUp();

        // 相对日期：写死绝对日期会让用例在某天之后集体变红
        $base = \Carbon\Carbon::now(config('fly.timezone'))->addDays(3)->startOfDay();
        $this->checkIn = $base->toDateString();
        $this->checkOut = $base->copy()->addDay()->toDateString();

        $this->actingAs(User::factory()->create());

        $this->hotel = Hotel::create([
            'name'   => '云途测试酒店',
            'city'   => '上海',
            'status' => Hotel::STATUS_OPEN,
        ]);

        $this->roomType = RoomType::create([
            'hotel_id' => $this->hotel->id,
            'name'     => '外滩江景大床房',
            'price'    => 880,
            'capacity' => 2,
        ]);

        $this->rooms = [];
        for ($i = 0; $i < 3; $i++) {
            $this->rooms[] = Room::create([
                'hotel_id'     => $this->hotel->id,
                'room_type_id' => $this->roomType->id,
                'room_no'      => '10' . ($i + 1),
                'floor'        => 10,
                'status'       => Room::STATUS_VACANT,
            ]);
        }
    }

    private function makeBooking($orderNo, $overrides = [])
    {
        return Booking::create(array_merge([
            'order_no'        => $orderNo,
            'hotel_id'        => $this->hotel->id,
            'room_type_id'    => $this->roomType->id,
            'room_id'         => $this->rooms[0]->id,
            'room_ids'        => [$this->rooms[0]->id],
            'check_in'        => $this->checkIn,
            'check_out'       => $this->checkOut,
            'nights'          => 1,
            'room_count'      => 1,
            'price'           => 880,
            'total_amount'    => 880,
            'guest_name'      => '张三',
            'guest_phone'     => '13700137000',
            'status'          => Booking::STATUS_BOOKED,
            'source'          => Booking::SOURCE_FLY,
            'idempotency_key' => 'key-' . $orderNo,
        ], $overrides));
    }

    /** 只读 JSON 里该房型在给定日期的剩余间数 —— 房量口径的唯一复核点 */
    private function remainingFor($checkIn, $checkOut)
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

    public function test_admin_booking_pages_require_login()
    {
        $booking = $this->makeBooking('YT261025000001');
        auth()->logout();

        $this->get('/bookings')->assertRedirect('/login');
        $this->get('/bookings/' . $booking->id)->assertRedirect('/login');
        $this->post('/bookings/' . $booking->id . '/cancel')->assertRedirect('/login');
    }

    public function test_index_shows_full_contact_instead_of_masked()
    {
        $this->makeBooking('YT261025000002');

        $this->get('/bookings')
            ->assertOk()
            ->assertSee('YT261025000002')
            ->assertSee('张三')
            ->assertSee('13700137000')
            // 后台绝不能出现脱敏串：客服拿不到真号就没法回访
            ->assertDontSee('137****7000')
            ->assertDontSee('张*');
    }

    public function test_index_filters_by_status_hotel_and_keyword()
    {
        $other = Hotel::create(['name' => '杭州分店', 'city' => '杭州', 'status' => Hotel::STATUS_OPEN]);

        $this->makeBooking('YT261025000003');
        $this->makeBooking('YT261025000004', [
            'hotel_id'    => $other->id,
            'guest_name'  => '李四',
            'guest_phone' => '13800138000',
            'status'      => Booking::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->get('/bookings')
            ->assertSee('YT261025000003')
            ->assertSee('YT261025000004');

        $this->get('/bookings?status=' . Booking::STATUS_CANCELLED)
            ->assertSee('YT261025000004')
            ->assertDontSee('YT261025000003');

        $this->get('/bookings?hotel_id=' . $other->id)
            ->assertSee('YT261025000004')
            ->assertDontSee('YT261025000003');

        $this->get('/bookings?q=13700137000')
            ->assertSee('YT261025000003')
            ->assertDontSee('YT261025000004');

        // 非法 status 不报错也不过滤：不能因为手输 URL 就 500
        $this->get('/bookings?status=abc')->assertOk()->assertSee('YT261025000003');
    }

    public function test_like_wildcards_in_keyword_do_not_match_everything()
    {
        $this->makeBooking('YT261025000005');

        // 未转义的话 '%' 会命中所有订单
        $this->get('/bookings?q=%')->assertDontSee('YT261025000005');
    }

    public function test_show_renders_assigned_rooms()
    {
        $booking = $this->makeBooking('YT261025000006', [
            'room_ids'   => [$this->rooms[0]->id, $this->rooms[1]->id],
            'room_count' => 2,
            'total_amount' => 1760,
        ]);

        $this->get('/bookings/' . $booking->id)
            ->assertOk()
            ->assertSee('YT261025000006')
            ->assertSee('101')
            ->assertSee('102')
            ->assertSee('¥1,760')
            ->assertSee('已预订');
    }

    public function test_admin_cancel_releases_inventory_and_cannot_repeat()
    {
        $booking = $this->makeBooking('YT261025000007', [
            'room_ids'   => $this->rooms,
            'room_count' => 3,
            'total_amount' => 2640,
        ]);

        $this->assertSame(0, $this->remainingFor($this->checkIn, $this->checkOut));

        $this->post('/bookings/' . $booking->id . '/cancel')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertNotNull($booking->fresh()->cancelled_at);
        $this->assertSame(3, $this->remainingFor($this->checkIn, $this->checkOut));

        // 重复取消：行不删、状态不回退，只给 error
        $this->post('/bookings/' . $booking->id . '/cancel')
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionMissing('success');

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => Booking::STATUS_CANCELLED]);
    }

    public function test_cancel_does_not_touch_an_order_that_is_already_checked_in()
    {
        $booking = $this->makeBooking('YT261025000008', ['status' => Booking::STATUS_CHECKED_IN]);

        $this->post('/bookings/' . $booking->id . '/cancel')->assertSessionHas('error');

        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->fresh()->status);
        // 已入住仍占用房量
        $this->assertSame(2, $this->remainingFor($this->checkIn, $this->checkOut));
    }
}
