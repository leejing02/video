#!/usr/bin/env bash
# ============================================================
# admin/ 裸机一键初始化脚本（适合宝塔等不用 Docker 的场景）
#
# 用法（在 admin/ 目录里跑）：
#   ./setup-host.sh
#
# 它会做：
#   1. 检测 PHP 版本（< 8.2 时自动切到 Laravel 10 兜底 composer.json）
#   2. composer install
#   3. 生成 .env / APP_KEY
#   4. 跑 migrate + seed（包含 Spatie 角色权限）
#   5. 缓存配置 / 路由 / 视图
#   6. 修正 storage / bootstrap/cache 权限
# ============================================================
set -euo pipefail
cd "$(dirname "$0")"

log() { echo -e "\n\033[1;36m▶ $*\033[0m"; }
err() { echo -e "\n\033[1;31m✗ $*\033[0m" >&2; }
ok()  { echo -e "\033[1;32m✓ $*\033[0m"; }

# ---------- 0. 基本检查 ----------
command -v php       >/dev/null || { err "找不到 php 命令"; exit 1; }
command -v composer  >/dev/null || { err "找不到 composer，先装：curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer"; exit 1; }

# ---------- 1. PHP 版本判定 ----------
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
log "检测到 PHP $PHP_VER"

PHP_OK_FOR_L11=$(php -r 'echo (PHP_VERSION_ID >= 80200) ? "yes" : "no";')

if [ "$PHP_OK_FOR_L11" = "no" ]; then
    err "PHP $PHP_VER < 8.2，Laravel 11 跑不动"
    echo
    echo "    选项 A（推荐）：升 PHP 到 8.2+"
    echo "      宝塔：软件商店 → 找到 PHP 8.2 / 8.3 → 安装 → 网站设置切换 PHP 版本"
    echo "      Ubuntu: sudo add-apt-repository ppa:ondrej/php; sudo apt install php8.3 php8.3-cli ..."
    echo
    echo "    选项 B：留在 PHP $PHP_VER 用 Laravel 10 兜底（功能一致，框架版本旧一档）"
    echo
    read -r -p "    切到 Laravel 10 继续吗？(y/N) " ans
    if [[ ! "$ans" =~ ^[Yy]$ ]]; then
        echo "已取消，请升 PHP 后再跑这个脚本"
        exit 1
    fi

    log "切换到 Laravel 10 兜底配置"
    cp -n composer.json composer.json.l11.bak 2>/dev/null || true
    cp composer.laravel10.json composer.json
    cp -n bootstrap/app.php bootstrap/app.php.l11.bak 2>/dev/null || true
    cp .laravel10/bootstrap/app.php bootstrap/app.php
    mkdir -p app/Http app/Console app/Exceptions
    cp .laravel10/app/Http/Kernel.php app/Http/Kernel.php

    # 写 Console/Kernel
    cat > app/Console/Kernel.php <<'EOF'
<?php
namespace App\Console;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void {}
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
EOF

    cat > app/Exceptions/Handler.php <<'EOF'
<?php
namespace App\Exceptions;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
class Handler extends ExceptionHandler
{
    protected $dontFlash = ['current_password', 'password', 'password_confirmation'];
    public function register(): void {}
}
EOF
    ok "已切到 Laravel 10"
fi

# ---------- 2. 装依赖 ----------
log "composer install"
if [ ! -d vendor ]; then
    composer install --no-dev --prefer-dist --optimize-autoloader
else
    composer install --prefer-dist --optimize-autoloader
fi
ok "依赖装好"

# ---------- 3. .env / APP_KEY ----------
if [ ! -f .env ]; then
    cp .env.example .env
    ok "生成了 .env，记得改数据库连接"
fi
if ! grep -q "^APP_KEY=base64:" .env; then
    php artisan key:generate
    ok "生成了 APP_KEY"
fi

# ---------- 4. 检查 DB 配置 ----------
DB_DATABASE=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2)
DB_USERNAME=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2)
log "DB 目标：${DB_USERNAME}@$(grep -E '^DB_HOST=' .env | cut -d= -f2)/${DB_DATABASE}"

# ---------- 5. 迁移 + 种子 ----------
log "php artisan migrate --seed"
php artisan migrate --force --seed

# ---------- 6. 缓存 ----------
log "缓存配置"
php artisan config:cache
php artisan route:cache  || true     # 路由缓存对闭包不友好，失败也无所谓
php artisan view:cache   || true
php artisan filament:upgrade || true

# ---------- 7. 权限 ----------
log "修正 storage / bootstrap/cache 权限"
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || \
chown -R www:www       storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# ---------- 8. 完成 ----------
echo
ok "全部就绪！"
echo
echo "  访问后台：  http://你的域名/admin"
echo "  默认账号：  admin@example.com / password"
echo
echo "  宝塔提醒：网站根目录请指到 admin/public 而不是 admin/"
echo "  伪静态规则用 admin/public/.htaccess 已配好（Apache）"
echo "  Nginx 用户在站点配置里加："
echo "    location / { try_files \$uri \$uri/ /index.php?\$query_string; }"
echo
