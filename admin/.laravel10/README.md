# Laravel 10 兜底文件包

如果服务器 PHP 版本 < 8.2，无法用 Laravel 11，把这套文件覆盖到上一级即可降到 Laravel 10。

## 切换步骤

```bash
cd admin

# 1. 备份 Laravel 11 的几个文件
cp composer.json composer.json.l11.bak
cp bootstrap/app.php bootstrap/app.php.l11.bak

# 2. 用 Laravel 10 兜底覆盖
cp composer.laravel10.json composer.json
cp .laravel10/bootstrap/app.php bootstrap/app.php
mkdir -p app/Http app/Console app/Exceptions
cp .laravel10/app/Http/Kernel.php app/Http/Kernel.php

# 3. 还需要 Console/Kernel.php 和 Exceptions/Handler.php — 用 Laravel 10 标准的：
cat > app/Console/Kernel.php <<'PHP'
<?php
namespace App\Console;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
class Kernel extends ConsoleKernel {
    protected function schedule(Schedule \$schedule): void {}
    protected function commands(): void {
        \$this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
PHP

cat > app/Exceptions/Handler.php <<'PHP'
<?php
namespace App\Exceptions;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
class Handler extends ExceptionHandler {
    protected \$dontFlash = ['current_password', 'password', 'password_confirmation'];
    public function register(): void {}
}
PHP

# 4. 装依赖、迁移
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --seed
```

## Laravel 10 vs 11 差别

- **路由 / 中间件** 走 `app/Http/Kernel.php`（Laravel 10）vs `bootstrap/app.php`（Laravel 11）
- **API 路由** 默认不开，要 `php artisan install:api` 启用 Sanctum + api.php
- **DB 字段类型** 不支持 PHP 8.2 typed enum 等少量语法
- 其余 Filament / Spatie 用法**完全一致**，业务代码不需要改

## 但更建议升 PHP

Laravel 10 已经停止 bug fix（2025-08）只剩安全更新到 2026-02。新项目还是建议升 PHP 到 8.2+ 跑 Laravel 11。
