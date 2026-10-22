<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 模型字段变动一键迁移命令
 *
 * 用法：
 *   php artisan model:field Hotel add slogan --comment="宣传语"
 *   php artisan model:field Hotel add price:decimal:8,2 --default=0
 *   php artisan model:field Hotel drop slogan:string
 *
 * 完成三件事：
 *   1. 在 database/migrations 生成迁移文件
 *   2. 同步修改 Model 的 $fillable（必要时含 $casts）
 *   3. 自动执行 php artisan migrate（--no-migrate 可跳过）
 *
 * 注意：保持 PHP 7.3 兼容，禁止箭头函数等 7.4+ 语法。
 */
class ModelFieldCommand extends Command
{
    protected $signature = 'model:field
        {model : 模型类名（不含命名空间，如 Hotel）}
        {action : 操作类型：add 新增字段 / drop 删除字段}
        {field : 字段定义，格式 name 或 name:type 或 name:type:参数（如 price:decimal:8,2），缺省类型为 string}
        {--nullable : 字段允许 NULL}
        {--default= : 字段默认值（数字原样写入，true/false 按布尔处理，其余按字符串）}
        {--comment= : 字段中文注释（写入迁移与 Model 的 $fillable 注释）}
        {--after= : 新字段放在指定字段之后}
        {--no-migrate : 只生成迁移文件并改模型，不执行 migrate}';

    protected $description = '模型字段变动一键迁移：生成迁移文件 + 同步 Model 的 $fillable/$casts + 自动 migrate';

    /** 允许使用的 Blueprint 字段类型白名单 */
    protected $allowedTypes = [
        'string', 'char', 'text', 'mediumText', 'longText',
        'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
        'unsignedInteger', 'unsignedBigInteger',
        'boolean', 'float', 'double', 'decimal',
        'date', 'datetime', 'timestamp', 'time',
        'json', 'uuid',
    ];

    /** 自动写入 $casts 的类型映射（其余类型不自动加 cast） */
    protected $castMap = [
        'integer' => 'integer', 'bigInteger' => 'integer',
        'smallInteger' => 'integer', 'tinyInteger' => 'integer',
        'unsignedInteger' => 'integer', 'unsignedBigInteger' => 'integer',
        'boolean' => 'boolean',
    ];

    public function handle()
    {
        $modelName = Str::studly($this->argument('model'));
        $action = strtolower($this->argument('action'));

        if (!in_array($action, ['add', 'drop'])) {
            $this->error('action 只支持 add 或 drop');
            return 1;
        }

        // 解析字段定义 name[:type[:params]]
        $parts = explode(':', $this->argument('field'));
        $name = $parts[0];
        $type = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : 'string';
        $params = isset($parts[2]) && $parts[2] !== '' ? $parts[2] : null;

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            $this->error("字段名 [{$name}] 不合法：需小写字母开头的 snake_case");
            return 1;
        }
        if (!in_array($type, $this->allowedTypes)) {
            $this->error("不支持的字段类型 [{$type}]，允许：" . implode(', ', $this->allowedTypes));
            return 1;
        }

        // 定位模型与表名
        $modelPath = app_path("Models/{$modelName}.php");
        $modelClass = "App\\Models\\{$modelName}";
        if (!file_exists($modelPath) || !class_exists($modelClass)) {
            $this->error("模型 {$modelClass} 不存在（预期文件：{$modelPath}）");
            return 1;
        }
        $table = (new $modelClass)->getTable();

        // 数据库现状安全检查
        $exists = Schema::hasColumn($table, $name);
        if ($action === 'add' && $exists) {
            $this->error("表 [{$table}] 已存在字段 [{$name}]，无需新增");
            return 1;
        }
        if ($action === 'drop' && !$exists) {
            $this->error("表 [{$table}] 不存在字段 [{$name}]，无法删除");
            return 1;
        }

        // 1. 生成迁移文件
        $migrationFile = $this->makeMigration($table, $action, $name, $type, $params);
        if ($migrationFile === null) {
            return 1;
        }
        $this->info('迁移文件已生成：' . $migrationFile);

        // 2. 同步修改 Model
        $this->updateModel($modelPath, $action, $name, $type);
        $this->info("Model 已更新：{$modelPath}");

        // 3. 执行迁移
        if ($this->option('no-migrate')) {
            $this->warn('已跳过 migrate（--no-migrate），请稍后手动执行 php artisan migrate');
            return 0;
        }
        $this->call('migrate', ['--force' => true]);

        $this->info('完成：' . ($action === 'add'
            ? "字段 [{$name}] 已加入 [{$table}] 表"
            : "字段 [{$name}] 已从 [{$table}] 表删除"));
        return 0;
    }

    /**
     * 生成迁移文件，返回文件路径；类名冲突时返回 null
     */
    protected function makeMigration($table, $action, $name, $type, $params)
    {
        $studlyName = Str::studly($name);
        $studlyTable = Str::studly($table);
        $class = $action === 'add'
            ? "Add{$studlyName}To{$studlyTable}Table"
            : "Drop{$studlyName}From{$studlyTable}Table";
        $snake = $action === 'add'
            ? "add_{$name}_to_{$table}_table"
            : "drop_{$name}_from_{$table}_table";

        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (strpos(file_get_contents($file), "class {$class} ") !== false) {
                $this->error("迁移类 {$class} 已存在于：" . basename($file));
                return null;
            }
        }

        $upLine = $action === 'add'
            ? $this->buildColumnLine($name, $type, $params)
            : "\$table->dropColumn('{$name}');";
        $downLine = $action === 'add'
            ? "\$table->dropColumn('{$name}');"
            : $this->buildColumnLine($name, $type, $params, true);

        $content = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class {$class} extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('{$table}', function (Blueprint \$table)
        {
            {$upLine}
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('{$table}', function (Blueprint \$table)
        {
            {$downLine}
        });
    }
}

PHP;

        $path = database_path('migrations/' . date('Y_m_d_His') . "_{$snake}.php");
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * 组装 Blueprint 列定义语句，如：
     *   $table->decimal('price', 8, 2)->default(0)->comment('价格');
     * $forRestore 为 drop 迁移的 down() 回滚恢复用，只补 nullable
     */
    protected function buildColumnLine($name, $type, $params, $forRestore = false)
    {
        $args = "'{$name}'";
        if ($params !== null) {
            $args .= ', ' . implode(', ', array_map('trim', explode(',', $params)));
        }
        $line = "\$table->{$type}({$args})";

        if ($forRestore) {
            return $line . '->nullable();';
        }

        if ($this->option('nullable')) {
            $line .= '->nullable()';
        }
        $default = $this->option('default');
        if ($default !== null) {
            if (is_numeric($default)) {
                $line .= "->default({$default})";
            } elseif (in_array(strtolower($default), ['true', 'false'])) {
                $line .= '->default(' . strtolower($default) . ')';
            } else {
                $line .= "->default('" . str_replace("'", "\'", $default) . "')";
            }
        }
        $comment = $this->option('comment');
        if ($comment !== null && $comment !== '') {
            $line .= "->comment('" . str_replace("'", "\'", $comment) . "')";
        }
        $after = $this->option('after');
        if ($after) {
            $line .= "->after('{$after}')";
        }
        return $line . ';';
    }

    /**
     * 同步修改 Model 文件的 $fillable（并按需维护 $casts）
     */
    protected function updateModel($modelPath, $action, $name, $type)
    {
        $code = file_get_contents($modelPath);
        $comment = $this->option('comment');

        if (preg_match('/\$fillable\s*=\s*\[[^\]]*\];/s', $code)) {
            $code = $this->editArrayProperty($code, '$fillable', $action, $name, $comment);
        } elseif ($action === 'add') {
            // 模型还没有 $fillable：在类开头插入一个
            $entry = "'{$name}',";
            if ($comment) {
                $entry .= ' // ' . $comment;
            }
            $block = "\n    protected \$fillable = [\n        {$entry}\n    ];\n";
            $code = preg_replace('/(class\s+\w+\s+extends\s+\w+\s*\n\{)/', "$1{$block}", $code, 1);
        }

        $cast = isset($this->castMap[$type]) ? $this->castMap[$type] : null;
        $hasCasts = (bool) preg_match('/\$casts\s*=\s*\[[^\]]*\];/s', $code);
        if ($hasCasts) {
            if ($action === 'add' && $cast !== null) {
                $code = $this->editArrayProperty($code, '$casts', 'add', $name, null, $cast);
            } elseif ($action === 'drop') {
                $code = $this->editArrayProperty($code, '$casts', 'drop', $name);
            }
        }

        file_put_contents($modelPath, $code);
    }

    /**
     * 在 Model 的数组属性（$fillable / $casts）中加入或移除一个条目。
     * 适配项目通行的多行数组格式，注释与 => 自动对齐；单行数组也能处理。
     */
    protected function editArrayProperty($code, $property, $action, $name, $comment = null, $cast = null)
    {
        $prop = preg_quote($property, '/');

        return preg_replace_callback(
            '/(' . $prop . '\s*=\s*\[)([^\]]*)(\];)/s',
            function ($m) use ($action, $name, $comment, $cast) {
                $inner = $m[2];

                if ($action === 'drop') {
                    // 多行：删掉整行；单行：删掉 'name', 片段
                    $inner = preg_replace("/\n[ \t]*'{$name}'[ \t]*=>[^\n]*/", '', $inner);
                    $inner = preg_replace("/\n[ \t]*'{$name}'[ \t]*,[^\n]*/", '', $inner);
                    $inner = preg_replace("/'{$name}'[ \t]*=>[ \t]*'[^']*'[ \t]*,[ \t]*/", '', $inner);
                    $inner = preg_replace("/'{$name}'[ \t]*,[ \t]*/", '', $inner);
                    return $m[1] . $inner . $m[3];
                }

                // add：已存在则不动
                if (strpos($inner, "'{$name}'") !== false) {
                    return $m[0];
                }

                if ($cast !== null) {
                    $token = "'{$name}' => '{$cast}',";
                } else {
                    $token = "'{$name}',";
                }

                if (strpos($inner, "\n") === false) {
                    return $m[1] . rtrim($inner) . (trim($inner) === '' ? '' : ' ') . $token . ' ' . $m[3];
                }

                // 多行格式：沿用现有条目的缩进，注释 / => 对齐到现有最宽处
                preg_match_all("/\n([ \t]*)'[^']+'\s*(=>)?\s*'?[^',]*'?\s*,/", $inner, $mm);
                $indent = end($mm[1]);
                if ($indent === false || $indent === '') {
                    $indent = '        ';
                }

                if ($cast !== null) {
                    // 键名对齐到现有最宽处，保持 'key' => 'cast', 一列对齐
                    $keyWidth = 0;
                    preg_match_all("/'[^']+'\s*=>/", $inner, $km);
                    foreach ($km[0] as $t) {
                        $keyWidth = max($keyWidth, strlen($t) - 3); // 去掉 ' =>'
                    }
                    $line = $indent . str_pad("'{$name}'", $keyWidth) . " => '{$cast}',";
                    if ($comment) {
                        $line .= ' // ' . $comment;
                    }
                    $line = rtrim($line);
                } else {
                    $width = 0;
                    preg_match_all("/'[^']+'\s*,/", $inner, $tm);
                    foreach ($tm[0] as $t) {
                        $width = max($width, strlen($t));
                    }
                    $line = $indent . str_pad($token, $width);
                    if ($comment) {
                        $line .= ' // ' . $comment;
                    }
                    $line = rtrim($line);
                }

                return $m[1] . rtrim($inner) . "\n" . $line . "\n    " . $m[3];
            },
            $code
        );
    }
}
