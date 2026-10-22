<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C 端订单的归属与取消（/fly/orders、POST /fly/orders/{booking}/cancel）。
 *
 * 凭证是 order_no + guest_phone，不是 Breeze 登录态：bookings 表没有 user_id。
 * 所以这里要钉住的是「猜得到订单号也不能看到别人的单」：
 *   手机号不符一律 404（用 403 等于对外确认了这个订单号存在）；
 *   取消是软状态流转，行不删、状态不回退，重复取消只给提示；
 *   释放的房量必须能被只读接口复核 —— 与详情页/下单页同一个口径。
 */
class FlyOrderAccessTest extends TestCase
{
    use RefreshDatabase;

    const PHONE_MINE = '13700137000';
    const PHONE_OTHER = '13800138000';

    /** @var Hotel */
    private $hotel;

    /** @var RoomType */
    private $roomType;

    /** @var Room[] */
    private $rooms;

    private $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::create([
            'name'   => '外滩测试酒店',
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

    private function day($offset)
    {
        return Carbon::now(config('fly.timezone'))->addDays($offset)->toDateString();
    }

    private function makeBooking($phone = self::PHONE_MINE, $overrides = [])
    {
        $this->seq++;

        return Booking::create(array_merge([
            'order_no'        => 'YT2610250000' . str_pad((string) $this->seq, 2, '0', STR_PAD_LEFT),
            'hotel_id'        => $this->hotel->id,
            'room_type_id'    => $this->roomType->id,
            'room_id'         => $this->rooms[0]->id,
            'room_ids'        => [$this->rooms[0]->id],
            'check_in'        => $this->day(5),
            'check_out'       => $this->day(6),
            'nights'          => 1,
            'room_count'      => 1,
            'price'           => 880,
            'total_amount'    => 880,
            'guest_name'      => '张三',
            'guest_phone'     => $phone,
            'status'          => Booking::STATUS_BOOKED,
            'source'          => Booking::SOURCE_FLY,
            'idempotency_key' => 'key-' . $this->seq,
        ], $overrides));
    }

    /** 只读接口里该房型在查询区间内的剩余间数（释放房量的复核点） */
    private function remaining()
    {
        $response = $this->getJson('/api/fly/hotels/' . $this->hotel->id . '/room-types'
            . '?check_in=' . $this->day(5) . '&check_out=' . $this->day(6));

        $response->assertStatus(200)->assertJsonPath('code', 0);

        foreach ($response->json('data.items') as $item) {
            if ((int) $item['id'] === (int) $this->roomType->id) {
                return (int) $item['remaining'];
            }
        }

        $this->fail('房型未出现在只读接口返回里');
    }

    // ================================================================= 查单

    public function test_index_lists_only_the_orders_of_the_queried_phone()
    {
        $this->makeBooking(self::PHONE_MINE, ['order_no' => 'YTMINE0000001']);
        $this->makeBooking(self::PHONE_MINE, ['order_no' => 'YTMINE0000002', 'guest_name' => '李四']);
        $this->makeBooking(self::PHONE_OTHER, ['order_no' => 'YTOTHER0000001', 'guest_name' => '王五']);

        $response = $this->get('/fly/orders?phone=' . self::PHONE_MINE)
            ->assertOk()
            ->assertSee('YTMINE0000001')
            ->assertSee('YTMINE0000002')
            ->assertDontSee('YTOTHER0000001')
            ->assertDontSee('王五');

        // 列表页不重复展示联系人：手机号是用户自己输入的，姓名留给详情页
        $this->assertStringContainsString('共 2 单', (string) $response->getContent());
    }

    public function test_index_without_a_phone_only_shows_the_form()
    {
        $this->makeBooking(self::PHONE_MINE, ['order_no' => 'YTMINE0000003']);

        // 「还没查」与「查了没单」是两种状态，不能混成一个空列表
        $this->get('/fly/orders')
            ->assertOk()
            ->assertSee('手机号即凭证，无需登录')
            ->assertDontSee('YTMINE0000003');

        $this->get('/fly/orders?phone=' . self::PHONE_MINE)
            ->assertOk()
            ->assertSee('YTMINE0000003');
    }

    public function test_index_rejects_a_malformed_phone()
    {
        $this->makeBooking(self::PHONE_MINE, ['order_no' => 'YTMINE0000004']);

        $this->get('/fly/orders?phone=1370013700')
            ->assertOk()
            ->assertSee('请输入 11 位大陆手机号')
            ->assertDontSee('YTMINE0000004');
    }

    public function test_a_booking_at_a_delisted_hotel_is_still_listed()
    {
        $this->makeBooking(self::PHONE_MINE, ['order_no' => 'YTMINE0000005']);
        $this->hotel->forceFill(['status' => Hotel::STATUS_CLOSED])->save();

        // 酒店下架不能吞掉顾客已下的单：订单行照旧列出，状态照旧可查
        $this->get('/fly/orders?phone=' . self::PHONE_MINE)
            ->assertOk()
            ->assertSee('YTMINE0000005')
            ->assertSee('外滩测试酒店');
    }

    public function test_detail_requires_the_matching_phone()
    {
        $booking = $this->makeBooking(self::PHONE_MINE, ['order_no' => 'YTMINE0000006']);

        $this->get('/fly/orders/' . $booking->id . '?phone=' . self::PHONE_MINE)
            ->assertOk()
            ->assertSee('YTMINE0000006')
            ->assertSee('张三')
            ->assertSee('101')
            ->assertSee('到店报手机号或订单号即可办理入住');

        // 404 而不是 403：403 等于对外确认「这个订单号存在」
        $this->get('/fly/orders/' . $booking->id . '?phone=' . self::PHONE_OTHER)->assertNotFound();
        $this->get('/fly/orders/' . $booking->id)->assertNotFound();
        $this->get('/fly/orders/' . $booking->id . '?phone=')->assertNotFound();
    }

    // ================================================================= 取消

    public function test_cancel_is_a_soft_state_change_and_frees_the_room()
    {
        $booking = $this->makeBooking(self::PHONE_MINE, [
            'order_no'   => 'YTMINE0000007',
            // room_ids 存的是房间 id 数组（下单时由 AvailabilityService::allocateRooms 给出），不是模型
            'room_ids'   => array_map(function ($room) {
                return $room->id;
            }, $this->rooms),
            'room_count' => 3,
            'total_amount' => 2640,
        ]);

        $this->assertSame(0, $this->remaining());

        $this->post('/fly/orders/' . $booking->id . '/cancel', ['phone' => self::PHONE_MINE])
            ->assertStatus(302)
            ->assertSessionHas('flyFlash', '订单已取消，房量已释放。');

        $fresh = $booking->fresh();
        $this->assertSame(Booking::STATUS_CANCELLED, (int) $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame(3, $this->remaining());

        // 行不删：历史订单仍可查，只是状态与时间变了
        $this->get('/fly/orders/' . $booking->id . '?phone=' . self::PHONE_MINE)
            ->assertOk()
            ->assertSee('已取消')
            ->assertSee('本单已取消，占用的房量已释放')
            ->assertDontSee('到店报手机号或订单号即可办理入住');
    }

    public function test_repeat_cancel_is_rejected_and_does_not_touch_the_room()
    {
        $booking = $this->makeBooking(self::PHONE_MINE, ['order_no' => 'YTMINE0000008']);

        $this->post('/fly/orders/' . $booking->id . '/cancel', ['phone' => self::PHONE_MINE]);
        $this->assertSame(3, $this->remaining());

        $this->post('/fly/orders/' . $booking->id . '/cancel', ['phone' => self::PHONE_MINE])
            ->assertStatus(302)
            ->assertSessionHas('flyFlashError')
            ->assertSessionMissing('flyFlash');

        $this->assertSame(Booking::STATUS_CANCELLED, (int) $booking->fresh()->status);
        // 重复取消不能把已释放的房量再算一遍
        $this->assertSame(3, $this->remaining());
    }

    public function test_cancel_with_wrong_phone_is_404_and_keeps_the_booking()
    {
        $booking = $this->makeBooking(self::PHONE_MINE, ['order_no' => 'YTMINE0000009']);

        $this->post('/fly/orders/' . $booking->id . '/cancel', ['phone' => self::PHONE_OTHER])->assertNotFound();
        $this->post('/fly/orders/' . $booking->id . '/cancel', [])->assertNotFound();

        $this->assertSame(Booking::STATUS_BOOKED, (int) $booking->fresh()->status);
        $this->assertSame(2, $this->remaining());
    }

    public function test_cancel_does_not_touch_an_order_that_is_already_checked_in()
    {
        // 核销入住是后台的事；C 端把「已入住」的单取消掉等于把房量放回市场
        $booking = $this->makeBooking(self::PHONE_MINE, [
            'order_no' => 'YTMINE0000010',
            'status'   => Booking::STATUS_CHECKED_IN,
        ]);

        $this->post('/fly/orders/' . $booking->id . '/cancel', ['phone' => self::PHONE_MINE])
            ->assertSessionHas('flyFlashError')
            ->assertSessionMissing('flyFlash');

        $this->assertStringContainsString('无需重复操作', (string) $this->statusFlash());
        $this->assertSame(Booking::STATUS_CHECKED_IN, (int) $booking->fresh()->status);
        $this->assertSame(2, $this->remaining(), '已入住仍占用房量');
    }

    /** 最近一次 redirect 里 flash 的错误文案（读 session 而不是重跑请求，避免把 flash 老化掉） */
    private function statusFlash()
    {
        return $this->app['session.store']->get('flyFlashError');
    }

    // ================================================================= JSON

    public function test_api_bookings_are_masked_and_public()
    {
        $booking = $this->makeBooking(self::PHONE_MINE, ['order_no' => 'YTMINE0000011']);

        $response = $this->getJson('/api/fly/bookings?phone=' . self::PHONE_MINE)
            ->assertStatus(200)
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.items.0.order_no', 'YTMINE0000011');

        // 接口返回的姓名/号码一律脱敏：拿到别人订单号的人不该从 JSON 里再读到一次联系方式
        // （data.phone 是调用方自己输入的查询凭证，不参与脱敏）
        $this->assertSame('张*', $response->json('data.items.0.guest_name'));
        $this->assertSame('137****7000', $response->json('data.items.0.guest_phone'));
        $this->assertTrue($response->json('data.items.0.can_cancel'));
        $this->assertStringNotContainsString('13700137000', (string) json_encode($response->json('data.items')));

        $this->post('/fly/orders/' . $booking->id . '/cancel', ['phone' => self::PHONE_MINE]);

        $after = $this->getJson('/api/fly/bookings?phone=' . self::PHONE_MINE . '&order_no=YTMINE0000011');
        $this->assertFalse($after->json('data.items.0.can_cancel'));
        $this->assertSame('已取消', $after->json('data.items.0.status_label'));

        // 别人的手机号查不到我的单，反之亦然
        $this->getJson('/api/fly/bookings?phone=' . self::PHONE_OTHER)->assertJsonPath('data.items', []);
        $this->getJson('/api/fly/bookings')->assertStatus(422)->assertJsonPath('code', 422);
    }
}
