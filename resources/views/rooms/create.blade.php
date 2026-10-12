@extends('admin.layout')

@section('title', '新增房间')

@section('content')
    <div class="admin-page-header">
        <div>
            <h1 class="admin-title">新增房间</h1>
            <p class="admin-subtitle">{{ $hotel->name }}</p>
        </div>
    </div>

    <div class="admin-panel max-w-3xl p-8">
        <form method="POST" action="{{ route('hotels.rooms.store', $hotel) }}">
            @include('rooms._form')
        </form>
    </div>
@endsection
