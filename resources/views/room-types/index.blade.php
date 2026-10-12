@extends('admin.layout')

@section('title', '房型管理')

@section('content')
    <a href="{{ route('hotels.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-gray-500 transition hover:text-gray-900">
        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        返回酒店列表
    </a>

    <div class="admin-page-header">
        <div>
            <h1 class="admin-title">房型管理</h1>
            <p class="admin-subtitle">{{ $hotel->name }}</p>
        </div>
        <a href="{{ route('hotels.room-types.create', $hotel) }}" class="admin-btn-primary">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            新增房型
        </a>
    </div>

    <div class="admin-tabs">
        <a href="{{ route('hotels.room-types.index', $hotel) }}" class="admin-tab admin-tab--active">房型管理</a>
        <a href="{{ route('hotels.rooms.index', $hotel) }}" class="admin-tab">房间管理</a>
        <a href="{{ route('hotels.edit', $hotel) }}" class="admin-tab">酒店设置</a>
    </div>

    <div class="admin-panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>房型名称</th>
                        <th>门市价</th>
                        <th>可住人数</th>
                        <th>床型</th>
                        <th>面积</th>
                        <th>房间数</th>
                        <th class="text-right">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($roomTypes as $roomType)
                        <tr>
                            <td class="font-medium text-gray-900">{{ $roomType->name }}</td>
                            <td class="tabular-nums">¥{{ $roomType->price }}</td>
                            <td class="tabular-nums">{{ $roomType->capacity }} 人</td>
                            <td>{{ $roomType->bed_type ?: '—' }}</td>
                            <td class="tabular-nums">{{ $roomType->area ? $roomType->area . ' ㎡' : '—' }}</td>
                            <td class="tabular-nums">{{ $roomType->rooms_count }}</td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-4">
                                    <a href="{{ route('hotels.room-types.edit', [$hotel, $roomType]) }}" class="admin-action">编辑</a>
                                    <form method="POST" action="{{ route('hotels.room-types.destroy', [$hotel, $roomType]) }}"
                                          onsubmit="return confirm('确定删除房型「{{ $roomType->name }}」吗？');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="admin-action admin-action--danger">删除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="admin-empty">还没有房型，点击右上角「新增房型」创建</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('admin.partials.pagination', ['paginator' => $roomTypes])
@endsection
