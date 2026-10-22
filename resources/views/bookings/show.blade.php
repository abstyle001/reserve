{{--
    后台订单详情（/bookings/{booking}）。

    时间一律用 booked_at_text / cancelled_at_text：app.timezone 是 UTC，裸 format 出来的
    下单时间比顾客看到的早 8 小时，客服对不上话。C 端页面用的也是这两个访问器。
--}}
@php
    $statusBadge = [
        \App\Models\Booking::STATUS_BOOKED      => 'badge--green',
        \App\Models\Booking::STATUS_CHECKED_IN  => 'badge--blue',
        \App\Models\Booking::STATUS_CHECKED_OUT => 'badge--amber',
        \App\Models\Booking::STATUS_CANCELLED   => 'badge--gray',
    ];

    $rows = [
        ['label' => '入住 / 离店', 'value' => $booking->check_in . ' → ' . $booking->check_out . '（共 ' . $booking->nights . ' 晚）'],
        ['label' => '预订间数',   'value' => $booking->room_count . ' 间'],
        ['label' => '房型单价',   'value' => '¥' . $booking->unit_price_text . ' / 晚'],
        ['label' => '订单金额',   'value' => '¥' . $booking->amount_text],
        ['label' => '入住人',     'value' => $booking->guest_name ?: '—'],
        ['label' => '手机号',     'value' => $booking->guest_phone],
        ['label' => '下单时间',   'value' => $booking->booked_at_text],
        ['label' => '取消时间',   'value' => $booking->cancelled_at_text],
        ['label' => '备注',       'value' => $booking->remark ?: '—'],
    ];
@endphp

@extends('admin.layout')

@section('title', '订单 ' . $booking->order_no)

@section('content')
    <a href="{{ route('bookings.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-gray-500 transition hover:text-gray-900">
        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        返回订单列表
    </a>

    <div class="admin-page-header">
        <div>
            <h1 class="admin-title tabular-nums">{{ $booking->order_no }}</h1>
            <p class="admin-subtitle">
                {{ $booking->source_label }}
                @if ($booking->hotel)
                    · {{ $booking->hotel->city ? $booking->hotel->city . ' · ' : '' }}{{ $booking->hotel->name }}
                @endif
            </p>
        </div>
        <span class="badge {{ isset($statusBadge[$booking->status]) ? $statusBadge[$booking->status] : 'badge--gray' }}">
            {{ $booking->status_label }}
        </span>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="admin-panel p-6">
                <h2 class="mb-5 text-sm font-semibold text-gray-900">{{ $booking->roomType ? $booking->roomType->name : '房型 #' . $booking->room_type_id }}</h2>

                <dl class="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                    @foreach ($rows as $row)
                        <div class="flex items-baseline justify-between gap-4 border-b border-gray-100 py-2.5">
                            <dt class="text-sm text-gray-500">{{ $row['label'] }}</dt>
                            <dd class="text-right text-sm tabular-nums text-gray-900">{{ $row['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>

                <h3 class="mt-6 text-sm font-semibold text-gray-900">分配房间</h3>
                @if ($rooms->isEmpty())
                    <p class="mt-2 text-sm text-gray-400">本单没有指定具体房间（下单时按房型口径占用，到店由前台分房）。</p>
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>房号</th>
                                    <th>楼层</th>
                                    <th>当前客房状态</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rooms as $room)
                                    <tr>
                                        <td class="font-medium tabular-nums text-gray-900">{{ $room->room_no }}</td>
                                        <td class="tabular-nums">{{ $room->floor !== null ? $room->floor . ' 层' : '—' }}</td>
                                        <td>{{ $room->status_label }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-xs leading-relaxed text-gray-400">
                        「当前客房状态」是这间房此刻的物理状态（空闲 / 在住 / 打扫中），与未来某天的房量无关 ——
                        可售口径看 rooms.status（只排除停用）减去 bookings 的日期区间占用，两者不能混。
                    </p>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="admin-panel p-6">
                <h2 class="mb-4 text-sm font-semibold text-gray-900">操作</h2>

                @if ($booking->status === \App\Models\Booking::STATUS_BOOKED)
                    <form method="POST" action="{{ route('bookings.cancel', $booking) }}"
                          onsubmit="return confirm('确定取消订单「{{ $booking->order_no }}」吗？取消后 {{ $booking->room_count }} 间房量立即释放，且不可恢复。');">
                        @csrf
                        <p class="mb-3 text-xs leading-relaxed text-gray-500">
                            后台取消与顾客在订单页自己取消走的是同一条状态流转：行不删，状态改为「已取消」，
                            该日期区间的占用立即释放。
                        </p>
                        <button type="submit" class="admin-btn-ghost w-full justify-center !text-red-600">取消订单</button>
                    </form>
                @else
                    <p class="text-sm leading-relaxed text-gray-500">
                        本单状态为「{{ $booking->status_label }}」，无需再操作。
                    </p>
                @endif

                <div class="mt-5 space-y-2 border-t border-gray-100 pt-5">
                    @if ($booking->hotel)
                        <a href="{{ route('hotels.rooms.index', $booking->hotel_id) }}" class="admin-action">管理「{{ $booking->hotel->name }}」的房间</a>
                        <a href="{{ route('fly.hotels.show', $booking->hotel_id) }}" target="_blank" rel="noopener" class="admin-action block">在前台查看这家酒店</a>
                    @endif
                    <a href="{{ route('fly.orders') }}?phone={{ rawurlencode((string) $booking->guest_phone) }}"
                       target="_blank" rel="noopener" class="admin-action block">以顾客视角查这个手机号</a>
                </div>
            </div>

            <div class="admin-panel p-6">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">房量口径</h2>
                <p class="text-xs leading-relaxed text-gray-500">
                    本单占用 {{ $booking->check_in }} 起 {{ $booking->nights }} 晚 × {{ $booking->room_count }} 间。
                    离店当天不占房（半开区间 [{{ $booking->check_in }}, {{ $booking->check_out }})），
                    所以下一个订单的入住日正好等于本单离店日时不冲突。
                </p>
            </div>
        </div>
    </div>
@endsection
