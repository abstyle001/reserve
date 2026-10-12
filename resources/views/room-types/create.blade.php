@extends('admin.layout')

@section('title', '新增房型')

@section('content')
    <div class="admin-page-header">
        <div>
            <h1 class="admin-title">新增房型</h1>
            <p class="admin-subtitle">{{ $hotel->name }}</p>
        </div>
    </div>

    <div class="admin-panel max-w-3xl p-8">
        <form method="POST" action="{{ route('hotels.room-types.store', $hotel) }}">
            @include('room-types._form')
        </form>
    </div>
@endsection
