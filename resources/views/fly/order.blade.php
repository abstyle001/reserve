{{--
    订单详情 = 下单结果页（/fly/orders/{booking}?phone=…）。

    凭证是手机号：URL 里没有 token、没有登录态，改别人的手机号只会拿到 404。
    时间/金额一律走模型访问器（C 端时区 + 千分位），本页不自己 format。
--}}
@extends('fly.layout')

@php
    $statusClass = [
        \App\Models\Booking::STATUS_BOOKED     => 'fly-status fly-status--booked',
        \App\Models\Booking::STATUS_CANCELLED  => 'fly-status fly-status--cancelled',
        \App\Models\Booking::STATUS_CHECKED_IN => 'fly-status fly-status--stay',
        \App\Models\Booking::STATUS_CHECKED_OUT => 'fly-status fly-status--stay',
    ];
    $hotelOpen = $hotel && (int) $hotel->status === \App\Models\Hotel::STATUS_OPEN;
    $detailUrl = $hotelOpen ? '/fly/hotels/' . (int) $hotel->id . '?check_in=' . $booking->check_in . '&check_out=' . $booking->check_out : null;
@endphp

@section('title', '订单 ' . $booking->order_no . ' — 云途旅行')

@section('content')
    <div class="fly-container">
        <nav class="fly-crumb" aria-label="面包屑">
            <a href="{{ route('fly.home') }}">首页</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('fly.orders') }}">我的订单</a>
            <span aria-hidden="true">/</span>
            <span class="text-gray-600">{{ $booking->order_no }}</span>
        </nav>
    </div>

    <section class="fly-container pb-10">
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_21rem] lg:items-start">
            <div class="fly-card">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="text-xl font-bold tracking-tight text-gray-900">{{ $booking->order_no }}</h1>
                        <p class="mt-1 text-xs text-gray-400">
                            下单时间 {{ $booking->booked_at_text }}
                        </p>
                    </div>
                    <span class="{{ isset($statusClass[$booking->status]) ? $statusClass[$booking->status] : 'fly-status' }}">
                        {{ $booking->status_label }}
                    </span>
                </div>

                <dl class="mt-5">
                    <div class="fly-sum-row">
                        <dt>酒店</dt>
                        <dd>
                            @if ($detailUrl)
                                <a href="{{ $detailUrl }}" class="font-medium text-apple-blue hover:underline">{{ $hotel->name }}</a>
                            @else
                                {{ $hotel ? $hotel->name : '该酒店已下架' }}
                            @endif
                        </dd>
                    </div>
                    <div class="fly-sum-row"><dt>房型</dt><dd>{{ $roomType ? $roomType->name : '—' }}</dd></div>
                    <div class="fly-sum-row">
                        <dt>入住 / 离店</dt>
                        <dd>{{ $booking->check_in }} → {{ $booking->check_out }}</dd>
                    </div>
                    <div class="fly-sum-row"><dt>晚数</dt><dd>{{ $booking->nights }} 晚</dd></div>
                    <div class="fly-sum-row"><dt>间数</dt><dd>{{ $booking->room_count }} 间</dd></div>
                    <div class="fly-sum-row"><dt>单价</dt><dd>¥{{ $booking->unit_price_text }} /晚</dd></div>
                    <div class="fly-sum-row">
                        <dt>合计</dt>
                        <dd class="text-base font-semibold text-gray-900">¥{{ $booking->amount_text }}</dd>
                    </div>
                    <div class="fly-sum-row"><dt>入住人</dt><dd>{{ $booking->guest_name }}</dd></div>
                    <div class="fly-sum-row"><dt>手机号</dt><dd>{{ $booking->guest_phone }}</dd></div>
                    <div class="fly-sum-row">
                        <dt>房间号</dt>
                        <dd>{{ empty($roomNos) ? '到店由前台分配' : implode('、', $roomNos) }}</dd>
                    </div>
                    @if ($booking->remark)
                        <div class="fly-sum-row"><dt>备注</dt><dd>{{ $booking->remark }}</dd></div>
                    @endif
                    @if ($booking->cancelled_at)
                        <div class="fly-sum-row"><dt>取消时间</dt><dd>{{ $booking->cancelled_at_text }}</dd></div>
                    @endif
                </dl>
            </div>

            <aside class="fly-card">
                <h2 class="fly-section-title text-base">接下来</h2>
                @if ($booking->status === \App\Models\Booking::STATUS_BOOKED)
                    <p class="mt-3 text-sm leading-relaxed text-gray-500">
                        到店报手机号或订单号即可办理入住。
                    </p>
                @else
                    <p class="mt-3 text-sm leading-relaxed text-gray-500">
                        本单已取消，占用的房量已释放。如需入住可重新搜索预订。
                    </p>
                @endif

                <div class="mt-5 flex flex-col gap-3">
                    <a href="{{ route('fly.orders') }}" class="fly-btn-primary justify-center">查我的其他订单</a>
                    @if ($detailUrl)
                        <a href="{{ $detailUrl }}" class="fly-btn-outline justify-center">再看看这家酒店</a>
                    @endif
                    <a href="{{ route('fly.home') }}" class="fly-filter-btn justify-center">返回首页</a>
                </div>

                @if ($booking->status === \App\Models\Booking::STATUS_BOOKED)
                    <form method="POST" action="{{ route('fly.orders.cancel', $booking) }}"
                          class="mt-6 border-t border-gray-100 pt-5">
                        @csrf
                        {{-- phone 是归属凭证，必须随表单一起提交：只靠 URL 上的 phone 挡不住直接 POST --}}
                        <input type="hidden" name="phone" value="{{ $booking->guest_phone }}">
                        <p class="text-xs leading-relaxed text-gray-400">
                            取消后本单占用的 {{ $booking->room_count }} 间房量立即释放，且不可恢复。
                        </p>
                        <button type="submit" class="fly-btn-outline mt-3 w-full justify-center text-red-600 hover:border-red-300">
                            取消订单
                        </button>
                    </form>
                @else
                    <p class="mt-6 border-t border-gray-100 pt-5 text-xs text-gray-400">
                        本单状态为「{{ $booking->status_label }}」，无需再操作。
                    </p>
                @endif

                <p class="mt-4 text-xs leading-relaxed text-gray-400">
                    请收藏本页链接，或记住订单号 + 手机号 —— 这是查询本单的唯一凭证。
                </p>
            </aside>
        </div>
    </section>
@endsection
