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
 * 下单闭环（POST /fly/bookings）。
 *
 * 全程游客（无 auth）：C 端订单不属于 Breeze 用户，写路由只要 session + CSRF。
 * 这里钉三件事：
 *   1) 校验与业务失败的中文提示要落回表单（redirect back + withInput），不是 500；
 *   2) 金额与房量都是下单那一刻的快照，之后改房型价格不影响历史订单；
 *   3) 双击 / 重提被 idempotency_key 唯一索引挡下，只产生一单。
 *
 * 日期相对今天生成：check_in 早于今天会被规则拒掉，硬编码的日期过几天就会让用例集体变红。
 */
class FlyBookingTest extends TestCase
{
    use RefreshDatabase;

    /** @var Hotel */
    private $hotel;

    /** @var RoomType */
    private $roomType;

    /** @var int rooms 上有 (hotel_id, room_no) 唯一索引 */
    private $roomSeq = 0;

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

        $this->makeRooms($this->roomType, 3);
    }

    private function day($offset)
    {
        return Carbon::now(config('fly.timezone'))->addDays($offset)->toDateString();
    }

    private function makeRooms(RoomType $type, $count)
    {
        $rooms = [];

        for ($i = 0; $i < $count; $i++) {
            $this->roomSeq++;
            $rooms[] = Room::create([
                'hotel_id'     => $type->hotel_id,
                'room_type_id' => $type->id,
                'room_no'      => sprintf('%03d', $this->roomSeq),
                'floor'        => 10,
                'status'       => Room::STATUS_VACANT,
            ]);
        }

        return $rooms;
    }

    /** @return array 一份合法的下单表单参数 */
    private function payload($overrides = [])
    {
        return array_merge([
            'hotel_id'        => $this->hotel->id,
            'room_type_id'    => $this->roomType->id,
            'check_in'        => $this->day(5),
            'check_out'       => $this->day(7),
            'room_count'      => 1,
            'guest_name'      => '张三',
            'guest_phone'     => '13700137000',
            'remark'          => '高楼层',
            'idempotency_key' => 'key-' . uniqid(),
        ], $overrides);
    }

    /**
     * 取回表单错误里的第一条中文提示。
     *
     * 必须在发起下一个请求之前调用：errors 是 flash 数据，任何一次后续请求
     * （包括下面 remaining() 打的只读接口）都会把它老化掉。
     */
    private function firstError($key)
    {
        $bag = $this->app['session.store']->get('errors');

        return $bag === null ? null : $bag->getBag('default')->first($key);
    }

    /** 详情页接口里该房型的剩余间数：下单前后的口径复核点 */
    private function remaining($checkIn = null, $checkOut = null)
    {
        $response = $this->getJson('/api/fly/hotels/' . $this->hotel->id . '/room-types'
            . '?check_in=' . ($checkIn === null ? $this->day(5) : $checkIn)
            . '&check_out=' . ($checkOut === null ? $this->day(7) : $checkOut));

        $response->assertStatus(200)->assertJsonPath('code', 0);

        foreach ($response->json('data.items') as $item) {
            if ((int) $item['id'] === (int) $this->roomType->id) {
                return (int) $item['remaining'];
            }
        }

        $this->fail('房型未出现在只读接口返回里');
    }

    // ================================================================= 渲染

    public function test_book_page_is_public_and_prefills_the_date_window()
    {
        $this->get('/fly/hotels/' . $this->hotel->id . '/book'
            . '?check_in=' . $this->day(10) . '&check_out=' . $this->day(12) . '&room_type_id=' . $this->roomType->id)
            ->assertOk()
            ->assertSee('外滩江景大床房')
            // 日期是从详情页带过来的，不能静默跳回「今晚一晚」
            ->assertSee($this->day(10))
            ->assertSee('2 晚')
            ->assertSee('剩 3 间');
    }

    public function test_book_page_of_closed_hotel_is_404()
    {
        $this->hotel->forceFill(['status' => Hotel::STATUS_CLOSED])->save();

        $this->get('/fly/hotels/' . $this->hotel->id . '/book')->assertNotFound();
    }

    // ================================================================= 成功路径

    public function test_guest_can_book_without_login_and_price_is_snapshotted()
    {
        $response = $this->post('/fly/bookings', $this->payload(['room_count' => 2]));

        $response->assertStatus(302)->assertSessionHas('flyFlash');

        $booking = Booking::sole();

        $this->assertSame(Booking::STATUS_BOOKED, (int) $booking->status);
        $this->assertSame(Booking::SOURCE_FLY, $booking->source);
        $this->assertSame($this->day(5), $booking->check_in);
        $this->assertSame($this->day(7), $booking->check_out);
        $this->assertSame(2, (int) $booking->nights);
        $this->assertSame(2, (int) $booking->room_count);
        // 金额 = 单价 × 晚数 × 间数，全部是下单时的快照：后台随后改价不能影响历史订单
        $this->assertEquals(880.0, (float) $booking->price);
        $this->assertEquals(3520.0, (float) $booking->total_amount);
        $this->assertMatchesRegularExpression('/^YT\d{12}$/', $booking->order_no);

        // PRG：POST 完跳可收藏的结果页，Location 自带手机号凭证
        $this->get($response->headers->get('Location'))->assertOk()->assertSee($booking->order_no);
    }

    public function test_booking_assigns_distinct_rooms_and_reduces_availability()
    {
        $before = $this->remaining();

        $this->post('/fly/bookings', $this->payload(['room_count' => 2]))->assertStatus(302);

        $booking = Booking::sole();
        $assigned = (array) $booking->room_ids;

        $this->assertCount(2, $assigned);
        $this->assertSame($assigned[0], $booking->room_id, '主房间必须是分配列表里的第一间');
        $this->assertCount(2, array_unique($assigned), '同一订单不能分到同一间房两次');

        // room_id 存主房间、room_ids 存全量：分配时两个字段都要排除，否则次房间会被重复卖
        $this->assertSame($before - 2, $this->remaining());

        $this->post('/fly/bookings', $this->payload(['room_count' => 1]))->assertStatus(302);

        $second = Booking::query()->orderByDesc('id')->first();
        $this->assertEmpty(array_intersect($assigned, (array) $second->room_ids));
        $this->assertSame(0, $this->remaining());
    }

    // ================================================================= 业务失败

    public function test_more_rooms_than_left_are_rejected()
    {
        $response = $this->post('/fly/bookings', $this->payload(['room_count' => 4]));

        $response->assertStatus(302)->assertSessionHasErrors('submit');
        $message = $this->firstError('submit');

        $this->assertStringContainsString('仅剩 3 间', (string) $message);
        $this->assertSame(0, Booking::count());
        $this->assertSame(3, $this->remaining(), '失败的下单不得占用房量');
    }

    public function test_sold_out_room_type_gives_a_clear_message()
    {
        $this->post('/fly/bookings', $this->payload(['room_count' => 3]))->assertStatus(302);
        $this->assertSame(0, $this->remaining());

        $response = $this->post('/fly/bookings', $this->payload(['room_count' => 1]));
        $response->assertSessionHasErrors('submit');

        $this->assertStringContainsString('已售罄', (string) $this->firstError('submit'));
        $this->assertSame(1, Booking::count());
    }

    public function test_cross_hotel_room_type_is_rejected()
    {
        $other = Hotel::create(['name' => '西子湖酒店', 'city' => '杭州', 'status' => Hotel::STATUS_OPEN]);
        $otherType = RoomType::create(['hotel_id' => $other->id, 'name' => '湖景房', 'price' => 500, 'capacity' => 2]);
        $this->makeRooms($otherType, 2);

        // 篡改 room_type_id 或 hotel_id 都不给跨酒店下单：房量必须按归属配对扣
        $this->post('/fly/bookings', $this->payload(['room_type_id' => $otherType->id]))
            ->assertSessionHasErrors('submit');
        $this->assertStringContainsString('所选房型不存在', (string) $this->firstError('submit'));

        $this->post('/fly/bookings', $this->payload(['hotel_id' => $other->id]))
            ->assertSessionHasErrors('submit');
        $this->assertStringContainsString('所选房型不存在', (string) $this->firstError('submit'));

        $this->assertSame(0, Booking::count());
        $this->assertSame(3, $this->remaining());
    }

    public function test_closed_hotel_cannot_be_booked()
    {
        $this->hotel->forceFill(['status' => Hotel::STATUS_CLOSED])->save();

        $this->post('/fly/bookings', $this->payload())->assertSessionHasErrors('submit');

        $this->assertStringContainsString('暂停营业', (string) $this->firstError('submit'));
        $this->assertSame(0, Booking::count());
    }

    // ================================================================= 参数校验

    public function test_invalid_dates_are_rejected_with_chinese_messages()
    {
        $this->post('/fly/bookings', $this->payload(['check_in' => $this->day(-1)]))
            ->assertSessionHasErrors('check_in');
        $this->assertStringContainsString('不能早于今天', (string) $this->firstError('check_in'));

        $this->post('/fly/bookings', $this->payload(['check_in' => 'abc']))
            ->assertSessionHasErrors('check_in');
        $this->assertStringContainsString('格式不正确', (string) $this->firstError('check_in'));

        // 离店必须严格晚于入住：同一天不算一晚
        $this->post('/fly/bookings', $this->payload(['check_out' => $this->day(5)]))
            ->assertSessionHasErrors('check_out');
        $this->assertStringContainsString('必须晚于入住日期', (string) $this->firstError('check_out'));

        // 晚数上限没法用 date 规则表达（要的是两个字段之差），由 after 钩子补
        $this->post('/fly/bookings', $this->payload(['check_out' => $this->day(40)]))
            ->assertSessionHasErrors('check_out');
        $this->assertStringContainsString('最多预订 30 晚', (string) $this->firstError('check_out'));

        $this->assertSame(0, Booking::count());
    }

    public function test_invalid_contact_and_count_are_rejected()
    {
        foreach (['12345', '23700137000', '1370013700', '13700137000a'] as $phone) {
            $this->post('/fly/bookings', $this->payload(['guest_phone' => $phone]))
                ->assertSessionHasErrors('guest_phone');
            $this->assertStringContainsString('11 位大陆手机号', (string) $this->firstError('guest_phone'));
        }

        // 一次最多 10 间（config fly.max_rooms），0 间与负数同样不给过
        $this->post('/fly/bookings', $this->payload(['room_count' => 99]))
            ->assertSessionHasErrors('room_count');
        $this->assertStringContainsString('一次最多预订 10 间', (string) $this->firstError('room_count'));

        $this->post('/fly/bookings', $this->payload(['room_count' => 0]))
            ->assertSessionHasErrors('room_count');

        $this->post('/fly/bookings', $this->payload(['guest_name' => '']))
            ->assertSessionHasErrors('guest_name');

        // 表单失效（页面被缓存过）不能静默建单：没有幂等键就退回表单
        $this->post('/fly/bookings', $this->payload(['idempotency_key' => '']))
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(0, Booking::count());
    }

    // ================================================================= 幂等

    public function test_same_idempotency_key_creates_only_one_booking()
    {
        $payload = $this->payload(['idempotency_key' => 'key-double-click']);

        $first = $this->post('/fly/bookings', $payload);
        $this->post('/fly/bookings', $payload);
        $third = $this->post('/fly/bookings', $payload);

        $booking = Booking::sole();

        $third->assertSessionHasNoErrors();
        $this->assertSame($first->headers->get('Location'), $third->headers->get('Location'));

        // 重提不是报错，而是跳回同一张订单：用户看到的是「已经订好了」
        $this->assertStringContainsString('/fly/orders/' . $booking->id, (string) $third->headers->get('Location'));
        $this->assertSame(2, $this->remaining(), '重复提交不得二次占用房量');
    }

    public function test_a_new_key_cannot_book_rooms_the_first_order_sold_out()
    {
        // 刷新下单页会拿到新 key：上一单不取消的话，房量就是真的订完了
        $this->post('/fly/bookings', $this->payload(['room_count' => 3, 'idempotency_key' => 'key-a']))->assertStatus(302);

        $this->post('/fly/bookings', $this->payload(['room_count' => 1, 'idempotency_key' => 'key-b']))
            ->assertSessionHasErrors('submit');

        $this->assertSame(1, Booking::count());
        $this->assertSame(0, $this->remaining());
    }
}
