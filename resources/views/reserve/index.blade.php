{{--
    预约取号 / 放号大屏控制台（单页）

    技术约定：
      - 不复用 layouts/app.blade.php（那个带 Breeze 鉴权导航），这里是一套独立的全屏文档
      - $store.reserve 是界面上一切数值的唯一来源，partials 只读不写
      - 资源一律走 mix() 辅助函数，路径由 public/mix-manifest.json 决定
--}}
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>取号 / 放号大屏</title>
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
    <script src="{{ mix('js/reserve/app.js') }}" defer></script>
</head>
{{--
    队列可选项由后端注入（JSON 编码必须转义引号与尖括号，
    否则中文引号 / 单引号会破坏 HTML 属性）。
--}}
<body
    class="font-sans antialiased overflow-hidden"
    data-reserve-config="{{ json_encode(['queueOptions' => $queueOptions], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) }}"
>
    <div x-data x-cloak class="screen flex h-screen w-screen flex-col">

        @include('reserve.partials.topbar')

        <main class="grid min-h-0 flex-1 grid-cols-1 gap-10 px-14 pb-10 pt-7 lg:grid-cols-[1.35fr_1fr] lg:px-20 3xl:px-28">

            <div class="flex min-h-0 flex-col gap-10">
                @include('reserve.partials.ticket')
                @include('reserve.partials.actions')
            </div>

            <div class="flex min-h-0 flex-col gap-10">
                @include('reserve.partials.stats')
                @include('reserve.partials.records')
            </div>

        </main>

        @include('reserve.partials.toast')
    </div>
</body>
</html>
