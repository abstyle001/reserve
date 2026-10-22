<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HotelController extends Controller
{
    const MAX_IMAGES = 10; // 每家酒店最多保留的图片数

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
        $hotel = Hotel::create(array_merge(
            $this->validateData($request),
            ['img' => $this->storeImages($request)]
        ));

        return redirect()->route('hotels.index')->with('success', '酒店创建成功');
    }

    public function edit(Hotel $hotel)
    {
        return view('hotels.edit', compact('hotel'));
    }

    public function update(Request $request, Hotel $hotel)
    {
        $hotel->update(array_merge(
            $this->validateData($request),
            ['img' => $this->syncImages($request, $hotel)]
        ));

        return redirect()->route('hotels.index')->with('success', '酒店信息已更新');
    }

    public function destroy(Hotel $hotel)
    {
        // 有下级数据时不允许删，避免房型/房间变成孤儿数据
        if ($hotel->roomTypes()->exists() || $hotel->rooms()->exists()) {
            return back()->with('error', '该酒店下还有房型或房间，不能删除');
        }

        foreach ((array) $hotel->img as $path) {
            Storage::disk('public')->delete($path);
        }

        $hotel->delete();

        return redirect()->route('hotels.index')->with('success', '酒店已删除');
    }

    private function validateData(Request $request)
    {
        return $request->validate([
            'name'         => 'required|string|max:100',
            'city'         => 'nullable|string|max:50',
            'district'     => 'nullable|string|max:50',
            'address'      => 'nullable|string|max:255',
            'phone'        => 'nullable|string|max:20',
            'star_level'   => 'nullable|integer|min:1|max:5',
            'score'        => 'nullable|numeric|min:0|max:5',
            'review_count' => 'nullable|integer|min:0|max:99999999',
            'tags'         => 'nullable|string|max:255',
            'status'       => 'required|in:0,1',
            'remark'       => 'nullable|string|max:500',
            'images'     => 'nullable|array|max:' . self::MAX_IMAGES,
            'images.*'   => 'image|mimes:jpg,jpeg,png,webp,gif|max:5120',
            'remove_images'   => 'nullable|array',
            'remove_images.*' => 'string',
        ], [
            'name.required'      => '酒店名称不能为空',
            'name.max'           => '酒店名称不能超过 100 个字符',
            'city.max'           => '城市不能超过 50 个字符',
            'district.max'       => '商圈不能超过 50 个字符',
            'address.max'        => '地址不能超过 255 个字符',
            'phone.max'          => '电话不能超过 20 个字符',
            'star_level.integer' => '星级必须是 1-5 的整数',
            'star_level.min'     => '星级必须是 1-5 的整数',
            'star_level.max'     => '星级必须是 1-5 的整数',
            'score.numeric'      => '评分必须是数字',
            'score.min'          => '评分不能小于 0',
            'score.max'          => '评分不能大于 5',
            'review_count.integer' => '点评数必须是整数',
            'review_count.min'     => '点评数不能小于 0',
            'review_count.max'     => '点评数过大',
            'tags.max'           => '标签不能超过 255 个字符',
            'status.required'    => '请选择酒店状态',
            'status.in'          => '酒店状态不合法',
            'remark.max'         => '备注不能超过 500 个字符',
            'images.max'    => '一次最多上传 ' . self::MAX_IMAGES . ' 张图片',
            'images.*.image' => '只能上传图片文件',
            'images.*.mimes' => '图片格式只支持 jpg、png、webp、gif',
            'images.*.max'   => '单张图片不能超过 5MB',
        ]);
    }

    /**
     * 把上传的图片存到 public 磁盘的 hotels/ 目录，返回相对路径数组
     */
    private function storeImages(Request $request)
    {
        $paths = [];
        foreach ((array) $request->file('images') as $file) {
            if ($file && $file->isValid()) {
                $paths[] = $file->store('hotels', 'public');
            }
        }

        return $paths;
    }

    /**
     * 编辑时的图片增删：先删掉勾选的旧图（含磁盘文件），再追加新上传的
     */
    private function syncImages(Request $request, Hotel $hotel)
    {
        $remove = (array) $request->input('remove_images', []);

        $keep = [];
        foreach ((array) $hotel->img as $path) {
            if (in_array($path, $remove, true)) {
                Storage::disk('public')->delete($path);
            } else {
                $keep[] = $path;
            }
        }

        return array_slice(array_merge($keep, $this->storeImages($request)), 0, self::MAX_IMAGES);
    }
}
