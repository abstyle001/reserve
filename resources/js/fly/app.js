/**
 * 云途旅行（/fly）- JS 入口（webpack.mix 独立入口）
 *
 * 与 reserve / mobile 的「注册 store → start → 手动 init」相比多一步：
 * hydrate() 必须在 Alpine.start() 之前跑。start 一旦执行，:value / x-text
 * 这些绑定立刻生效，若此时 store 还是默认空值，首屏会把 Blade 渲染好的真实
 * 数据擦掉一下再填回来（肉眼可见的闪烁 + 日期框被清空）。
 * init() 仍然放在 start 之后：它只读 localStorage，且可能发起搜索请求，
 * 需要 DOM 上的 x-ref / 表单元素已经就绪。
 */

import '../bootstrap';
import Alpine from 'alpinejs';
import { registerFlyStore } from './store';

registerFlyStore(Alpine);

window.Alpine = Alpine;

Alpine.store('fly').hydrate();

Alpine.start();

Alpine.store('fly').init();
