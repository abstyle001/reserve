@extends('admin.layout')

@section('title', '编辑酒店')

@section('content')
    <div class="admin-page-header">
        <div>
            <h1 class="admin-title">编辑酒店</h1>
            <p class="admin-subtitle">{{ $hotel->name }}</p>
        </div>
    </div>

    <div class="admin-panel max-w-3xl p-8">
        <form method="POST" action="{{ route('hotels.update', $hotel) }}" enctype="multipart/form-data">
            @method('PUT')
            @include('hotels._form')
        </form>
    </div>
@endsection
