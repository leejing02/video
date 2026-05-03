# Admin — Laravel 11 + Filament v3（仅后台管理）

> ⚠️ 这是后台管理面板，**不再对外提供 API**。给 iOS 用的接口都在 `../api/`（Go 实现）。

## 系统要求

- **PHP ≥ 8.2**（Laravel 11 强制要求；当前推荐 PHP 8.3）
- 必装扩展：`bcmath`、`mbstring`、`pdo_mysql`、`gd`、`intl`、`zip`、`opcache`（可选）、`exif`、`fileinfo`、`redis`（用 Redis 缓存时）
- MySQL 8.0+ / MariaDB 10.6+
- Composer 2

PHP 还在 8.1 的话先升：

```bash
# 宝塔：软件商店 → 装 PHP 8.3 → 网站设置 PHP 版本 → 切到 8.3
# 命令行 PHP 也要换：
ln -sf /www/server/php/83/bin/php /usr/bin/php

# Ubuntu/Debian：
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install php8.3 php8.3-cli php8.3-fpm php8.3-mysql \
                 php8.3-mbstring php8.3-xml php8.3-curl \
                 php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath
sudo update-alternatives --set php /usr/bin/php8.3

php -v   # 验证：PHP 8.3.x
```

## 两种部署方式

### A. 一键脚本（宝塔 / 裸机推荐）⭐

```bash
cd /www/wwwroot/video/admin
chmod +x setup-host.sh
./setup-host.sh
```

脚本会：
1. 检测 PHP 版本（< 8.2 直接报错让你升）
2. `composer install`
3. 生成 `.env` + `APP_KEY`，把 `BROADCAST_CONNECTION` 钳到 `log`（避免 Pusher 报错）
4. 跑 `migrate --seed`（含角色权限）
5. 缓存 + 修权限

宝塔：网站根目录指 `admin/public`（不是 `admin/`），PHP 选 8.3。

### B. Docker 一键（不用装 PHP）

回到仓库根目录跑 `docker compose up -d --build` —— admin 容器里跑的是 PHP 8.3。详见 `../README.md`。

### C. 全手动

```bash
cd admin
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# 编辑 .env：DB_DATABASE / DB_USERNAME / DB_PASSWORD
php artisan migrate --seed
php artisan filament:upgrade
php artisan config:cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

## 常见错误清单

### `Pusher\\Pusher::__construct(): Argument #1 ($auth_key) must be of type string, null given`

广播驱动默认 reverb，内部会装 Pusher SDK。`.env` 里设：

```env
BROADCAST_CONNECTION=log
```

`setup-host.sh` 已经会自动处理这步。

### `Could not open input file: artisan`

仓库里有 `artisan` 文件，确认你 git 拉到了。或者：

```bash
curl -o artisan https://raw.githubusercontent.com/laravel/laravel/11.x/artisan
chmod +x artisan
```

### `Class "Spatie\\Permission\\..." not found`

`composer install` 没跑成功。检查：

```bash
ls vendor/spatie/laravel-permission/   # 应该有这个目录
composer install --no-dev --optimize-autoloader
```

### `SQLSTATE[HY000] [2002] Connection refused`

`.env` 里 `DB_HOST` 写 `127.0.0.1` 不要写 `localhost`（避免走 unix socket）。

### `Permission denied: storage/...`

```bash
chown -R www-data:www-data storage bootstrap/cache  # 或 www:www（宝塔）
chmod -R 775 storage bootstrap/cache
```

### 后台样式空白

```bash
php artisan storage:link
php artisan filament:upgrade
php artisan optimize:clear
```

### composer 内存不足

```bash
COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev
```

### 国内拉包慢

```bash
composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/
```

## 默认账号（Seeder 后）

| 邮箱                  | 密码     | 后台角色   |
|-----------------------|----------|------------|
| admin@example.com     | password | super-admin（拥有全部权限）|
| alice@example.com     | password | （无后台角色，前台用户）|
| bob@example.com       | password | （无后台角色）|

登录后台后，进 "用户" 给 alice / bob 分配角色（editor / moderator / viewer）就能让他们也进部分菜单。

## 后台菜单

```
内容管理
├── 长视频               LongVideoResource
├── 短视频               ShortVideoResource
├── 长视频分类           LongVideoCategoryResource
└── 短视频分类           ShortVideoCategoryResource

用户与互动
├── 用户                 UserResource（角色多选 / 锁定 / 重置密码）
├── 角色                 RoleResource（按 Resource 分组勾选权限）
└── 评论                 CommentResource

聊天
├── 聊天室               ChatRoomResource
└── 聊天消息             ChatMessageResource
```

Dashboard 自带 7 个 widgets：4 张统计卡 + 视频类型饼图 + 30 天发布趋势 + 用户增长 + 最新视频 + 最新评论 + 活跃聊天室。

## 默认 4 个角色

| 角色 | 权限范围 |
|---|---|
| `super-admin` | 全部（before hook 直接放行）|
| `editor`      | 视频/分类全 CRUD + 评论删除 |
| `moderator`   | 评论 + 聊天消息删除、只读视频/聊天 |
| `viewer`      | 全部 `*.view` 只读 |

权限命名规范：`<resource>.<action>`，例如 `long_video.create`、`short_category.update`、`comment.delete`。

## 目录速查

```
admin/
├── artisan                          Laravel CLI 入口
├── composer.json                    Laravel 11
├── setup-host.sh                    一键裸机初始化
├── app/
│   ├── Models/                      User Category Video Comment ChatRoom ChatMessage
│   ├── Filament/
│   │   ├── Resources/               11 个 Resource（含长/短视频分开）
│   │   └── Widgets/                 7 个 Dashboard Widgets
│   ├── Policies/                    7 个 Policy（绑 Spatie 权限名）
│   ├── Events/                      MessageSent UserJoinedRoom（遗留，可不用）
│   └── Providers/                   AppServiceProvider AdminPanelProvider
├── bootstrap/                       app.php providers.php
├── config/                          broadcasting reverb sanctum permission
├── database/
│   ├── migrations/                  9 张表（含 spatie 权限 4 张）
│   ├── seeders/                     DatabaseSeeder + PermissionSeeder
│   └── factories/                   UserFactory
├── public/                          ★ 网站根目录指这里
│   ├── index.php
│   └── .htaccess
├── routes/                          api / web / channels / console
├── storage/                         logs / cache / sessions / views
└── docker/                          Docker 用配置（nginx / php / supervisord）
```

## Filament 命令清单

```bash
php artisan filament:upgrade            # 改完 Resource 后跑
php artisan make:filament-user          # 创建一个新管理员
php artisan optimize:clear              # 清所有缓存
```

## Nginx 站点配置（宝塔最常见的伪静态）

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
```

宝塔面板里这个叫"伪静态"，模板选 **Laravel 5** 或者直接粘上面的 `location /` 那段。
