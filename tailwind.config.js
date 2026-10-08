const defaultTheme = require('tailwindcss/defaultTheme');

/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // store.js 里也引用组件类（如 badge--green），不扫描会被 purge 掉
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                // 大屏优先使用苹果系统字体，Nunito 作为兜底保留原有设定
                sans: [
                    '-apple-system',
                    'BlinkMacSystemFont',
                    '"SF Pro Display"',
                    '"PingFang SC"',
                    '"Helvetica Neue"',
                    'Nunito',
                    ...defaultTheme.fontFamily.sans,
                ],
            },
            colors: {
                // 苹果系统色板（WWDC 风格）
                apple: {
                    blue: '#0A84FF',
                    indigo: '#5E5CE6',
                    green: '#30D158',
                    red: '#FF453A',
                    amber: '#FFD60A',
                    gray: '#8E8E93',
                },
            },
            screens: {
                // 大屏控制台专用断点
                '3xl': '1920px',
                '4xl': '2560px',
            },
        },
    },

    plugins: [require('@tailwindcss/forms')],
};
