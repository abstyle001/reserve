@extends('admin.layout')

@section('title', '编辑房型')

@section('content')
    <div class="admin-page-header">
        <div>
            <h1 class="admin-title">编辑房型</h1>
            <p class="admin-subtitle">{{ $hotel->name }} · {{ $roomType->name }}</p>
        </div>
    </div>

    <div class="admin-panel max-w-3xl p-8">
        <form method="POST" action="{{ route('hotels.room-types.update', [$hotel, $roomType]) }}">
            @method('PUT')
            @include('room-types._form')
        </form>
    </div>
@endsection
