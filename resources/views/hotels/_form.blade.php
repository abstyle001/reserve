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
        <x-city-picker :value="old('city', isset($hotel) ? $hotel->city : '')" />
        <p class="mt-1 text-xs text-gray-400">C 端按城市搜索酒店时使用，留空则不出现在城市筛选中</p>
        @error('city') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="district" class="admin-label">商圈 / 区县</label>
        <input id="district" name="district" type="text" class="admin-input" placeholder="如：黄浦区 · 外滩商圈"
               value="{{ old('district', isset($hotel) ? $hotel->district : '') }}">
        <p class="mt-1 text-xs text-gray-400">与地址一起拼成 C 端卡片的位置行</p>
        @error('district') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div>
            <label for="score" class="admin-label">展示评分</label>
            <input id="score" name="score" type="number" step="0.1" min="0" max="5" class="admin-input"
                   placeholder="0.0 - 5.0"
                   value="{{ old('score', isset($hotel) ? $hotel->score : '') }}">
            <p class="mt-1 text-xs text-gray-400">留空则前台不显示评分行</p>
            @error('score') <p class="admin-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="review_count" class="admin-label">点评条数</label>
            <input id="review_count" name="review_count" type="number" min="0" max="99999999" class="admin-input"
                   placeholder="如：21000"
                   value="{{ old('review_count', isset($hotel) ? $hotel->review_count : '') }}">
            <p class="mt-1 text-xs text-gray-400">超过 1 万自动显示为「2.1万 条点评」</p>
            @error('review_count') <p class="admin-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="address" class="admin-label">地址</label>
        <input id="address" name="address" type="text" class="admin-input"
               value="{{ old('address', isset($hotel) ? $hotel->address : '') }}">
        @error('address') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="tags" class="admin-label">C 端标签</label>
        <input id="tags" name="tags" type="text" class="admin-input"
               placeholder="半角逗号分隔，如：含双早,免费取消,地铁 2 号线"
               value="{{ old('tags', isset($hotel) ? $hotel->tags : '') }}">
        <p class="mt-1 text-xs text-gray-400">前台最多展示 4 个</p>
        @error('tags') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="remark" class="admin-label">备注</label>
        <textarea id="remark" name="remark" rows="3" class="admin-input">{{ old('remark', isset($hotel) ? $hotel->remark : '') }}</textarea>
        @error('remark') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="images" class="admin-label">酒店图片</label>

        @if (isset($hotel) && !empty($hotel->img))
            <div class="mb-3 flex flex-wrap gap-3">
                @foreach ($hotel->img_urls as $i => $url)
                    <label class="group relative block h-20 w-20 cursor-pointer overflow-hidden rounded-lg border border-gray-200">
                        <img src="{{ $url }}" alt="酒店图片 {{ $i + 1 }}" class="h-full w-full object-cover">
                        <span class="absolute inset-x-0 bottom-0 flex items-center justify-center gap-1 bg-black/50 py-0.5 text-xs text-white">
                            <input type="checkbox" name="remove_images[]" value="{{ $hotel->img[$i] }}" class="h-3 w-3 rounded border-gray-300 text-red-500 focus:ring-red-400">
                            删除
                        </span>
                    </label>
                @endforeach
            </div>
        @endif

        <input id="images" name="images[]" type="file" accept="image/*" multiple class="admin-input py-2">
        <p class="mt-1 text-xs text-gray-400">支持 jpg / png / webp / gif，单张不超过 5MB，最多保留 10 张</p>
        @error('images') <p class="admin-error">{{ $message }}</p> @enderror
        @error('images.*') <p class="admin-error">{{ $message }}</p> @enderror
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="admin-btn-primary">保存</button>
        <a href="{{ route('hotels.index') }}" class="admin-btn-ghost">返回列表</a>
    </div>
</div>
