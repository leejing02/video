#!/usr/bin/env bash
# ============================================================
# admin/ 裸机一键初始化脚本（Laravel 11 / PHP 8.2+）
#
# 用法（在 admin/ 目录里跑）：
#   ./setup-host.sh
#
# 它会做：
#   1. 检测 PHP 版本（< 8.2 直接退出，提示如何升）
#   2. composer install
#   3. 生成 .env / APP_KEY，把 BROADCAST_CONNECTION 钳制到 log
#   4. 跑 migrate + seed（包含 Spatie 角色权限）
#   5. 缓存配置 / 视图
#   6. 修正 storage / bootstrap/cache 权限
# ============================================================
set -euo pipefail
cd "$(dirname "$0")"

log() { echo -e "\n\033[1;36m▶ $*\033[0m"; }
err() { echo -e "\n\033[1;31m✗ $*\033[0m" >&2; }
ok()  { echo -e "\033[1;32m✓ $*\033[0m"; }

# ---------- 0. 基本检查 ----------
command -v php       >/dev/null || { err "找不到 php 命令"; exit 1; }
command -v composer  >/dev/null || {
    err "找不到 composer，先装："
    err "  curl -sS https://getcomposer.org/installer | php"
    err "  mv composer.phar /usr/local/bin/composer"
    exit 1
}

# ---------- 1. PHP 版本判定 ----------
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
log "检测到 PHP $PHP_VER"

PHP_OK=$(php -r 'echo (PHP_VERSION_ID >= 80200) ? "yes" : "no";')

if [ "$PHP_OK" = "no" ]; then
    err "PHP $PHP_VER 太老，Laravel 11 要求 PHP ≥ 8.2"
    echo
    echo "  宝塔：软件商店 → 装 PHP 8.2 / 8.3 → 网站设置切换 PHP 版本"
    echo "         命令行 PHP 也要切：ln -sf /www/server/php/83/bin/php /usr/bin/php"
    echo
    echo "  Ubuntu/Debian:"
    echo "    sudo add-apt-repository ppa:ondrej/php"
    echo "    sudo apt update"
    echo "    sudo apt install php8.3 php8.3-cli php8.3-fpm php8.3-mysql \\"
    echo "                     php8.3-mbstring php8.3-xml php8.3-curl \\"
    echo "                     php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath"
    echo "    sudo update-alternatives --set php /usr/bin/php8.3"
    echo
    echo "  升级完再跑这个脚本"
    exit 1
fi
ok "PHP 版本满足要求"

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

# 把广播驱动钳制到 log（避免 Pusher 因缺 KEY 报错）
if grep -q '^BROADCAST_CONNECTION=' .env; then
    sed -i 's/^BROADCAST_CONNECTION=.*/BROADCAST_CONNECTION=log/' .env
else
    echo 'BROADCAST_CONNECTION=log' >> .env
fi

if ! grep -q "^APP_KEY=base64:" .env; then
    php artisan key:generate
    ok "生成了 APP_KEY"
fi

# ---------- 4. 检查 DB 配置 ----------
DB_DATABASE=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2)
DB_USERNAME=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2)
DB_HOST=$(grep -E '^DB_HOST=' .env | cut -d= -f2)
log "DB 目标：${DB_USERNAME}@${DB_HOST}/${DB_DATABASE}"

# ---------- 5. 迁移 + 种子 ----------
log "php artisan migrate --seed"
php artisan migrate --force --seed

# ---------- 6. 缓存 ----------
log "缓存配置"
php artisan config:cache
php artisan view:cache   || true
php artisan filament:upgrade || true

# 路由缓存对闭包不友好，可能失败，不阻塞
php artisan route:cache  || true

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
