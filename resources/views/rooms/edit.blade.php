@extends('admin.layout')

@section('title', '编辑房间')

@section('content')
    <div class="admin-page-header">
        <div>
            <h1 class="admin-title">编辑房间</h1>
            <p class="admin-subtitle">{{ $hotel->name }} · {{ $room->room_no }}</p>
        </div>
    </div>

    <div class="admin-panel max-w-3xl p-8">
        <form method="POST" action="{{ route('hotels.rooms.update', [$hotel, $room]) }}">
            @method('PUT')
            @include('rooms._form')
        </form>
    </div>
@endsection
