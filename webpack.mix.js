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
    .postCss('resources/css/app.css', 'public/css');
