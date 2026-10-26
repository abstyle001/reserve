/**
 * 后台表单的城市选择组件（Alpine.data('cityPicker')）。
 *
 * 与云途 C 端（fly/store.js + fly/partials/city-panel.blade.php）同一套交互与样式：
 * 点击弹出面板、按市名/省名搜索、点选回填、再点已选城市清除。区别是这里没有
 * $store.fly（后台是普通 Blade 表单，不走局部刷新），选中值直接写进组件内
 * 隐藏的 <input name="..."> 随表单提交；JS 关闭时输入框退化为普通文本框。
 * 城市数据与过滤函数与 C 端共用 china-cities.js。
 */
import { CHINA_CITIES, filterCityGroups } from './china-cities';

export function registerCityPicker(Alpine) {
    Alpine.data('cityPicker', (initial, placeholder) => ({
        open: false,
        query: '',
        value: typeof initial === 'string' ? initial : '',
        placeholder: typeof placeholder === 'string' && placeholder !== '' ? placeholder : '选择城市',

        toggle() {
            if (this.open) {
                this.open = false;

                return;
            }

            this.query = '';
            this.open = true;
        },

        groups() {
            return filterCityGroups(CHINA_CITIES, this.query);
        },

        /** 类名写完整字面量：拼接写法会被 Tailwind 内容扫描当成未使用而清除 */
        cityClass(city) {
            return this.value === city
                ? 'fly-city-item fly-city-item--active'
                : 'fly-city-item';
        },

        /** 与 C 端城市卡同一 toggle 语义：再点已选城市即清除 */
        pick(city) {
            this.value = this.value === city ? '' : city;
            this.open = false;
        },
    }));
}
