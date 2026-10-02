{{--
    号牌：整个界面最醒目的元素
      - 数字用 tabular-nums，避免 5s 轮询刷新时字宽变化导致抖动
      - 取号成功后 .ticket-pop 触发一次弹跳动画
--}}
<section class="glass ticket-shell">

    <div class="flex items-center justify-between">
        <span class="stat-label">当前号码</span>
        <span
            class="badge"
            :class="$store.reserve.needReset() ? 'badge--amber' : 'badge--blue'"
            x-text="$store.reserve.needReset() ? '第 ' + ($store.reserve.state.batch_no || 0) + ' 批 · 已换批' : '第 ' + ($store.reserve.state.batch_no || 0) + ' 批'"
            x-show="$store.reserve.state"
        ></span>
    </div>

    <div class="flex flex-1 items-center justify-center py-4">
        <div
            class="ticket-number"
            :class="{ 'ticket-number--empty': !$store.reserve.currentLatest(), 'ticket-pop': $store.reserve.flash }"
            x-text="$store.reserve.currentLatest() ? $store.reserve.currentLatest().serial_no : '—'"
        >—</div>
    </div>

    <div class="ticket-hint">
        <span x-show="$store.reserve.currentLatest()" class="text-2xl text-white/70">
            请在窗口内侧留意叫号
        </span>
        <span x-show="!$store.reserve.currentLatest()" class="text-2xl text-white/45">
            点击下方「取号」开始排队
        </span>
    </div>

</section>
