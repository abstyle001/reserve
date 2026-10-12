@php
    $statusBadge = [
        \App\Models\Room::STATUS_VACANT   => 'badge--green',
        \App\Models\Room::STATUS_OCCUPIED => 'badge--blue',
        \App\Models\Room::STATUS_CLEANING => 'badge--amber',
        \App\Models\Room::STATUS_DISABLED => 'badge--gray',
    ];
@endphp

@extends('admin.layout')

@section('title', '房间管理')

@section('content')
    <a href="{{ route('hotels.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-gray-500 transition hover:text-gray-900">
        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        返回酒店列表
    </a>

    <div class="admin-page-header">
        <div>
            <h1 class="admin-title">房间管理</h1>
            <p class="admin-subtitle">{{ $hotel->name }}</p>
        </div>
        <a href="{{ route('hotels.rooms.create', $hotel) }}" class="admin-btn-primary">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            新增房间
        </a>
    </div>

    <div class="admin-tabs">
        <a href="{{ route('hotels.room-types.index', $hotel) }}" class="admin-tab">房型管理</a>
        <a href="{{ route('hotels.rooms.index', $hotel) }}" class="admin-tab admin-tab--active">房间管理</a>
        <a href="{{ route('hotels.edit', $hotel) }}" class="admin-tab">酒店设置</a>
    </div>

    <div class="admin-panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>房号</th>
                        <th>楼层</th>
                        <th>房型</th>
                        <th>状态</th>
                        <th>备注</th>
                        <th class="text-right">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rooms as $room)
                        <tr>
                            <td class="font-medium tabular-nums text-gray-900">{{ $room->room_no }}</td>
                            <td class="tabular-nums">{{ $room->floor !== null ? $room->floor . ' 层' : '—' }}</td>
                            <td>{{ $room->roomType ? $room->roomType->name : '—' }}</td>
                            <td>
                                <span class="badge badge--sm {{ isset($statusBadge[$room->status]) ? $statusBadge[$room->status] : 'badge--gray' }}">
                                    {{ $room->status_label }}
                                </span>
                            </td>
                            <td>{{ $room->remark ?: '—' }}</td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-4">
                                    <a href="{{ route('hotels.rooms.edit', [$hotel, $room]) }}" class="admin-action">编辑</a>
                                    <form method="POST" action="{{ route('hotels.rooms.destroy', [$hotel, $room]) }}"
                                          onsubmit="return confirm('确定删除房间「{{ $room->room_no }}」吗？');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="admin-action admin-action--danger">删除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="admin-empty">还没有房间，点击右上角「新增房间」创建</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('admin.partials.pagination', ['paginator' => $rooms])
@endsection
