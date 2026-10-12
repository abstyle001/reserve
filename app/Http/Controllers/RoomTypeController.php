<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomTypeController extends Controller
{
    public function index(Hotel $hotel)
    {
        $roomTypes = $hotel->roomTypes()
            ->withCount('rooms')
            ->orderBy('id')
            ->paginate(15);

        return view('room-types.index', compact('hotel', 'roomTypes'));
    }

    public function create(Hotel $hotel)
    {
        return view('room-types.create', compact('hotel'));
    }

    public function store(Request $request, Hotel $hotel)
    {
        $data = $this->validateData($request, $hotel);
        $data['hotel_id'] = $hotel->id;

        RoomType::create($data);

        return redirect()->route('hotels.room-types.index', $hotel)
            ->with('success', '房型创建成功');
    }

    public function edit(Hotel $hotel, RoomType $roomType)
    {
        $this->checkBelongsToHotel($hotel, $roomType);

        return view('room-types.edit', compact('hotel', 'roomType'));
    }

    public function update(Request $request, Hotel $hotel, RoomType $roomType)
    {
        $this->checkBelongsToHotel($hotel, $roomType);

        $roomType->update($this->validateData($request, $hotel, $roomType->id));

        return redirect()->route('hotels.room-types.index', $hotel)
            ->with('success', '房型已更新');
    }

    public function destroy(Hotel $hotel, RoomType $roomType)
    {
        $this->checkBelongsToHotel($hotel, $roomType);

        if ($roomType->rooms()->exists()) {
            return back()->with('error', '该房型下还有房间，不能删除');
        }

        $roomType->delete();

        return redirect()->route('hotels.room-types.index', $hotel)
            ->with('success', '房型已删除');
    }

    // 嵌套路由手动校验归属：Laravel 8 没有隐式 scoped 绑定，
    // 不挡的话可以用 /hotels/1/room-types/2 操作到别的酒店的房型
    private function checkBelongsToHotel(Hotel $hotel, RoomType $roomType)
    {
        if ((int) $roomType->hotel_id !== (int) $hotel->id) {
            abort(404);
        }
    }

    private function validateData(Request $request, Hotel $hotel, $ignoreId = null)
    {
        $uniqueName = Rule::unique('room_types', 'name')->where('hotel_id', $hotel->id);
        if ($ignoreId) {
            $uniqueName->ignore($ignoreId);
        }

        return $request->validate([
            'name'        => ['required', 'string', 'max:50', $uniqueName],
            'price'       => 'required|numeric|min:0|max:99999999.99',
            'capacity'    => 'required|integer|min:1|max:20',
            'bed_type'    => 'nullable|string|max:50',
            'area'        => 'nullable|integer|min:1|max:100000',
            'description' => 'nullable|string|max:500',
        ], [
            'name.required'      => '房型名称不能为空',
            'name.max'           => '房型名称不能超过 50 个字符',
            'name.unique'        => '该酒店下已存在同名房型',
            'price.required'     => '门市价不能为空',
            'price.numeric'      => '门市价必须是数字',
            'price.min'          => '门市价不能为负数',
            'capacity.required'  => '可住人数不能为空',
            'capacity.integer'   => '可住人数必须是 1-20 的整数',
            'capacity.min'       => '可住人数必须是 1-20 的整数',
            'capacity.max'       => '可住人数必须是 1-20 的整数',
            'bed_type.max'       => '床型不能超过 50 个字符',
            'area.integer'       => '面积必须是整数',
            'description.max'    => '房型描述不能超过 500 个字符',
        ]);
    }
}
