# Admin — Laravel 11 + Filament v3（仅后台管理）

> ⚠️ 这是后台管理面板，**不再对外提供 API**。给 iOS 用的接口都在 `../api/`（Go 实现）。

## 三种部署方式（任选）

### A. 一键脚本（宝塔 / 裸机推荐）⭐

```bash
cd /www/wwwroot/video/admin
chmod +x setup-host.sh
./setup-host.sh
```

脚本会自动：
1. 检测 PHP 版本——< 8.2 时提示切到 **Laravel 10 兜底**（功能一致）
2. `composer install`
3. 生成 `.env` + `APP_KEY`
4. 跑 `migrate --seed`（含角色权限）
5. 缓存 + 修权限

宝塔：网站根目录指 `admin/public`（不是 `admin/`），PHP 选 8.2+。

### B. Docker 一键（不用装 PHP）

回到仓库根目录跑 `docker compose up -d --build` —— admin 容器里已经是 PHP 8.3，不用管宿主机版本。详见 `../README.md`。

### C. 全手动

```bash
cd admin
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed   # 包含 PermissionSeeder（角色权限）
php artisan filament:upgrade
php artisan config:cache
chown -R www-data:www-data storage bootstrap/cache
```

## 你遇到的两个常见错误

### 1. `Root composer.json requires php ^8.2 but your php version (8.1.x)`

PHP 太老。三种修法：

**A. 升 PHP（推荐）** — 宝塔：`软件商店` → 装 PHP 8.2 / 8.3 → 网站设置切版本。Ubuntu 直接：

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install php8.3 php8.3-cli php8.3-fpm php8.3-mysql \
                 php8.3-mbstring php8.3-xml php8.3-curl \
                 php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath
sudo update-alternatives --set php /usr/bin/php8.3
```

**B. 切到 Laravel 10**（保留 PHP 8.1）

```bash
cd admin
cp composer.json composer.json.bak
cp composer.laravel10.json composer.json
cp .laravel10/bootstrap/app.php bootstrap/app.php
mkdir -p app/Http app/Console app/Exceptions
cp .laravel10/app/Http/Kernel.php app/Http/Kernel.php
# 还差 Console/Kernel.php 和 Exceptions/Handler.php，详见 .laravel10/README.md
composer install
```

或直接 `./setup-host.sh`，它检测到 PHP 8.1 会问你要不要切。

**C. 用 Docker** — 宿主机不用动。

### 2. `Could not open input file: artisan`

这意味着 Laravel 项目脚手架没装。仓库里现在已经包含 `artisan`、`public/index.php`、`public/.htaccess`、`storage/` 目录骨架。如果是更早 clone 的版本：

```bash
git pull   # 拿最新文件

# 或者手动从 Laravel 11 复制 artisan：
curl -o artisan https://raw.githubusercontent.com/laravel/laravel/11.x/artisan
chmod +x artisan
```

然后 `composer install` 装 vendor 才能 `php artisan migrate`。

## 默认账号（Seeder 后）

| 邮箱                  | 密码     | 后台角色   |
|-----------------------|----------|------------|
| admin@example.com     | password | super-admin（拥有全部权限）|
| alice@example.com     | password | （无后台角色，只前台用户）|
| bob@example.com       | password | （无后台角色）|

登录后台后，进 "用户" 给 alice / bob 分配角色（editor / moderator / viewer）就能让他们也进部分菜单。

## 后台菜单一览

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

Dashboard 自带 7 个 widgets（统计卡 / 视频类型饼图 / 30 天趋势 / 用户增长 / 最新视频列表 / 最新评论 / 活跃聊天室）
```

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
├── artisan                          ★ Laravel CLI 入口
├── composer.json                    Laravel 11 用
├── composer.laravel10.json          PHP 8.1 兜底版
├── setup-host.sh                    一键裸机初始化
├── .laravel10/                      Laravel 10 兜底文件包
├── app/
│   ├── Models/                      User Category Video Comment ChatRoom ChatMessage
│   ├── Filament/
│   │   ├── Resources/               11 个 Resource（含长/短视频分开）
│   │   └── Widgets/                 7 个 Dashboard Widgets
│   ├── Policies/                    7 个 Policy（绑 Spatie 权限名）
│   ├── Events/                      MessageSent UserJoinedRoom（仅遗留，新接口走 Go）
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
├── storage/                         （logs、cache、sessions...）
└── docker/                          Docker 用配置（nginx / php / supervisord）
```

## Filament 后台命令清单

```bash
# 改完 Resource 后
php artisan filament:upgrade

# 创建一个新管理员
php artisan make:filament-user

# 清缓存
php artisan optimize:clear
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
