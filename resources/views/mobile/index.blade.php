{{--
    移动端排队查询（只读）

    技术约定：
      - 与 reserve 大屏同一套约定：$store.mobile 是唯一数据源，模板只读不写
      - 只读页面：没有任何取号/放号入口，JS 里也没有 POST/DELETE
      - 入口两种：手动输入（队列 + 号码），或扫大屏号票二维码 /m?key=xxx&no=27 直达
--}}
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>排队查询</title>
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
    <script src="{{ mix('js/mobile/app.js') }}" defer></script>
</head>
<body
    class="font-sans antialiased"
    data-reserve-config="{{ json_encode(['queueOptions' => $queueOptions], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) }}"
>
    <div x-data x-cloak class="screen min-h-screen w-full">
        <div class="mx-auto flex min-h-screen w-full max-w-md flex-col px-5 pb-8 safe-t">

            <header class="flex items-center justify-between pt-6">
                <div class="flex items-center gap-3">
                    <span class="brand-mark"></span>
                    <span class="text-xl font-semibold tracking-tight text-white">排队查询</span>
                </div>
                <span
                    class="status-dot"
                    :class="$store.mobile.online ? 'status-dot--online' : 'status-dot--offline'"
                    :title="$store.mobile.online ? '已连接' : '连接中断，正在重试'"
                ></span>
            </header>

            <main class="flex flex-1 flex-col gap-5 pt-6">

                {{-- 查询表单：未绑定号码时显示 --}}
                <section class="glass flex flex-col gap-6 p-6" x-show="!$store.mobile.ticket">
                    <div class="flex flex-col gap-2">
                        <label class="m-label">队列</label>
                        <select class="queue-picker__input w-full !text-2xl" x-model="$store.mobile.queueKey">
                            <template x-for="option in $store.mobile.queueOptions" :key="option">
                                <option :value="option" x-text="option"></option>
                            </template>
                        </select>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="m-label">我的号码</label>
                        <input
                            class="m-input"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            placeholder="输入大屏上的号码"
                            x-model="$store.mobile.serialInput"
                            @keydown.enter="$store.mobile.submit()"
                        >
                    </div>

                    <button type="button" class="m-btn-primary" @click="$store.mobile.submit()">
                        查询排队进度
                    </button>

                    <p class="text-center text-sm text-white/40">
                        号码见大屏号票，也可直接扫号票旁的二维码
                    </p>
                </section>

                {{-- 查询结果：绑定号码后显示，每 5 秒自动刷新 --}}
                <template x-if="$store.mobile.ticket">
                    <section class="glass flex flex-col items-center gap-6 p-6 text-center">
                        <div class="flex w-full items-center justify-between">
                            <span class="m-label" x-text="$store.mobile.ticket.key"></span>
                            <span
                                class="badge"
                                :class="$store.mobile.statusBadgeClass()"
                                x-text="$store.mobile.statusLabel()"
                            ></span>
                        </div>

                        <div class="flex flex-col gap-1">
                            <span class="m-label">我的号码</span>
                            <span
                                class="text-6xl font-extrabold tabular-nums text-white"
                                x-text="$store.mobile.ticket.serialNo"
                            ></span>
                        </div>

                        <div
                            class="flex flex-col items-center gap-2"
                            x-show="$store.mobile.result && $store.mobile.result.status === 'waiting'"
                        >
                            <span class="m-label">前面还有</span>
                            <div class="flex items-baseline gap-2">
                                <span class="m-hero" x-text="$store.mobile.result ? $store.mobile.result.ahead_count : '—'"></span>
                                <span class="text-2xl text-white/60">人</span>
                            </div>
                        </div>

                        <p class="text-base leading-relaxed text-white/55" x-text="$store.mobile.statusHint()"></p>

                        <div class="grid w-full grid-cols-2 gap-4" x-show="$store.mobile.result">
                            <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
                                <div class="m-label">本批已发到</div>
                                <div
                                    class="mt-1 text-3xl font-bold tabular-nums text-white"
                                    x-text="$store.mobile.result ? $store.mobile.result.current_no : '—'"
                                ></div>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4">
                                <div class="m-label">剩余人数</div>
                                <div
                                    class="mt-1 text-3xl font-bold tabular-nums text-white"
                                    x-text="$store.mobile.result ? $store.mobile.result.active_count : '—'"
                                ></div>
                            </div>
                        </div>

                        <button type="button" class="m-btn-ghost" @click="$store.mobile.reset()">
                            换个号码查询
                        </button>
                    </section>
                </template>
            </main>

            <footer class="pt-6 text-center text-xs text-white/35" x-show="$store.mobile.result">
                每 5 秒自动刷新 · <span x-text="$store.mobile.result ? $store.mobile.result.server_time : ''"></span>
            </footer>

            {{-- 提示浮层 --}}
            <div class="fixed inset-x-0 bottom-8 z-50 flex justify-center px-6" x-show="$store.mobile.toast" x-transition>
                <div
                    class="rounded-full px-6 py-3 text-base font-semibold shadow-2xl backdrop-blur-2xl"
                    :class="$store.mobile.toast && $store.mobile.toast.type === 'success'
                        ? 'border border-emerald-300/30 bg-emerald-300/95 text-emerald-900'
                        : 'border border-rose-300/30 bg-rose-300/95 text-rose-900'"
                    x-text="$store.mobile.toast ? $store.mobile.toast.text : ''"
                ></div>
            </div>
        </div>
    </div>
</body>
</html>
