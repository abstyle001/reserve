{{--
    自绘入住/离店日历（仅 Alpine 启动后出现）。

    渐进增强边界：本面板整体 x-cloak，JS 关闭时永不显示，
    触发它的原生 <input type="date">（x-show="false" 仅在 JS 开启时隐藏）照常工作。
    必须放在同时包含两个触发按钮、且 position:relative 的容器里 ——
    @click.outside 挂在那个容器上，点容器外关闭、点触发按钮不误关。

    42 格日历的日期 / disabled / 区间状态全部由 store 的 calDays() 算好，
    这里只读；改选择逻辑去 store（pickCalDay / setDates），不要在这里写。
--}}
<div class="fly-cal" x-cloak x-show="$store.fly.calOpen">
    <div class="fly-cal__head">
        <button type="button" class="fly-cal__nav" :disabled="! $store.fly.calCanShift(-1)"
                @click="$store.fly.calShiftMonth(-1)" aria-label="上个月">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m15 18-6-6 6-6"/>
            </svg>
        </button>
        <p class="fly-cal__title" x-text="$store.fly.calTitle()"></p>
        <button type="button" class="fly-cal__nav" :disabled="! $store.fly.calCanShift(1)"
                @click="$store.fly.calShiftMonth(1)" aria-label="下个月">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </button>
    </div>

    <div class="fly-cal__week" aria-hidden="true">
        @foreach (['日', '一', '二', '三', '四', '五', '六'] as $weekday)
            <span>{{ $weekday }}</span>
        @endforeach
    </div>

    {{-- mouseleave 清掉悬停预览：停在旧格子上不该残留蓝色区间 --}}
    {{-- key 由 store 生成（cell.key）：x-for 的 :key 表达式里拿不到 $index --}}
    <div class="fly-cal__grid" @mouseleave="$store.fly.calHover = ''">
        <template x-for="cell in $store.fly.calDays()" :key="cell.key">
            <div class="fly-cal__cell">
                <button type="button" x-show="cell.date" :disabled="cell.disabled"
                        :class="$store.fly.calClass(cell)" x-text="cell.label"
                        @click="$store.fly.pickCalDay(cell)"
                        @mouseenter="cell.date && ($store.fly.calHover = cell.date)"></button>
            </div>
        </template>
    </div>

    <p class="fly-cal__hint" x-text="$store.fly.calPicking === 'out' ? '请选择离店日期' : '请选择入住日期'"></p>
</div>
