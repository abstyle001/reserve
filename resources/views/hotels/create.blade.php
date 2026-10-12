@extends('admin.layout')

@section('title', '新增酒店')

@section('content')
    <div class="admin-page-header">
        <div>
            <h1 class="admin-title">新增酒店</h1>
            <p class="admin-subtitle">创建后可继续维护该酒店的房型与房间</p>
        </div>
    </div>

    <div class="admin-panel max-w-3xl p-8">
        <form method="POST" action="{{ route('hotels.store') }}">
            @include('hotels._form')
        </form>
    </div>
@endsection
