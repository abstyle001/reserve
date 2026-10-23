const mix = require('laravel-mix');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel applications. By default, we are compiling the CSS
 | file for the application as well as bundling up all the JS files.
 |
 */

mix.js('resources/js/app.js', 'public/js')
    // 预约大屏独立入口（Blade 里用 mix('js/reserve/app.js') 引入）
    .js('resources/js/reserve/app.js', 'public/js/reserve')
    // 移动端排队查询独立入口（Blade 里用 mix('js/mobile/app.js') 引入）
    .js('resources/js/mobile/app.js', 'public/js/mobile')
    // 云途旅行 C 端独立入口（渐进增强：SSR 首屏 + Alpine 局部刷新）
    .js('resources/js/fly/app.js', 'public/js/fly')
    .postCss('resources/css/app.css', 'public/css')
    // 资源 URL 加 ?id=hash：不开启的话浏览器会一直用缓存里的旧 JS/CSS，
    // 改完前端代码用户侧看不到变化（曾让「日历高亮」修复看起来没生效）
    .version();
