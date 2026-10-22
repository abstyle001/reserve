{{--
    酒店预订搜索表单（真 GET 表单，/fly）。

    字段名必须与 HotelSearchService::normalize() 的键一一对应；
    Alpine 的 submitSearch() 会读同样的字段并写回 URL，两边共用这一份 DOM。
    日期用原生 <input type="date">：项目没有 dayjs，不为一个日期框引日期库。

    Alpine 侧（渐进增强，关掉 JS 时下列绑定全部不参与）：
      - @submit.prevent / @change：读同一组 name，把整页刷新换成局部刷新。
      - :value 绑定回写输入框：点城市卡/热门词改的是 store，表单要跟着显示。
        水合发生在 Alpine.start() 之前，所以首帧值与 SSR 一致，不会清空一下再填回。
      - :class 一律用对象语法：字符串语法只会「加」类，摘不掉 SSR 写上的
        fly-chip--active（Alpine 的 setClassesFromString 只 add 不 remove）。
--}}
<form class="fly-form" action="{{ route('fly.home') }}" method="get"
      @submit.prevent="$store.fly.submitSearch($el)"
      @change="$store.fly.onFormChange($el)">
    {{-- 目的地城市 --}}
    <div class="fly-field fly-form__city">
        <svg class="fly-field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>
            <circle cx="12" cy="10" r="3"/>
        </svg>
        <div class="min-w-0 flex-1">
            <label class="fly-field__label" for="fly-city">城市</label>
            <input id="fly-city" type="text" name="city" list="fly-city-options" autocomplete="off"
                   placeholder="选择或输入城市" value="{{ $filters['city'] }}"
                   :value="$store.fly.filters.city">
        </div>
    </div>

    {{-- 入住 / 离店日期：不放字段图标，原生日期控件自带日历小图标，再加一个就是三个图标 --}}
    <div class="fly-field fly-field--dates fly-form__dates">
        <div class="fly-dates">
            <div class="fly-dates__cell">
                <label class="fly-field__label" for="fly-check-in">入住</label>
                <input id="fly-check-in" type="date" name="check_in"
                       value="{{ $filters['check_in'] }}" min="{{ $filters['today'] }}"
                       :value="$store.fly.filters.check_in">
            </div>
            <div class="fly-dates__cell">
                <label class="fly-field__label" for="fly-check-out">离店</label>
                <input id="fly-check-out" type="date" name="check_out" value="{{ $filters['check_out'] }}"
                       :value="$store.fly.filters.check_out">
            </div>
        </div>
        <span class="fly-nights" x-text="$store.fly.nights + ' 晚'">{{ $filters['nights'] }} 晚</span>
    </div>

    {{-- 关键词 --}}
    <div class="fly-field fly-form__q">
        <svg class="fly-field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"/>
            <path d="m21 21-4.3-4.3"/>
        </svg>
        <label class="sr-only" for="fly-q">关键词</label>
        <input id="fly-q" type="text" name="q" class="flex-1" placeholder="酒店名 / 地标 / 商圈"
               value="{{ $filters['q'] }}" maxlength="50" autocomplete="off"
               :value="$store.fly.filters.q">
    </div>

    {{-- 动作区：搜索 + 重置同列。xl 时贴在第 3 列与左侧两行字段等高（搜索上、重置下），
         md 以下占满整行两枚并排。重置是真链接兜底（关 JS 整页跳回 /fly 默认态），
         开 JS 时局部刷新，href 与 resetFilters() 行为一致 --}}
    <div class="fly-form__actions">
        <button type="submit" class="fly-btn-search flex-1" :disabled="$store.fly.loading">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="8"/>
                <path d="m21 21-4.3-4.3"/>
            </svg>
            搜索酒店
        </button>
        <a href="{{ route('fly.home') }}" class="fly-btn-reset flex-1"
           :href="$store.fly.resetUrl()"
           @click.prevent="$store.fly.resetFilters()">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                <path d="M3 3v5h5"/>
            </svg>
            重置搜索
        </a>
    </div>

    {{-- 排序不进表单网格，由下方筛选 chip 以链接方式改 --}}
    <input type="hidden" name="sort" value="{{ $filters['sort'] }}" :value="$store.fly.filters.sort">

    @if (!empty($cities))
        <datalist id="fly-city-options">
            @foreach ($cities as $option)
                <option value="{{ $option['city'] }}">{{ $option['hotel_count'] }} 家酒店</option>
            @endforeach
        </datalist>
    @endif
</form>
