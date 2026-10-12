@if (session('success'))
    <div class="admin-flash admin-flash--success">{{ session('success') }}</div>
@endif

@if (session('error'))
    <div class="admin-flash admin-flash--error">{{ session('error') }}</div>
@endif
