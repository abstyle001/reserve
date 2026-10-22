{{--
    下单页（/fly/hotels/{hotel}/book）—— 零 JS 也要能走完闭环。

    三个刻意的取舍：
      1. 「合计」不做独立数字：没有 JS 时 <select> 改了它就成了假数，所以把每个间数
         对应的真实总价直接写进 option 文案（¥880 × 2 晚 × 3 间 一眼可核）。
         P8 接了 Alpine 也不在这里实时汇总：金额与晚数最终由
         FlyBookingController 在服务端重算才落库，前端再算一份只会多一个「看起来对但可能不一致」的口径。
      2. 入住/离店是可编辑的 POST 字段而非 hidden：改日期等于改房量口径，
         藏在 hidden 里会让用户以为在详情页改过日期就生效了。
      3. JS 在这里唯一的作用是把提交按钮锁住（$store.fly.lockSubmit），
         不做任何前端校验；重复提交的最终兜底是 idempotency_key 唯一索引。
--}}
@extends('fly.layout')

@php
    $search = app(\App\Services\Fly\HotelSearchService::class);

    // 校验失败回表单时，展示口径跟着用户填的值走，而不是 URL 上的旧日期
    $checkIn  = (string) old('check_in', $filters['check_in']);
    $checkOut = (string) old('check_out', $filters['check_out']);
    $nights   = \App\Models\Booking::nightsBetween($checkIn, $checkOut);

    $canBook     = $selected !== null && !$selected['sold_out'];
    $countShown  = (int) old('room_count', 1);
    $countMax    = $selected ? min((int) $selected['remaining'], $maxRooms) : 0;
@endphp

@section('title', '填写订单 · ' . $card['name'] . ' — 云途旅行')

@section('content')
    <div class="fly-container">
        <nav class="fly-crumb" aria-label="面包屑">
            <a href="{{ route('fly.home') }}">首页</a>
            <span aria-hidden="true">/</span>
            <a href="{{ $card['detail_url'] }}">{{ $card['name'] }}</a>
            <span aria-hidden="true">/</span>
            <span class="text-gray-600">填写订单</span>
        </nav>
    </div>

    <section class="fly-container pb-10">
        @if ($selected === null)
            @include('fly.partials.empty-state', [
                'emptyTitle' => '这家酒店还没有房型',
                'emptySub' => '后台「酒店 → 房型 → 房间」里补齐后，前台即可查询房量并预订。',
            ])
        @else
            <form action="{{ route('fly.bookings.store') }}" method="POST"
                  @submit="$store.fly.lockSubmit($el)"
                  class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_21rem] lg:items-start">
                @csrf
                <input type="hidden" name="hotel_id" value="{{ $hotel->id }}">
                <input type="hidden" name="room_type_id" value="{{ $selected['id'] }}">
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                <div>
                    @if ($errors->has('submit'))
                        <div class="fly-flash fly-flash--err">
                            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>
                            </svg>
                            <span>{{ $errors->first('submit') }}</span>
                        </div>
                    @endif

                    {{-- 房型 --}}
                    <div class="fly-card">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-xs text-gray-400">{{ $card['name'] }}</p>
                                <h2 class="fly-hotel-name mt-1 text-lg">{{ $selected['name'] }}</h2>
                                <p class="mt-1 text-xs text-gray-400">
                                    {{ $selected['bed_type'] ?: '—' }} · {{ $selected['capacity'] }} 人 ·
                                    {{ $selected['area'] }} ㎡ · 可售 {{ $selected['rooms_total'] }} 间
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold text-gray-900 tabular-nums">
                                    ¥{{ $selected['price_text'] }}<span class="text-xs font-normal text-gray-400"> /晚</span>
                                </p>
                                @if ($canBook)
                                    <p class="fly-room-row__remain mt-1 text-green-600">当前日期剩 {{ $selected['remaining'] }} 间</p>
                                @else
                                    <p class="fly-room-row__remain mt-1 text-orange-500">已售罄</p>
                                @endif
                            </div>
                        </div>

                        @if (!$canBook)
                            <div class="fly-flash fly-flash--err mt-4 mb-0">
                                <span>该房型在当前日期已订满，换个日期或选下面的其他房型即可。</span>
                            </div>
                        @endif
                    </div>

                    {{-- 入住信息 --}}
                    <div class="fly-card mt-5">
                        <h3 class="fly-section-title text-base">入住信息</h3>

                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="fly-label" for="b-check-in">入住日期</label>
                                <input id="b-check-in" class="fly-input{{ $errors->has('check_in') ? ' fly-input--error' : '' }}"
                                       type="date" name="check_in" value="{{ $checkIn }}" min="{{ $filters['today'] }}">
                                @error('check_in')<p class="fly-field-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="fly-label" for="b-check-out">离店日期</label>
                                <input id="b-check-out" class="fly-input{{ $errors->has('check_out') ? ' fly-input--error' : '' }}"
                                       type="date" name="check_out" value="{{ $checkOut }}" min="{{ $checkIn }}">
                                @error('check_out')<p class="fly-field-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="fly-label" for="b-name">入住人姓名</label>
                                <input id="b-name" class="fly-input{{ $errors->has('guest_name') ? ' fly-input--error' : '' }}"
                                       type="text" name="guest_name" value="{{ old('guest_name') }}"
                                       maxlength="30" autocomplete="name" placeholder="与证件一致">
                                @error('guest_name')<p class="fly-field-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="fly-label" for="b-phone">手机号</label>
                                <input id="b-phone" class="fly-input{{ $errors->has('guest_phone') ? ' fly-input--error' : '' }}"
                                       type="text" name="guest_phone" value="{{ old('guest_phone') }}"
                                       inputmode="numeric" maxlength="11" autocomplete="tel" placeholder="用于查单，请牢记">
                                @error('guest_phone')<p class="fly-field-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="fly-label" for="b-count">预订间数</label>
                                @if ($canBook)
                                    <select id="b-count" class="fly-input{{ $errors->has('room_count') ? ' fly-input--error' : '' }}" name="room_count">
                                        @for ($n = 1; $n <= $countMax; $n++)
                                            {{-- @selected 是 Laravel 9 才有的指令，8.x 会原样输出 --}}
                                            <option value="{{ $n }}"{{ $countShown === $n ? ' selected' : '' }}>
                                                {{ $n }} 间 · {{ $nights }} 晚 · 合计 ¥{{ number_format($selected['price'] * $nights * $n, 0, '.', '') }}
                                            </option>
                                        @endfor
                                    </select>
                                @else
                                    <input class="fly-input bg-gray-50 text-gray-400" type="text" value="暂不可订" disabled>
                                @endif
                                @error('room_count')<p class="fly-field-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="fly-label" for="b-remark">备注</label>
                                <textarea id="b-remark" class="fly-input{{ $errors->has('remark') ? ' fly-input--error' : '' }}"
                                          name="remark" rows="2" maxlength="200" placeholder="高楼层、加床等（选填）">{{ old('remark') }}</textarea>
                                @error('remark')<p class="fly-field-error">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <p class="mt-4 text-xs leading-relaxed text-gray-400">
                            手机号与订单号是您查询、取消订单的唯一凭证（本站订单不绑定登录账号），请填写本人常用号码。
                        </p>
                    </div>

                    {{-- 同酒店其他房型：点进去沿用同一日期窗口 --}}
                    @if (count($roomTypes) > 1)
                        <div class="fly-card mt-5">
                            <h3 class="fly-section-title text-base">该日期其他房型</h3>
                            <div class="mt-2">
                                @foreach ($roomTypes as $type)
                                    @if ((int) $type['id'] !== (int) $selected['id'])
                                        <a href="{{ $type['book_url'] }}" class="fly-room-row">
                                            <div class="min-w-0 flex-1">
                                                <h4 class="fly-hotel-name">{{ $type['name'] }}</h4>
                                                <p class="mt-1 text-xs text-gray-400">
                                                    {{ $type['bed_type'] ?: '—' }} · {{ $type['capacity'] }} 人
                                                </p>
                                            </div>
                                            <div class="shrink-0 text-right">
                                                <p class="fly-room-row__remain {{ $type['sold_out'] ? 'text-orange-500' : 'text-green-600' }}">
                                                    {{ $type['sold_out'] ? '已售罄' : '剩 ' . $type['remaining'] . ' 间' }}
                                                </p>
                                                <p class="mt-1 text-sm font-semibold text-gray-900 tabular-nums">¥{{ $type['price_text'] }}</p>
                                            </div>
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- 右栏：订单摘要 + 提交 --}}
                <aside class="fly-card lg:sticky lg:top-6">
                    <h3 class="fly-section-title text-base">订单摘要</h3>
                    <dl class="mt-3">
                        <div class="fly-sum-row"><dt>酒店</dt><dd>{{ $card['name'] }}</dd></div>
                        <div class="fly-sum-row"><dt>房型</dt><dd>{{ $selected['name'] }}</dd></div>
                        <div class="fly-sum-row"><dt>入住 / 离店</dt><dd>{{ $checkIn }} → {{ $checkOut }}</dd></div>
                        <div class="fly-sum-row"><dt>晚数</dt><dd>{{ $nights }} 晚</dd></div>
                        <div class="fly-sum-row"><dt>单价</dt><dd>¥{{ $selected['price_text'] }} /晚</dd></div>
                        <div class="fly-sum-row"><dt>间数</dt><dd>{{ $countShown }} 间</dd></div>
                        <div class="fly-sum-row">
                            <dt>合计</dt>
                            <dd class="text-base font-semibold text-gray-900">
                                ¥{{ number_format($selected['price'] * $nights * max(1, $countShown), 0, '.', '') }}
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-xs leading-relaxed text-gray-400">
                        合计 = 单价 × 晚数 × 间数。停用 JavaScript 时本页不会重算，
                        改完间数请以「预订间数」下拉里标注的金额为准，提交后订单页可核对。
                    </p>

                    @if ($canBook)
                        <button type="submit" class="fly-btn-primary mt-5 w-full justify-center">提交预订</button>
                    @else
                        <span class="fly-btn-outline mt-5 w-full cursor-default justify-center opacity-60">暂不可订</span>
                    @endif

                    <a href="{{ $card['detail_url'] }}" class="mt-3 block text-center text-xs text-gray-400 transition hover:text-apple-blue">
                        返回酒店详情
                    </a>
                </aside>
            </form>
        @endif
    </section>
@endsection
