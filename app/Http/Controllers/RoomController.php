<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    public function index(Hotel $hotel)
    {
        $rooms = $hotel->rooms()
            ->with('roomType')
            ->orderBy('floor')
            ->orderBy('room_no')
            ->paginate(15);

        return view('rooms.index', compact('hotel', 'rooms'));
    }

    public function create(Hotel $hotel)
    {
        return view('rooms.create', [
            'hotel'     => $hotel,
            'roomTypes' => $hotel->roomTypes()->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, Hotel $hotel)
    {
        $data = $this->validateData($request, $hotel);
        $data['hotel_id'] = $hotel->id;

        Room::create($data);

        return redirect()->route('hotels.rooms.index', $hotel)
            ->with('success', '房间创建成功');
    }

    public function edit(Hotel $hotel, Room $room)
    {
        $this->checkBelongsToHotel($hotel, $room);

        return view('rooms.edit', [
            'hotel'     => $hotel,
            'room'      => $room,
            'roomTypes' => $hotel->roomTypes()->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, Hotel $hotel, Room $room)
    {
        $this->checkBelongsToHotel($hotel, $room);

        $room->update($this->validateData($request, $hotel, $room->id));

        return redirect()->route('hotels.rooms.index', $hotel)
            ->with('success', '房间已更新');
    }

    public function destroy(Hotel $hotel, Room $room)
    {
        $this->checkBelongsToHotel($hotel, $room);

        $room->delete();

        return redirect()->route('hotels.rooms.index', $hotel)
            ->with('success', '房间已删除');
    }

    // 同 RoomTypeController：手动校验房间归属当前酒店
    private function checkBelongsToHotel(Hotel $hotel, Room $room)
    {
        if ((int) $room->hotel_id !== (int) $hotel->id) {
            abort(404);
        }
    }

    private function validateData(Request $request, Hotel $hotel, $ignoreId = null)
    {
        $uniqueRoomNo = Rule::unique('rooms', 'room_no')->where('hotel_id', $hotel->id);
        if ($ignoreId) {
            $uniqueRoomNo->ignore($ignoreId);
        }

        return $request->validate([
            'room_type_id' => [
                'required',
                Rule::exists('room_types', 'id')->where(function ($query) use ($hotel) {
                    $query->where('hotel_id', $hotel->id);
                }),
            ],
            'room_no' => ['required', 'string', 'max:20', $uniqueRoomNo],
            'floor'   => 'nullable|integer|min:0|max:200',
            'status'  => ['required', Rule::in(array_keys(Room::$statusLabels))],
            'remark'  => 'nullable|string|max:500',
        ], [
            'room_type_id.required' => '请选择房型',
            'room_type_id.exists'   => '所选房型不存在',
            'room_no.required'      => '房号不能为空',
            'room_no.max'           => '房号不能超过 20 个字符',
            'room_no.unique'        => '该酒店下已存在相同房号',
            'floor.integer'         => '楼层必须是整数',
            'status.required'       => '请选择房间状态',
            'status.in'             => '房间状态不合法',
            'remark.max'            => '备注不能超过 500 个字符',
        ]);
    }
}
