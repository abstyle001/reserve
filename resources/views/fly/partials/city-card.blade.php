{{--
    城市卡 —— 入参 $city = HotelSearchService::cities() 的一行 + url / grad_index。

    点击后是「该城市 + 当前日期窗口」的搜索，不是无条件的城市页，
    否则用户刚选的日期会被点卡片这一下丢掉。

    城市清单本身不随搜索变化，所以这里不复制 live 版本：
    :href 让链接跟着 store 里的日期走，@click.prevent 把跳转换成局部刷新。
    城市名走 data-city（Blade 转义）而不是拼进 JS 表达式。
--}}
<a href="{{ $city['url'] }}" class="fly-city-card fly-grad-{{ $city['grad_index'] }}"
   data-city="{{ $city['city'] }}"
   :href="$store.fly.cityUrl($el.dataset.city)"
   @click.prevent="$store.fly.selectCity($el.dataset.city)">
    <span class="fly-city-card__name">{{ $city['city'] }}</span>
    <span class="fly-city-card__sub">{{ $city['hotel_count'] }} 家在售酒店</span>
</a>
