# /fly 订单改为登录归属：强制登录下单 + 下线手机号查单

## Summary

当前 `/fly/orders` 与 `/api/fly/bookings` 只凭手机号即可列出订单，任何人输入别人的手机号就能看到其订单（越权）。经与用户确认，采用**最强方案**：

1. **强制登录下单**：`/fly` 下单页、下单写接口、查单、取消全部要求 Breeze 登录；`bookings` 表新增 `user_id`，下单时绑定当前登录用户。
2. **登录用户直达「我的订单」**：`/fly/orders` 不再要手机号，直接列出当前用户的订单；详情/取消校验 `user_id` 归属，不符一律 404。
3. **下线 `GET /api/fly/bookings`**：前端 `resources/js/fly/api.js` 只调 hotels/room-types，该接口无人使用，连路由带控制器方法一并删除。
4. 历史订单（`user_id` 为 NULL）C 端不再可见，后台 `/bookings` 照常管理（见「假设与决策」）。

## Current State Analysis

- `bookings` 表无 `user_id`（[2026_10_21_000002_create_bookings_table.php](file:///d:/project/reserve/database/migrations/2026_10_21_000002_create_bookings_table.php#L25-L26) 注释明确「C 端顾客不登录，无 user_id」）；`User` 模型只有 name/email，无手机号字段。
- 查单三处只靠手机号：[FlyOrderController.php](file:///d:/project/reserve/app/Http/Controllers/FlyOrderController.php)（index 列表 / show / cancel 均校验 `guest_phone`）、[api.php](file:///d:/project/reserve/routes/api.php#L39) 的 `/fly/bookings`（[FlyReadController.php](file:///d:/project/reserve/app/Http/Controllers/Api/FlyReadController.php#L166-L223) 的 `bookings()` + `formatBooking()`）。
- 下单时 [FlyBookingController.php](file:///d:/project/reserve/app/Http/Controllers/FlyBookingController.php#L182-L200) 不写任何用户归属；`resultUrl()` 把手机号拼进结果页 URL（L244）。
- 受影响视图：`fly/orders.blade.php`（手机号表单）、`fly/order.blade.php`（hidden phone + 凭证文案）、`fly/book.blade.php`（L152-154 「不绑定登录账号」文案）、`fly/layout.blade.php`（头部注释）、后台 `bookings/show.blade.php`（L124-125「以顾客视角查这个手机号」链接将失效）。
- 受影响测试：`FlyBookingTest`（全程游客）、`FlyOrderAccessTest`（手机号凭证全套断言）、`FlyRateLimitTest`（无 auth 探针 + JSON 查单限流用例）、`FlyAvailabilityTest`（`test_read_endpoints_and_pages_are_public`、下架酒店 book 页 404 用例）。
- 其他引用：`FlyDemoSeeder`（打印「查单演示手机号」）、`FlyHomeController.php` L32 注释、`RouteServiceProvider` fly 限流注释、`README.md` API 表、`AGENTS.md` §1/§3/§5/§7.3/§7.6/§10。
- 中间件顺序：Laravel 默认 `$middlewarePriority` 中 auth 先于 throttle，叠 `auth` 后游客直接被 302 到 `/login`，不消耗限流桶；登录后 `intended()` 会送回下单页（GET 带 query string 保留）。
- 双驱动约束：`addColumn` 在 sqlite 可用；`dropColumn` 在 sqlite 需 doctrine/dbal，迁移 `down()` 需按驱动分支。

## Proposed Changes

### 1. 迁移：bookings 加 user_id

新建 `database/migrations/2026_11_12_000001_add_user_id_to_bookings_table.php`：

- `up()`：`$table->unsignedBigInteger('user_id')->nullable()->after('id')->comment('下单登录账号；历史单为 NULL')`；`$table->index('user_id', 'bookings_user_idx')`。
  - 不建外键（AGENTS.md §7.4：bookings 沿用「无 FK + 控制器级联保护」）。
  - `nullable`：历史单没有归属；`after('id')` 仅 MySQL 生效，sqlite 语法层自动忽略。
- `down()`：按 `Schema::getConnection()->getDriverName()` 分支——mysql 先 `dropIndex('bookings_user_idx')` 再 `dropColumn('user_id')`；sqlite 不 dropColumn（Laravel 8 需 doctrine/dbal，项目未装），留注释说明回滚只支持 MySQL。

### 2. [Booking.php](file:///d:/project/reserve/app/Models/Booking.php)

- `$fillable` 增加 `'user_id'`；`$casts` 增加 `'user_id' => 'integer'`。
- 增加 `user()` belongsTo 关系（后台订单详情将来展示下单账号用）。
- 更新 `guest_phone` 相关注释语义：手机号只是入住人联系方式，不再是查单凭证。

### 3. 路由

[routes/web.php](file:///d:/project/reserve/routes/web.php#L31-L47)：给 5 条路由各叠 `->middleware('auth')`（保留原有 throttle）：

- `fly.book`（GET 下单页）、`fly.bookings.store`（POST 下单）
- `fly.orders`（GET 列表）、`fly.orders.show`（GET 详情）、`fly.orders.cancel`（POST 取消）

注释改写为「C 端订单属于登录用户；游客点预订/我的订单会被 guest 中间件送去 /login，登录后由 intended() 送回原目标」。

[routes/api.php](file:///d:/project/reserve/routes/api.php#L37-L39)：删除 `/fly/bookings` 路由及其注释，顶部注释「这 4 个 URI」改「这 3 个 URI」。

### 4. [FlyReadController.php](file:///d:/project/reserve/app/Http/Controllers/Api/FlyReadController.php)

删除 `bookings()` 方法与私有 `formatBooking()`（全项目无其他调用方，已 grep 确认）；类注释「4 个只读接口」改 3 个。

### 5. [FlyBookingController.php](file:///d:/project/reserve/app/Http/Controllers/FlyBookingController.php)

- `store()`：`$input = $validator->validated();` 之后补 `$input['user_id'] = $request->user()->id;`（路由已叠 auth，user() 必非空）。
- `createBooking()`：`Booking::create([...])` 载荷加 `'user_id' => $input['user_id']`。
- `resultUrl()`：去掉 `?phone=` 拼接，只返回 `route('fly.orders.show', $booking)`。
- 类注释「C 端顾客不登录」改为「下单/查单需登录，订单归属 user_id」。

### 6. [FlyOrderController.php](file:///d:/project/reserve/app/Http/Controllers/FlyOrderController.php)

整体改为登录归属：

- `index()`：去掉 phone 读取/校验/表单错误逻辑；直接 `Booking::where('user_id', $request->user()->id)->with(['hotel:id,name,city,status', 'roomType:id,name'])->orderByDesc('id')->limit(self::LIST_LIMIT)->get()`；视图变量只传 `bookings`。
- `show()`：归属校验 `if ((int) $booking->user_id !== (int) $request->user()->id) { abort(404); }`（沿用「404 而不是 403」原则，不对外确认订单存在）。不再读 `phone`。
- `cancel()`：同样的归属校验；`$target` 不再拼 `?phone=`；条件 UPDATE 软取消逻辑原样保留。
- 类注释重写：凭证是登录态 + user_id 归属，手机号仅是入住人联系方式。

### 7. 视图

**[orders.blade.php](file:///d:/project/reserve/resources/views/fly/orders.blade.php)**（重写主体）：

- 删除手机号查询表单、`$phone`/`$errors` 引用、三态注释。
- 进入页面直接渲染：`count($bookings) === 0` → 空态（「你还没有订单」+「去挑一家酒店」）；否则订单列表（行结构不变，「查看详情」链接不再带 `?phone=`，副标题改「共 N 单」去掉手机号回显）。
- 底部 promise 三条文案改为登录体系口径（如「订单绑定登录账号」「取消后房量即时释放」「入住人信息仅用于酒店联系」）。

**[order.blade.php](file:///d:/project/reserve/resources/views/fly/order.blade.php)**：

- 删除取消表单里的 `<input type="hidden" name="phone">` 及其注释（L110-111）。
- L125-127 「请收藏本页链接，或记住订单号 + 手机号」改为「本单已绑定当前登录账号，可在『我的订单』随时查看」。
- 头部注释「凭证是手机号」改为「归属是当前登录用户」。

**[book.blade.php](file:///d:/project/reserve/resources/views/fly/book.blade.php)**：

- L117 入住人姓名预填：`value="{{ old('guest_name', Auth::user()->name) }}"`。
- L125 手机号 placeholder「用于查单，请牢记」改「用于酒店联系」。
- L152-154 文案改为「订单将绑定到当前登录账号，下单后可在『我的订单』查看与取消；入住人手机号仅用于酒店联系。」

**[layout.blade.php](file:///d:/project/reserve/resources/views/fly/layout.blade.php)**：头部注释（L4-6）改为「订单属于登录用户（bookings.user_id），游客点『我的订单』会被送去登录」。顶栏与页脚的「我的订单」链接保持 `/fly/orders` 不变（游客点击 → /login → intended 回来，行为正确）。

**[bookings/show.blade.php](file:///d:/project/reserve/resources/views/bookings/show.blade.php#L124-L125)**：删除「以顾客视角查这个手机号」链接（查单页已不按手机号工作，登录后台账号点过去只会看到自己的单）。

### 8. 杂项注释与种子

- [FlyHomeController.php](file:///d:/project/reserve/app/Http/Controllers/FlyHomeController.php#L32-L33)：注释里 `/api/fly/bookings` 的提及删掉。
- [RouteServiceProvider.php](file:///d:/project/reserve/app/Providers/RouteServiceProvider.php#L70-L72)：fly 限流注释改为「下单/取消/查单页均需登录；限流兜底防脚本批量占房与暴力探测订单 id」。
- [FlyDemoSeeder.php](file:///d:/project/reserve/database/seeders/FlyDemoSeeder.php)：演示订单 `Booking::create` 载荷加 `'user_id' => User::query()->value('id')`（无用户则为 null，演示单只影响房量展示）；结尾打印改为「演示订单已尽量绑定到首个注册用户；查单请登录后访问 /fly/orders」。更新 `DEMO_PHONE` 注释（仅作入住人电话）。

### 9. 测试改造

**[FlyBookingTest.php](file:///d:/project/reserve/tests/Feature/FlyBookingTest.php)**：

- `setUp()` 末尾 `$this->actingAs(User::factory()->create());`（import `App\Models\User`）。
- `test_guest_can_book_without_login_and_price_is_snapshotted` 改名 `test_logged_in_user_can_book_and_price_is_snapshotted`：断言 `$booking->user_id === 当前用户 id`；PRG Location 不含 `phone=`；`$this->get(Location)` 直接 200（不带任何凭证）。
- 渲染两个用例保持，但 `test_book_page_is_public_and_prefills_the_date_window` 改名 `test_book_page_prefills_the_date_window`（已有 actingAs，无需再改请求）。
- 新增 `test_guest_is_redirected_to_login`：退出登录态（`$this->app['auth']->forgetGuards()` 或新开不带 actingAs 的子用例类里直接不登录——本项目风格是直接写：`$this->get('/fly/hotels/{id}/book')->assertRedirect('/login')`、`$this->post('/fly/bookings', payload)->assertRedirect('/login')`、`$this->get('/fly/orders')->assertRedirect('/login')`，放在本文件，需先把 actingAs 解除：用 `auth()->logout()`。）

**[FlyOrderAccessTest.php](file:///d:/project/reserve/tests/Feature/FlyOrderAccessTest.php)**（重写）：

- `setUp()` 造两个用户 `$this->me / $this->other`；`makeBooking()` 默认绑 `$this->me->id`（phone 字段仍填，只是入住人电话）。
- 列表：`actingAs($this->me)` 访问 `/fly/orders` 看到自己的单、看不到 `$this->other` 的单；游客 `assertRedirect('/login')`；下架酒店的单仍列出。
- 详情：owner 200；`actingAs($this->other)` 404；`user_id` 为 NULL 的历史单任何人 404。
- 取消：owner 取消成功 + 房量释放 + 行不删（沿用现有剩余间数复核 `remaining()`）；非 owner 取消 404 且状态不变；重复取消与「已入住不可取消」两个用例保留（请求不再带 phone）。
- 删除 `test_api_bookings_are_masked_and_public`（接口已下线）。

**[FlyRateLimitTest.php](file:///d:/project/reserve/tests/Feature/FlyRateLimitTest.php)**：

- 每个用例开头 `$this->actingAs(User::factory()->create());`（否则探针在 auth 层就被 302，打不到 throttle）。
- 删除 `test_order_lookup_json_is_throttled`（路由已不存在）。
- 类注释更新（不再提「顾客全程不登录」）。

**[FlyAvailabilityTest.php](file:///d:/project/reserve/tests/Feature/FlyAvailabilityTest.php)**：

- `test_read_endpoints_and_pages_are_public`：删 `/api/fly/bookings` 的 200 断言，改为 `getJson('/api/fly/bookings?phone=...')->assertNotFound()`（钉住「路由已下线」）；`/fly/orders` 游客断言从 `assertOk()` 改 `assertRedirect('/login')`；`/fly`、酒店详情页保持公开断言。
- 下架酒店用例（L197）的 book 页 404 断言前补 actingAs（否则先撞 auth 重定向而不是酒店 404）。该文件其余房量用例不涉及登录。

### 10. 文档同步

- [README.md](file:///d:/project/reserve/README.md#L131-L133)：删除 `/api/fly/bookings` 行，改写下单/取消说明为「需登录」。
- [AGENTS.md](file:///d:/project/reserve/AGENTS.md)：
  - §1：删「整个 /fly C 端公开（顾客不登录，订单凭证是 订单号 + 手机号）」→ 改「/fly 浏览公开，下单/查单/取消需登录，订单归属 bookings.user_id」；「非目标」里删「不做登录态会员体系」。
  - §3：`FlyReadController` 注释「4 个只读 JSON 接口」改 3 个；`FlyOrderController` 注释改「登录用户的订单列表/详情/取消」。
  - §5：`/api/fly/*` 段删 bookings 行、标题改 3 条；删「data.items 脱敏」那条（随接口删除）。
  - §7.3：路由表加 `auth` 标注（fly.book / fly.bookings.store / fly.orders*）；鉴权边界表「C 端页面 + 写操作」行改为「浏览公开，下单/订单需登录」；删「登录的后台账号在 C 端本来就不该看到订单」。
  - §7.6 重写：订单归属 = user_id；入住人手机号只是联系方式；详情/取消校验归属、不符 404；历史 NULL 单仅后台可见。
  - §10：`FlyBookingTest` / `FlyOrderAccessTest` / `FlyRateLimitTest` 的描述同步更新。
  - 末尾「最后更新」改为 2026-11-12 并写明本次变更。

## Assumptions & Decisions

1. **历史订单（user_id = NULL）C 端不可见**：不做数据回填（无法可靠推断归属账号），后台 `/bookings` 仍可查看与取消。若用户需要给历史单补归属，另行决定。
2. **入住人姓名/手机号字段保留**：登录账号 ≠ 入住人，表单仍收集 `guest_name`/`guest_phone`（手机号 regex 校验不变），但仅作酒店联系用途，不再是任何凭证。
3. **注册即会员**：沿用 Breeze 邮箱注册，不加手机号注册/短信验证（无短信通道，超出本次范围）。
4. **throttle:fly-query 保留**在 orders 两条路由上（auth 后仍兜底防暴力遍历订单 id）；fly-write 保留。
5. **不改动** `/api/fly/cities|hotels|room-types` 与 `/fly` 首页/详情页的公开属性；后台 `/bookings*` 不变。
6. 迁移 `down()` 在 sqlite 上不回滚列（Laravel 8 限制），注释写明。

## Verification

1. `./vendor/bin/phpunit` 全量绿（重点：FlyBookingTest / FlyOrderAccessTest / FlyRateLimitTest / FlyAvailabilityTest / ReserveAuthTest）。
2. 迁移双驱动验证：本机 `php artisan migrate`（MySQL）+ `DB_CONNECTION=sqlite php artisan migrate`（sqlite，需 database/database.sqlite 存在）。
3. 无 JS 链路手测（curl 或浏览器）：
   - 游客访问 `/fly/hotels/{id}/book`、`/fly/orders` → 302 到 `/login`；登录后 intended 回下单页。
   - 注册用户 A 下单 → 结果页直接打开（URL 无 phone）→ `/fly/orders` 列出该单 → 取消成功、房量释放。
   - 注册用户 B 访问 A 的 `/fly/orders/{id}` 与取消 → 404。
   - `GET /api/fly/bookings?phone=...` → 404。
4. `npm run prod` 不需要跑（本次无 JS/CSS 变更）。
