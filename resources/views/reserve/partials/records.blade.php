{{--
    我的号票：取号结果存在 localStorage，刷新页面后仍能拿回来放号
      只展示当前批次的号票（换批后历史批次的记录隐藏，但仍保留在本地）；
      选中态用 store 的 selectedId 比较（不要在 x-for 项上再开 x-data，
      局部作用域会遮蔽 store，导致选中态失效）
--}}
<section class="glass flex min-h-0 flex-1 flex-col overflow-hidden rounded-[2.25rem] p-7">

    <div class="flex items-center justify-between">
        <h2 class="section-title">我的号票</h2>
        <span class="text-base text-white/45" x-text="$store.reserve.currentBatchRecords().length + ' 张'"></span>
    </div>

    {{-- pb-3：最后一行号票与面板底边留出呼吸感；pr-3：给滚动条留边距 --}}
    <div class="record-grid mt-6 min-h-0 flex-1 gap-4 overflow-y-auto pb-3 pr-3">

        <template x-for="record in $store.reserve.currentBatchRecords()" :key="record.id">
            <button type="button"
                    class="ticket-chip"
                    :class="{
                        'ticket-chip--active': $store.reserve.selectedId === record.id,
                        'ticket-chip--done': record.is_finish === 1
                    }"
                    @click="$store.reserve.selectRecord(record.id)">

                <span class="flex items-center gap-2">
                    <span class="text-base text-white/45">批次</span>
                    <span class="text-lg font-semibold text-white/80" x-text="record.batch_no"></span>
                </span>

                <span class="ticket-chip__no" x-text="record.serial_no"></span>

                <span class="text-base"
                      :class="record.is_finish === 1 ? 'text-white/40' : 'text-white/70'"
                      x-text="record.is_finish === 1 ? '已放号' : ($store.reserve.selectedId === record.id ? '已选中' : '待叫号')">
                    待叫号
                </span>

            </button>
        </template>

    </div>

    <p class="mt-6 text-xl text-white/40" x-show="$store.reserve.currentBatchRecords().length === 0">
        尚未取号，点击「取号」后会显示在这里
    </p>

</section>
