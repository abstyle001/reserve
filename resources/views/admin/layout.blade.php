{{--
    酒店管理后台布局：独立的深色玻璃管理台，不复用 Breeze 的 layouts/app.blade.php。
    页面用法：@extends('admin.layout') + @section('title') + @section('content')
--}}
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '酒店管理') · 管理后台</title>
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
    <script src="{{ mix('js/app.js') }}" defer></script>
</head>
<body class="font-sans antialiased overflow-hidden">
@php($sidebarHotels = \App\Models\Hotel::orderBy('id')->get())
<div x-data="{ sidebarOpen: false }" class="admin-shell">

    {{-- 移动端抽屉遮罩 --}}
    <div x-show="sidebarOpen" x-cloak class="fixed inset-0 z-40 bg-black/60 lg:hidden"
         @click="sidebarOpen = false"></div>

    <aside class="admin-sidebar" :class="sidebarOpen ? 'translate-x-0' : ''">
        <a href="{{ route('hotels.index') }}" class="admin-brand">
            <span class="brand-mark"></span>
            <span>
                <span class="block text-base font-semibold text-gray-900">酒店管理</span>
                <span class="block text-xs text-gray-400">管理后台</span>
            </span>
        </a>

        <nav class="admin-nav pt-4">
            <a href="{{ route('hotels.index') }}"
               class="admin-nav-item {{ request()->routeIs('hotels.*') ? 'admin-nav-item--active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-4h6v4"/><path d="M9 10h.01M15 10h.01M9 14h.01M15 14h.01"/></svg>
                酒店列表
            </a>
        </nav>

        @if ($sidebarHotels->isNotEmpty())
            <p class="admin-side-section">房间快捷管理</p>
            <nav class="admin-nav overflow-y-auto no-scrollbar">
                @foreach ($sidebarHotels as $sideHotel)
                    <a href="{{ route('hotels.rooms.index', $sideHotel) }}"
                       class="admin-hotel-link {{ isset($hotel) && $hotel->id === $sideHotel->id ? 'admin-nav-item--active' : '' }}">
                        <span class="truncate">{{ $sideHotel->name }}</span>
                        <svg class="h-3.5 w-3.5 shrink-0 opacity-40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                    </a>
                @endforeach
            </nav>
        @endif

        <div class="admin-side-footer">
            <div class="px-2 py-2">
                <p class="truncate text-sm font-medium text-gray-900">{{ Auth::user()->name }}</p>
                <p class="truncate text-xs text-gray-400">{{ Auth::user()->email }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="admin-nav-item w-full text-left">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg>
                    退出登录
                </button>
            </form>
        </div>
    </aside>

    <div class="admin-main">
        <header class="admin-mobilebar">
            <button type="button" @click="sidebarOpen = true" class="admin-btn-ghost !px-3 !py-2">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <span class="text-sm font-medium text-gray-900">酒店管理</span>
        </header>

        <main class="min-h-0 flex-1 overflow-y-auto">
            <div class="admin-page">
                @include('admin.partials.flash')
                @yield('content')
            </div>
        </main>
    </div>

</div>
</body>
</html>
