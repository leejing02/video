# syntax=docker/dockerfile:1.6
# ============================================================
# Laravel 11 + Filament v3 + Nginx 单容器
# php-fpm + nginx 用 supervisor 一起拉起
# ============================================================
FROM php:8.3-fpm-alpine

# ---------- 系统依赖 + PHP 扩展 ----------
RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        git \
        curl \
        zip unzip \
        libpng libpng-dev \
        libjpeg-turbo libjpeg-turbo-dev \
        freetype freetype-dev \
        oniguruma-dev \
        icu icu-dev \
        libzip-dev \
        mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        bcmath \
        mbstring \
        gd \
        intl \
        zip \
        opcache \
    && rm -rf /var/cache/apk/*

# ---------- composer ----------
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# 先单独 COPY composer.json 利用层缓存
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist || true

# 再 COPY 全部源码
COPY . .

# 生成 autoload 和 storage 链接
RUN composer dump-autoload --optimize --no-dev || true && \
    mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

# ---------- nginx + supervisor 配置 ----------
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
