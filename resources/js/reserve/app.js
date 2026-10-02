/**
 * 预约大屏 - JS 入口（webpack.mix 独立入口）
 *
 * 顺序约定：先注册 Alpine store，再 Alpine.start()。
 * Alpine 不会自动调用 store 里的 init()，需要手动触发一次。
 */

import '../bootstrap';
import Alpine from 'alpinejs';
import { registerReserveStore } from './store';

registerReserveStore(Alpine);

window.Alpine = Alpine;
Alpine.start();

Alpine.store('reserve').init();
