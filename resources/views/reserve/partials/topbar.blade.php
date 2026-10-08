{{--
    毛玻璃顶栏：队列切换、连接状态、放大/全屏
      放大按钮同时触发全屏（必须由用户手势调用 requestFullscreen）
--}}
<header class="glass safe-t flex shrink-0 items-center justify-between px-14 py-5 lg:px-20 3xl:px-28">

    <div class="flex items-center gap-6 pt-4">
        <span class="brand-mark" aria-hidden="true"></span>
        <span class="text-2xl font-semibold tracking-tight text-white 3xl:text-3xl">
            取号大屏
        </span>

        <div class="queue-picker ml-6">
            <span class="queue-picker__label">队列</span>
            <select class="queue-picker__input"
                    x-model="$store.reserve.queueKey"
                    @change="$store.reserve.selectQueue($store.reserve.queueKey)">
                <template x-for="option in $store.reserve.queueOptions" :key="option">
                    <option x-bind:value="option" x-text="option"></option>
                </template>
            </select>
        </div>
    </div>

    <div class="flex items-center gap-9">

        <div class="flex items-center gap-3">
            <span class="status-dot"
                  :class="$store.reserve.online ? 'status-dot--online' : 'status-dot--offline'"
                  aria-hidden="true"></span>
            <span class="text-base text-white/55"
                  x-text="$store.reserve.online ? '实时同步中' : '连接中断，重试中'">
                实时同步中
            </span>
        </div>

        <button type="button" class="btn-ghost" @click="$store.reserve.toggleZoom()">
            <svg x-show="!$store.reserve.zoomed" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>
            </svg>
            <svg x-show="$store.reserve.zoomed" x-cloak class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M8 3v5H3M16 21v-5h5M3 8l5 5M21 16l-5-5"/>
            </svg>
            <span x-text="$store.reserve.zoomed ? '缩 小' : '放 大'">放 大</span>
        </button>

        {{-- 退出登录：Breeze 的 /logout 只接受 POST + CSRF --}}
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-ghost">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>
                </svg>
                <span>退 出</span>
            </button>
        </form>

    </div>

</header>
