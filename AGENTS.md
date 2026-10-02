# AGENTS.md

供 AI 智能体（以及新加入的开发者）快速理解本项目的约定与坑位。
**改动代码前请先读完本文件。**

---

## 1. 项目定位

预约取号 / 放号系统。

- **后端**：Laravel 8.75（PHP 8），SQLite 单文件数据库，无鉴权（内网/演示用途）。
- **前端**：Blade + Alpine.js + Tailwind CSS，走 laravel-mix（webpack）构建。
- **核心场景**：大厅大屏展示取号结果、人工操作放号，前端 5 秒轮询队列状态。

### 非目标

- 不做登录鉴权、不做报表统计、不做多租户隔离。

---

## 2. 技术栈与版本（重要）

| 层 | 技术 | 版本 | 说明 |
|---|---|---|---|
| 后端 | Laravel | 8.75 | |
| 语言 | PHP | 7.3（本机运行时） | composer.json 声明 ^7.3/^8.0，但本机 serve/测试跑在 macOS 自带 PHP 7.3 上，**禁用 PHP 7.4+ 语法**（如 `fn()=>` 箭头函数，会 ParseError） |
| 数据库 | SQLite | — | 单文件 `database/database.sqlite` |
| 构建 | laravel-mix | ^6.0.6（webpack 5） | **实际构建工具是 webpack.mix.js** |
| 前端框架 | Alpine.js | ^3.4 | |
| 样式 | Tailwind CSS | ^3.1 | + `@tailwindcss/forms` 插件 |
| HTTP | axios | ^0.21 | |

> ⚠️ **忽略根目录的 `vite.config.js`**：它 import 的 `laravel-vite-plugin` 未安装在 package.json 里，是历史遗留文件。所有构建命令走 Mix（`npm run dev` / `npm run prod`）。

---

## 3. 目录结构

```
app/
  Http/Controllers/Api/
    ReserveController.php      # 取号(add) / 放号(remove) / 查询状态(state) / 排队位置(position) —— 全部业务在此
    HealthController.php       # 探活示例
  Models/
    Customer.php               # 表 customers：一条 = 一位客户的一次取号
    SerialGenerator.php        # 表 serial_generator：按 (queue_key, batch_no) 聚合的号池
config/
  reserve.php                  # 队列选项（RESERVE_QUEUE_OPTIONS 环境变量，逗号分隔）
database/migrations/           # 增量迁移，字段定义以最终 schema 为准
resources/
  js/
    bootstrap.js               # 挂 window.axios + baseURL='/api'
    app.js                     # Breeze 默认入口（Alpine 全局实例）
    reserve/                   # ⭐ 预约大屏前端模块（独立入口）
      app.js                   # 入口：注册 store → Alpine.start() → init()
      config.js                # 常量（轮询间隔 / localStorage key / 重试上限）
      api.js                   # axios 封装，统一解包 {code,message,data}
      store.js                 # Alpine store（唯一数据源，含轮询/降级/动画/二维码）
      storage.js               # localStorage 读写（try/catch + 版本号 + 裁剪）
    mobile/                    # ⭐ 移动端排队查询模块（独立入口，只读）
      app.js / config.js / api.js / store.js / storage.js  # 分层与 reserve 一致
  views/
    reserve/                   # ⭐ 预约大屏 Blade
      index.blade.php          # 单页骨架，body 注入 data-reserve-config
      partials/                # topbar / ticket / actions / stats / records / toast
    mobile/                    # ⭐ 移动端排队查询 Blade（/m，只读）
      index.blade.php          # 查询表单 + 结果卡（前面还有 N 人）
    ...                        # 其余为 Breeze 鉴权页面，与大屏无关
  css/app.css                  # Tailwind 三层 + 苹果风组件类（m-* 前缀为移动端）
routes/api.php                 # POST/DELETE/GET /api/reserve*
routes/web.php                 # GET /reserve → 大屏；GET /m → 移动端排队查询
webpack.mix.js                 # 构建入口（含 reserve / mobile 独立入口）
tailwind.config.js             # content 已覆盖 resources/views/**
```

---

## 4. 编码规范

### PHP（app/）

- 4 空格缩进，花括号下一行（与现有 `ReserveController` 一致）。
- 方法签名**不强制类型化**：`public function add(Request $request)`（现有 add/remove/state 都是这个风格）。
- 中文校验提示，走 `Validator::make(..., [...], [中文提示])`。
- 响应统一 `["code" => ..., "message" => ..., "data" => ..., "errors" => ...]` 结构。
- `code === 0` 为业务成功；`1` = 业务失败（如放号时记录不存在）；`422` = 参数校验失败；`500` = 未捕获异常。
- 异常兜底统一 `try/catch (\Throwable)` + 返回 `500 + message`。
- 写操作必须用 `DB::transaction` + `lockForUpdate` 保证并发安全；**只读接口（如 state）绝不加锁、不开事务**（sqlite 下会与轮询抢写锁）。

### 前端（resources/js/reserve/、resources/views/reserve/）

- ES Module 分层：`api.js` 只发请求 → `store.js` 只管状态与动作 → Blade partials 只读不写。
- **`$store.reserve` 是界面上一切数值的唯一来源**；新增交互一律扩 store 动作，不要在 partial 里写内联业务逻辑。
- 不要 `x-html`，动态文本一律 `x-text`（自动转义）。
- Blade 往 HTML 属性注入 JSON：`json_encode($x, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG)`，防引号破坏表达式。
- localStorage key 必须带版本号（如 `reserve:records:v1`），所有读写 try/catch。
- 大号数字（号牌/统计）加 `tabular-nums` + 定宽容器，避免轮询刷新时抖动。

---

## 5. 接口契约（API）

统一前缀 `/api`，均**无鉴权**（内网/演示）。

### `POST /api/reserve` — 取号

请求体：`{ key: string }`（队列标识）
成功：`code:0` + `data: { queue_key, batch_no, serial_no }`
失败：`422`（key 为空/超长）或 `500`。

### `DELETE /api/reserve` — 放号

请求体（注意是 **DELETE + JSON body**，axios 用 `{ data: {...} }`）：
`{ key, batch_no, serial_no }`
成功：`code:0`。
业务失败：`code:1` + `message`（"该预约不存在" / "重复放号"），HTTP 状态仍是 200。

### `GET /api/reserve/state?key=xxx` — 查询状态（只读）

成功：`code:0` + `data`:
```json
{
  "queue_key": "A", "batch_no": 3,
  "current_no": 27, "active_count": 4, "need_reset": 0,
  "released_total": 23, "remaining": 4,
  "tickets": [{"serial_no": 24, "is_finish": 1}, {"serial_no": 25, "is_finish": 0}],
  "server_time": "2026-10-02 10:00:00"
}
```
- `current_no` = 本批已发号上限；`active_count` = 本批未办结数量 = `remaining`。
- `need_reset === 1` 表示本批已办结，下次取号会开新批次。
- `released_total` 按**当前批次**统计，不要用全队列 count（跨批次会算错）。
- `tickets` = 当前批次号票列表（serial_no 升序），**大屏号票面板的唯一数据源**。
  号票必须后端下发：localStorage 是浏览器私有的，不同 origin/设备看到的不一致，
  且本地 is_finish 永远不会被后端校正。
- 失败：`422` / `500`，与上面同构。

> 注意路由顺序：`GET /reserve/state` 应声明在任何 `/reserve/{param}` 通配之前。

### `GET /api/reserve/position?key=xxx&serial_no=N` — 移动端排队查询（只读）

用户在手机端查自己的号码：前面还有几人、是否已办结。**用户不知道 batch_no**，
接口按该队列 `max(batch_no)` 定位最新批次后查 `serial_no`（依据见 §6 推论）。

成功：`code:0` + `data`:
```json
{
  "queue_key": "1窗口", "batch_no": 3, "serial_no": 27,
  "status": "waiting", "ahead_count": 4,
  "current_no": 30, "active_count": 5,
  "server_time": "2026-10-02 10:00:00"
}
```
- `status` 三态：`waiting`（排队中）/ `finished`（已办结）/ `not_found`（最新批次里没这个号，含换批后的旧号）。
- `ahead_count` = 同批次 `is_finish=0` 且 `serial_no` 更小的数量，仅 `waiting` 时有意义。
- `not_found` 是**正常状态不是错误**，`code` 恒为 0（轮询中换批会让旧号失效）。
- 失败：`422`（key / serial_no 校验失败）/ `500`，同构。

---

## 6. 业务规则（取号 / 放号状态机）

```
取号(add)：
  该队列存在 need_reset=0 的批次？
    ├─ 是 → current_no+1，serial_no=current_no，active_count+1
    └─ 否 → 开新批：batch_no = 历史最大+1，serial_no=1，active_count=1，need_reset=0

放号(remove)：
  customers 里按 (queue_key, batch_no, serial_no) 查到记录？
    ├─ 是 且 is_finish=0 → is_finish=1；active_count-1
    │     └─ active_count==0 → need_reset=1（本批办结）
    └─ 否 或已 is_finish=1 → code:1（"该预约不存在" / "重复放号"）
```

关键推论：

- 同一队列的号票全局唯一性靠 `(queue_key, batch_no, serial_no)` 三元组保证。
- `active_count` 归零 ≠ 删除批次记录，下次取号基于 `need_reset=1` 开新批，`batch_no` 单调递增。
- **仍在排队的号码必然位于该队列最新批次**（新批次的开启条件是上一批全部办结）。
  `position` 接口因此只需按 `max(batch_no)` 查 `serial_no`，用户无需输入批次号。
- 前端放号必须同时携带 `batch_no` 和 `serial_no`，**不能只用 serial_no**（跨批次会冲突）。

---

## 7. 前端模块约定（预约大屏）

### 交互流程

1. 页面加载 → store `init()` 读 localStorage 恢复队列 key 与「本机取的号」→ 立刻 `poll()` 一次 → `setTimeout` 调度轮询（间隔 5s，失败按 `min(5000×1.5^n, 20000ms)` 退避）。
2. 取号成功 → 写入 records（仅作「本机」角标）→ `latest` 触发号牌弹跳动画 + 生成扫码二维码 → toast → 立即 `poll()`。
3. 放号：先点选「当前号票」面板中一张（数据来自后端 `state.tickets`）→ 按钮文字变为「放号 N」→ 调 DELETE（`batch_no` 取 `state.batch_no`）→ 成功后清选中态并立即 `poll()`，面板状态以后端为准。
4. 页面 `visibilitychange` 隐藏时暂停轮询，回来立即补一次。
5. 「放大」按钮：切换 `html.zoomed`（改根字号）+ 调 `requestFullscreen()`（必须用户手势）；监听 `fullscreenchange` 同步状态。**不要**用 `transform: scale()` 放大（会糊且拖垮 backdrop-blur）。

### 号票面板数据源（铁律）

- 「当前号票」面板**只能渲染后端 `state.tickets`**，绝不能渲染 localStorage records。
  localStorage 按 origin 隔离：localhost 与局域网 IP 是两个 origin，各自只存了
  本机取过的号，且本地 `is_finish` 与后端无同步——渲染本地记录必然不一致。
- localStorage records 的唯一用途：`isMine(serial)` 给本机取的号打「本机」角标。
- 选中态用 `selectedSerial`（serial_no 在当前批次唯一）；换批后轮询会自动清掉失效选中。

### 已知坑

- **Alpine store 必须先注册再 `Alpine.start()`**；store 的 `init()` 不会被自动调用，需在入口里手动触发。
- 列表选中态用 store 的 `selectedId` 比较；不要在 `x-for` 项上再开 `x-data`，局部作用域会遮蔽 store。
- `x-cloak` 必须配 CSS `[x-cloak]{display:none!important}`（已写入 `app.css`）。
- 苹果风样式集中在 `resources/css/app.css` 的 `@layer components`；新增样式请遵循「底 / 玻璃 / 按钮 / 号牌 / 徽章」的命名空间。
- `queueOptions` 由后端 Blade 注入 `body[data-reserve-config]`；改队列列表请改路由/视图层，不要写死进 JS。

### 移动端排队查询（/m，只读）

- **只读约束是硬性的**：`resources/js/mobile/api.js` 整层没有 POST/DELETE，页面不放任何取号/放号入口。
- 号码来源优先级：URL 参数 `?key=xxx&no=27`（大屏二维码扫码直达）→ localStorage（`mobile:ticket:v1`）→ 查询表单。
- 轮询：绑定号码后每 5s 查一次 `position`，失败退避同 reserve；`visibilitychange` 隐藏暂停、回来立即补一次。
- **轮询重排要放在 poll 链的最后一个 `then` 里**（成功失败都重排，失败由 schedulePoll 内部退避）；只在失败分支重排会导致首次成功后轮询停掉（reserve 模块曾踩过这个 bug，已修）。
- 大屏二维码：`reserve/store.js` 的 `updateQr()` 用 `qrcode` 包生成 dataURL，内容为 `location.origin + /m?key&no`；**目标票由 `qrTicket()` 决定：优先当前选中的「待叫号」票，否则回退本机刚取的号（currentLatest）**，选中已放号的票不生成；同一目标不重复生成（闭包 `lastQrTarget` 去重），换批/取消选中自动隐藏。

---

## 8. 构建与运行

```bash
# 前端（必须跑，Blade 用 mix()，manifest 缺 key 会抛异常）
npm run dev        # 开发（watch 模式）
npm run prod       # 生产

# 后端
cp .env.example .env          # 首次
php artisan key:generate      # 首次
touch database/database.sqlite
php artisan migrate
php artisan serve

# 打开 http://localhost:8000/reserve
```

> Node 版本提示：laravel-mix 6 + webpack 5 在 Node 22 下偶发 `md4 hash` 报错，如遇可试 `NODE_OPTIONS=--openssl-legacy-provider npm run prod`。

### 环境注意

- macOS 系统自带的 `/usr/bin/php` 存在 `PHP_VERSION` 常量污染问题，会导致 Composer/PHPUnit 的 `version_compare()` 报错。**请使用 Homebrew 安装的 php**（`brew install php`），否则 artisan/test 都无法运行。
- `.env` 中 `DB_CONNECTION=sqlite`、`DB_DATABASE=<项目绝对路径>/database/database.sqlite` 必须配置且文件存在。

---

## 9. 测试与提交

- Feature 测试放 `tests/Feature/`，命名 `XxxTest.php`，继承 `Tests\TestCase`，用 `RefreshDatabase`（sqlite 会重建表）。
- 运行：`./vendor/bin/phpunit --filter=ReserveStateTest`（`php artisan test` 亦可）。
- 现有测试参考：`tests/Feature/ReserveStateTest.php`（覆盖取号→状态→放号→换批全链路）、`tests/Feature/ReservePositionTest.php`（移动端排队查询：前面人数 / 已办结 / 换批后旧号 not_found / 只读无副作用）。
- 提交信息：`feat: 取号放号大屏控制台`、`fix: 放号并发下批次计算错误` 这类 `type: 主题` 格式。

---

## 10. 常见坑速查（完整清单）

| 现象 | 原因 | 解决 |
|---|---|---|
| `route:list` 报 `PostController.php` 不存在 | **预存问题**：`routes/api.php` 引用的 `PostController` 文件已被删除，autoload classmap 里还有残留 | 补回 Controller 或删掉 `/posts` 两条路由后 `composer dump-autoload`；不影响 serve 运行 |
| Blade 报 `mix() not defined` / manifest 异常 | `npm run prod` 没跑或新入口没进 manifest | 重跑构建 |
| 大屏上字号放大后模糊 | 用了 `transform: scale()` | 改切 `html.zoomed`（根字号方案） |
| 轮询把 sqlite 写锁占满 | state 接口误加 `lockForUpdate` | 去掉锁与事务 |
| 放号报"重复放号" | 只用 `serial_no` 没带 `batch_no` | 三元组缺一不可 |
| DELETE 请求体丢失 | axios 把第二个参数当 config | 用 `{ data: {...} }` 包一层 |
| 页面首屏闪现 raw 状态 | 缺 `[x-cloak]` 或 x-show 未配 | 检查 CSS + x-cloak 属性 |
| 轮询首次成功后就停了 | schedulePoll 只写在失败分支 | 重排放 poll 链最后一个 then，成功失败都重排 |
| 两个入口/设备号票面板不一致 | 号票列表渲染了 localStorage records（按 origin 隔离） | 面板只渲染后端 `state.tickets`；localStorage 仅作「本机」角标 |
| 接口 500 报 `unexpected '=>'` | 运行时 PHP 7.3，用了 `fn()=>` 箭头函数 | 改回 `function ($x) { return ...; }` 传统闭包 |
| 手机扫码打不开页面 | `php artisan serve` 默认只绑 127.0.0.1 | 启动加 `--host=0.0.0.0`；大屏也用局域网 IP 打开（二维码取 location.origin） |
| 列表点选后高亮错乱 | `x-for` 里又开了 `x-data` | 改用 store `selectedId` |
| 接口在浏览器 401 | 误把 API 路由放进 `web.php` 或请求没带 CSRF | API 走 `api.php`（无 CSRF），确认 baseURL=`/api` |
| PHP 命令直接报 `invalid PHP_VERSION` | 用了 macOS 系统自带 php | 切到 brew php |

---

*最后更新：2026-10-02（号票面板改为后端 tickets 驱动，修复多端不一致）*
