@extends('admin.layout')

@section('title', '房量计划')

@section('content')
    <a href="{{ route('hotels.room-types.index', $hotel) }}" class="mb-4 inline-flex items-center gap-1 text-sm text-gray-500 transition hover:text-gray-900">
        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        返回房型列表
    </a>

    <div class="admin-page-header">
        <div>
            <h1 class="admin-title">房量计划</h1>
            <p class="admin-subtitle">{{ $hotel->name }} · {{ $roomType->name }}</p>
        </div>
    </div>

    <div class="admin-tabs">
        <a href="{{ route('hotels.room-types.index', $hotel) }}" class="admin-tab">房型管理</a>
        <a href="{{ route('hotels.rooms.index', $hotel) }}" class="admin-tab">房间管理</a>
        <a href="{{ route('hotels.edit', $hotel) }}" class="admin-tab">酒店设置</a>
    </div>

    <p class="mb-4 rounded-lg bg-blue-50 px-4 py-3 text-sm leading-6 text-blue-800">
        本房型可售房间共 <span class="font-semibold tabular-nums">{{ $sellable }}</span> 间。
        设置停售后，云途旅行对应日期的「剩 N 间」立即按
        <span class="font-semibold">可订 = 房间数 − 已订 − 停售</span> 扣减；同一晚再次提交会覆盖原设置。
    </p>

    <div class="admin-panel mb-6 p-6">
        <h2 class="mb-4 text-base font-semibold text-gray-900">新增停售计划</h2>
        <form method="POST" action="{{ route('hotels.room-types.inventory.store', [$hotel, $roomType]) }}" class="space-y-4">
            @csrf

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label for="start_date" class="admin-label">开始日期 *</label>
                    <input id="start_date" name="start_date" type="date" class="admin-input"
                           value="{{ old('start_date', $formStart) }}" required>
                    @error('start_date') <p class="admin-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="end_date" class="admin-label">结束日期（含当晚） *</label>
                    <input id="end_date" name="end_date" type="date" class="admin-input"
                           value="{{ old('end_date', $formEnd) }}" required>
                    @error('end_date') <p class="admin-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="closed_rooms" class="admin-label">每晚停售间数 *</label>
                    <input id="closed_rooms" name="closed_rooms" type="number" min="1" max="255" class="admin-input"
                           value="{{ old('closed_rooms', 1) }}" required>
                    @error('closed_rooms') <p class="admin-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="remark" class="admin-label">原因备注</label>
                <input id="remark" name="remark" type="text" class="admin-input" placeholder="如：维修、团房保留"
                       value="{{ old('remark') }}">
                @error('remark') <p class="admin-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-3 pt-1">
                <button type="submit" class="admin-btn-primary">设置停售</button>
            </div>
        </form>
    </div>

    <div class="admin-panel overflow-hidden">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
            <h2 class="text-base font-semibold text-gray-900">每晚房量</h2>
            <div class="flex items-center gap-2">
                @if ($prev)
                    <a href="{{ route('hotels.room-types.inventory', [$hotel, $roomType, 'start' => $prev]) }}" class="admin-btn-ghost !px-3 !py-1.5">上一窗口</a>
                @endif
                <a href="{{ route('hotels.room-types.inventory', [$hotel, $roomType, 'start' => $next]) }}" class="admin-btn-ghost !px-3 !py-1.5">下一窗口</a>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>日期</th>
                        <th>已订间数</th>
                        <th>停售间数</th>
                        <th>可订间数</th>
                        <th>备注</th>
                        <th class="text-right">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($nights as $row)
                        <tr @class(['bg-blue-50/60' => $row['is_today']])>
                            <td class="whitespace-nowrap font-medium text-gray-900">
                                {{ $row['date'] }}
                                <span class="ml-1 text-xs font-normal text-gray-400">{{ $row['weekday'] }}{{ $row['is_today'] ? ' · 今天' : '' }}</span>
                            </td>
                            <td class="tabular-nums">{{ $row['occupied'] > 0 ? $row['occupied'] . ' 间' : '—' }}</td>
                            <td>
                                @if ($row['closed'] > 0)
                                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 tabular-nums">
                                        {{ $row['closed'] }} 间
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                <span @class([
                                    'font-semibold tabular-nums',
                                    'text-red-600' => $row['remaining'] <= 0,
                                    'text-gray-900' => $row['remaining'] > 0,
                                ])>{{ $row['remaining'] }}</span>
                                <span class="text-xs text-gray-400">间</span>
                            </td>
                            <td class="max-w-[16rem] truncate text-gray-500">{{ $row['block'] && $row['block']->remark ? $row['block']->remark : '—' }}</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($row['block'])
                                    <form method="POST" action="{{ route('hotels.room-types.inventory.destroy', [$hotel, $roomType, $row['block']]) }}"
                                          onsubmit="return confirm('恢复 {{ $row['date'] }} 的停售间数？');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="admin-action admin-action--danger">恢复</button>
                                    </form>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
