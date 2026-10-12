<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;

class HotelController extends Controller
{
    public function index()
    {
        $hotels = Hotel::withCount(['roomTypes', 'rooms'])
            ->orderBy('id')
            ->paginate(15);

        $stats = [
            'hotels'     => Hotel::count(),
            'room_types' => RoomType::count(),
            'rooms'      => Room::count(),
        ];

        return view('hotels.index', compact('hotels', 'stats'));
    }

    public function create()
    {
        return view('hotels.create');
    }

    public function store(Request $request)
    {
        Hotel::create($this->validateData($request));

        return redirect()->route('hotels.index')->with('success', '酒店创建成功');
    }

    public function edit(Hotel $hotel)
    {
        return view('hotels.edit', compact('hotel'));
    }

    public function update(Request $request, Hotel $hotel)
    {
        $hotel->update($this->validateData($request));

        return redirect()->route('hotels.index')->with('success', '酒店信息已更新');
    }

    public function destroy(Hotel $hotel)
    {
        // 有下级数据时不允许删，避免房型/房间变成孤儿数据
        if ($hotel->roomTypes()->exists() || $hotel->rooms()->exists()) {
            return back()->with('error', '该酒店下还有房型或房间，不能删除');
        }

        $hotel->delete();

        return redirect()->route('hotels.index')->with('success', '酒店已删除');
    }

    private function validateData(Request $request)
    {
        return $request->validate([
            'name'       => 'required|string|max:100',
            'address'    => 'nullable|string|max:255',
            'phone'      => 'nullable|string|max:20',
            'star_level' => 'nullable|integer|min:1|max:5',
            'status'     => 'required|in:0,1',
            'remark'     => 'nullable|string|max:500',
        ], [
            'name.required'      => '酒店名称不能为空',
            'name.max'           => '酒店名称不能超过 100 个字符',
            'address.max'        => '地址不能超过 255 个字符',
            'phone.max'          => '电话不能超过 20 个字符',
            'star_level.integer' => '星级必须是 1-5 的整数',
            'star_level.min'     => '星级必须是 1-5 的整数',
            'star_level.max'     => '星级必须是 1-5 的整数',
            'status.required'    => '请选择酒店状态',
            'status.in'          => '酒店状态不合法',
            'remark.max'         => '备注不能超过 500 个字符',
        ]);
    }
}
