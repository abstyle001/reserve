{{--
    C 端旅行首页（/fly）静态原型页

    技术约定（与 reserve 大屏 / hotels 后台保持一致的分层思路）：
      - 独立全屏文档，不复用 layouts/app.blade.php（Breeze 鉴权导航不适用 C 端场景）
      - 全部为静态数据，后续接酒店域模型时把 $hotels / $cities 注入改为循环即可
      - 视觉走浅色消费级风格（fly-* 组件类），与深色大屏、浅灰后台明确区分
      - 图片位暂用 .fly-grad-* 渐变占位，接真实图片后替换为 background-image
      - 页面零 JS；tab 切换、日期选择等交互后续用 Alpine 补
--}}
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>云途旅行 — 酒店 · 机票 · 火车票一站式预订</title>
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
</head>
<body class="fly-body">

    {{-- ===================== 顶部导航 ===================== --}}
    <header class="fly-topbar">
        <div class="fly-container flex h-16 items-center justify-between gap-6">
            <div class="flex items-center gap-8">
                {{-- 品牌 --}}
                <a href="#" class="flex items-center gap-2.5">
                    <span class="fly-brand-mark" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m22 2-7 20-4-9-9-4Z"/>
                            <path d="M22 2 11 13"/>
                        </svg>
                    </span>
                    <span>
                        <span class="fly-brand-name">云途旅行</span>
                        <span class="fly-brand-slogan">让每一次出发都简单</span>
                    </span>
                </a>

                {{-- 主菜单（原型期全部为 # 占位） --}}
                <nav class="hidden items-center gap-1 md:flex">
                    <a href="#" class="fly-nav-link fly-nav-link--active">首页</a>
                    <a href="#" class="fly-nav-link">酒店</a>
                    <a href="#" class="fly-nav-link">机票</a>
                    <a href="#" class="fly-nav-link">火车票</a>
                    <a href="#" class="fly-nav-link">门票</a>
                    <a href="#" class="fly-nav-link">旅行攻略</a>
                </nav>
            </div>

            <div class="flex items-center gap-3">
                <a href="#" class="hidden items-center gap-1.5 text-sm text-gray-500 transition hover:text-apple-blue sm:flex">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92Z"/>
                    </svg>
                    95010
                </a>
                @auth
                    <a href="{{ route('dashboard') }}" class="fly-btn-outline">我的订单</a>
                    <span class="flex items-center gap-1.5 text-sm font-medium text-gray-700">
                        <svg class="h-4 w-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                        </svg>
                        {{ Auth::user()->name }}
                    </span>
                    {{-- 退出登录：Breeze 的 /logout 只接受 POST + CSRF --}}
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="fly-btn-primary">退出登录</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="fly-btn-outline">我的订单</a>
                    <a href="{{ route('login') }}" class="fly-btn-primary">登录 / 注册</a>
                @endauth
            </div>
        </div>
    </header>

    {{-- ===================== Hero：左侧业务分类 + 酒店预订面板 ===================== --}}
    <section class="fly-hero">
        <div class="fly-container pb-10 pt-8 lg:pt-12">
            <div class="fly-panel">

                {{-- 左侧旅行服务分类 --}}
                <aside class="fly-sidebar">
                    <p class="fly-side-title">旅行服务</p>

                    <a href="#" class="fly-side-item fly-side-item--active">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/>
                        </svg>
                        酒店
                    </a>
                    <a href="#" class="fly-side-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>
                        </svg>
                        机票
                    </a>
                    <a href="#" class="fly-side-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M8 3.1V7a4 4 0 0 0 8 0V3.1"/>
                            <path d="m9 15-1-1"/><path d="m15 15 1-1"/>
                            <path d="M9 17H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-4"/>
                            <path d="M8 17v3"/><path d="M16 17v3"/>
                        </svg>
                        火车票
                    </a>
                    <a href="#" class="fly-side-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>
                        </svg>
                        旅行
                    </a>
                    <a href="#" class="fly-side-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/>
                            <path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>
                        </svg>
                        门票
                    </a>
                    <a href="#" class="fly-side-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M8 6v6"/><path d="M15 6v6"/><path d="M2 12h19.6"/>
                            <path d="M18 18h3s.5-1.7.8-2.8c.1-.4.2-.8.2-1.2 0-.4-.1-.8-.2-1.2l-1.4-5C20.1 6.8 19.1 6 18 6H4a2 2 0 0 0-2 2v10h3"/>
                            <circle cx="7" cy="18" r="2"/><path d="M9 18h5"/><circle cx="16" cy="18" r="2"/>
                        </svg>
                        汽车票
                    </a>
                    <a href="#" class="fly-side-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="5" r="3"/>
                            <line x1="12" x2="12" y1="22" y2="8"/>
                            <path d="M5 12H2a10 10 0 0 0 20 0h-3"/>
                        </svg>
                        船票
                    </a>

                    {{-- 侧栏底部营销位 --}}
                    <div class="fly-side-coupon">
                        <p class="text-xs font-semibold text-gray-700">新客专享</p>
                        <p class="mt-1 font-bold text-orange-500">¥50<span class="ml-1 text-xs font-normal text-gray-400">酒店立减券</span></p>
                        <a href="#" class="mt-2 inline-block text-xs font-medium text-apple-blue transition hover:underline">立即领取 →</a>
                    </div>
                </aside>

                {{-- 中间：预订 tab + 表单 --}}
                <div class="fly-search">
                    <div class="fly-tabs" role="tablist">
                        <button type="button" class="fly-tab fly-tab--active" role="tab" aria-selected="true">酒店</button>
                        <button type="button" class="fly-tab" role="tab" aria-selected="false">机票</button>
                        <button type="button" class="fly-tab" role="tab" aria-selected="false">火车票</button>
                        <button type="button" class="fly-tab" role="tab" aria-selected="false">门票</button>
                        <button type="button" class="fly-tab" role="tab" aria-selected="false">汽车票</button>
                        <button type="button" class="fly-tab" role="tab" aria-selected="false">船票</button>
                    </div>

                    {{-- 酒店预订表单（静态，提交后续接搜索接口） --}}
                    <form class="fly-form" action="#" method="get" onsubmit="return false;">
                        {{-- 目的地城市 --}}
                        <div class="fly-field">
                            <svg class="fly-field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>
                                <circle cx="12" cy="10" r="3"/>
                            </svg>
                            <div class="min-w-0 flex-1">
                                <span class="fly-field__label">城市</span>
                                <input type="text" value="上海" aria-label="目的地城市">
                            </div>
                        </div>

                        {{-- 入住 / 离店日期 --}}
                        <div class="fly-field">
                            <svg class="fly-field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect width="18" height="18" x="3" y="4" rx="2"/>
                                <path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>
                            </svg>
                            <div class="flex flex-1 items-center">
                                <div class="flex-1">
                                    <span class="fly-field__label">入住</span>
                                    <span class="fly-field__value">10月12日 <span class="text-xs font-normal text-gray-400">周一</span></span>
                                </div>
                                <span class="fly-dates__divider" aria-hidden="true"></span>
                                <div class="flex-1">
                                    <span class="fly-field__label">离店</span>
                                    <span class="fly-field__value">10月13日 <span class="text-xs font-normal text-gray-400">周二</span></span>
                                </div>
                            </div>
                            <span class="fly-nights">1 晚</span>
                        </div>

                        {{-- 关键词 --}}
                        <div class="fly-field">
                            <svg class="fly-field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="11" cy="11" r="8"/>
                                <path d="m21 21-4.3-4.3"/>
                            </svg>
                            <input type="text" class="flex-1" placeholder="酒店名 / 地标 / 商圈" aria-label="关键词">
                        </div>

                        {{-- 搜索按钮 --}}
                        <button type="submit" class="fly-btn-search">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="11" cy="11" r="8"/>
                                <path d="m21 21-4.3-4.3"/>
                            </svg>
                            搜索酒店
                        </button>
                    </form>

                    {{-- 热门搜索 + 快捷筛选 --}}
                    <div class="fly-quickrow">
                        <span class="text-xs text-gray-400">热门搜索</span>
                        <a href="#" class="fly-chip fly-chip--hot">外滩江景房</a>
                        <a href="#" class="fly-chip">迪士尼周边</a>
                        <a href="#" class="fly-chip">温泉酒店</a>
                        <a href="#" class="fly-chip">亲子房</a>
                        <span class="ml-auto hidden items-center gap-2 lg:flex">
                            <span class="text-xs text-gray-400">筛选</span>
                            <a href="#" class="fly-filter-btn">价格 / 星级</a>
                            <a href="#" class="fly-filter-btn">品牌</a>
                        </span>
                    </div>
                </div>
            </div>

            {{-- 服务保障条 --}}
            <div class="fly-promise mt-6">
                <div class="fly-promise__item">
                    <span class="fly-promise__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1Z"/>
                            <path d="m9 12 2 2 4-4"/>
                        </svg>
                    </span>
                    低价保障 · 买贵赔
                </div>
                <div class="fly-promise__item">
                    <span class="fly-promise__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                            <path d="M3 3v5h5"/>
                        </svg>
                    </span>
                    随时退 · 免手续费
                </div>
                <div class="fly-promise__item">
                    <span class="fly-promise__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
                        </svg>
                    </span>
                    极速入住 · 免押金
                </div>
                <div class="fly-promise__item">
                    <span class="fly-promise__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5Zm18 0h-3a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-5Z"/>
                            <path d="M3 14v-3a9 9 0 0 1 18 0v3"/>
                        </svg>
                    </span>
                    7×24 小时客服
                </div>
            </div>
        </div>
    </section>

    {{-- ===================== 酒店推荐 ===================== --}}
    <section class="fly-container fly-section">
        <div class="fly-section-head">
            <div>
                <h2 class="fly-section-title">酒店推荐</h2>
                <p class="fly-section-sub">编辑精选 · 全网低价 · 真实住客点评</p>
            </div>
            <a href="#" class="fly-more">
                查看更多
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m9 18 6-6-6-6"/>
                </svg>
            </a>
        </div>

        <div class="fly-hotel-grid">
            {{-- 卡片 1 --}}
            <a href="#" class="group fly-hotel-card">
                <div class="fly-hotel-card__media fly-grad-1">
                    <span class="fly-hotel-card__badge">限时 5 折</span>
                    <div class="fly-hotel-card__price"><span>¥</span><b>1288</b><span>起</span></div>
                </div>
                <div class="fly-hotel-card__body">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="fly-hotel-name">上海外滩茂悦大酒店</h3>
                        <span class="fly-stars" aria-label="五星级">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        </span>
                    </div>
                    <p class="fly-hotel-loc">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
                        </svg>
                        黄浦区 · 外滩商圈 · 距外滩 320m
                    </p>
                    <div class="fly-hotel-tags">
                        <span class="fly-tag fly-tag--blue">含双早</span>
                        <span class="fly-tag fly-tag--green">免费取消</span>
                        <span class="fly-tag">地铁 2 号线</span>
                    </div>
                    <div class="fly-hotel-foot">
                        <div>
                            <span class="fly-score">4.9</span>
                            <span class="fly-score-txt">超赞 · 2.1w 条点评</span>
                        </div>
                        <span class="fly-price">¥1288<small> 起</small></span>
                    </div>
                </div>
            </a>

            {{-- 卡片 2 --}}
            <a href="#" class="group fly-hotel-card">
                <div class="fly-hotel-card__media fly-grad-2">
                    <span class="fly-hotel-card__badge">爆款热卖</span>
                    <div class="fly-hotel-card__price"><span>¥</span><b>980</b><span>起</span></div>
                </div>
                <div class="fly-hotel-card__body">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="fly-hotel-name">杭州西湖国宾馆</h3>
                        <span class="fly-stars" aria-label="五星级">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        </span>
                    </div>
                    <p class="fly-hotel-loc">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
                        </svg>
                        西湖区 · 西湖风景区 · 沿湖 200m
                    </p>
                    <div class="fly-hotel-tags">
                        <span class="fly-tag fly-tag--blue">园林景观</span>
                        <span class="fly-tag fly-tag--green">含早</span>
                        <span class="fly-tag">接机服务</span>
                    </div>
                    <div class="fly-hotel-foot">
                        <div>
                            <span class="fly-score">4.8</span>
                            <span class="fly-score-txt">棒 · 9863 条点评</span>
                        </div>
                        <span class="fly-price">¥980<small> 起</small></span>
                    </div>
                </div>
            </a>

            {{-- 卡片 3 --}}
            <a href="#" class="group fly-hotel-card">
                <div class="fly-hotel-card__media fly-grad-3">
                    <span class="fly-hotel-card__badge">近地铁</span>
                    <div class="fly-hotel-card__price"><span>¥</span><b>560</b><span>起</span></div>
                </div>
                <div class="fly-hotel-card__body">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="fly-hotel-name">成都太古里书香府邸</h3>
                        <span class="fly-stars" aria-label="四星级">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        </span>
                    </div>
                    <p class="fly-hotel-loc">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
                        </svg>
                        锦江区 · 春熙路商圈 · 地铁 2/3 号线
                    </p>
                    <div class="fly-hotel-tags">
                        <span class="fly-tag fly-tag--blue">亲子房</span>
                        <span class="fly-tag fly-tag--green">延迟退房</span>
                        <span class="fly-tag">近太古里</span>
                    </div>
                    <div class="fly-hotel-foot">
                        <div>
                            <span class="fly-score">4.7</span>
                            <span class="fly-score-txt">超赞 · 1.4w 条点评</span>
                        </div>
                        <span class="fly-price">¥560<small> 起</small></span>
                    </div>
                </div>
            </a>

            {{-- 卡片 4 --}}
            <a href="#" class="group fly-hotel-card">
                <div class="fly-hotel-card__media fly-grad-4">
                    <span class="fly-hotel-card__badge">海景必住</span>
                    <div class="fly-hotel-card__price"><span>¥</span><b>1580</b><span>起</span></div>
                </div>
                <div class="fly-hotel-card__body">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="fly-hotel-name">三亚亚龙湾美高梅度假酒店</h3>
                        <span class="fly-stars" aria-label="五星级">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        </span>
                    </div>
                    <p class="fly-hotel-loc">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
                        </svg>
                        吉阳区 · 亚龙湾国家旅游度假区
                    </p>
                    <div class="fly-hotel-tags">
                        <span class="fly-tag fly-tag--blue">私汤别墅</span>
                        <span class="fly-tag fly-tag--green">私人沙滩</span>
                        <span class="fly-tag">含双早</span>
                    </div>
                    <div class="fly-hotel-foot">
                        <div>
                            <span class="fly-score">4.9</span>
                            <span class="fly-score-txt">超赞 · 3.6w 条点评</span>
                        </div>
                        <span class="fly-price">¥1580<small> 起</small></span>
                    </div>
                </div>
            </a>

            {{-- 卡片 5 --}}
            <a href="#" class="group fly-hotel-card">
                <div class="fly-hotel-card__media fly-grad-5">
                    <span class="fly-hotel-card__badge">立减 ¥80</span>
                    <div class="fly-hotel-card__price"><span>¥</span><b>890</b><span>起</span></div>
                </div>
                <div class="fly-hotel-card__body">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="fly-hotel-name">北京王府井希尔顿酒店</h3>
                        <span class="fly-stars" aria-label="五星级">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        </span>
                    </div>
                    <p class="fly-hotel-loc">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
                        </svg>
                        东城区 · 王府井大街 · 近天安门
                    </p>
                    <div class="fly-hotel-tags">
                        <span class="fly-tag fly-tag--blue">行政酒廊</span>
                        <span class="fly-tag fly-tag--green">免费取消</span>
                        <span class="fly-tag">健身房</span>
                    </div>
                    <div class="fly-hotel-foot">
                        <div>
                            <span class="fly-score">4.6</span>
                            <span class="fly-score-txt">棒 · 1.8w 条点评</span>
                        </div>
                        <span class="fly-price">¥890<small> 起</small></span>
                    </div>
                </div>
            </a>

            {{-- 卡片 6 --}}
            <a href="#" class="group fly-hotel-card">
                <div class="fly-hotel-card__media fly-grad-6">
                    <span class="fly-hotel-card__badge">人气民宿</span>
                    <div class="fly-hotel-card__price"><span>¥</span><b>368</b><span>起</span></div>
                </div>
                <div class="fly-hotel-card__body">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="fly-hotel-name">厦门鼓浪屿颂海民宿</h3>
                        <span class="fly-stars" aria-label="精品民宿">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        </span>
                    </div>
                    <p class="fly-hotel-loc">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
                        </svg>
                        思明区 · 鼓浪屿 · 步行至三丘田码头
                    </p>
                    <div class="fly-hotel-tags">
                        <span class="fly-tag fly-tag--blue">海景露台</span>
                        <span class="fly-tag fly-tag--green">含早</span>
                        <span class="fly-tag">网红打卡</span>
                    </div>
                    <div class="fly-hotel-foot">
                        <div>
                            <span class="fly-score">4.8</span>
                            <span class="fly-score-txt">超赞 · 7621 条点评</span>
                        </div>
                        <span class="fly-price">¥368<small> 起</small></span>
                    </div>
                </div>
            </a>
        </div>
    </section>

    {{-- ===================== 热门目的地 ===================== --}}
    <section class="fly-container fly-section pt-0 lg:pt-0">
        <div class="fly-section-head">
            <div>
                <h2 class="fly-section-title">热门目的地</h2>
                <p class="fly-section-sub">不知道去哪？看看大家都在搜</p>
            </div>
            <a href="#" class="fly-more">
                更多城市
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m9 18 6-6-6-6"/>
                </svg>
            </a>
        </div>

        <div class="fly-city-grid">
            <a href="#" class="fly-city-card fly-grad-1">
                <span class="fly-city-card__name">上海</span>
                <span class="fly-city-card__sub">5.2 万家酒店</span>
            </a>
            <a href="#" class="fly-city-card fly-grad-2">
                <span class="fly-city-card__name">北京</span>
                <span class="fly-city-card__sub">6.8 万家酒店</span>
            </a>
            <a href="#" class="fly-city-card fly-grad-3">
                <span class="fly-city-card__name">杭州</span>
                <span class="fly-city-card__sub">3.4 万家酒店</span>
            </a>
            <a href="#" class="fly-city-card fly-grad-4">
                <span class="fly-city-card__name">成都</span>
                <span class="fly-city-card__sub">4.1 万家酒店</span>
            </a>
            <a href="#" class="fly-city-card fly-grad-5">
                <span class="fly-city-card__name">三亚</span>
                <span class="fly-city-card__sub">1.2 万家酒店</span>
            </a>
            <a href="#" class="fly-city-card fly-grad-6">
                <span class="fly-city-card__name">西安</span>
                <span class="fly-city-card__sub">2.7 万家酒店</span>
            </a>
            <a href="#" class="fly-city-card fly-grad-7">
                <span class="fly-city-card__name">厦门</span>
                <span class="fly-city-card__sub">1.6 万家酒店</span>
            </a>
            <a href="#" class="fly-city-card fly-grad-8">
                <span class="fly-city-card__name">广州</span>
                <span class="fly-city-card__sub">3.9 万家酒店</span>
            </a>
        </div>
    </section>

    {{-- ===================== 页脚 ===================== --}}
    <footer class="fly-footer">
        <div class="fly-container py-10">
            <div class="grid grid-cols-2 gap-8 md:grid-cols-4">
                <div>
                    <p class="fly-footer-title">关于我们</p>
                    <div class="fly-footer-links">
                        <a href="#" class="block">公司介绍</a>
                        <a href="#" class="block">联系我们</a>
                        <a href="#" class="block">加入我们</a>
                    </div>
                </div>
                <div>
                    <p class="fly-footer-title">加盟合作</p>
                    <div class="fly-footer-links">
                        <a href="#" class="block">酒店入驻</a>
                        <a href="#" class="block">旅行社合作</a>
                        <a href="#" class="block">广告服务</a>
                    </div>
                </div>
                <div>
                    <p class="fly-footer-title">帮助中心</p>
                    <div class="fly-footer-links">
                        <a href="#" class="block">预订流程</a>
                        <a href="#" class="block">退改政策</a>
                        <a href="#" class="block">常见问题</a>
                    </div>
                </div>
                <div>
                    <p class="fly-footer-title">用户协议</p>
                    <div class="fly-footer-links">
                        <a href="#" class="block">服务条款</a>
                        <a href="#" class="block">隐私政策</a>
                        <a href="#" class="block">版权声明</a>
                    </div>
                </div>
            </div>
            <p class="fly-footer-bottom">
                © 2026 云途旅行 YunTu Travel · 让每一次出发都简单 · 京ICP备00000000号-1（占位）
            </p>
        </div>
    </footer>
</body>
</html>
