{{--
    城市面板：点击 search-form 的「城市」字段弹出（渐进增强，与日期的 calendar-panel 同构）。

    数据与逻辑全部在 $store.fly：cityGroups()（按省分组 + 搜索过滤）、
    cityBadge()（在售酒店数角标）、pickCity()（选中 = toggle，与城市卡一致）。
    搜索框 x-model 直绑 store 的 cityQuery，不在模板里写业务逻辑。
    JS 关闭时本文件整体不参与（x-cloak 隐藏、x-show 属性被忽略），
    城市字段退化为自由文本 input，功能不退化。
--}}
<div class="fly-city-panel" x-cloak x-show="$store.fly.cityOpen">
    <div class="fly-city-panel__search">
        <input type="text" x-model="$store.fly.cityQuery"
               placeholder="输入城市名或省份" maxlength="20" autocomplete="off">
    </div>

    <div class="fly-city-panel__scroll">
        <template x-for="group in $store.fly.cityGroups()" :key="group.province">
            <div class="fly-city-province">
                <p class="fly-city-province__title" x-text="group.province"></p>
                <div class="fly-city-panel__grid">
                    <template x-for="city in group.cities" :key="city">
                        <button type="button" :class="$store.fly.cityItemClass(city)"
                                @click="$store.fly.pickCity(city)">
                            <span class="fly-city-item__name" x-text="city"></span>
                            <span class="fly-city-item__badge" x-show="$store.fly.cityBadge(city) > 0"
                                  x-text="$store.fly.cityBadge(city) + ' 家'"></span>
                        </button>
                    </template>
                </div>
            </div>
        </template>

        <p class="fly-city-panel__empty" x-show="$store.fly.cityGroups().length === 0">
            未找到匹配的城市
        </p>
    </div>
</div>
