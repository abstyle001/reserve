{{--
    当前号票：渲染后端 state.tickets（当前批次真实号票），所有端看到的一致。
      - 不再渲染 localStorage 记录：localStorage 是浏览器私有的，不同 origin/设备
        会不一致，且本地 is_finish 无法被后端校正（别处放号后本地仍显示待叫号）
      - localStorage records 只用于「本机」角标（标记哪些号是本机取的）
      - 选中态用 store 的 selectedSerial 比较（不要在 x-for 项上再开 x-data，
        局部作用域会遮蔽 store，导致选中态失效）
--}}
<section class="glass flex min-h-0 flex-1 flex-col overflow-hidden rounded-[2.25rem] p-7">

    <div class="flex items-center justify-between">
        <h2 class="section-title">当前号票</h2>
        <span class="badge badge--gray" x-text="$store.reserve.currentTickets().length + ' 张'"></span>
    </div>

    {{-- pb-3：最后一行号票与面板底边留出呼吸感；pr-3：给滚动条留边距 --}}
    <div class="record-grid mt-6 min-h-0 flex-1 gap-4 overflow-y-auto pb-3 pr-3">

        <template x-for="ticket in $store.reserve.currentTickets()" :key="ticket.serial_no">
            <button type="button"
                    class="ticket-chip"
                    :class="{
                        'ticket-chip--active': $store.reserve.selectedSerial === ticket.serial_no,
                        'ticket-chip--done': Number(ticket.is_finish) === 1
                    }"
                    @click="$store.reserve.selectRecord(ticket.serial_no)">

                <span class="flex h-7 items-center">
                    <span class="badge badge--blue !px-3 !py-0.5 !text-sm"
                          x-show="$store.reserve.isMine(ticket.serial_no)">本机</span>
                </span>

                <span class="ticket-chip__no" x-text="ticket.serial_no"></span>

                <span class="text-base"
                      :class="Number(ticket.is_finish) === 1 ? 'text-white/40' : 'text-white/70'"
                      x-text="Number(ticket.is_finish) === 1 ? '✓ 已放号' : ($store.reserve.selectedSerial === ticket.serial_no ? '已选中' : '待叫号')">
                    待叫号
                </span>

            </button>
        </template>

    </div>

    <p class="mt-6 text-xl text-white/40" x-show="$store.reserve.currentTickets().length === 0">
        当前批次暂无号票，点击「取号」后会显示在这里
    </p>

</section>
