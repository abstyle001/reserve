<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelManageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 酒店管理全部页面要求登录
        $this->actingAs(User::factory()->create());
    }

    public function test_guest_is_redirected_to_login()
    {
        auth()->logout();

        $this->get('/hotels')->assertRedirect('/login');
        $this->post('/hotels', [])->assertRedirect('/login');
    }

    public function test_hotel_crud()
    {
        // 独立后台布局的页面可以正常渲染
        $this->get('/hotels')->assertOk();

        // 创建
        $this->post('/hotels', [
            'name'       => '云栖大酒店',
            'phone'      => '0571-88888888',
            'star_level' => 4,
            'status'     => 1,
        ])->assertRedirect(route('hotels.index'));
        $this->assertDatabaseHas('hotels', ['name' => '云栖大酒店', 'star_level' => 4]);

        $hotel = Hotel::where('name', '云栖大酒店')->first();

        // 更新
        $this->put("/hotels/{$hotel->id}", [
            'name'   => '云栖大酒店（旗舰店）',
            'status' => 0,
        ])->assertRedirect(route('hotels.index'));
        $this->assertDatabaseHas('hotels', ['id' => $hotel->id, 'name' => '云栖大酒店（旗舰店）', 'status' => 0]);

        // 删除（无下级数据时允许）
        $this->delete("/hotels/{$hotel->id}")->assertRedirect(route('hotels.index'));
        $this->assertDatabaseMissing('hotels', ['id' => $hotel->id]);
    }

    public function test_hotel_name_is_required()
    {
        $this->post('/hotels', ['name' => '', 'status' => 1])
            ->assertSessionHasErrors('name');
    }

    public function test_hotel_with_children_cannot_be_deleted()
    {
        $hotel = Hotel::create(['name' => '测试酒店', 'status' => 1]);
        RoomType::create(['hotel_id' => $hotel->id, 'name' => '大床房', 'price' => 299, 'capacity' => 2]);

        $this->delete("/hotels/{$hotel->id}");
        $this->assertDatabaseHas('hotels', ['id' => $hotel->id]);
    }

    public function test_room_type_crud()
    {
        $hotel = Hotel::create(['name' => '测试酒店', 'status' => 1]);

        $this->get("/hotels/{$hotel->id}/room-types")->assertOk();

        // 创建
        $this->post("/hotels/{$hotel->id}/room-types", [
            'name'     => '大床房',
            'price'    => 299.5,
            'capacity' => 2,
            'bed_type' => '1.8m大床',
        ])->assertRedirect(route('hotels.room-types.index', $hotel));
        $this->assertDatabaseHas('room_types', ['hotel_id' => $hotel->id, 'name' => '大床房']);

        $roomType = RoomType::where('name', '大床房')->first();

        // 更新
        $this->put("/hotels/{$hotel->id}/room-types/{$roomType->id}", [
            'name'     => '豪华大床房',
            'price'    => 399,
            'capacity' => 2,
        ])->assertRedirect(route('hotels.room-types.index', $hotel));
        $this->assertDatabaseHas('room_types', ['id' => $roomType->id, 'name' => '豪华大床房', 'price' => 399]);

        // 删除
        $this->delete("/hotels/{$hotel->id}/room-types/{$roomType->id}");
        $this->assertDatabaseMissing('room_types', ['id' => $roomType->id]);
    }

    public function test_room_type_name_must_be_unique_within_same_hotel()
    {
        $hotel = Hotel::create(['name' => '测试酒店', 'status' => 1]);
        RoomType::create(['hotel_id' => $hotel->id, 'name' => '大床房', 'price' => 299, 'capacity' => 2]);

        // 同酒店重名 → 校验失败
        $this->post("/hotels/{$hotel->id}/room-types", [
            'name' => '大床房', 'price' => 199, 'capacity' => 2,
        ])->assertSessionHasErrors('name');

        // 不同酒店允许重名
        $other = Hotel::create(['name' => '另一家酒店', 'status' => 1]);
        $this->post("/hotels/{$other->id}/room-types", [
            'name' => '大床房', 'price' => 199, 'capacity' => 2,
        ])->assertSessionDoesntHaveErrors();
    }

    public function test_room_crud()
    {
        $hotel = Hotel::create(['name' => '测试酒店', 'status' => 1]);
        $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => '大床房', 'price' => 299, 'capacity' => 2]);

        $this->get("/hotels/{$hotel->id}/rooms")->assertOk();

        // 创建
        $this->post("/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $type->id,
            'room_no'      => '301',
            'floor'        => 3,
            'status'       => Room::STATUS_VACANT,
        ])->assertRedirect(route('hotels.rooms.index', $hotel));
        $this->assertDatabaseHas('rooms', ['hotel_id' => $hotel->id, 'room_no' => '301', 'floor' => 3]);

        $room = Room::where('room_no', '301')->first();

        // 更新状态为在住
        $this->put("/hotels/{$hotel->id}/rooms/{$room->id}", [
            'room_type_id' => $type->id,
            'room_no'      => '301',
            'floor'        => 3,
            'status'       => Room::STATUS_OCCUPIED,
        ])->assertRedirect(route('hotels.rooms.index', $hotel));
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'status' => Room::STATUS_OCCUPIED]);

        // 删除
        $this->delete("/hotels/{$hotel->id}/rooms/{$room->id}");
        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    public function test_room_no_must_be_unique_within_same_hotel()
    {
        $hotel = Hotel::create(['name' => '测试酒店', 'status' => 1]);
        $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => '大床房', 'price' => 299, 'capacity' => 2]);
        Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'room_no' => '301', 'status' => 1]);

        $this->post("/hotels/{$hotel->id}/rooms", [
            'room_type_id' => $type->id,
            'room_no'      => '301',
            'status'       => Room::STATUS_VACANT,
        ])->assertSessionHasErrors('room_no');
    }

    public function test_room_must_use_room_type_of_same_hotel()
    {
        $hotelA = Hotel::create(['name' => '酒店A', 'status' => 1]);
        $hotelB = Hotel::create(['name' => '酒店B', 'status' => 1]);
        $typeOfB = RoomType::create(['hotel_id' => $hotelB->id, 'name' => '大床房', 'price' => 299, 'capacity' => 2]);

        // 给酒店A 添加房间时引用酒店B的房型 → 校验失败
        $this->post("/hotels/{$hotelA->id}/rooms", [
            'room_type_id' => $typeOfB->id,
            'room_no'      => '301',
            'status'       => Room::STATUS_VACANT,
        ])->assertSessionHasErrors('room_type_id');
    }

    public function test_room_type_with_rooms_cannot_be_deleted()
    {
        $hotel = Hotel::create(['name' => '测试酒店', 'status' => 1]);
        $type = RoomType::create(['hotel_id' => $hotel->id, 'name' => '大床房', 'price' => 299, 'capacity' => 2]);
        Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'room_no' => '301', 'status' => 1]);

        $this->delete("/hotels/{$hotel->id}/room-types/{$type->id}");
        $this->assertDatabaseHas('room_types', ['id' => $type->id]);
    }

    public function test_nested_child_must_belong_to_parent_hotel()
    {
        $hotelA = Hotel::create(['name' => '酒店A', 'status' => 1]);
        $hotelB = Hotel::create(['name' => '酒店B', 'status' => 1]);
        $typeOfB = RoomType::create(['hotel_id' => $hotelB->id, 'name' => '大床房', 'price' => 299, 'capacity' => 2]);

        // 用酒店A的 URL 操作酒店B的房型 → 404
        $this->get("/hotels/{$hotelA->id}/room-types/{$typeOfB->id}/edit")->assertNotFound();
        $this->delete("/hotels/{$hotelA->id}/room-types/{$typeOfB->id}")->assertNotFound();
        $this->assertDatabaseHas('room_types', ['id' => $typeOfB->id]);
    }
}
