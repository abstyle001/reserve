{{-- 全站 429（限流命中）兜底页：默认 Symfony 页是英文，C 端顾客看不懂。
     独立极简页面，不套 fly/layout —— 429 也可能落在 /login、大屏等后台路径上。 --}}
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>操作太频繁了 — 云途旅行</title>
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
</head>
<body class="fly-body">
    <div class="fly-container flex min-h-screen flex-col items-center justify-center gap-4 py-16 text-center">
        <p class="text-6xl font-bold text-gray-300 tabular-nums">429</p>
        <h1 class="text-xl font-semibold text-gray-800">操作太频繁了，请稍后再试</h1>
        <p class="text-sm text-gray-500">为了保护您的订单安全，连续操作次数过多时需要稍作等待（约 1 分钟）。</p>
        <a href="{{ route('fly.home') }}" class="fly-btn-primary mt-2">返回首页</a>
    </div>
</body>
</html>
