{{--
    操作区：取号 / 放号两个主按钮
      - 加载态用 spinner，禁用期间重复点击会被 store 里的 taking / releasing 拦掉
--}}
<section class="grid shrink-0 grid-cols-2 gap-8">

    <button type="button"
            class="btn-take"
            @click="$store.reserve.take()"
            :disabled="$store.reserve.taking">
        <span class="btn-spinner" x-show="$store.reserve.taking" aria-hidden="true"></span>
        <span x-text="$store.reserve.taking ? '取号中…' : '取 号'">取 号</span>
    </button>

    <button type="button"
            class="btn-release"
            @click="$store.reserve.release()"
            :disabled="$store.reserve.releasing || !$store.reserve.canRelease()">
        <span class="btn-spinner" x-show="$store.reserve.releasing" aria-hidden="true"></span>
        <span x-show="$store.reserve.releasing">放号中…</span>
        <span x-show="!$store.reserve.releasing"
              x-text="$store.reserve.activeTicket() ? '放号 ' + $store.reserve.activeTicket().serial_no : '放 号'">放 号</span>
    </button>

</section>
