# model:field 命令使用文档

模型字段变动一键迁移：一条命令完成「生成迁移文件 → 同步修改 Model → 执行 migrate」三步。

## 命令签名

```bash
php artisan model:field {model} {action} {field} [选项]
```

| 参数 | 说明 |
|---|---|
| `model` | 模型类名，不含命名空间，如 `Hotel`（对应 `app/Models/Hotel.php`） |
| `action` | `add` 新增字段 / `drop` 删除字段 |
| `field` | 字段定义：`name` 或 `name:type` 或 `name:type:参数`，类型缺省为 `string` |

| 选项 | 说明 |
|---|---|
| `--nullable` | 字段允许 NULL |
| `--default=值` | 默认值。数字原样写入；`true`/`false` 按布尔处理；其余按字符串 |
| `--comment="注释"` | 字段中文注释，同时写入迁移和 Model 的 `$fillable` 行内注释 |
| `--after=字段名` | 新字段放在指定字段之后（MySQL 有效，SQLite 忽略） |
| `--no-migrate` | 只生成迁移文件并改 Model，不自动执行 migrate |

## 命令做了什么

1. **安全检查**：新增时字段已存在、删除时字段不存在，都会直接报错退出。
2. **生成迁移文件**：`database/migrations/YYYY_MM_DD_His_add_xxx_to_xxx_table.php`，
   含完整的 `up()` / `down()`（drop 的 down 会按指定类型恢复字段，可安全 rollback）。
3. **同步 Model**：
   - `$fillable` 中加入/移除该字段，注释自动与现有条目对齐；
   - 整型家族（`integer`/`bigInteger` 等）与 `boolean` 会自动写入/移除 `$casts`，
     其余类型不加 cast，需要时请手动调整；
   - 模型还没有 `$fillable` 时会在类开头自动创建一个。
4. **执行迁移**：自动调用 `php artisan migrate`（加 `--force`），`--no-migrate` 可跳过。

## 使用示例

```bash
# 1. 给 Hotel 加一个字符串字段（最简形式，类型默认 string）
php artisan model:field Hotel add slogan --comment="宣传语"

# 2. 加整型字段，带默认值，自动写入 $casts（'priority' => 'integer'）
php artisan model:field Hotel add priority:integer --default=0 --comment="排序权重"

# 3. 加 decimal 金额字段，参数按「精度,标度」跟在第二个冒号后
php artisan model:field Hotel add base_price:decimal:8,2 --default=0 --comment="基础房价"

# 4. 加可空文本字段，并指定列位置
php artisan model:field Hotel add intro:text --nullable --after=remark --comment="酒店简介"

# 5. 删除字段（:string 用于声明 down() 回滚恢复时的类型，缺省 string）
php artisan model:field Hotel drop slogan:string

# 6. 只生成文件和改 Model，迁移稍后手动执行
php artisan model:field Hotel add slogan --no-migrate
php artisan migrate
```

实际效果示例（示例 1 执行后）：

```php
// database/migrations/2026_10_12_071922_add_slogan_to_hotels_table.php
public function up()
{
    Schema::table('hotels', function (Blueprint $table)
    {
        $table->string('slogan')->comment('宣传语');
    });
}

// app/Models/Hotel.php 的 $fillable 末尾自动追加（注释列对齐）
'slogan',     // 宣传语
```

## 支持的字段类型

`string`、`char`、`text`、`mediumText`、`longText`、`integer`、`bigInteger`、`smallInteger`、
`tinyInteger`、`unsignedInteger`、`unsignedBigInteger`、`boolean`、`float`、`double`、
`decimal`、`date`、`datetime`、`timestamp`、`time`、`json`、`uuid`。

## 注意事项与回滚

- 命令依赖模型实例的 `getTable()` 推导表名，自定义了 `$table` 属性的模型同样支持。
- Model 编辑基于数组属性的文本匹配：`$fillable`/`$casts` 使用项目通行的多行数组格式
  效果最佳；单行数组（`['a','b']`）也能处理。
- 删除字段后想反悔：直接 `php artisan migrate:rollback --step=1`，down() 会按
  `drop` 时声明的类型把字段加回来（恢复为 nullable，数据不可恢复）。
- 命令不会改动已有迁移历史，每次变动生成独立的新迁移文件，符合增量迁移约定。
- 字段名要求小写字母开头的 snake_case，不合法会直接报错。
