<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 云途旅行（/fly）演示数据 —— 只在 local 环境跑，生产无关。
 *
 * 用途：现库只有 2 家酒店且 city 全为空，撑不起「6 卡 + 8 城市」的版面，
 * 也无法验证日期维度的房量扣减。这里造 8 个城市 × 14 家酒店（含 1 家停用、
 * 1 家今晚售罄、1 家未填评分点评）+ 房型 + 房间 + 若干订单。
 *
 * 数据全部写死、不用随机，保证每次跑出来一样，便于对照 curl 结果。
 * 重跑安全：按 remark 标记先删干净再造。
 *
 * 用法：php artisan db:seed --class=FlyDemoSeeder
 */
class FlyDemoSeeder extends Seeder
{
    const MARK = 'fly-demo';          // 打在 hotels.remark 上，作为清理依据
    const ORDER_PREFIX = 'YTDEMO';    // 演示订单号前缀

    /**
     * 演示查单手机号：跑完会打印，可在 /fly/orders 直接输入
     */
    const DEMO_PHONE = '13800001111';

    public function run()
    {
        if (!app()->environment('local')) {
            if ($this->command) {
                $this->command->warn('FlyDemoSeeder 是演示数据，只在 local 环境运行，已跳过。');
            }

            return;
        }

        $this->clean();

        $today = Carbon::now(config('fly.timezone'))->startOfDay();

        foreach ($this->hotels() as $spec) {
            $hotel = Hotel::create([
                'name'         => $spec['name'],
                'city'         => $spec['city'],
                'district'     => $spec['district'],
                'address'      => $spec['address'],
                'phone'        => $spec['phone'],
                'star_level'   => $spec['star'],
                'score'        => $spec['score'],
                'review_count' => $spec['reviews'],
                'tags'         => $spec['tags'],
                'status'       => $spec['status'],
                'remark'       => self::MARK,
            ]);

            foreach ($spec['types'] as $typeIndex => $type) {
                $roomType = RoomType::create([
                    'hotel_id'    => $hotel->id,
                    'name'        => $type['name'],
                    'price'       => $type['price'],
                    'capacity'    => $type['capacity'],
                    'bed_type'    => $type['bed'],
                    'area'        => $type['area'],
                    'description' => $type['desc'],
                ]);

                // 房号按房型分层：3 层起，同一酒店内不重复（rooms 有 hotel_id + room_no 唯一约束）
                $floor = 3 + $typeIndex;
                for ($i = 0; $i < $type['rooms']; $i++) {
                    // 状态分布刻意混入「打扫中 / 在住」：它们可售（是此刻的客房状态），
                    // 只有「停用」不可售 —— 这条口径由 Room::scopeSellable 保证。
                    $status = Room::STATUS_VACANT;
                    if (!empty($type['clean']) && $i === 0) {
                        $status = Room::STATUS_CLEANING;
                    }
                    if (!empty($type['occupied']) && $i === 0) {
                        $status = Room::STATUS_OCCUPIED;
                    }
                    if (!empty($type['disabled']) && $i === 0) {
                        $status = Room::STATUS_DISABLED;
                    }

                    Room::create([
                        'hotel_id'     => $hotel->id,
                        'room_type_id' => $roomType->id,
                        'room_no'      => sprintf('%d%02d', $floor, $i + 1),
                        'floor'        => $floor,
                        'status'       => $status,
                    ]);
                }
            }
        }

        $this->seedBookings($today);

        $this->command->line(sprintf(
            '云途演示数据：%d 家酒店 / %d 个房型 / %d 间房 / %d 张订单',
            Hotel::where('remark', self::MARK)->count(),
            RoomType::whereHas('hotel', function ($q) { $q->where('remark', self::MARK); })->count(),
            Room::whereHas('hotel', function ($q) { $q->where('remark', self::MARK); })->count(),
            Booking::where('source', self::MARK)->count()
        ));
        $this->command->line('查单演示手机号：' . self::DEMO_PHONE . '（/fly/orders）');
    }

    private function clean()
    {
        $hotelIds = Hotel::query()->where('remark', self::MARK)->pluck('id')->all();

        if (!empty($hotelIds)) {
            DB::table('bookings')->whereIn('hotel_id', $hotelIds)->delete();
            DB::table('rooms')->whereIn('hotel_id', $hotelIds)->delete();
            DB::table('room_types')->whereIn('hotel_id', $hotelIds)->delete();
            DB::table('hotels')->whereIn('id', $hotelIds)->delete();
        }

        // 兜底：清掉历史遗留的演示订单（酒店行已被删过的情况）
        DB::table('bookings')->where('source', self::MARK)->delete();
    }

    /**
     * 演示订单：offset 是相对「今天」的天数，跑多少次都贴着当前日期。
     *
     * 覆盖的场景：
     *  - 今晚订光 2 间 → 该酒店卡片 sold_out
     *  - 已取消订单（status=2）不占房量 → 验证释放
     *  - 相邻订单 A 的离店 == B 的入住 → 半开区间不冲突
     *  - 跨今晚的 3 晚长住
     *  - 明天入住、与今天不重叠
     */
    private function seedBookings(Carbon $today)
    {
        $specs = [
            // [酒店名, 房型名, 入住偏移, 离店偏移, 间数, 手机号, 状态, 姓名]
            ['上海南京路云途经济酒店', '单人房', 0, 1, 2, self::DEMO_PHONE, Booking::STATUS_BOOKED, '张伟'],
            ['上海外滩云途酒店', '高级大床房', 0, 1, 2, '13900002222', Booking::STATUS_BOOKED, '李娜'],
            ['上海外滩云途酒店', '江景双床房', 0, 2, 1, self::DEMO_PHONE, Booking::STATUS_CANCELLED, '王强'],
            ['北京国贸云途酒店', '行政大床房', -1, 2, 1, '13700003333', Booking::STATUS_CHECKED_IN, '赵敏'],
            ['杭州西湖云途酒店', '标准大床房', 0, 1, 1, self::DEMO_PHONE, Booking::STATUS_BOOKED, '陈晨'],
            ['杭州西湖云途酒店', '标准大床房', 1, 2, 1, '13600004444', Booking::STATUS_BOOKED, '刘洋'],
            ['三亚亚龙湾云途度假酒店', '海景套房', 2, 5, 1, '13500005555', Booking::STATUS_BOOKED, '孙悦'],
            ['西安钟楼云途酒店', '标准双床房', 0, 1, 1, self::DEMO_PHONE, Booking::STATUS_BOOKED, '周杰'],
        ];

        $seq = 0;
        foreach ($specs as $spec) {
            list($hotelName, $typeName, $inOffset, $outOffset, $count, $phone, $status, $guestName) = $spec;

            $hotel = Hotel::query()->where('name', $hotelName)->first();
            if (!$hotel) {
                continue;
            }

            $roomType = RoomType::query()
                ->where('hotel_id', $hotel->id)
                ->where('name', $typeName)
                ->first();
            if (!$roomType) {
                continue;
            }

            $checkIn = $today->copy()->addDays($inOffset)->toDateString();
            $checkOut = $today->copy()->addDays($outOffset)->toDateString();
            $nights = Booking::nightsBetween($checkIn, $checkOut);

            // 已取消的订单也留房号，验证「软状态流转后行不删、历史可查」
            $rooms = Room::query()
                ->where('room_type_id', $roomType->id)
                ->orderBy('id')
                ->limit($count)
                ->pluck('id')
                ->all();

            $seq++;
            $price = (float) $roomType->price;

            Booking::create([
                'order_no'        => self::ORDER_PREFIX . str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                'hotel_id'        => $hotel->id,
                'room_type_id'    => $roomType->id,
                'room_id'         => isset($rooms[0]) ? $rooms[0] : null,
                'room_ids'        => $rooms,
                'check_in'        => $checkIn,
                'check_out'       => $checkOut,
                'nights'          => $nights,
                'room_count'      => $count,
                'price'           => $price,
                'total_amount'    => $price * $nights * $count,
                'guest_name'      => $guestName,
                'guest_phone'     => $phone,
                'remark'          => '云途演示订单',
                'status'          => $status,
                'source'          => self::MARK,
                'idempotency_key' => self::MARK . '-' . $seq,
                'cancelled_at'    => $status === Booking::STATUS_CANCELLED ? $today->copy() : null,
            ]);
        }
    }

    private function hotels()
    {
        return [
            [
                'name' => '上海外滩云途酒店', 'city' => '上海', 'district' => '黄浦区', 'address' => '中山东一路 18 号',
                'phone' => '021-63330001', 'star' => 5, 'score' => '4.9', 'reviews' => 21345,
                'tags' => '含双早,免费取消,江景,地铁 2 号线', 'status' => 1,
                'types' => [
                    ['name' => '高级大床房', 'price' => 880, 'capacity' => 2, 'bed' => '1.8m 大床', 'area' => 32, 'rooms' => 3, 'desc' => '城景，含双早'],
                    ['name' => '江景双床房', 'price' => 1280, 'capacity' => 2, 'bed' => '1.2m 双床', 'area' => 38, 'rooms' => 3, 'desc' => '正对陆家嘴天际线'],
                    ['name' => '家庭套房', 'price' => 1980, 'capacity' => 4, 'bed' => '大床 + 儿童床', 'area' => 58, 'rooms' => 2, 'desc' => '一室一厅，可加床', 'clean' => true],
                ],
            ],
            [
                'name' => '上海迪士尼周边云途酒店', 'city' => '上海', 'district' => '浦东新区', 'address' => '川沙镇华夏东路 2 号',
                'phone' => '021-63330002', 'star' => 4, 'score' => '4.6', 'reviews' => 3210,
                'tags' => '含双早,接驳车,亲子房', 'status' => 1,
                'types' => [
                    ['name' => '舒适双床房', 'price' => 520, 'capacity' => 2, 'bed' => '1.2m 双床', 'area' => 28, 'rooms' => 3, 'desc' => '免费园区接驳'],
                    ['name' => '亲子主题房', 'price' => 760, 'capacity' => 3, 'bed' => '大床 + 帐篷床', 'area' => 36, 'rooms' => 2, 'desc' => '卡通布置，可加婴儿床', 'occupied' => true],
                ],
            ],
            [
                'name' => '上海南京路云途经济酒店', 'city' => '上海', 'district' => '黄浦区', 'address' => '南京东路 300 号',
                'phone' => '021-63330003', 'star' => 2, 'score' => '3.4', 'reviews' => 45,
                'tags' => '地铁 2 号线', 'status' => 1,
                // 只有 2 间单人房，演示数据里今晚全部订光 → 卡片 sold_out
                'types' => [
                    ['name' => '单人房', 'price' => 210, 'capacity' => 1, 'bed' => '1.5m 大床', 'area' => 18, 'rooms' => 2, 'desc' => '无窗'],
                ],
            ],
            [
                'name' => '杭州西湖云途酒店', 'city' => '杭州', 'district' => '西湖区', 'address' => '北山街 96 号',
                'phone' => '0571-87730001', 'star' => 5, 'score' => '4.8', 'reviews' => 18200,
                'tags' => '湖景,含双早,免费取消', 'status' => 1,
                'types' => [
                    ['name' => '标准大床房', 'price' => 690, 'capacity' => 2, 'bed' => '1.8m 大床', 'area' => 30, 'rooms' => 2, 'desc' => '庭院景'],
                    ['name' => '湖景套房', 'price' => 1580, 'capacity' => 3, 'bed' => '1.8m 大床 + 沙发床', 'area' => 52, 'rooms' => 2, 'desc' => '推窗见断桥', 'clean' => true],
                ],
            ],
            [
                'name' => '杭州千岛湖云途度假酒店', 'city' => '杭州', 'district' => '淳安县', 'address' => '千岛湖镇环湖东路 1 号',
                'phone' => '0571-87730002', 'star' => 4, 'score' => '4.4', 'reviews' => 860,
                'tags' => '温泉,亲子房,含双早', 'status' => 1,
                'types' => [
                    ['name' => '山景大床房', 'price' => 450, 'capacity' => 2, 'bed' => '1.8m 大床', 'area' => 32, 'rooms' => 2, 'desc' => '含双人早餐'],
                    ['name' => '温泉私汤房', 'price' => 880, 'capacity' => 2, 'bed' => '1.8m 大床', 'area' => 40, 'rooms' => 2, 'desc' => '房内私汤', 'disabled' => true],
                ],
            ],
            [
                'name' => '北京国贸云途酒店', 'city' => '北京', 'district' => '朝阳区', 'address' => '建国门外大街 1 号',
                'phone' => '010-65050001', 'star' => 5, 'score' => '4.7', 'reviews' => 9600,
                'tags' => '含双早,免费取消,商务', 'status' => 1,
                'types' => [
                    ['name' => '行政大床房', 'price' => 1020, 'capacity' => 2, 'bed' => '1.8m 大床', 'area' => 36, 'rooms' => 3, 'desc' => '行政酒廊礼遇'],
                    ['name' => '豪华双床房', 'price' => 1260, 'capacity' => 2, 'bed' => '1.35m 双床', 'area' => 42, 'rooms' => 2, 'desc' => '国贸城景'],
                ],
            ],
            [
                'name' => '成都春熙路云途酒店', 'city' => '成都', 'district' => '锦江区', 'address' => '春熙路 88 号',
                'phone' => '028-86660001', 'star' => 4, 'score' => '4.5', 'reviews' => 5400,
                'tags' => '地铁 2 号线,含双早', 'status' => 1,
                'types' => [
                    ['name' => '轻奢大床房', 'price' => 380, 'capacity' => 2, 'bed' => '1.8m 大床', 'area' => 26, 'rooms' => 2, 'desc' => '商圈核心'],
                    ['name' => '观景双床房', 'price' => 460, 'capacity' => 2, 'bed' => '1.2m 双床', 'area' => 30, 'rooms' => 2, 'desc' => '高层看雪山', 'occupied' => true],
                ],
            ],
            [
                'name' => '成都青城山云途民宿', 'city' => '成都', 'district' => '都江堰市', 'address' => '青城山镇前山街 12 号',
                'phone' => '028-86660002', 'star' => 3, 'score' => '3.9', 'reviews' => 120,
                'tags' => '温泉', 'status' => 1,
                'types' => [
                    ['name' => '山景房', 'price' => 280, 'capacity' => 2, 'bed' => '1.5m 大床', 'area' => 24, 'rooms' => 2, 'desc' => '山间庭院'],
                ],
            ],
            [
                'name' => '三亚亚龙湾云途度假酒店', 'city' => '三亚', 'district' => '吉阳区', 'address' => '亚龙湾国家旅游度假区',
                'phone' => '0898-88880001', 'star' => 5, 'score' => '4.9', 'reviews' => 12800,
                'tags' => '海景,含双早,亲子房,免费取消', 'status' => 1,
                'types' => [
                    ['name' => '园景房', 'price' => 1180, 'capacity' => 2, 'bed' => '1.8m 大床', 'area' => 45, 'rooms' => 3, 'desc' => '热带园林'],
                    ['name' => '海景套房', 'price' => 2680, 'capacity' => 4, 'bed' => '大床 + 双床', 'area' => 78, 'rooms' => 2, 'desc' => '一线海景，独立客厅', 'clean' => true],
                ],
            ],
            [
                'name' => '三亚海棠湾云途公寓', 'city' => '三亚', 'district' => '海棠区', 'address' => '海棠湾镇龙海路 5 号',
                'phone' => '0898-88880002', 'star' => 3, 'score' => null, 'reviews' => null,
                'tags' => '', 'status' => 1,
                // 评分与点评留空：验证卡片「没填就不渲染」的回落
                'types' => [
                    ['name' => '一房一厅公寓', 'price' => 640, 'capacity' => 3, 'bed' => '大床 + 沙发床', 'area' => 55, 'rooms' => 2, 'desc' => '可自助洗衣'],
                ],
            ],
            [
                'name' => '西安钟楼云途酒店', 'city' => '西安', 'district' => '碑林区', 'address' => '东大街 120 号',
                'phone' => '029-87270001', 'star' => 4, 'score' => '4.3', 'reviews' => 2100,
                'tags' => '含双早,地铁 2 号线', 'status' => 1,
                'types' => [
                    ['name' => '标准双床房', 'price' => 320, 'capacity' => 2, 'bed' => '1.2m 双床', 'area' => 26, 'rooms' => 2, 'desc' => '近钟楼'],
                    ['name' => '唐风大床房', 'price' => 360, 'capacity' => 2, 'bed' => '1.8m 大床', 'area' => 30, 'rooms' => 2, 'desc' => '中式布置'],
                ],
            ],
            [
                'name' => '厦门鼓浪屿云途客栈', 'city' => '厦门', 'district' => '思明区', 'address' => '鼓浪屿鹿礁路 30 号',
                'phone' => '0592-2060001', 'star' => 3, 'score' => '4.1', 'reviews' => 760,
                'tags' => '海景', 'status' => 1,
                'types' => [
                    ['name' => '海景双人房', 'price' => 420, 'capacity' => 2, 'bed' => '1.5m 大床', 'area' => 22, 'rooms' => 2, 'desc' => '老别墅改造'],
                ],
            ],
            [
                'name' => '苏州平江路云途酒店', 'city' => '苏州', 'district' => '姑苏区', 'address' => '平江路历史街区南 6 号',
                'phone' => '0512-67770001', 'star' => 4, 'score' => '4.6', 'reviews' => 1350,
                'tags' => '园林,含双早,免费取消', 'status' => 1,
                'types' => [
                    ['name' => '园景大床房', 'price' => 560, 'capacity' => 2, 'bed' => '1.8m 大床', 'area' => 30, 'rooms' => 2, 'desc' => '临河小院'],
                    ['name' => '家庭房', 'price' => 780, 'capacity' => 4, 'bed' => '大床 + 双床', 'area' => 46, 'rooms' => 2, 'desc' => '可住四人', 'occupied' => true],
                ],
            ],
            [
                // 停用酒店：C 端搜索与详情都应看不到，用于验证下架可见性
                'name' => '杭州西溪云途酒店（停用）', 'city' => '杭州', 'district' => '西湖区', 'address' => '天目山路 15 号',
                'phone' => '0571-87730003', 'star' => 4, 'score' => '4.2', 'reviews' => 300,
                'tags' => '含双早', 'status' => 0,
                'types' => [
                    ['name' => '标准大床房', 'price' => 430, 'capacity' => 2, 'bed' => '1.8m 大床', 'area' => 28, 'rooms' => 2, 'desc' => '芦苇荡景'],
                ],
            ],
        ];
    }
}
