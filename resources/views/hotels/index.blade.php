@extends('admin.layout')

@section('title', '酒店列表')

@section('content')
    <div class="admin-page-header">
        <div>
            <h1 class="admin-title">酒店列表</h1>
            <p class="admin-subtitle">管理酒店基础信息，房型与房间在酒店维度下维护</p>
        </div>
        <a href="{{ route('hotels.create') }}" class="admin-btn-primary">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            新增酒店
        </a>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="admin-stat">
            <div>
                <p class="admin-stat__value">{{ $stats['hotels'] }}</p>
                <p class="admin-stat__label">酒店</p>
            </div>
        </div>
        <div class="admin-stat">
            <div>
                <p class="admin-stat__value">{{ $stats['room_types'] }}</p>
                <p class="admin-stat__label">房型</p>
            </div>
        </div>
        <div class="admin-stat">
            <div>
                <p class="admin-stat__value">{{ $stats['rooms'] }}</p>
                <p class="admin-stat__label">房间</p>
            </div>
        </div>
    </div>

    <div class="admin-panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>名称</th>
                        <th>星级</th>
                        <th>电话</th>
                        <th>地址</th>
                        <th>状态</th>
                        <th>房型数</th>
                        <th>房间数</th>
                        <th class="text-right">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($hotels as $hotel)
                        <tr>
                            <td class="font-medium text-gray-900">{{ $hotel->name }}</td>
                            <td class="whitespace-nowrap text-amber-500">{{ $hotel->star_level ? str_repeat('★', $hotel->star_level) : '—' }}</td>
                            <td>{{ $hotel->phone ?: '—' }}</td>
                            <td>{{ $hotel->address ?: '—' }}</td>
                            <td>
                                @if ($hotel->status === \App\Models\Hotel::STATUS_OPEN)
                                    <span class="badge badge--sm badge--green">营业</span>
                                @else
                                    <span class="badge badge--sm badge--gray">停用</span>
                                @endif
                            </td>
                            <td class="tabular-nums">{{ $hotel->room_types_count }}</td>
                            <td class="tabular-nums">{{ $hotel->rooms_count }}</td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-4">
                                    <a href="{{ route('hotels.room-types.index', $hotel) }}" class="admin-action">房型</a>
                                    <a href="{{ route('hotels.rooms.index', $hotel) }}" class="admin-action">房间</a>
                                    <a href="{{ route('hotels.edit', $hotel) }}" class="admin-action">编辑</a>
                                    <form method="POST" action="{{ route('hotels.destroy', $hotel) }}"
                                          onsubmit="return confirm('确定删除酒店「{{ $hotel->name }}」吗？');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="admin-action admin-action--danger">删除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="admin-empty">还没有酒店，点击右上角「新增酒店」创建</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('admin.partials.pagination', ['paginator' => $hotels])
@endsection
