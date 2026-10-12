@csrf

<div class="space-y-5">
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div>
            <label for="room_no" class="admin-label">房号 *</label>
            <input id="room_no" name="room_no" type="text" class="admin-input" placeholder="如：301"
                   value="{{ old('room_no', isset($room) ? $room->room_no : '') }}" required>
            @error('room_no') <p class="admin-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="floor" class="admin-label">楼层</label>
            <input id="floor" name="floor" type="number" min="0" max="200" class="admin-input"
                   value="{{ old('floor', isset($room) ? $room->floor : '') }}">
            @error('floor') <p class="admin-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div>
            <label for="room_type_id" class="admin-label">房型 *</label>
            @if ($roomTypes->isEmpty())
                <p class="mt-2 text-sm text-gray-500">
                    该酒店还没有房型，请先
                    <a href="{{ route('hotels.room-types.create', $hotel) }}" class="admin-action">创建房型</a>。
                </p>
            @else
                <select id="room_type_id" name="room_type_id" class="admin-input">
                    <option value="">请选择房型</option>
                    @foreach ($roomTypes as $roomType)
                        <option value="{{ $roomType->id }}" {{ (string) old('room_type_id', isset($room) ? $room->room_type_id : '') === (string) $roomType->id ? 'selected' : '' }}>
                            {{ $roomType->name }}（¥{{ $roomType->price }}）
                        </option>
                    @endforeach
                </select>
            @endif
            @error('room_type_id') <p class="admin-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="status" class="admin-label">状态 *</label>
            <select id="status" name="status" class="admin-input">
                @foreach (\App\Models\Room::$statusLabels as $value => $label)
                    <option value="{{ $value }}" {{ (string) old('status', isset($room) ? $room->status : \App\Models\Room::STATUS_VACANT) === (string) $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            @error('status') <p class="admin-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="remark" class="admin-label">备注</label>
        <textarea id="remark" name="remark" rows="3" class="admin-input">{{ old('remark', isset($room) ? $room->remark : '') }}</textarea>
        @error('remark') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="admin-btn-primary">保存</button>
        <a href="{{ route('hotels.rooms.index', $hotel) }}" class="admin-btn-ghost">返回列表</a>
    </div>
</div>
