# 项目长期记忆（laravel-app 预约取号系统）

## 技术栈事实

- Laravel 8.75 + sqlite；**本机运行时 PHP 7.3（macOS 自带），禁用 PHP 7.4+ 语法**（`fn()=>` 箭头函数会 ParseError 500）；构建用 **laravel-mix 6 / webpack 5**（`npx mix --production`，用 managed node 22）。根目录 vite.config.js 是缺 `laravel-vite-plugin` 的遗留文件，**忽略**。
- 前端大屏模块：`resources/js/reserve/`（Alpine store 唯一数据源）+ `resources/views/reserve/`；移动端 `/m`：`resources/js/mobile/` + `resources/views/mobile/`。规范详见项目根 `AGENTS.md`。
- **号票面板只渲染后端 `state.tickets`**；localStorage 按 origin 隔离（localhost ≠ 局域网 IP），只配做「本机取的」角标，不能做列表数据源。
- 接口统一 `{code,message,data}`；`code:0` 成功、`code:1` 业务失败、422 校验、500 异常。放号必须带 `(queue_key, batch_no, serial_no)` 三元组。
- 队列状态机：`active_count` 归零 → `need_reset=1` → 下次取号开新批次。批次号单调递增。

## 本机环境约束（每次来都会踩）

- 系统 PHP（/usr/bin/php 7.3.24）的 `PHP_VERSION` 常量被污染，PHPUnit 入口直接 die。跑测试用 runner 脚本绕过：
  `php -r 'require "vendor/autoload.php"; PHPUnit\TextUI\Command::main(false);' -- -c phpunit.xml <测试文件>`
  （`vendor/bin/phpunit` 和 `php artisan test` 都不行；`php artisan serve`/`route:list` 可用）
- brew 装不了 php（/usr/local 属主问题需 sudo）；Docker Hub 网络不可达。
- **局域网访问（手机扫码）必须 `php artisan serve --host=0.0.0.0 --port=8000`**；默认只绑 127.0.0.1。大屏也要用局域网 IP 打开，二维码才会带上手机可达的地址。本机 LAN IP 可查 `ipconfig getifaddr en0`（当前 192.168.2.7）。
- 验证页面渲染用系统 Chrome 138 headless：`--headless --no-sandbox --disable-gpu --disable-dev-shm-usage --virtual-time-budget=10000 --screenshot=...`
- macOS 11.3：agent-browser 自带 Chromium 起不来，别浪费时间。

## 预存问题（非本次任务引入）

- `routes/api.php` 引用的 `PostController` 不存在，`route:list` 报错；serve 正常运行不受影响。

## 用户偏好

- 界面要美观、放大、苹果官网风格；代码可维护性优先（分层、规范文档、AGENTS.md）。
- 中文交流；提交信息用 `type: 主题` 格式。
