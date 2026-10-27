# AGENTS.md

供 AI 智能体（以及新加入的开发者）快速理解本项目的约定与坑位。
**改动代码前请先读完本文件。**

---

## 1. 项目定位

预约取号 / 放号系统 + 云途旅行（`/fly`）酒店预订 C 端。

- **后端**：Laravel 8.75（PHP 8）。数据库**本机 `.env` 是 MySQL，测试跑 sqlite `:memory:`** —— 所有 SQL 必须双方言通用（见 §2 的坑位说明）。
- **鉴权**：Breeze session 登录（`/login`、`/register`、`/logout`）。大屏 `/reserve` 与取号/放号写接口需登录；移动端 `/m`、只读查询接口、以及**整个 `/fly` C 端**公开（顾客不登录，订单凭证是 `订单号 + 手机号`）。
- **前端**：Blade + Alpine.js + Tailwind CSS，走 laravel-mix（webpack）构建。
- **核心场景**：① 大厅大屏展示取号结果、人工放号，前端 5 秒轮询队列状态；② 顾客在 `/fly` 搜酒店 → 选日期 → 看房量 → 下单 → 凭手机号查单/取消，后台 `/hotels` `/room-types` `/rooms` `/bookings` 维护数据与订单，房型「房量计划」按晚停售控制 C 端各日期的可订间数。

### 非目标

- 不做报表统计、不做多租户隔离、不做 RBAC 多角色（登录即可用）。
- `/fly` 不做入住核销（`bookings.status` 已预留 3已入住 / 4已退房），不做后台补录下单（第二条写路径），不做登录态会员体系。

---

## 2. 技术栈与版本（重要）

| 层 | 技术 | 版本 | 说明 |
|---|---|---|---|
| 后端 | Laravel | 8.75 | |
| 语言 | PHP | 8.2.12（本机 Windows 运行时） | composer.json 仍声明 `^7.3\|^8.0`；代码约定用传统闭包 `function ($x) { return ...; }`，不用 `match`（Laravel 8 / PHP 7.3 兼容习惯），箭头函数在当前位置无必要 |
| 数据库 | **MySQL（本机 `.env`）+ sqlite `:memory:`（phpunit.xml 的 `<server>` 覆盖）** | — | `database/database.sqlite` 只是 sqlite 分支用得到；**写 SQL 必须两套方言都过** |
| 构建 | laravel-mix | ^6.0.6（webpack 5） | **实际构建工具是 webpack.mix.js** |
| 前端框架 | Alpine.js | ^3.4 | |
| 样式 | Tailwind CSS | ^3.1 | + `@tailwindcss/forms` 插件 |
| HTTP | axios | ^0.21 | |

> ⚠️ **忽略根目录的 `vite.config.js`**：它 import 的 `laravel-vite-plugin` 未安装在 package.json 里，是历史遗留文件。所有构建命令走 Mix（`npm run dev` / `npm run prod`）。

### 双驱动纪律（改库存/日期查询前必读）

线上与本机是 MySQL，测试库是 sqlite `:memory:`，两边都能跑才算对：

- **禁用专有函数**：`strftime` / `DATEDIFF` / `IFNULL` / `GROUP_CONCAT(DISTINCT …)` / MySQL 的 `ON DUPLICATE KEY`。只用 `COUNT` / `SUM` / `COALESCE` / `MAX` 和普通比较。
- **全项目禁用 `whereDate()`** 处理入住/离店：sqlite 编译成 `date(col) = date(?)`，函数包裹列会让日期索引失效，MySQL 上 date 与 datetime 的比较语义也不一致。日期一律是 `Y-m-d` 字符串，走纯区间比较。
- **sqlite 下 `lockForUpdate()` 是语法级 no-op**（`SQLiteGrammar::compileLock()` 直接 `return ''`）。§4 那条「写操作必须 lockForUpdate」只在 MySQL 上真正生效，sqlite 侧要靠条件 UPDATE / 唯一索引兜底（见 §7.4）。

---

## 3. 目录结构

```
app/
  Http/Controllers/Api/
    ReserveController.php      # 取号(add) / 放号(remove) / 查询状态(state) / 排队位置(position) —— 全部业务在此
    FlyReadController.php      # ⭐ /fly 的 4 个只读 JSON 接口（无 session、无锁、无事务）
    HealthController.php       # 探活示例
  Http/Controllers/
    FlyHomeController.php      # GET /fly 首页（SSR）
    FlyHotelController.php     # GET /fly/hotels/{hotel} 详情（SSR）
    FlyBookingController.php   # ⭐ create=下单页 / store=下单写接口（web 表单，非 JSON）
    FlyOrderController.php     # index=手机号查单 / show=订单详情=下单结果页 / cancel=取消
    BookingAdminController.php # 后台 /bookings 列表 / 详情 / 取消（需登录）
    HotelController.php RoomTypeController.php RoomController.php   # 后台三级 CRUD
    RoomInventoryController.php # ⭐ 房量计划：按晚停售的展示 / 设置 / 恢复（与 /fly 日期筛选联动）
  Models/
    Customer.php               # 表 customers：一条 = 一位客户的一次取号
    SerialGenerator.php        # 表 serial_generator：按 (queue_key, batch_no) 聚合的号池
    Booking.php                # ⭐ 表 bookings：一条 = 某房型 [check_in, check_out) 订 N 间（日期一律 Y-m-d 字符串）
    RoomTypeBlock.php          # ⭐ 表 room_type_blocks：一行 = 某房型某晚停售 N 间（不占具体房间号）
    Hotel.php RoomType.php Room.php User.php
  Services/Fly/                # ⭐ 库存口径唯一出处（Controller 里不要重写 overlap 条件）
    StockSql.php               # 「可订间数」SQL 片段：可售房数 − 区间重叠占用 − 各晚停售 max，列表页 selectRaw 与聚合共用
    AvailabilityService.php    # 房型/酒店维度批量聚合（含 blockedByRange 停售聚合）+ 下单前房间分配 allocateRooms()
    HotelSearchService.php     # 搜索/分页/城市卡/排序，卡片载荷 formatCard()（SSR 与 JSON 同一规格）
    BookingRejectedException.php  # 房量业务拒绝（售罄 / 仅剩 N 间 / 房型跨酒店 / 酒店停业），转中文表单错误
config/
  reserve.php                  # 队列选项（RESERVE_QUEUE_OPTIONS 环境变量，逗号分隔）
  fly.php                      # ⭐ C 端业务参数（见 §7.1，时区/分页/上限/热门关键词）
database/migrations/           # 增量迁移，字段定义以最终 schema 为准
database/seeders/
  FlyDemoSeeder.php            # ⭐ 仅 local 的演示数据（酒店/房型/房间），文件名已标 demo
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
    fly/                       # ⭐ 云途 C 端渐进增强模块（独立入口，只读）
      app.js                   # 入口：注册 store → hydrate() → Alpine.start() → init()
      config.js                # LS key 版本号、debounce 250ms、与 config/fly.php 对齐的展示常量
      api.js                   # 只有 fetchHotels / fetchRoomTypes，整层无 POST/DELETE
      store.js                 # $store.fly：搜索/筛选/URL 同步/详情页房量 + 卡片派生（class、价格、剩 N 间）
      storage.js               # localStorage 读写，隐私模式兜底
  views/
    reserve/                   # ⭐ 预约大屏 Blade
      index.blade.php          # 单页骨架，body 注入 data-reserve-config
      partials/                # topbar / ticket / actions / stats / records / toast
    mobile/                    # ⭐ 移动端排队查询 Blade（/m，只读）
      index.blade.php          # 查询表单 + 结果卡（前面还有 N 人）
    fly/                       # ⭐ 云途 C 端 Blade（SSR 首屏 + Alpine 局部刷新）
      layout.blade.php         # 顶栏/页脚外壳，body 注入 data-fly-config
      home.blade.php hotel.blade.php book.blade.php order.blade.php orders.blade.php
      partials/                # ⭐ SSR 与 Alpine 成对存在，改一处必须同步另一处：
                               #   hotel-card（SSR）↔ hotel-card-live（<template x-for>）
                               #   room-row（SSR）↔ room-row-live（<template x-for>）
                               #   外壳：results-live / pagination / city-card / search-form /
                               #         empty-state / promise-bar
    bookings/                  # 后台订单列表 + 详情
    hotels/ rooms/ room-types/ # 后台三级 CRUD；room-types/ 里含 inventory.blade.php（房量计划）
    ...                        # 其余为 Breeze 鉴权页面
  css/app.css                  # Tailwind 三层 + 苹果风组件类（m-* 移动端，fly-* 云途 C 端）
routes/api.php                 # POST/DELETE/GET /api/reserve*；GET /api/fly/* 四条只读
routes/web.php                 # /reserve 大屏、/m 移动端、/fly/* C 端页面与写接口、/bookings* 后台
webpack.mix.js                 # 构建入口（含 reserve / mobile / fly 独立入口）
tailwind.config.js             # content 已覆盖 resources/views/** 与 resources/js/**/*.js
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
- **`/fly` 的库存口径不许散落在 Controller 里**：可订间数只从 `App\Services\Fly\StockSql` / `AvailabilityService` 出，重叠判定只用 `Booking::scopeOverlapping()` + `scopeActive()`（§7.2）。
- **C 端日期基准是 `config('fly.timezone')`，不是 `now()`**：全局 `app.timezone` 是 UTC，裸用 `now()` 会让中国用户早上 8 点前的「今天」少一天（§7.1）。只改 fly 侧，不要顺手改全局时区，那会把 reserve/customers 的既有时间展示一起挪走。
- `/fly` 的展示派生（`score_text` / `review_text` / `loc_text` / `tag_list` / `min_price_text`）一律放模型访问器或 `HotelSearchService::formatCard()`，**不在 Blade 里拼字符串**，否则 SSR 与 JSON 两份页面会各说一个值。

### 前端（resources/js/reserve/、resources/js/fly/ 及其 Blade）

- ES Module 分层：`api.js` 只发请求 → `store.js` 只管状态与动作 → Blade partials 只读不写。
- **`$store.reserve` / `$store.fly` 是各自界面上一切数值的唯一来源**；新增交互一律扩 store 动作，不要在 partial 里写内联业务逻辑。
- 不要 `x-html`，动态文本一律 `x-text`（自动转义）。
- **不要在 `x-for` 项里再开 `x-data`**（局部作用域会遮蔽 store）；判断逻辑（tag 配色、剩 N 间的颜色、渐变底色）写成 store 方法。
- **元素上不要留会被 `:class` 改写的静态 class**：Alpine 的字符串语法 `:class="'x'"` 只加不摘，留着旧修饰类会摘不掉（`room-row-live` 的 `fly-room-row__remain` 就是这个原因）。
- Blade 往 HTML 属性注入 JSON：`json_encode($x, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG)`，防引号破坏表达式。
- **模板里不要把手改的值拼进 JS 表达式**：关键词/排序值走 `data-keyword` / `data-sort` 属性，由 store 读，避免把不可信输入写进 `onclick`。
- localStorage key 必须带版本号（如 `reserve:records:v1`、`fly:search:v1`），所有读写 try/catch。
- 大号数字（号牌/统计/价格/剩 N 间/评分）加 `tabular-nums` + 定宽容器，避免轮询或局部刷新时抖动。

### 样式（`resources/css/app.css` 的 `fly-*` 组件）

三条都是踩过的坑，改搜索栏样式前先看：

- **`.fly-field` 里的 input 必须显式清掉 `@tailwindcss/forms` 的白底 / 边框 / 内边距 / ring**
  （`rounded-none border-0 bg-transparent p-0 shadow-none focus:shadow-none`）。表单插件给
  `[type=text]`、`[type=date]` 都上了独立盒子，套进 `.fly-field` 就是「框中框」，原生日期控件最明显。
- **网格轨道一律写 `minmax(0, …)`**：`1fr` 轨的自动最小宽度是内容的 min-content，而 input 的
  min-content 有 ~180px（默认 `size=20`），四个框挤一行时轨道塞不下，最窄的 `auto` 轨（搜索按钮）
  会被压成一列竖排字。按钮另外加 `whitespace-nowrap` 兜底。
- **原生 date 一格要 ~120px**（`2026-10-22` + 日历小图标，实测 en  locale 更宽）。两格塞不下时
  靠 `flex-wrap` 叠成两行、由 `.fly-field--dates` 的 `min-h-[3.5rem]`（不是固定 `h-14`）长高，
  绝不能溢出圆角外——那正是「日历撕裂」的形态。日期字段自己不再放 `fly-field__icon`：
  原生控件右侧已有日历图标，再加一个就是三个图标。

---

## 5. 接口契约（API）

统一前缀 `/api`。**只读接口公开**（state / position，手机端轮询要用）；**写接口（取号/放号）要求登录**。

> ⚠️ 写接口（`POST/DELETE /api/reserve`）定义在 `routes/web.php`（同名 URI），不在 `api.php`。
> 原因：api 中间件组没有 session，写接口要登录态；直接给 api.php 路由叠 `['web','auth']`
> 会踩 Laravel 中间件优先级排序的坑（`App\Http\Middleware\EncryptCookies` 是子类，不在
> `$middlewarePriority` 名单里，StartSession 会被排到它前面，session 读不到解密后的
> cookie，写接口永远 401）。web 组自带 session + CSRF，axios 自动从 XSRF cookie
> 带 X-XSRF-TOKEN 头。
> 未登录 → `401` JSON；CSRF 过期 → `419`；大屏前端 `reserve/api.js` 识别两者后跳 `/login`。

### `POST /api/reserve` — 取号（需登录）

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

### `/api/fly/*` — 云途 C 端只读接口（全部公开）

四条都在 `routes/api.php`，`api` 中间件组：**无 session、不加锁、不开事务**。
响应外壳与 `ReserveController` 逐字同构（`{code, message, data, errors}`；`0` 成功 / `1` 业务失败 / `422` 校验 / `500` 兜底）。

```
GET /api/fly/cities                                  → data.items: [{city, hotel_count}]
GET /api/fly/hotels?city&q&check_in&check_out&sort&page
                                                     → data: {items[card], total, per_page, current_page, last_page, facets}
GET /api/fly/hotels/{hotel}/room-types?check_in&check_out
                                                     → data: {hotel{card}, check_in, check_out, nights, items[房型+remaining]}
GET /api/fly/bookings?phone[&order_no]               → data: {items[订单], phone}
```

- `card` 由 `HotelSearchService::formatCard()` 产出，**Blade 循环与 JSON 同一个函数、键名一字不差**；
  `grad_index`（`$index % 8 + 1`）必须后端下发，否则 SSR 与 Alpine 两次渲染底色不一致。
- 下架酒店：模型绑定查得到，但内容不能出 —— 页面 `404`，接口 `code:1` + 「酒店不存在或已下架」。
- `data.items` 里的姓名/手机号是**脱敏后**的（`张*` / `137****0000`）；`data.phone` 回显用户输入的查询号（本来就是他自己输的）。
- **只读 GET 只在 api.php 注册一次，web.php 不许出现同名 URI**，否则后加载的 web 组会遮蔽 api 组。
- **下单/取消绝不放这里**：写操作只在 `routes/web.php` 的 `/fly` 组（web 组自带 session + CSRF，走 Blade 表单 + redirect）。理由同 §5 顶部那条中间件排序坑。

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

## 7. 云途 C 端（`/fly`）模块约定

### 7.1 配置与时区基准

`config/fly.php`（写法照 `config/reserve.php`）：`timezone`(Asia/Shanghai) / `per_page`(6) /
`city_limit`(8) / `max_nights`(30) / `max_rooms`(10) / `max_days_ahead`(365) / `hot_keywords`。

- **C 端所有「今天 / 日期上下界」以 `config('fly.timezone')` 为准**，不要裸用 `now()`：全局
  `app.timezone` 是 UTC，中国用户早上 8 点前会少一天。展示时间也走模型访问器
  （`Booking::booked_at_text` / `cancelled_at_text`），别让 JSON 与 Blade 各说一个时间。
- 前端 `resources/js/fly/config.js` 里有与之对齐的展示常量；改一边必须改另一边。
- 新增/删除 fly 环境变量式参数时，**同步 `.env.example`**（该文件历史上就漏过 `RESERVE_QUEUE_OPTIONS`）。

### 7.2 库存口径（最重要的一节）

```
可订间数 = 该房型下非停用房间数 − Σ room_count（active 且日期重叠的订单）− 区间内各晚停售间数的最大值
```

- **半开区间 `[check_in, check_out)`**：入住当天算一晚、离店当天不算。于是
  「A 的 check_out == B 的 check_in」天然不冲突，边界不需要 +1 天特例。重叠判定唯一出处：
  `Booking::scopeOverlapping($in, $out)` = `check_in < $out && check_out > $in`。
- **占用只认 `scopeActive()` = `status IN (1 已预订, 3 已入住)`**。将来加核销入住时不改库存口径、
  不改查询，只多一次状态流转。取消是软状态（`status=2` + `cancelled_at`），行不删，
  `scopeActive` 自动把它剔出占用集合 → 房量立刻释放且历史可查。
- **按晚停售（`room_type_blocks`，后台「房量计划」）**：一行 = 某房型某晚停售 N 间，
  不占具体房间号。停售晚同属半开区间（`night_date ∈ [check_in, check_out)`），
  判定唯一出处是 `RoomTypeBlock::scopeBetween()`。**取各晚的 max 不取 sum**：
  每晚一行，同一批房间连续停售多晚时，对一次多晚查询只该扣一次。
  后台入口 `RoomInventoryController`（GET/POST `/hotels/{hotel}/room-types/{roomType}/inventory`），
  表单区间在 store 里展开成每晚一行并 upsert；重复提交同晚会覆盖，删除行即释放。
- 日期由 `setCheckInAttribute` / `setCheckOutAttribute` 修改器强制归一成 `Y-m-d` 字符串。
  **绝不让 Eloquent 的 date cast 写成 `Y-m-d H:i:s`**，否则 `check_out > :in` 边界会算错。
  同理**全项目禁用 `whereDate()`**（§2）。`night_date` 同理，由 `setNightDateAttribute` 归一。
- **查询条数恒定，不许 N+1**：搜索 = `paginate` 的 count + 页查询（2 条）+ 房型清单 +
  可售聚合 + 占用聚合 + 停售聚合（4 条）= **6 条**，与「本页几家酒店、每家几个房型、每天几个订单」无关。
  `FlyAvailabilityTest` 用 `DB::listen` 把这条钉死了，加查询会让它红 —— 那是对的，别去改断言
  （口径本身扩展时，跟着把断言计数与注释一起更新）。
- 可售过滤必须留在 SQL 的 `whereExists` 里（`StockSql::minSellablePrice`），放 PHP 里 filter
  会让 `paginate` 的 total 与实际条数不一致。
- 停售扣减有两个实现处，**必须同时改**：SQL 侧 `StockSql::remaining()` 的内联子查询
  （列表页 min_price 排序用，不增查询条数），与 PHP 侧 `AvailabilityService::blockedByRange()`
  （详情/下单的批量聚合）。口径注释两边都已写明，改一处漏一处 = 列表与详情各说一个数。

### 7.3 路由与鉴权边界

| 类别 | 位置 | 中间件 | 说明 |
|---|---|---|---|
| 只读 JSON（4 条） | `routes/api.php` | `api` | 无 session、无锁、不开事务 |
| C 端页面 + 写操作 | `routes/web.php` 的 `fly` 组 | `web`（**不加 `auth`**） | 顾客全程不登录 |
| 后台订单 | `routes/web.php` | `web` + `auth` | `/bookings*` |

```
GET  /fly                                 fly.home              首页（搜索 + 卡片）
GET  /fly/hotels/{hotel}                  fly.hotels.show       详情 + 房型房量
GET  /fly/hotels/{hotel}/book             fly.book              下单页
POST /fly/bookings                        fly.bookings.store    下单（写，Blade 表单）
GET  /fly/orders                          fly.orders            手机号查单
GET  /fly/orders/{booking}?phone=         fly.orders.show       订单详情 = 下单结果页
POST /fly/orders/{booking}/cancel         fly.orders.cancel     取消（写）
```

- **写操作走 Blade 表单 `@csrf` + redirect，不暴露 C 端写 JSON 接口**（与后台全站 redirect 风格
  一致，也避开 axios 0.21 的 DELETE body 坑）。取消用 POST 不用 DELETE。
- 「我的订单」两处（`@auth` / `@else` 分支）都指 `/fly/orders`，**不是 `route('dashboard')`** ——
  那是后台首页；登录的后台账号在 C 端本来就不该看到订单。
- `RouteServiceProvider::HOME = '/fly'`：Breeze 登录/注册的落地页是云途 C 端首页（站内所有「首页」
  都指 `route('fly.home')`）。不要改成 `'/'`（Laravel 原版欢迎页，只有文档外链），`ReserveAuthTest` 守这条。
- HOME 只管「直接访问 /login」的落点：被 guest 中间件拦下的请求登录后由 `intended()` 送回原目标，
  所以操作员先看 `/reserve` 再登录，仍然回到大屏（有测试覆盖）。

### 7.4 下单写路径与并发兜底

顺序固定：Validator → `DB::transaction`{ 载入 RoomType 并确认 hotel 营业中（防跨酒店越权）
→ `remainingFor()` 复核（含停售扣减，口径与 C 端一致）→ `AvailabilityService::allocateRooms()` → 落库 } → catch。

- **sqlite 下 `lockForUpdate()` 是 no-op**，所以并发安全靠三重兜底，别谎称有行锁：
  ① `idempotency_key` 唯一索引挡双击提交（命中 `QueryException` 时按 key 回查，查到就当成功
  跳结果页）；② 取消/后台取消用 `where('status', STATUS_BOOKED)->update(...)` 的**条件 UPDATE**
  而不是「读-改-写」；③ `allocateRooms` 用 `whereNotIn('id', 已占房)` 排除，不足数直接业务失败。
  要在 sqlite 上拿 100% 硬保证，唯一办法是按晚展开的 occupancy 子表 + `unique(room_id, night_date)`
  —— 本期列为演进路径，没做。
- 业务拒绝统一抛 `BookingRejectedException`（售罄 / 仅剩 N 间 / 房型不属于该酒店 / 暂停营业），
  转成 `submit` 表单错误；未预期异常 `report($e)` 后回「下单失败，请稍后重试」，**不把 SQL 透出页面**。
- 订单粒度：**一行承载 N 间**（`room_count=N` + `room_ids` 存 JSON 全量 + `room_id` 存主房间）。
  金额与单价是**下单时快照**（`price` / `total_amount` / `nights`），房型改价不影响历史单。
- `bookings` **不建外键**：沿用 `room_types.hotel_id` / `rooms.room_type_id` 的「无 FK + 控制器级联
  保护」风格，否则 MySQL 上删酒店会被 FK 拦住，与 `HotelController::destroy` 的手动保护冲突。

### 7.5 渐进增强（SSR 与 Alpine 的分工）

首屏纯 SSR，JS 只做局部刷新。**关 JS 时全链路（搜索 → 详情 → 下单 → 查单 → 取消）必须仍然可用。**

| 交互 | 走哪条路 |
|---|---|
| 6 个业务 tab + 左栏服务分类 | 纯本地（同一 store 字段），不跳 URL |
| 搜索 / 城市卡 / 热门 chip / 排序 / 翻页 | 局部刷新 + `history.replaceState`（只 replace，不 push） |
| 改日期 | 局部刷新，debounce 250ms，`setCheckIn` 自动顺延 `check_out` |
| 详情页房型房量 | 首屏 SSR，改日期时局部刷新 |
| **下单提交 / 取消订单** | **SSR 表单 POST + redirect，不经 JS**；JS 只把按钮锁住（`lockSubmit`） |

- **SSR 网格与 live 网格并存**：SSR 容器 `x-show="! $store.fly.resultsDirty"`（JS 关闭时 Alpine
  不跑、属性被忽略，SSR 恒显示），live 容器 `x-show="$store.fly.resultsDirty" x-cloak`。
  详情页同理用 `detailDirty`。这是渐进增强的固有成本，两份模板要互相注释指向。
- 入口顺序是硬的：`registerFlyStore(Alpine)` → **`Alpine.store('fly').hydrate()`** →
  `Alpine.start()` → `Alpine.store('fly').init()`。hydrate 必须在 start 之前，否则会把
  SSR 已经绑好的表单值刷掉；store 的 `init()` 不会被自动调用。
- 定时器句柄与请求序号（`searchSeq` / `detailSeq`）放**模块闭包**，不要放 store state
  （会被响应式代理追踪）；序号用于丢弃迟到响应。
- URL 与 PHP 的省略规则要一致：`HotelSearchService::pageUrl()`（空值 + `page=1` 不进 URL）
  ↔ `store.buildUrl()`。日期窗口必须一路带进详情/下单链接，否则点进去房量口径跳回「今晚一晚」。
- 只有 `hotel` tab 有数据，其余 tab 是纯本地态 —— 给它们跳 URL 会造出 404/空页。

### 7.6 订单归属与脱敏

- **C 端订单不属于 Breeze 用户**：`bookings` 没有 `user_id`，凭证是 `订单号 + guest_phone`。
- 手机号不符一律 **404 而不是 403**：403 等于对外确认「这个订单号存在」。
- 查单接口/页面只返回该手机号的单，`limit(50)`（页面侧 `LIST_LIMIT` 同）防止拿别人的号爬。
- JSON 输出走 `guest_name_masked` / `guest_phone_masked`；页面展示可以全量（那是本人凭号查询）。

---

## 8. 前端模块约定（预约大屏）

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
  现有变体类：`glass--hero`（号牌主面板蓝光描边）、`stat-card--blue/teal/amber/violet`（统计色条）、
  `badge--green`、`m-hero--next` + `m-banner--next`（手机端「轮到您了」高亮）、`m-stat-card`、`qr-card`。
- `queueOptions` 由后端 Blade 注入 `body[data-reserve-config]`；改队列列表请改路由/视图层，不要写死进 JS。

### 移动端排队查询（/m，只读）

- **只读约束是硬性的**：`resources/js/mobile/api.js` 整层没有 POST/DELETE，页面不放任何取号/放号入口。
- 号码来源优先级：URL 参数 `?key=xxx&no=27`（大屏二维码扫码直达）→ localStorage（`mobile:ticket:v1`）→ 查询表单。
- 轮询：绑定号码后每 5s 查一次 `position`，失败退避同 reserve；`visibilitychange` 隐藏暂停、回来立即补一次。
- **轮询重排要放在 poll 链的最后一个 `then` 里**（成功失败都重排，失败由 schedulePoll 内部退避）；只在失败分支重排会导致首次成功后轮询停掉（reserve 模块曾踩过这个 bug，已修）。
- 大屏二维码：`reserve/store.js` 的 `updateQr()` 用 `qrcode` 包生成 dataURL，内容为 `location.origin + /m?key&no`；**目标票由 `qrTicket()` 决定：优先当前选中的「待叫号」票，否则回退本机刚取的号（currentLatest）**，选中已放号的票不生成；同一目标不重复生成（闭包 `lastQrTarget` 去重），换批/取消选中自动隐藏。

---

## 9. 构建与运行

```bash
# 前端（必须跑，Blade 用 mix()，manifest 缺 key 会抛异常；新增入口要进 manifest）
npm run dev        # 开发（watch 模式）
npm run prod       # 生产

# 后端
cp .env.example .env          # 首次
php artisan key:generate      # 首次
php artisan migrate           # 本机跑在 .env 的 DB_CONNECTION 上（当前是 mysql）
php artisan db:seed --class=FlyDemoSeeder   # 仅 local：造 /fly 演示数据
php artisan serve

# 打开 http://localhost:8000/reserve（未登录会跳 /login；首次先到 /register 注册一个账号）
#      http://127.0.0.1:8000/fly     云途 C 端（无需登录）
```

> Node 版本提示：laravel-mix 6 + webpack 5 在 Node 22 下偶发 `md4 hash` 报错，如遇可试 `NODE_OPTIONS=--openssl-legacy-provider npm run prod`。

### 环境注意

- **本机（Windows）运行时**：`php` 8.2.12，Shell 是 Git Bash；`.env` 的 `DB_CONNECTION=mysql`，
  所以 `php artisan migrate` 动的是 MySQL，测试动的是 sqlite `:memory:`（phpunit.xml 覆盖）。
  验证迁移在两个驱动都能过，就再跑一次 `DB_CONNECTION=sqlite php artisan migrate`
  （`bookings` 的部分索引有 `getDriverName() === 'sqlite'` 分支，只有这一关能验）。
- 若要用 sqlite 开发库：`DB_CONNECTION=sqlite` + `DB_DATABASE=<项目绝对路径>/database/database.sqlite`，
  且文件必须存在（`touch database/database.sqlite`）。
- 历史提示（macOS 系统自带 `/usr/bin/php` 的 `PHP_VERSION` 常量污染会让 Composer/PHPUnit 的
  `version_compare()` 报错，需用 Homebrew php）：**只在 macOS 上适用**，本机 Windows 无此问题。

---

## 10. 测试与提交

- Feature 测试放 `tests/Feature/`，命名 `XxxTest.php`，继承 `Tests\TestCase`，用 `RefreshDatabase`（sqlite `:memory:`，见 phpunit.xml，**不要改回文件库**，否则测试会清掉开发数据）。
- 写接口（取号/放号）要求登录：测试里用 `$this->actingAs(User::factory()->create())`（参考两个现有测试的 `setUp`）；测试环境自动跳过 CSRF。
- 运行：`./vendor/bin/phpunit --filter=ReserveStateTest`（`php artisan test` 亦可）。
- 现有测试参考：`tests/Feature/ReserveStateTest.php`（覆盖取号→状态→放号→换批全链路）、`tests/Feature/ReservePositionTest.php`（移动端排队查询：前面人数 / 已办结 / 换批后旧号 not_found / 只读无副作用）、`tests/Feature/ReserveAuthTest.php`（页面级鉴权：大屏跳登录、/m 与只读接口公开）。
- 云途 C 端测试（同为 `RefreshDatabase` + sqlite `:memory:`）：
  - `FlyAvailabilityTest` — 城市精确匹配、关键词命中 tags 且转义 `%_\`、日期重叠扣减、**边界相邻不冲突**（半开区间）、已取消释放 / 已入住仍占用、下架酒店页面 404 与接口 `code:1`、页码越界回落、脏日期夹紧、**`DB::listen` 钉住 5 条查询**。
  - `FlyBookingTest` — 游客可下单（无 auth）、金额/晚数/单价快照、PRG 结果页可用自己的手机号打开、超量拒绝、售罄、跨酒店 `room_type_id` 越权、日期与手机号规则中文提示、同一 `idempotency_key` 只产生 1 单。
  - `FlyOrderAccessTest` — 按手机号只列自己的单、无 phone 与「查过但没有」的区别、详情凭手机号（错号 404 不是 403）、取消是软释放且重复取消被拒、JSON 脱敏。
  - `FlyBookingAdminTest` — 后台 `/bookings` 需登录、列表/详情/取消。
  - `RoomTypeBlockTest` — 房量计划：停售只作用在停售的晚（半开区间）、多晚查询取各晚 max 不取 sum、
    停售挡住超卖但放行余量、搜索列表同样扣减、后台设置/覆盖/恢复实时反映到 C 端、越权 404 与表单校验。
- 测试里造日期用**相对日期**（`Carbon::now(config('fly.timezone'))->addDays(n)`），别硬编码
  `2026-10-25` 这种绝对日期 —— 过了那天用例会变红（`FlyBookingAdminTest` 曾踩过，已改到 `setUp` 里算）。
- 读接口断言金额时用 `min_price_text`（字符串）或 `(float)`：Laravel 序列化 `900.0` 会输出 `900`，
  `assertSame(900.0, …)` 会假失败。
- 想在测试里看表单的第一条中文错误，得在**发起下一个请求之前**取
  `$this->app['session.store']->get('errors')->getBag('default')->first($key)` —— 任何后续请求
  （包括顺手打的只读接口断言）都会把 flash 老化掉。
- 提交信息：`feat: 取号放号大屏控制台`、`fix: 放号并发下批次计算错误` 这类 `type: 主题` 格式。

---

## 11. 常见坑速查（完整清单）

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
| 接口 500 报 `unexpected '=>'` | 早期本机运行时是 PHP 7.3，`fn()=>` 箭头函数会 ParseError（本机现已是 8.2.12） | 沿用传统闭包 `function ($x) { return ...; }`，与既有代码一致 |
| 手机扫码打不开页面 | `php artisan serve` 默认只绑 127.0.0.1 | 启动加 `--host=0.0.0.0`；大屏也用局域网 IP 打开（二维码取 location.origin） |
| 列表点选后高亮错乱 | `x-for` 里又开了 `x-data` | 改用 store `selectedId` |
| 接口在浏览器 401 | 只读接口正常但取号/放号 401 | 未登录或 session 过期，前端会自动跳 `/login`；注意写接口故意放在 `web.php`（见 §5 警告），别挪回 `api.php` |
| 写接口挪回 api.php 后永远 401 | 叠 `['web','auth']` 触发中间件优先级排序：子类 `EncryptCookies` 不在 `$middlewarePriority`，StartSession 先于解密执行 | 保持写接口在 `web.php`；勿叠 web 组到 api 路由 |
| 大屏突然跳登录页 | session/CSRF 过期（419）或未登录（401），`reserve/api.js` 自动跳转 | 重新登录即可；长挂大屏可调大 `.env` 的 `SESSION_LIFETIME` |
| PHP 命令直接报 `invalid PHP_VERSION` | 用了 macOS 系统自带 php | 切到 brew php（仅 macOS） |
| 测试全绿但线上 500 / 迁移只在某个库能过 | 本机 `.env` 是 mysql、phpunit 强制 sqlite `:memory:`，而 SQL 里用了方言专有语法（`strftime` / `DATEDIFF` / `IFNULL` / 部分索引） | 只用 `COUNT/SUM/COALESCE/MAX` + 字符串比较；方言差异按 `getDriverName()` 分支，两个驱动各跑一次 `migrate` |
| 日期索引像没生效 / 同一条查询 mysql 与 sqlite 结果不一致 | 用了 `whereDate()`（sqlite 编译成 `date(col)=date(?)`，函数包裹列废索引；MySQL 上 date 与 datetime 语义也不同） | 全项目禁用 `whereDate()`，改纯 `Y-m-d` 字符串区间比较，值由修改器归一 |
| 「今天」少一天 / 默认入住日不对 | 全局 `app.timezone` 是 UTC，裸用 `now()` | C 端一律 `Carbon::now(config('fly.timezone'))`；**不要改全局时区**（会挪走 reserve/customers 的既有展示） |
| 房量看着不对：相邻两单抢同一间 | 把 `[check_in, check_out)` 当成闭区间，或自己 +1 天 | 半开区间是约定：入住当天算、离店当天不算；只用 `Booking::scopeOverlapping()` |
| 明明有酒店却显示「没找到酒店」 | 换筛选条件后停在旧页码，`currentPage > lastPage` | `HotelSearchService::search()` 已回落最后一页；改分页逻辑时保留这段 |
| 列表条数与「共 N 家」对不上 | 把可售过滤从 SQL 挪到 PHP 里 filter | 可售过滤必须留在 SQL 的 `whereExists`（否则 `paginate` 的 total 与实际不一致） |
| SSR 与局部刷新的底色/文案不一致 | `grad_index` 在前端自己算，或两份模板各改一处 | `grad_index` 由 `formatCard()` 下发；`hotel-card` ↔ `hotel-card-live`、`room-row` ↔ `room-row-live` 成对修改 |
| Alpine 刷新后表单值/配色残留 | `:class` 用字符串语法只加不摘，元素上还留着静态修饰类 | 动态类全交给 store 方法（`roomRemainClass` / `gradClass`），元素上不写死 |
| 改完 Blade/JS 页面 500 报 manifest 缺 key | 只改了 `resources/js/fly/` 没 `npm run prod` | 重跑构建，确认 `public/mix-manifest.json` 里有 `/js/fly/app.js` |
| `/fly` 下单双击出两单 | 没带 `idempotency_key`，或把它从表单里摘掉了 | 每次渲染生成新 key；唯一索引命中后按 key 回查当成功（`catch (QueryException)` 分支） |
| 登录后落点对不上 | `RouteServiceProvider::HOME` 被改成了 `'/'`（Laravel 原版欢迎页）或旧值 | 落地页是 `'/fly'`；`ReserveAuthTest` 同时守「直接登录去 /fly」与「被 /reserve 拦下者登录后回大屏」 |
| 停掉 JS 后某个交互失灵 | 把写操作或唯一数据源交给了 Alpine | 写操作本来就走 SSR 表单；用 `curl` 无 JS 跑一遍全链路再收工 |
| 用 curl 试下单，中文姓名报 MySQL 1366 | Git Bash 的 argv 是 GBK 编码，`-d "名称=中文"` 会送出非法 UTF-8（**是客户端问题，不是应用 bug**） | 用 `--data-urlencode "字段@utf8文件"`；浏览器路径不受影响 |
| 验证写路径时以为「报错没提示」 | 探针构造得不合法，压根没进到业务拒绝分支 | 用超量 `room_count` 触发 `BookingRejectedException`，能看到 `fly-flash--err` 中文提示 |
| 搜索栏日期框「框中框」并溢出圆角、按钮文字竖排成一列 | ① `@tailwindcss/forms` 给 input 上了独立盒子；② `1fr` 轨的自动最小宽度 = 内容 min-content（input ~180px），轨道塞不下时最窄的那条（`auto` 的按钮）先被压扁 | 见 §4「样式（`fly-*` 组件）」三条：清盒子外观 / 轨道写 `minmax(0,…)` / 按钮 `whitespace-nowrap`；日期格靠 `flex-wrap` + `min-h` 长高而不是溢出 |
| 桌面端布局改完只在窄屏看过（以为没问题） | Qoder 内置浏览器面板宽度有限，且不可见时 `take_screenshot` 直接报 `NATIVE_BROWSER_VIEWPORT_UNAVAILABLE` | 用 `msedge --headless --screenshot --window-size=1440,900 <url>` 逐尺寸截图（**每个尺寸换一个新的 `--user-data-dir`**，复用会静默不出图），390 / 640 / 820 / 1200 / 1440 各一张，再加 `--blink-settings=scriptsEnabled=false` 验一遍 JS 关闭态 |

---

*最后更新：2026-10-26（新增房量计划：`room_type_blocks` 按晚停售表 + `RoomInventoryController` 后台页面，库存口径扩为「可订 = 非停用房间数 − 重叠有效订单 − 各晚停售 max」，搜索列表恒定查询 5 → 6 条，见 §7.2；此前为 2026-10-22 的云途 C 端接入与 §4 样式订正）*
