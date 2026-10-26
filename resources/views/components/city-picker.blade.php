{{--
    城市选择器（后台表单用，与云途 C 端城市面板同一套交互与样式）。

    用法：<x-city-picker :value="old('city', isset($hotel) ? $hotel->city : '')" />
    可选：name / id / label / placeholder。

    JS 开启时：点按钮弹出全国城市面板（fly-city-panel 样式），搜索、点选回填，
    选中值写回隐藏 input 随表单提交；JS 关闭时按钮不渲染（x-cloak）、输入框是
    普通文本框，手打城市名照常提交。
--}}
@props([
    'value' => '',
    'name' => 'city',
    'id' => 'city',
    'label' => '城市',
    'placeholder' => '选择城市',
])

<div class="relative" x-data="cityPicker({{ Js::from((string) $value) }}, {{ Js::from($placeholder) }})"
     @click.outside="open = false"
     @keydown.escape.window="open = false">
    <label class="admin-label" for="{{ $id }}">{{ $label }}</label>
    <input id="{{ $id }}" name="{{ $name }}" type="text" class="admin-input" autocomplete="off"
           placeholder="{{ $placeholder }}" value="{{ $value }}" :value="value" x-show="false">
    <button type="button" class="admin-input text-left" x-cloak
            @click="toggle()"
            :class="{ 'text-gray-400': !value }"
            x-text="value || placeholder"></button>

    <div class="fly-city-panel" x-cloak x-show="open">
        <div class="fly-city-panel__search">
            <input type="text" x-model="query" placeholder="输入城市名或省份" maxlength="20" autocomplete="off">
        </div>

        <div class="fly-city-panel__scroll">
            <template x-for="group in groups()" :key="group.province">
                <div class="fly-city-province">
                    <p class="fly-city-province__title" x-text="group.province"></p>
                    <div class="fly-city-panel__grid">
                        <template x-for="city in group.cities" :key="city">
                            <button type="button" :class="cityClass(city)" @click="pick(city)">
                                <span class="fly-city-item__name" x-text="city"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>

            <p class="fly-city-panel__empty" x-show="groups().length === 0">
                未找到匹配的城市
            </p>
        </div>
    </div>
</div>
