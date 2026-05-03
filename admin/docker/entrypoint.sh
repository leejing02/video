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
        if mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" --silent 2>/dev/null; then
            break
        fi
        sleep 2
    done
fi

# 同步迁移（schema.sql 已预置时这步是 no-op）
php artisan migrate --force || true

# Filament 资源发布（首次）
php artisan filament:upgrade || true

# 缓存
php artisan config:cache || true
php artisan route:cache  || true
php artisan view:cache   || true

# 权限
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

exec "$@"
