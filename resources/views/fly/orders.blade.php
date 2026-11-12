{{--
    我的订单（/fly/orders）：登录用户的订单列表，进入即渲染，无需任何查询条件。

    订单归属 bookings.user_id（路由已叠 auth，到这里的必是登录用户）；
    入住人姓名/手机号只是联系方式，不再是查单凭证。
--}}
@extends('fly.layout')

@php
    $statusClass = [
        \App\Models\Booking::STATUS_BOOKED     => 'fly-status fly-status--booked',
        \App\Models\Booking::STATUS_CANCELLED  => 'fly-status fly-status--cancelled',
        \App\Models\Booking::STATUS_CHECKED_IN => 'fly-status fly-status--stay',
        \App\Models\Booking::STATUS_CHECKED_OUT => 'fly-status fly-status--stay',
    ];
@endphp

@section('title', '我的订单 — 云途旅行')

@section('content')
    <div class="fly-container">
        <nav class="fly-crumb" aria-label="面包屑">
            <a href="{{ route('fly.home') }}">首页</a>
            <span aria-hidden="true">/</span>
            <span class="text-gray-600">我的订单</span>
        </nav>
    </div>

    <section class="fly-container pb-10">
        @if (count($bookings) === 0)
            <div class="fly-empty">
                <span class="fly-empty__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M8 21h8"/><path d="M7 10h10"/><path d="M12 2v10"/>
                        <path d="M17 21a5 5 0 0 0 5-5v-4a7 7 0 0 0-7-7H9a7 7 0 0 0-7 7v4a5 5 0 0 0 5 5Z"/>
                    </svg>
                </span>
                <p class="fly-empty__title">你还没有订单</p>
                <p class="fly-empty__sub">下单后订单会绑定到当前登录账号，在这里随时查看与取消。</p>
                <div class="mt-5">
                    <a href="{{ route('fly.home') }}" class="fly-btn-primary">去挑一家酒店</a>
                </div>
            </div>
        @else
            <div class="fly-section-head">
                <div>
                    <h2 class="fly-section-title">订单列表</h2>
                    <p class="fly-section-sub">共 {{ count($bookings) }} 单</p>
                </div>
            </div>

            <div class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
                @foreach ($bookings as $booking)
                    <div class="fly-room-row">
                        <div class="min-w-0 flex-1">
                            <h3 class="fly-hotel-name">{{ $booking->hotel ? $booking->hotel->name : '该酒店已下架' }}</h3>
                            <p class="mt-1 text-xs text-gray-400">
                                {{ $booking->roomType ? $booking->roomType->name : '—' }} ·
                                {{ $booking->check_in }} → {{ $booking->check_out }} ·
                                {{ $booking->nights }} 晚 · {{ $booking->room_count }} 间
                            </p>
                            <p class="mt-1 text-xs tabular-nums text-gray-400">
                                {{ $booking->order_no }} · 下单 {{ $booking->booked_at_text }}
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <span class="{{ isset($statusClass[$booking->status]) ? $statusClass[$booking->status] : 'fly-status' }}">
                                {{ $booking->status_label }}
                            </span>
                            <p class="mt-2 text-sm font-semibold text-gray-900 tabular-nums">¥{{ $booking->amount_text }}</p>
                            <a href="{{ route('fly.orders.show', $booking) }}"
                               class="fly-filter-btn mt-2">查看详情</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="fly-promise mt-5">
            <div class="fly-promise__item">
                <span class="fly-promise__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </span>
                订单绑定登录账号，仅本人可见
            </div>
            <div class="fly-promise__item">
                <span class="fly-promise__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>
                    </svg>
                </span>
                取消后房量即时释放
            </div>
            <div class="fly-promise__item">
                <span class="fly-promise__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1Z"/>
                        <path d="m9 12 2 2 4-4"/>
                    </svg>
                </span>
                入住人信息仅用于酒店联系
            </div>
        </div>
    </section>
@endsection
