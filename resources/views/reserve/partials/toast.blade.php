{{--
    提示浮层：取号 / 放号的成功与错误都在这里反馈
      成功用浅绿、错误用浅红，苹果系统 alert 的配色语言
--}}
<div class="pointer-events-none fixed inset-x-0 bottom-20 z-50 flex justify-center">

    <div class="toast"
         x-show="$store.reserve.toast"
         x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-6 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-4 scale-95"
         :class="$store.reserve.toast && $store.reserve.toast.type === 'error' ? 'toast--error' : 'toast--success'"
         x-text="$store.reserve.toast ? $store.reserve.toast.text : ''">
    </div>

</div>
