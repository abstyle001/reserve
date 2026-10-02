{{--
    队列状态：当前号 / 已放号 / 剩余 / 批次
      数字全部 tabular-nums，轮询刷新时不抖动；剩余归零时高亮提醒
--}}
<section class="glass grid shrink-0 grid-cols-4 gap-4 rounded-[2.25rem] px-6 py-6">

    <div class="stat-card">
        <span class="stat-label">当前号</span>
        <span class="stat-value"
              x-text="$store.reserve.state ? $store.reserve.state.current_no : 0">0</span>
    </div>

    <div class="stat-card">
        <span class="stat-label">已放号</span>
        <span class="stat-value"
              x-text="$store.reserve.state ? $store.reserve.state.released_total : 0">0</span>
    </div>

    <div class="stat-card">
        <span class="stat-label">剩余</span>
        <span class="stat-value"
              :class="{ 'stat-value--amber': $store.reserve.state && Number($store.reserve.state.remaining) === 0 }"
              x-text="$store.reserve.state ? $store.reserve.state.remaining : 0">0</span>
    </div>

    <div class="stat-card">
        <span class="stat-label">批次</span>
        <span class="stat-value"
              x-text="$store.reserve.state ? $store.reserve.state.batch_no : 0">0</span>
    </div>

</section>
