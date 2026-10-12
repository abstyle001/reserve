@csrf

<div class="space-y-5">
    <div>
        <label for="name" class="admin-label">酒店名称 *</label>
        <input id="name" name="name" type="text" class="admin-input"
               value="{{ old('name', isset($hotel) ? $hotel->name : '') }}" required>
        @error('name') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
        <div>
            <label for="star_level" class="admin-label">星级</label>
            <select id="star_level" name="star_level" class="admin-input">
                <option value="">未评星</option>
                @for ($i = 1; $i <= 5; $i++)
                    <option value="{{ $i }}" {{ (string) old('star_level', isset($hotel) ? $hotel->star_level : '') === (string) $i ? 'selected' : '' }}>{{ $i }} 星</option>
                @endfor
            </select>
            @error('star_level') <p class="admin-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="phone" class="admin-label">联系电话</label>
            <input id="phone" name="phone" type="text" class="admin-input"
                   value="{{ old('phone', isset($hotel) ? $hotel->phone : '') }}">
            @error('phone') <p class="admin-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="status" class="admin-label">状态 *</label>
            <select id="status" name="status" class="admin-input">
                @foreach (\App\Models\Hotel::$statusLabels as $value => $label)
                    <option value="{{ $value }}" {{ (string) old('status', isset($hotel) ? $hotel->status : \App\Models\Hotel::STATUS_OPEN) === (string) $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            @error('status') <p class="admin-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="address" class="admin-label">地址</label>
        <input id="address" name="address" type="text" class="admin-input"
               value="{{ old('address', isset($hotel) ? $hotel->address : '') }}">
        @error('address') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="remark" class="admin-label">备注</label>
        <textarea id="remark" name="remark" rows="3" class="admin-input">{{ old('remark', isset($hotel) ? $hotel->remark : '') }}</textarea>
        @error('remark') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="admin-btn-primary">保存</button>
        <a href="{{ route('hotels.index') }}" class="admin-btn-ghost">返回列表</a>
    </div>
</div>
