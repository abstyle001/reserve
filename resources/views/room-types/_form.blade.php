@csrf

<div class="space-y-5">
    <div>
        <label for="name" class="admin-label">房型名称 *</label>
        <input id="name" name="name" type="text" class="admin-input" placeholder="如：大床房、标准双床房"
               value="{{ old('name', isset($roomType) ? $roomType->name : '') }}" required>
        @error('name') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
        <div>
            <label for="price" class="admin-label">门市价（元） *</label>
            <input id="price" name="price" type="number" step="0.01" min="0" class="admin-input"
                   value="{{ old('price', isset($roomType) ? $roomType->price : '') }}" required>
            @error('price') <p class="admin-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="capacity" class="admin-label">可住人数 *</label>
            <input id="capacity" name="capacity" type="number" min="1" max="20" class="admin-input"
                   value="{{ old('capacity', isset($roomType) ? $roomType->capacity : 2) }}" required>
            @error('capacity') <p class="admin-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="area" class="admin-label">面积（㎡）</label>
            <input id="area" name="area" type="number" min="1" class="admin-input"
                   value="{{ old('area', isset($roomType) ? $roomType->area : '') }}">
            @error('area') <p class="admin-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="bed_type" class="admin-label">床型</label>
        <input id="bed_type" name="bed_type" type="text" class="admin-input" placeholder="如：1.8m大床、双床 1.2m×2"
               value="{{ old('bed_type', isset($roomType) ? $roomType->bed_type : '') }}">
        @error('bed_type') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="description" class="admin-label">房型描述</label>
        <textarea id="description" name="description" rows="3" class="admin-input">{{ old('description', isset($roomType) ? $roomType->description : '') }}</textarea>
        @error('description') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="admin-btn-primary">保存</button>
        <a href="{{ route('hotels.room-types.index', $hotel) }}" class="admin-btn-ghost">返回列表</a>
    </div>
</div>
