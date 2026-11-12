<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 自助取消时限：入住前一日 config('fly.cancel_deadline_time')（C 端时区）之后不可自助取消。
 *
 * 要钉住的是四条：
 *   1) 截止前能取消、截止后不能，且逾期请求绝不动状态与房量 —— 不是只把按钮藏了；
 *   2) 边界是整点即截止，基准是 config('fly.timezone') 而不是裸 UTC；
 *   3) 当天入住的单同样受约束（20:00 订今晚 = 下单即逾期），下单页与列表页提前告知政策；
 *   4) 时限只管 C 端自助取消，后台 /bookings 人工取消是逾期后的兜底通道。
 *
 * 时间一律 Carbon::setTestNow 冻结 + 相对日期，不写死绝对日期（过了那天用例会变红）。
 */
class FlyCancelDeadlineTest extends TestCase
{
    use RefreshDatabase;

    /** @var User 订单归属账号 */
    private $me;

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

        // 冻结在「今天 10:00（C 端时区）」：造数据与断言共用同一个现在，避免跨秒抖动
        Carbon::setTestNow(Carbon::now(config('fly.timezone'))->startOfDay()->addHours(10));

        $this->me = User::factory()->create();

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function day($offset)
    {
        return Carbon::now(config('fly.timezone'))->addDays($offset)->toDateString();
    }

    /** 把「现在」冻结到 C 端时区的某日某刻 */
    private function freezeAt($date, $time)
    {
        Carbon::setTestNow(Carbon::parse($date . ' ' . $time, config('fly.timezone')));
    }

    /**
     * 这单的截止日（入住前一日）。从 check_in 推而不是 day(4)：
     * 冻结「现在」之后相对偏移会跟着漂，断言就会拿错日期。
     */
    private function cutoffDay(Booking $booking)
    {
        return Carbon::parse($booking->check_in, config('fly.timezone'))->subDay()->toDateString();
    }

    private function makeBooking($overrides = [])
    {
        $this->seq++;

        return Booking::create(array_merge([
            'order_no'        => 'YTDL0000000' . str_pad((string) $this->seq, 2, '0', STR_PAD_LEFT),
            'user_id'         => $this->me->id,
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
            'guest_phone'     => '13700137000',
            'status'          => Booking::STATUS_BOOKED,
            'source'          => Booking::SOURCE_FLY,
            'idempotency_key' => 'dl-key-' . $this->seq,
        ], $overrides));
    }

    /** 只读接口里该房型在这单日期上的剩余间数：房量口径的唯一复核点 */
    private function remaining(Booking $booking)
    {
        $response = $this->getJson('/api/fly/hotels/' . $this->hotel->id . '/room-types'
            . '?check_in=' . $booking->check_in . '&check_out=' . $booking->check_out);

        $response->assertStatus(200)->assertJsonPath('code', 0);

        foreach ($response->json('data.items') as $item) {
            if ((int) $item['id'] === (int) $this->roomType->id) {
                return (int) $item['remaining'];
            }
        }

        $this->fail('房型未出现在只读接口返回里');
    }

    private function cancel(Booking $booking)
    {
        return $this->actingAs($this->me)->post('/fly/orders/' . $booking->id . '/cancel');
    }

    /** 最近一次 redirect 的错误文案（读 session，不重跑请求，免得把 flash 老化掉） */
    private function errorFlash()
    {
        return (string) $this->app['session.store']->get('flyFlashError');
    }

    // ================================================================= 写路径

    public function test_cancel_succeeds_before_the_deadline()
    {
        $booking = $this->makeBooking();
        $cutoff = $this->cutoffDay($booking);
        $this->freezeAt($cutoff, '17:59');

        $this->assertSame(2, $this->remaining($booking));

        $this->cancel($booking)
            ->assertStatus(302)
            ->assertSessionHas('flyFlash', '订单已取消，房量已释放。');

        $fresh = $booking->fresh();
        $this->assertSame(Booking::STATUS_CANCELLED, (int) $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame(3, $this->remaining($booking));
    }

    public function test_cancel_after_the_deadline_keeps_status_and_room()
    {
        $booking = $this->makeBooking();
        $cutoff = $this->cutoffDay($booking);
        $this->freezeAt($cutoff, '18:01');

        $this->cancel($booking)
            ->assertStatus(302)
            ->assertSessionHas('flyFlashError')
            ->assertSessionMissing('flyFlash');

        $this->assertStringContainsString('已过自助取消截止时间', $this->errorFlash());
        $this->assertStringContainsString($cutoff . ' 18:00', $this->errorFlash());

        $fresh = $booking->fresh();
        $this->assertSame(Booking::STATUS_BOOKED, (int) $fresh->status, '逾期请求不能改状态');
        $this->assertNull($fresh->cancelled_at);
        $this->assertSame(2, $this->remaining($booking), '逾期请求不能释放房量');
    }

    public function test_the_deadline_is_the_configured_clock_time_sharp()
    {
        $booking = $this->makeBooking();
        $cutoff = $this->cutoffDay($booking);

        // 18:00:00 整点即截止，前一秒还放行
        $this->freezeAt($cutoff, '17:59:59');
        $this->assertTrue($booking->can_cancel);

        $this->freezeAt($cutoff, '18:00:00');
        $this->assertFalse($booking->can_cancel);

        $this->cancel($booking)->assertSessionHas('flyFlashError');
        $this->assertSame(Booking::STATUS_BOOKED, (int) $booking->fresh()->status);
    }

    public function test_same_day_booking_is_already_past_the_deadline()
    {
        // 20:00 订今晚：截止时刻是昨天 18:00，下单那一刻就已逾期（严格按时限，不给宽限）
        $booking = $this->makeBooking([
            'check_in'  => $this->day(0),
            'check_out' => $this->day(1),
        ]);
        $cutoff = $this->cutoffDay($booking);
        $this->freezeAt($booking->check_in, '20:00');

        $this->assertFalse($booking->can_cancel);
        $this->assertSame($cutoff . ' 18:00', $booking->cancel_deadline_text);

        $this->cancel($booking)->assertSessionHas('flyFlashError');
        $this->assertSame(Booking::STATUS_BOOKED, (int) $booking->fresh()->status);
        $this->assertSame(2, $this->remaining($booking));
    }

    public function test_deadline_uses_the_fly_timezone_not_utc()
    {
        $booking = $this->makeBooking();
        $cutoff = $this->cutoffDay($booking);

        // C 端 18:30 == UTC 10:30。裸用 now()（app.timezone=UTC）会以为还没到 18:00 而放行
        Carbon::setTestNow(Carbon::parse($cutoff . ' 10:30', 'UTC'));
        $this->assertSame('18:30', Carbon::now(config('fly.timezone'))->format('H:i'));

        $this->assertFalse($booking->can_cancel);
        $this->cancel($booking)->assertSessionHas('flyFlashError');
        $this->assertSame(Booking::STATUS_BOOKED, (int) $booking->fresh()->status);
    }

    public function test_deadline_time_comes_from_config()
    {
        config(['fly.cancel_deadline_time' => '12:00']);
        $this->assertSame('入住前一日 12:00 前可自助取消', Booking::cancelPolicyText());

        $booking = $this->makeBooking();
        $cutoff = $this->cutoffDay($booking);
        $this->assertSame($cutoff . ' 12:00', $booking->cancel_deadline_text);

        $this->freezeAt($cutoff, '11:00');
        $this->assertTrue($booking->can_cancel);

        $this->freezeAt($cutoff, '13:00');
        $this->assertFalse($booking->can_cancel);
        $this->cancel($booking)->assertSessionHas('flyFlashError');
        $this->assertSame(Booking::STATUS_BOOKED, (int) $booking->fresh()->status);
    }

    public function test_checked_in_order_reports_status_not_deadline()
    {
        // 已入住的单即使也过了时限，提示仍该是「无需重复操作」，别把状态问题说成时限问题
        $booking = $this->makeBooking(['status' => Booking::STATUS_CHECKED_IN]);
        $this->freezeAt($this->cutoffDay($booking), '18:01');

        $this->cancel($booking)->assertSessionHas('flyFlashError');

        $this->assertStringContainsString('无需重复操作', $this->errorFlash());
        $this->assertStringNotContainsString('已过自助取消截止时间', $this->errorFlash());
        $this->assertSame(Booking::STATUS_CHECKED_IN, (int) $booking->fresh()->status);
        $this->assertSame(2, $this->remaining($booking), '已入住仍占用房量');
    }

    public function test_admin_cancel_ignores_the_deadline()
    {
        // 时限只约束 C 端自助取消；逾期后顾客打电话给酒店，后台仍要能人工取消并还房量
        $booking = $this->makeBooking();
        $this->freezeAt($this->cutoffDay($booking), '18:01');

        $this->actingAs($this->me)->post('/bookings/' . $booking->id . '/cancel')
            ->assertStatus(302)
            ->assertSessionHas('success');

        $this->assertSame(Booking::STATUS_CANCELLED, (int) $booking->fresh()->status);
        $this->assertSame(3, $this->remaining($booking));
    }

    // ================================================================= 展示

    public function test_detail_page_drops_the_cancel_form_after_the_deadline()
    {
        $booking = $this->makeBooking();
        $cutoff = $this->cutoffDay($booking);

        $this->freezeAt($cutoff, '17:00');
        $this->actingAs($this->me)->get('/fly/orders/' . $booking->id)
            ->assertOk()
            ->assertSee('取消订单')
            ->assertSee('自助取消截止 ' . $cutoff . ' 18:00')
            ->assertDontSee('已过取消时限');

        $this->freezeAt($cutoff, '18:01');
        $this->actingAs($this->me)->get('/fly/orders/' . $booking->id)
            ->assertOk()
            ->assertSee('已过取消时限')
            ->assertSee('已过自助取消截止时间（' . $cutoff . ' 18:00）')
            ->assertSee('95010')
            // 表单整个不渲染：留着按钮只会让用户点了才知道失败
            ->assertDontSee('取消订单')
            ->assertDontSee('/fly/orders/' . $booking->id . '/cancel', false);
    }

    public function test_book_page_and_order_list_state_the_policy()
    {
        $policy = Booking::cancelPolicyText();

        $this->actingAs($this->me)->get('/fly/hotels/' . $this->hotel->id . '/book'
            . '?check_in=' . $this->day(5) . '&check_out=' . $this->day(6)
            . '&room_type_id=' . $this->roomType->id)
            ->assertOk()
            ->assertSee($policy)
            ->assertSee('逾期请联系酒店处理');

        $this->makeBooking();
        $this->actingAs($this->me)->get('/fly/orders')
            ->assertOk()
            ->assertSee($policy);
    }
}