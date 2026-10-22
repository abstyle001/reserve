{{--
    云途旅行（/fly）C 端外壳：顶栏 + 内容 + 页脚。

    独立全屏文档，不复用 layouts/app.blade.php（Breeze 鉴权导航不适用 C 端场景）。
    「我的订单」两处都指 /fly/orders：C 端订单不属于 Breeze 用户（无 user_id，
    凭证是 order_no + guest_phone），指回 route('dashboard') 是给后台管理员看的。

    Alpine 相关（x-data / data-fly-config 的局部刷新）见 resources/js/fly/，
    本文件在无 JS 时必须完整可用 —— 渐进增强的下限。
--}}
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>@yield('title', '云途旅行 — 酒店 · 机票 · 火车票一站式预订')</title>
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
</head>
<body class="fly-body"@if (isset($flyConfig)) data-fly-config="{{ json_encode($flyConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) }}"@endif>

    {{-- ===================== 顶部导航 ===================== --}}
    <header class="fly-topbar">
        <div class="fly-container flex h-16 items-center justify-between gap-6">
            <div class="flex items-center gap-8">
                {{-- 品牌 --}}
                <a href="{{ route('fly.home') }}" class="flex items-center gap-2.5">
                    <span class="fly-brand-mark" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m22 2-7 20-4-9-9-4Z"/>
                            <path d="M22 2 11 13"/>
                        </svg>
                    </span>
                    <span>
                        <span class="fly-brand-name">云途旅行</span>
                        <span class="fly-brand-slogan">让每一次出发都简单</span>
                    </span>
                </a>

                {{-- 主菜单：只有酒店有真实数据，其余业务尚未开放 --}}
                <nav class="hidden items-center gap-1 md:flex">
                    <a href="{{ route('fly.home') }}" class="fly-nav-link fly-nav-link--active">首页</a>
                    <a href="{{ route('fly.home') }}" class="fly-nav-link">酒店</a>
                    <span class="fly-nav-link cursor-default opacity-50" title="尚未开放">机票</span>
                    <span class="fly-nav-link cursor-default opacity-50" title="尚未开放">火车票</span>
                    <span class="fly-nav-link cursor-default opacity-50" title="尚未开放">门票</span>
                    <span class="fly-nav-link cursor-default opacity-50" title="尚未开放">旅行攻略</span>
                </nav>
            </div>

            <div class="flex items-center gap-3">
                <a href="tel:95010" class="hidden items-center gap-1.5 text-sm text-gray-500 transition hover:text-apple-blue sm:flex">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92Z"/>
                    </svg>
                    95010
                </a>
                <a href="{{ route('fly.orders') }}" class="fly-btn-outline">我的订单</a>
                @auth
                    <span class="flex items-center gap-1.5 text-sm font-medium text-gray-700">
                        <svg class="h-4 w-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                        </svg>
                        {{ Auth::user()->name }}
                    </span>
                    {{-- 退出登录：Breeze 的 /logout 只接受 POST + CSRF --}}
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="fly-btn-primary">退出登录</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="fly-btn-primary">登录 / 注册</a>
                @endauth
            </div>
        </div>
    </header>

    {{-- 一次性提示：下单/取消的成功与失败都往这里落，PRG 跳转后仍能看到。
         用两个独立 key 而不是一个带样式的 key，是为了让 Blade 不用判断文案内容。 --}}
    @if (session('flyFlash'))
        <div class="fly-container pt-5">
            <div class="fly-flash fly-flash--ok">{{ session('flyFlash') }}</div>
        </div>
    @endif
    @if (session('flyFlashError'))
        <div class="fly-container pt-5">
            <div class="fly-flash fly-flash--err">{{ session('flyFlashError') }}</div>
        </div>
    @endif

    {{-- Alpine 作用域的根：只建作用域、不带任何状态（x-data 空表达式即 {}），
         一切数值来自 $store.fly。这里绝不能加 x-cloak —— 包着的是 SSR 首屏，
         JS 尚未加载完（defer）时 x-cloak 会让整页内容闪没一次。 --}}
    <div x-data>
        @yield('content')
    </div>

    {{-- ===================== 页脚 ===================== --}}
    <footer class="fly-footer">
        <div class="fly-container py-10">
            <div class="grid grid-cols-2 gap-8 md:grid-cols-4">
                <div>
                    <p class="fly-footer-title">关于我们</p>
                    <div class="fly-footer-links">
                        <a href="#" class="block">公司介绍</a>
                        <a href="#" class="block">联系我们</a>
                        <a href="#" class="block">加入我们</a>
                    </div>
                </div>
                <div>
                    <p class="fly-footer-title">加盟合作</p>
                    <div class="fly-footer-links">
                        <a href="{{ route('hotels.index') }}" class="block">酒店入驻</a>
                        <a href="#" class="block">旅行社合作</a>
                        <a href="#" class="block">广告服务</a>
                    </div>
                </div>
                <div>
                    <p class="fly-footer-title">帮助中心</p>
                    <div class="fly-footer-links">
                        <a href="{{ route('fly.orders') }}" class="block">订单查询</a>
                        <a href="#" class="block">退改政策</a>
                        <a href="#" class="block">常见问题</a>
                    </div>
                </div>
                <div>
                    <p class="fly-footer-title">用户协议</p>
                    <div class="fly-footer-links">
                        <a href="#" class="block">服务条款</a>
                        <a href="#" class="block">隐私政策</a>
                        <a href="#" class="block">版权声明</a>
                    </div>
                </div>
            </div>
            <p class="fly-footer-bottom">
                © 2026 云途旅行 YunTu Travel · 让每一次出发都简单 · 京ICP备00000000号-1（占位）
            </p>
        </div>
    </footer>

    {{-- defer：先让 SSR 首屏画完，Alpine 再接管（store 里 hydrate 早于 start，
         所以接管那一刻绑定的就是服务端已经渲染过的同一份数据）。 --}}
    <script src="{{ mix('js/fly/app.js') }}" defer></script>

    @stack('scripts')
</body>
</html>
