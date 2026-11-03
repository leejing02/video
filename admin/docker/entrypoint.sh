#!/bin/bash
set -e

cd /var/www/html

# 第一次容器启动时确保 vendor / .env 准备好
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
    fi
fi

# vendor 目录可能挂卷为空，跑一次安装
if [ ! -d vendor ]; then
    composer install --no-dev --optimize-autoloader --no-interaction || true
fi

# APP_KEY 没生成就生成
if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    php artisan key:generate --force || true
fi

# 等 MySQL 就绪
if [ -n "${DB_HOST}" ]; then
    echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT}..."
    for i in {1..30}; do
        if php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}','${DB_USERNAME}','${DB_PASSWORD}');" 2>/dev/null; then
            break
        fi
        sleep 2
    done
fi

# 同步迁移（schema.sql 已预置时这步是 no-op）
php artisan migrate --force || true

# 重要：Dockerfile 的 composer install 用了 --no-scripts，
# Laravel 的 package:discover 没自动跑过——必须在这里补，否则 Filament
# 等 service provider 不会被注册，/admin/* 路由全部 404。
php artisan package:discover --ansi || true

# Filament 资源发布（首次）
php artisan filament:upgrade || true

# Uploads live in a persistent volume; recreate the public link on every boot.
# --force repairs symlinks copied from a different build path, without deleting files.
mkdir -p storage/app/public
php artisan storage:link --force

# 缓存（route:cache 与 Filament 闭包路由有兼容性问题，跳过）
php artisan route:clear   || true
php artisan config:cache  || true
php artisan view:cache    || true
php artisan event:cache   || true

# 权限
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

mkdir -p /var/lib/nginx/tmp/fastcgi
chown -R www-data:www-data /var/lib/nginx/tmp
chmod -R u+rwX /var/lib/nginx/tmp

# Fix nginx temp permissions
mkdir -p /var/lib/nginx/tmp/fastcgi
chown -R www-data:www-data /var/lib/nginx
chmod -R u+rwX /var/lib/nginx

exec "$@"
