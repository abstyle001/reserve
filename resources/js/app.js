import './bootstrap';

import Alpine from 'alpinejs';
import { registerCityPicker } from './city-picker';

window.Alpine = Alpine;

// 组件必须先注册再 Alpine.start()（start 后注册的指令对已有 DOM 不生效）
registerCityPicker(Alpine);

Alpine.start();
