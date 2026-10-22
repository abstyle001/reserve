<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 库存口径与搜索过滤（只读 JSON /api/fly/*）。
 *
 * 这里钉住的是「剩几间」这一件事：AvailabilityService 是首页卡片、详情页、
 * 下单写接口三处共用的唯一实现，口径错三处一起错，所以断言全部走接口返回值，
 * 不在测试里重算一遍减法（那样测试只会复现实现里的错）。
 *
 * 日期一律相对「今天」生成：config('fly.timezone') 之后的日子才不会被
 * normalize() 夹回今天，测试也不会在某天集体变红。
 */
class FlyAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @var string 查询用的入住日（今天 + 5，留足跨度给相邻区间用例） */
    private $checkIn;

    /** @var string 查询用的离店日 */
    private $checkOut;

    /** @var int room_types 上有 (hotel_id, name) 唯一索引，同店多房型必须换名 */
    private $typeSeq = 0;

    /** @var int rooms 上有 (hotel_id, room_no) 唯一索引，房号全局自增 */
    private $roomSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkIn = $this->day(5);
        $this->checkOut = $this->day(6);
    }

    private function day($offset)
    {
        return Carbon::now(config('fly.timezone'))->addDays($offset)->toDateString();
    }

    private function makeHotel($name, $city, $overrides = [])
    {
        return Hotel::create(array_merge([
            'name'   => $name,
            'city'   => $city,
            'status' => Hotel::STATUS_OPEN,
        ], $overrides));
    }

    private function makeRoomType(Hotel $hotel, $price, $overrides = [])
    {
        $this->typeSeq++;

        return RoomType::create(array_merge([
            'hotel_id' => $hotel->id,
            'name'     => $hotel->name . ' 房型' . $this->typeSeq,
            'price'    => $price,
            'capacity' => 2,
        ], $overrides));
    }

    /** @return Room[] 建 count 间可售房（status 默认空闲） */
    private function makeRooms(RoomType $type, $count, $status = Room::STATUS_VACANT)
    {
        $rooms = [];

        for ($i = 0; $i < $count; $i++) {
            $this->roomSeq++;
            $rooms[] = Room::create([
                'hotel_id'     => $type->hotel_id,
                'room_type_id' => $type->id,
                'room_no'      => sprintf('%03d', $this->roomSeq),
                'floor'        => 1,
                'status'       => $status,
            ]);
        }

        return $rooms;
    }

    private function makeBooking(RoomType $type, $checkIn, $checkOut, $roomCount = 1, $overrides = [])
    {
        return Booking::create(array_merge([
            'order_no'        => 'YT' . microtime(true) . random_int(1000, 9999),
            'hotel_id'        => $type->hotel_id,
            'room_type_id'    => $type->id,
            'check_in'        => $checkIn,
            'check_out'       => $checkOut,
            'nights'          => Booking::nightsBetween($checkIn, $checkOut),
            'room_count'      => $roomCount,
            'price'           => $type->price,
            'total_amount'    => (float) $type->price * $roomCount,
            'guest_name'      => '张三',
            'guest_phone'     => '13700137000',
            'status'          => Booking::STATUS_BOOKED,
            'idempotency_key' => 'k' . uniqid(),
        ], $overrides));
    }

    /** 详情页接口里某个房型的剩余间数 */
    private function remaining(RoomType $type, $checkIn, $checkOut)
    {
        $response = $this->getJson('/api/fly/hotels/' . $type->hotel_id . '/room-types'
            . '?check_in=' . $checkIn . '&check_out=' . $checkOut);

        $response->assertStatus(200)->assertJsonPath('code', 0);

        foreach ($response->json('data.items') as $item) {
            if ((int) $item['id'] === (int) $type->id) {
                return (int) $item['remaining'];
            }
        }

        $this->fail('房型未出现在只读接口返回里');
    }

    /** 列表接口里某家酒店的卡片（按城市筛，避免依赖默认排序） */
    private function card($hotel, $checkIn = null, $checkOut = null)
    {
        $response = $this->getJson('/api/fly/hotels?city=' . rawurlencode($hotel->city)
            . '&check_in=' . ($checkIn === null ? $this->checkIn : $checkIn)
            . '&check_out=' . ($checkOut === null ? $this->checkOut : $checkOut));

        $response->assertStatus(200)->assertJsonPath('code', 0);

        foreach ($response->json('data.items') as $item) {
            if ((int) $item['id'] === (int) $hotel->id) {
                return $item;
            }
        }

        $this->fail('酒店未出现在列表接口返回里：' . $hotel->name);
    }

    // ================================================================= 过滤口径

    public function test_city_filter_is_exact_match()
    {
        $this->makeRooms($this->makeRoomType($this->makeHotel('外滩酒店', '上海'), 600), 2);
        $this->makeRooms($this->makeRoomType($this->makeHotel('临港酒店', '上海市'), 600), 2);

        $shanghai = $this->getJson('/api/fly/hotels?city=上海')->assertJsonPath('code', 0);
        $shanghai->assertJsonPath('data.total', 1);
        $this->assertSame('外滩酒店', $shanghai->json('data.items.0.name'));

        // 精确匹配才会与城市卡上的「N 家酒店」对上：like 会把「上海市」一起捞进来
        $city = $this->getJson('/api/fly/hotels?city=上海市')->assertJsonPath('code', 0);
        $this->assertSame('临港酒店', $city->json('data.items.0.name'));
    }

    public function test_keyword_hits_tags_and_escapes_like_wildcards()
    {
        $hotel = $this->makeHotel('和平饭店', '上海');
        $hotel->forceFill(['tags' => '外滩江景,含早餐', 'district' => '黄浦区'])->save();
        $this->makeRooms($this->makeRoomType($hotel, 1288), 2);

        $other = $this->makeHotel('静安酒店', '上海');
        $this->makeRooms($this->makeRoomType($other, 500), 2);

        $this->getJson('/api/fly/hotels?q=' . rawurlencode('早餐'))
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.name', '和平饭店');

        // 未转义的话 '%' 会命中所有酒店
        $this->getJson('/api/fly/hotels?q=%')->assertJsonPath('data.total', 0);
    }

    public function test_hotel_without_room_types_is_excluded()
    {
        $this->makeRooms($this->makeRoomType($this->makeHotel('有房型', '上海'), 600), 2);
        $this->makeHotel('没房型', '上海');

        $this->getJson('/api/fly/hotels?city=上海')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.name', '有房型');
    }

    public function test_closed_hotel_is_hidden_from_api_and_pages()
    {
        $type = $this->makeRoomType($this->makeHotel('关门店', '上海', ['status' => Hotel::STATUS_CLOSED]), 600);
        $this->makeRooms($type, 2);

        // 模型绑定查得到，但内容不能出：详情 404，接口给业务失败而不是空列表
        $this->get('/fly/hotels/' . $type->hotel_id)->assertNotFound();
        $this->get('/fly/hotels/' . $type->hotel_id . '/book')->assertNotFound();
        $this->getJson('/api/fly/hotels/' . $type->hotel_id . '/room-types')
            ->assertStatus(200)
            ->assertJsonPath('code', 1)
            ->assertJsonPath('message', '酒店不存在或已下架');
    }

    public function test_read_endpoints_and_pages_are_public()
    {
        $hotel = $this->makeHotel('外滩酒店', '上海');
        $this->makeRooms($this->makeRoomType($hotel, 600), 2);

        // 顾客全程不登录：这三个入口任何一个要求登录，C 端就废了
        $this->getJson('/api/fly/cities')->assertOk()->assertJsonPath('code', 0);
        $this->getJson('/api/fly/hotels')->assertOk()->assertJsonPath('code', 0);
        $this->getJson('/api/fly/hotels/' . $hotel->id . '/room-types')->assertOk()->assertJsonPath('code', 0);
        $this->getJson('/api/fly/bookings?phone=13700137000')->assertOk()->assertJsonPath('code', 0);

        $this->get('/fly')->assertOk();
        $this->get('/fly/hotels/' . $hotel->id)->assertOk();
        $this->get('/fly/orders')->assertOk();
    }

    // ================================================================= 房量口径

    public function test_overlapping_booking_subtracts_remaining()
    {
        $type = $this->makeRoomType($this->makeHotel('外滩酒店', '上海'), 600);
        $this->makeRooms($type, 3);

        $this->assertSame(3, $this->remaining($type, $this->checkIn, $this->checkOut));

        // 一行订单占 2 间：room_count 是求和项，不是行数
        $this->makeBooking($type, $this->checkIn, $this->checkOut, 2);
        $this->assertSame(1, $this->remaining($type, $this->checkIn, $this->checkOut));

        $this->makeBooking($type, $this->checkIn, $this->checkOut, 1);
        $this->assertSame(0, $this->remaining($type, $this->checkIn, $this->checkOut));
    }

    public function test_adjacent_ranges_do_not_conflict()
    {
        $type = $this->makeRoomType($this->makeHotel('外滩酒店', '上海'), 600);
        $this->makeRooms($type, 1);

        // 半开区间 [check_in, check_out)：上一位客人离店当天就是下一位的入住当天
        $this->makeBooking($type, $this->day(5), $this->day(6), 1);

        $this->assertSame(0, $this->remaining($type, $this->day(5), $this->day(6)));
        $this->assertSame(1, $this->remaining($type, $this->day(6), $this->day(7)));

        // 部分重叠仍然算冲突
        $this->assertSame(0, $this->remaining($type, $this->day(4), $this->day(6)));
    }

    public function test_cancelled_booking_releases_inventory()
    {
        $type = $this->makeRoomType($this->makeHotel('外滩酒店', '上海'), 600);
        $this->makeRooms($type, 2);

        $booking = $this->makeBooking($type, $this->checkIn, $this->checkOut, 2);
        $this->assertSame(0, $this->remaining($type, $this->checkIn, $this->checkOut));

        $booking->forceFill(['status' => Booking::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
        $this->assertSame(2, $this->remaining($type, $this->checkIn, $this->checkOut));

        // 已入住仍占房量：将来加核销不必改库存口径
        $booking->forceFill(['status' => Booking::STATUS_CHECKED_IN])->save();
        $this->assertSame(0, $this->remaining($type, $this->checkIn, $this->checkOut));
    }

    public function test_disabled_room_is_not_sellable_but_occupied_one_is()
    {
        $type = $this->makeRoomType($this->makeHotel('外滩酒店', '上海'), 600);
        $this->makeRooms($type, 1, Room::STATUS_VACANT);
        $this->makeRooms($type, 1, Room::STATUS_OCCUPIED);
        $this->makeRooms($type, 1, Room::STATUS_CLEANING);
        $this->makeRooms($type, 1, Room::STATUS_DISABLED);

        // 「在住/打扫中」是此刻的客房状态，与未来某天无关；只有停用才永久不可售
        $this->assertSame(3, $this->remaining($type, $this->checkIn, $this->checkOut));
    }

    public function test_min_price_ignores_sold_out_room_types()
    {
        $hotel = $this->makeHotel('外滩酒店', '上海');
        $cheap = $this->makeRoomType($hotel, 300);
        $dear  = $this->makeRoomType($hotel, 900);
        $this->makeRooms($cheap, 1);
        $this->makeRooms($dear, 2);

        $this->makeBooking($cheap, $this->checkIn, $this->checkOut, 1);

        $card = $this->card($hotel);
        $this->assertFalse($card['sold_out']);
        // 起价只统计还订得出的房型，售罄的低价房型不能把起价拉低。
        // 断言走 min_price_text：JSON 序列化不带小数位（900.0 出去就是 900），前端展示的也是这个串
        $this->assertSame('900', $card['min_price_text']);
        $this->assertSame(900.0, (float) $card['min_price']);
        $this->assertSame(2, $card['remaining_total']);

        $this->makeBooking($dear, $this->checkIn, $this->checkOut, 2);

        $soldOut = $this->card($hotel);
        $this->assertTrue($soldOut['sold_out']);
        $this->assertNull($soldOut['min_price']);
        $this->assertNull($soldOut['min_price_text']);
        $this->assertSame(0, $soldOut['remaining_total']);
    }

    // ================================================================= 链接与分页

    public function test_card_links_carry_the_date_window()
    {
        $hotel = $this->makeHotel('外滩酒店', '上海');
        $this->makeRooms($this->makeRoomType($hotel, 600), 2);

        $card = $this->card($hotel, $this->day(10), $this->day(12));

        // 点进详情必须沿用搜索时的日期，否则房量口径会跳回「今晚一晚」
        $this->assertStringContainsString('check_in=' . $this->day(10), $card['detail_url']);
        $this->assertStringContainsString('check_out=' . $this->day(12), $card['detail_url']);
        $this->assertStringContainsString('check_in=' . $this->day(10), $card['book_url']);
    }

    public function test_page_overflow_falls_back_to_last_page()
    {
        $hotel = $this->makeHotel('外滩酒店', '上海');
        $this->makeRooms($this->makeRoomType($hotel, 600), 2);

        // 换筛选条件后停在旧页码是常态：有数据却报「没找到」比越界更糟
        $response = $this->getJson('/api/fly/hotels?city=上海&page=99');

        $response->assertJsonPath('code', 0)->assertJsonPath('data.total', 1);
        $this->assertSame('外滩酒店', $response->json('data.items.0.name'));
        $this->assertGreaterThan(0, count($response->json('data.items')));
    }

    public function test_dirty_dates_are_clamped_instead_of_erroring()
    {
        $hotel = $this->makeHotel('外滩酒店', '上海');
        $this->makeRooms($this->makeRoomType($hotel, 600), 2);

        // 倒挂 + 过去日期 + 非法格式：一律夹紧到默认窗口，不 500 也不白屏
        $response = $this->getJson('/api/fly/hotels?check_in=2000-01-01&check_out=2000-01-01&q=..')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $facets = $response->json('data.facets');
        $this->assertSame($this->day(0), $facets['check_in']);
        $this->assertSame($this->day(1), $facets['check_out']);
        $this->assertSame(1, $facets['nights']);

        // 格式不合法由接口挡下（422），页面侧则走 normalize 夹紧
        $this->getJson('/api/fly/hotels?check_in=abc')
            ->assertStatus(422)
            ->assertJsonPath('code', 422);
    }

    // ================================================================= 查询条数

    public function test_search_uses_a_constant_number_of_queries()
    {
        // 3 家酒店 × 4 房型 × 5 房间 + 一堆订单：页面上任何一家都不该多打一条 SQL
        for ($h = 0; $h < 3; $h++) {
            $hotel = $this->makeHotel('酒店' . $h, '上海');
            for ($t = 0; $t < 4; $t++) {
                $type = $this->makeRoomType($hotel, 300 + $t * 100);
                $this->makeRooms($type, 5);
                $this->makeBooking($type, $this->checkIn, $this->checkOut, 1);
            }
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->getJson('/api/fly/hotels?city=上海&check_in=' . $this->checkIn . '&check_out=' . $this->checkOut)
            ->assertJsonPath('code', 0);

        // paginate 的 count + items，加房型清单、可售聚合、占用聚合 = 5 条
        $this->assertCount(5, $queries, implode("\n", $queries));
        $this->assertSame(3, $response->json('data.total'));
        $this->assertCount(3, $response->json('data.items'));
    }
}
