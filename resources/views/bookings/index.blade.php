{{--
    后台订单列表（/bookings，需登录）。

    这里显示**完整姓名与手机号**，不脱敏 —— 脱敏访问器（guest_name_masked /
    guest_phone_masked）只服务 C 端查单页：那边没有登录态，任何人输手机号就能看到列表。
    后台有 auth，客服要打给用户回访必须拿到真号。

    状态徽标的 class 全部写成完整字面量放在 $statusBadge 里，Tailwind purge 扫的是源码
    文本，写成 badge--{{ $x }} 会被整段删掉。
--}}
@php
    $statusBadge = [
        \App\Models\Booking::STATUS_BOOKED      => 'badge--green',
        \App\Models\Booking::STATUS_CHECKED_IN  => 'badge--blue',
        \App\Models\Booking::STATUS_CHECKED_OUT => 'badge--amber',
        \App\Models\Booking::STATUS_CANCELLED   => 'badge--gray',
    ];

    $statusTabs = [
        '' => '全部',
        (string) \App\Models\Booking::STATUS_BOOKED     => '已预订',
        (string) \App\Models\Booking::STATUS_CHECKED_IN => '已入住',
        (string) \App\Models\Booking::STATUS_CHECKED_OUT => '已退房',
        (string) \App\Models\Booking::STATUS_CANCELLED  => '已取消',
    ];

    $activeTab = isset($statusTabs[$status]) ? $status : '';
@endphp

@extends('admin.layout')

@section('title', '订单管理')

@section('content')
    <div class="admin-page-header">
        <div>
            <h1 class="admin-title">订单管理</h1>
            <p class="admin-subtitle">云途旅行前台产生的预订单，取消后房量即时释放</p>
        </div>
        <a href="{{ route('fly.home') }}" target="_blank" rel="noopener" class="admin-btn-primary">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
            前台页面
        </a>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
        <div class="admin-stat">
            <div>
                <p class="admin-stat__value tabular-nums">{{ $stats['total'] }}</p>
                <p class="admin-stat__label">订单总数</p>
            </div>
        </div>
        <div class="admin-stat">
            <div>
                <p class="admin-stat__value tabular-nums">{{ $stats['active'] }}</p>
                <p class="admin-stat__label">占用房量</p>
            </div>
        </div>
        <div class="admin-stat">
            <div>
                <p class="admin-stat__value tabular-nums">{{ $stats['cancelled'] }}</p>
                <p class="admin-stat__label">已取消</p>
            </div>
        </div>
        <div class="admin-stat">
            <div>
                <p class="admin-stat__value tabular-nums">¥{{ number_format($stats['active_amount'], 0, '.', ',') }}</p>
                <p class="admin-stat__label">占用订单金额</p>
            </div>
        </div>
    </div>

    {{-- 筛选：GET 表单，无 JS。status 用 tab 链接而不是 select，跟房型/房间页的 tabs 同一套交互 --}}
    <div class="admin-tabs">
        @foreach ($statusTabs as $tabValue => $tabLabel)
            <a href="{{ route('bookings.index', array_filter(['status' => $tabValue, 'q' => $keyword, 'hotel_id' => $hotelId ?: null])) }}"
               class="admin-tab {{ $activeTab === (string) $tabValue ? 'admin-tab--active' : '' }}">{{ $tabLabel }}</a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('bookings.index') }}" class="mb-6 flex flex-wrap items-end gap-3">
        @if ($activeTab !== '')
            <input type="hidden" name="status" value="{{ $activeTab }}">
        @endif
        <div class="min-w-[14rem] flex-1">
            <label class="admin-label" for="q">订单号 / 手机号 / 入住人</label>
            <input id="q" type="text" name="q" value="{{ $keyword }}" class="admin-input" placeholder="如 YT261021589907 或 13700137000">
        </div>
        <div class="w-56">
            <label class="admin-label" for="hotel_id">酒店</label>
            <select id="hotel_id" name="hotel_id" class="admin-input">
                <option value="">全部酒店</option>
                @foreach ($hotels as $hotel)
                    <option value="{{ $hotel->id }}"{{ $hotelId === (int) $hotel->id ? ' selected' : '' }}>
                        {{ $hotel->city ? $hotel->city . ' · ' : '' }}{{ $hotel->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="admin-btn-primary h-[2.5rem]">筛选</button>
        @if ($keyword !== '' || $hotelId > 0 || $activeTab !== '')
            <a href="{{ route('bookings.index') }}" class="admin-btn-ghost h-[2.5rem]">清空</a>
        @endif
    </form>

    <div class="admin-panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>订单号</th>
                        <th>酒店 / 房型</th>
                        <th>入住人</th>
                        <th>入住 — 离店</th>
                        <th>间数</th>
                        <th>金额</th>
                        <th>状态</th>
                        <th>下单时间</th>
                        <th class="text-right">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bookings as $booking)
                        <tr>
                            <td class="whitespace-nowrap">
                                <a href="{{ route('bookings.show', $booking) }}"
                                   class="font-medium tabular-nums text-gray-900 transition hover:text-blue-600">{{ $booking->order_no }}</a>
                                <span class="block text-xs text-gray-400">{{ $booking->source_label }}</span>
                            </td>
                            <td>
                                <span class="block max-w-[14rem] truncate font-medium text-gray-900">{{ $booking->hotel ? $booking->hotel->name : '酒店 #' . $booking->hotel_id }}</span>
                                <span class="block max-w-[14rem] truncate text-xs text-gray-400">
                                    {{ $booking->roomType ? $booking->roomType->name : '房型 #' . $booking->room_type_id }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap">
                                <span class="block text-gray-900">{{ $booking->guest_name ?: '—' }}</span>
                                <span class="block tabular-nums text-xs text-gray-500">{{ $booking->guest_phone }}</span>
                            </td>
                            <td class="whitespace-nowrap tabular-nums">
                                <span class="text-gray-900">{{ $booking->check_in }}</span>
                                <span class="text-gray-400"> → {{ $booking->check_out }}</span>
                                <span class="block text-xs text-gray-400">共 {{ $booking->nights }} 晚</span>
                            </td>
                            <td class="tabular-nums">{{ $booking->room_count }}</td>
                            <td class="whitespace-nowrap tabular-nums text-gray-900">¥{{ $booking->amount_text }}</td>
                            <td>
                                <span class="badge badge--sm {{ isset($statusBadge[$booking->status]) ? $statusBadge[$booking->status] : 'badge--gray' }}">
                                    {{ $booking->status_label }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap tabular-nums text-gray-500">{{ $booking->booked_at_text }}</td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-4">
                                    <a href="{{ route('bookings.show', $booking) }}" class="admin-action">详情</a>
                                    @if ($booking->status === \App\Models\Booking::STATUS_BOOKED)
                                        <form method="POST" action="{{ route('bookings.cancel', $booking) }}"
                                              onsubmit="return confirm('确定取消订单「{{ $booking->order_no }}」吗？取消后 {{ $booking->room_count }} 间房量立即释放，且不可恢复。');">
                                            @csrf
                                            <button type="submit" class="admin-action admin-action--danger">取消</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="admin-empty">
                                @if ($keyword !== '' || $hotelId > 0 || $activeTab !== '')
                                    没有匹配条件的订单，换个关键词或清空筛选试试
                                @else
                                    还没有订单 —— 顾客在云途前台下单后会出现在这里
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('admin.partials.pagination', ['paginator' => $bookings])
@endsection
