# 视频平台 · 使用手册

> 涵盖：项目结构、3 种启动方式、3 类用户操作、运维 / 备份 / 升级、API 速查、常见错误。

## 目录

1. [项目一图速览](#1-项目一图速览)
2. [3 种启动方式](#2-3-种启动方式)
3. [域名 + HTTPS 部署](#3-域名--https-部署)
4. [管理员手册（Filament 后台）](#4-管理员手册filament-后台)
5. [普通用户手册（H5 / App）](#5-普通用户手册h5--app)
6. [开发者手册](#6-开发者手册)
7. [日常运维](#7-日常运维)
8. [API 速查表](#8-api-速查表)
9. [常见错误清单](#9-常见错误清单)

---

## 1. 项目一图速览

```
                ┌──────────────────────────┐
                │ iOS / Android (Capacitor)│
                └──────────┬───────────────┘
                           │
                           ▼
┌──────────────┐    ┌──────────────────┐    ┌─────────────────┐
│ 浏览器 H5     │───▶│  Go API (Gin)    │◀──▶│  MySQL 8 + Redis│
│ Vue 3 / Vite │    │  /api/* /ws/chat │    │  + 本地视频卷    │
└──────────────┘    └──────────────────┘    └─────────────────┘
                           ▲
                           │同库
                ┌──────────────────────┐
                │  Laravel + Filament  │
                │  /admin 后台         │
                └──────────────────────┘
```

| 模块 | 路径 | 端口（默认） | 作用 |
|---|---|---|---|
| Go API | `api/` | 8000 | 给 iOS/web 提供所有 REST + WebSocket |
| Admin 后台 | `admin/` | 8001 / 80 | 仅管理员用，内容审核、视频管理、用户权限 |
| H5 前端 | `web/` | 5173（dev）| iOS/Android 壳内同样跑这套 |
| iOS 原生 | `ios/` | — | 长期目标，当前是骨架 |
| 反向代理 | `docker-compose.yml` 里的 traefik | 8088 / 80 / 443 | 统一入口 + 自动 HTTPS |

---

## 2. 3 种启动方式

按"省心程度"递减：Docker 一键 → 裸机分开跑 → 开发模式分别跑。

### 方式 A · Docker 一键（推荐）

```bash
git clone <仓库> /www/wwwroot/video
cd /www/wwwroot/video
docker compose up -d --build
```

完成后：

| 入口 | URL |
|---|---|
| Filament 后台 | http://admin.video.localhost:8088/admin |
| Go API | http://api.video.localhost:8088/api/* |
| WebSocket | ws://api.video.localhost:8088/ws/chat |
| Traefik 看板 | http://localhost:8081 |

> `*.localhost` 在 macOS 和 Linux 上自动指向 127.0.0.1。Windows 加 `127.0.0.1 admin.video.localhost api.video.localhost` 到 `C:\Windows\System32\drivers\etc\hosts`。

**默认账号**：`admin@example.com / password`

mysql 第一次启动会自动跑 `api/migrations/schema.sql` 和 `seed.sql`。

### 方式 B · 裸机分开跑（宝塔 / 单台 VPS）

#### B.1 数据库

```bash
# MySQL 8 创建库 + 跑迁移
mysql -uroot -p < api/migrations/schema.sql
mysql -uroot -p video_platform < api/migrations/seed.sql
```

或者走 Laravel 迁移：

```bash
cd admin
./setup-host.sh    # 自动 PHP 检查 + composer install + migrate + seed
```

#### B.2 Go API

```bash
cd api
cp .env.example .env
vim .env   # 改 DB_HOST/USER/PASSWORD，PUBLIC_BASE_URL，UPLOAD_KEY
go mod tidy
go build -o video-api ./cmd/server
nohup ./video-api > video-api.log 2>&1 &
```

#### B.3 Admin 后台

```bash
cd admin
# PHP 必须 ≥ 8.2，宝塔装 PHP 8.3 后：
ln -sf /www/server/php/83/bin/php /usr/bin/php

./setup-host.sh   # 一键脚本，跑完就能访问
```

宝塔站点根目录指 `admin/public`，PHP 选 8.3，伪静态选 Laravel 5。

#### B.4 H5 前端

```bash
cd web
npm install
cp .env.example .env
vim .env   # VITE_API_BASE 指到 Go API
npm run build
# 把 web/dist/ 当静态文件部署到 Nginx 或 Caddy 即可
```

或者继续走 Capacitor 打包给 App，见 [开发者手册](#6-开发者手册)。

### 方式 C · 开发模式（前后端分开跑）

适合改代码 / 调试。

```bash
# Terminal 1: 数据库（用 docker 起一个）
docker run -d --name dev-mysql \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=video_platform \
  -p 3306:3306 mysql:8.0

# Terminal 2: Go API
cd api
cp .env.example .env
go run ./cmd/server     # http://localhost:8000

# Terminal 3: H5 前端（热更新）
cd web
npm install
npm run dev             # http://localhost:5173

# Terminal 4: Admin 后台（可选）
cd admin
php artisan serve --port=8001  # http://localhost:8001/admin
```

---

## 3. 域名 + HTTPS 部署

绑你自己的域名 `hkho567.eu.cc`：

### 第 1 步：DNS 解析

```
admin.hkho567.eu.cc      A    <服务器IP>
api.hkho567.eu.cc        A    <服务器IP>
traefik.hkho567.eu.cc    A    <服务器IP>   # 可选，看 Traefik 后台
```

### 第 2 步：填 .env.prod

```bash
cd /www/wwwroot/video
cp .env.prod.example .env.prod
vim .env.prod
```

关键字段：
```env
ADMIN_DOMAIN=admin.hkho567.eu.cc
API_DOMAIN=api.hkho567.eu.cc
ACME_EMAIL=你的邮箱@gmail.com
TRAEFIK_DASHBOARD_AUTH=admin:$$2y$$05$$<htpasswd 输出>
DB_USER=video
DB_PASSWORD=<改成强密码>
DB_ROOT_PASSWORD=<改成强密码>
APP_KEY=base64:<artisan key:generate --show 拿到的>
JWT_SECRET=<openssl rand -hex 32>
PUBLIC_BASE_URL=https://api.hkho567.eu.cc
UPLOAD_KEY=<openssl rand -hex 32>
OSS_ENDPOINT=https://oss-cn-hangzhou.aliyuncs.com
OSS_BUCKET=...
OSS_AK=...
OSS_SK=...
```

生成 BasicAuth 用：

```bash
docker run --rm httpd:alpine htpasswd -nbB admin '你的Dashboard密码' | sed -e 's/\$/\$\$/g'
```

### 第 3 步：一键部署

```bash
./deploy.sh init
```

完成后访问：
- https://admin.hkho567.eu.cc/admin
- https://api.hkho567.eu.cc/api/up
- https://traefik.hkho567.eu.cc（BasicAuth 登录）

证书自动签 + 自动续期，不用管。

### 后续更新

```bash
git pull
./deploy.sh update    # 滚动重启 api/admin，db/traefik 不动
```

其他命令：

```bash
./deploy.sh status    # docker compose ps
./deploy.sh logs api  # 看 Go API 日志
./deploy.sh verify    # 排查 Traefik / Docker socket / 路由
./deploy.sh backup    # 立刻跑一次 mysqldump → OSS
./deploy.sh restore oss://bucket/backups/xxx.sql.gz   # 从备份恢复
```

---

## 4. 管理员手册（Filament 后台）

打开 `https://admin.hkho567.eu.cc/admin`，用 `admin@example.com / password` 登录。

### 4.1 Dashboard

进去就是首页，看到 7 个 Widgets：

- **统计卡** — 用户数 / 视频数 / 评论数 / 24h 群聊消息（带 14 天 mini 趋势）
- **视频类型饼图** — 长 vs 短的占比
- **30 天趋势** — 折线图，可切 7 / 30 / 90 天
- **用户增长** — 柱状（每日新增）+ 折线（累计）
- **最新视频** — 最近 10 条
- **最新评论** — 带头像
- **活跃聊天室** — 按最后消息时间排

### 4.2 内容管理

| 菜单 | 操作 |
|---|---|
| 长视频 | 上传 / 编辑 / 通过 / 拒绝 / 下架；按 Tab 切"全部 / 待审 / 通过 / 拒绝" |
| 短视频 | 同上，封面要求 9:16 |
| 长视频分类 | CRUD 分类，可拖拽排序 |
| 短视频分类 | 同上 |

**审核操作**：
- 单条：列表行的"通过 / 拒绝"按钮，拒绝时填原因
- 批量：勾选多行 → 顶部"批量通过"

待审视频不会出现在用户的列表里（Go API 强制 audit_status=approved 过滤）。

### 4.3 用户与互动

| 菜单 | 操作 |
|---|---|
| 用户 | 创建 / 编辑 / 分配后台角色（多选）|
| | 行操作：禁用（撤销 token）/ 启用 / 重置密码（生成临时密码）|
| | 批量禁用 / 删除 |
| 角色 | 4 个默认角色：super-admin / editor / moderator / viewer |
| | 编辑角色时按 Resource 分组勾选权限 |
| 评论 | 通过 / 拒绝 / 置顶 / 删除；4 个 Tab 分类查看 |

### 4.4 聊天

| 菜单 | 操作 |
|---|---|
| 群分类 | CRUD（影迷 / 学习 / 旅行 等）|
| 聊天室 | 行：全员禁言 / 解除；进编辑页：见下面 |
| 聊天消息 | 全局浏览所有群消息，可屏蔽 / 删除 |

**进群编辑页（聊天室 → 点行编辑）** 底部有 2 个 Tab：

- **成员管理** — 加成员 / 设管理员 / 撤管理员 / 临时禁言（1h~7天）/ 永久禁言 / 踢出
- **消息记录** — 屏蔽（用户不可见）/ 取消屏蔽 / 物理删除 / 批量屏蔽

### 4.5 审核

| 菜单 | 操作 |
|---|---|
| 举报处理 | pending 数量在导航上显示红点 |
| | "处理并删除内容"：一键下架被举报的视频 / 评论 / 消息 |
| | "驳回"：判定无问题 |
| 敏感词库 | 添加单个词 / 批量导入（每行一个）|
| | 严重度：warn（命中后入待审）/ block（直接拒绝发布）|
| | 分类：政治 / 色情 / 暴力 / 广告 / 其他 |

### 4.6 个人

右上角头像 → Profile，可改自己的密码 / 邮箱。

---

## 5. 普通用户手册（H5 / App）

无论是浏览器、Capacitor 打的 iOS App、还是 Android，UI 是同一份。

### 5.1 登录 / 注册

打开 `/login`：
- 默认是登录模式
- 点底部 "没有账号？去注册" 切到注册
- 注册需要：昵称 / 用户名（英文）/ 邮箱 / 密码（≥6 位）

登录后 token 存到 Capacitor `Preferences`（在浏览器是 localStorage 之上的封装），下次自动登录。

### 5.2 4 个 Tab

底部固定 4 个：

| Tab | 干啥 |
|---|---|
| 群聊 | 进首页广场群，左下消息列表，右下输入框，绿色"●"表示 WS 已连 |
| 长视频 | 顶部分类条横滑，下面列表，点进去全屏播放 + 评论 |
| 短视频 | 上下滑切换，类似抖音 / TikTok，右侧点赞 / 评论 / 分享按钮 |
| 我的 | 头像 / 资料 / **📤 上传视频** / 我的视频 / 退出登录 |

### 5.3 上传视频

点 "我的" → "📤 上传视频"，进 `/upload`：

1. 选类型：长视频 / 短视频
2. 选分类
3. 拖文件或点选
4. 自动开始：
   - **校验秒传**（算 MD5，命中已上传过的瞬间通过）
   - **断点上传**（10MB 一片，每片传前先问后端有没有，断网恢复后跳过已传）
   - **合并**
5. 完成后弹"上传成功，等待管理员审核"

支持的格式：mp4 / mov / mkv / flv / avi / wmv / ts / mpg。最大 4GB（看后端磁盘）。

### 5.4 评论 / 点赞 / 举报

- 视频详情页底部有评论列表 + 输入框
- 输入评论按发送 → 如果命中敏感词会弹"内容包含违禁词"
- 点❤点赞，再点取消
- 右上 "举报" → 选原因（色情 / 暴力 / 广告 / 诈骗 / 政治 / 骚扰 / 其他）→ 提交后等管理员处理

### 5.5 群聊

- 首页 Tab 就是全局广场群
- 输入框输入按发送（或 Enter）
- 顶部能看到连接状态：🟢已连接 / ⚪连接中
- 收到新消息会实时显示（WebSocket 推送）
- 被禁言时会弹错误提示

---

## 6. 开发者手册

### 6.1 前端改代码

```bash
cd web
npm install
npm run dev
```

热更新 5173 端口。

**改 API 调用** → 在 `src/api/<模块>.ts` 加方法。
**改页面** → 改 `src/views/*.vue`。
**改组件** → 改 `src/components/*.vue`。
**WebSocket 监听新事件** → `realtime.on('xxx', cb)`。

### 6.2 后端改代码

#### Go API

```bash
cd api
go run ./cmd/server   # 改完自动看效果
```

加新接口：
1. 在 `internal/handlers/` 写 handler
2. 在 `cmd/server/main.go` 注册路由
3. 如果要新表，加迁移到 `migrations/schema.sql`（或写 Laravel migration）

#### Admin 后台

```bash
cd admin
php artisan serve --port=8001
```

加新 Resource：
```bash
php artisan make:filament-resource MyModel
```

权限注册：在 `database/seeders/PermissionSeeder.php` 加权限名 → 重跑 `php artisan db:seed --class=PermissionSeeder`。

### 6.3 iOS / Android 打包

```bash
cd web

# 第一次
npx cap add ios
npx cap add android

# 改了代码后
npm run cap:sync       # = build + cap sync
npx cap open ios       # Xcode 打开
npx cap open android   # Android Studio 打开
```

或者：

```bash
npm run ios:run        # 一行命令 build → sync → run（需 Xcode + 模拟器）
npm run android:run
```

打 release 包前**必须**改 `web/.env`：

```env
VITE_API_BASE=https://api.hkho567.eu.cc
```

并删掉 `capacitor.config.ts` 里 `server.url`（如果之前为了真机连本机 dev 加过）。

### 6.4 新增敏感词

进后台 → 审核 → 敏感词库 → 新建，或批量导入（每行一个）。

服务端 5 分钟刷一次缓存（也可以在 admin 里手动改任意一条触发刷缓存）。

### 6.5 改默认角色权限

编辑 `admin/database/seeders/PermissionSeeder.php` 里的 `ROLES` 常量，再跑：

```bash
php artisan db:seed --class=PermissionSeeder --force
```

---

## 7. 日常运维

### 7.1 看日志

```bash
# 全部
./deploy.sh logs

# 只看某服务
./deploy.sh logs api
./deploy.sh logs admin
./deploy.sh logs traefik   # 排查证书 / 路由
./deploy.sh logs backup    # 看每日 mysqldump

# 实时跟踪某个容器
docker logs -f video-api
```

### 7.2 备份

自动备份每天 03:00 跑一次（在 `ops/backup/` 里），上传到 OSS。

手动触发：

```bash
./deploy.sh backup
```

恢复：

```bash
# 从 OSS
./deploy.sh restore oss://your-bucket/backups/2026/05/02/video_platform-20260502-030000.sql.gz

# 从本地
./deploy.sh restore /backups/video_platform-xxx.sql.gz
```

恢复脚本会让你输入数据库名做二次确认。

### 7.3 升级

```bash
git pull
./deploy.sh update    # 滚动重启 api/admin
```

要跑数据库迁移：

```bash
docker compose exec admin php artisan migrate --force
```

### 7.4 看 Traefik / 路由是否正常

```bash
./deploy.sh verify
```

会逐项检查：
- Docker socket 权限
- Docker context
- Traefik 是否加载了 docker provider
- 路由表（应该看到 admin@docker / api@docker）
- 容器健康

### 7.5 进容器排错

```bash
# 进 admin 容器跑 artisan
docker exec -it video-admin bash
php artisan tinker
> User::count()

# 进 api 容器（Alpine，没 bash 用 sh）
docker exec -it video-api sh

# 进 mysql
docker exec -it video-mysql mysql -uroot -prootpw video_platform
```

### 7.6 改环境变量

```bash
vim .env.prod
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d
# 容器会自动用新的 env 重启
```

### 7.7 清缓存（admin 出问题先这个）

```bash
docker compose exec admin php artisan optimize:clear
docker compose exec admin php artisan filament:upgrade
```

---

## 8. API 速查表

所有路径都在 `/api` 下。token 用 `Authorization: Bearer <JWT>` 传。

### 公开（不需要登录）

| Method | Path | 说明 |
|---|---|---|
| POST | /register | 注册，返回 token |
| POST | /login | 登录 |
| GET | /categories?kind=long\|short | 分类列表 |
| GET | /videos?type=long\|short&category_id=&q=&page=&per_page= | 视频列表 |
| GET | /videos/:id | 视频详情（带 +1 浏览）|
| GET | /videos/:id/comments | 评论 |
| GET | /chat/global | 全局聊天室 |

### 上传（公开但要 uploadkey）

| Method | Path | 说明 |
|---|---|---|
| GET | /upload?status=md5Check&md5=&uploadkey=&name= | 秒传校验 |
| GET | /upload?status=chunkCheck&name=&chunkIndex=&size= | 分片是否已存在 |
| POST | /upload | 上传分片 multipart |
| GET | /upload?status=chunksMerge&... | 合并 |

### 需要登录

| Method | Path | 说明 |
|---|---|---|
| POST | /logout | 注销 |
| GET | /me | 当前用户 |
| PATCH | /me | 改资料 |
| POST | /videos/:id/like | 点赞切换 |
| GET | /me/videos | 我的视频（含 pending）|
| POST | /videos/:id/comments | 发评论 |
| DELETE | /comments/:id | 删评论 |
| GET | /chat/rooms | 我的聊天室 |
| GET | /chat/rooms/:id/messages?before_id=&limit= | 历史消息 |
| POST | /chat/rooms/:id/messages | 发消息 |
| POST | /chat/rooms/:id/join | 加入群 |
| POST | /chat/rooms/:id/read | 标记已读 |
| POST | /reports | 举报（type / id / reason）|
| GET | /me/reports | 我的举报历史 |
| POST | /moderation/check | 内容预检（pass/warn/block）|
| GET | /admin/sensitive_words | admin 专用：列敏感词 |

### WebSocket

| Path | 说明 |
|---|---|
| `/ws/chat?room_id=N&token=<JWT>` | 群聊推送，事件 `message.sent`：`{event, data: ChatMessage}` |

---

## 8.5 dev compose vs prod compose（重要）

仓库里有**两份** docker-compose，用法不同：

| 文件 | 用途 | 监听 | 域名 | HTTPS |
|---|---|---|---|---|
| `docker-compose.yml` | 本地开发 / 局域网内试用 | 8088 / 8081 | `*.video.localhost` | ✗ |
| `docker-compose.prod.yml` | 公网生产 | **80 / 443** | 你的真实域名（`.env.prod` 里）| ✓ Let's Encrypt 自动签 |

### 为什么 `docker compose up -d --build` 后没有 HTTPS？

因为它默认读 `docker-compose.yml`（dev 版），那份只有 8088 入口、不开 443。

**生产部署一定要用：**

```bash
# 第一次
./deploy.sh init

# 后续更新
./deploy.sh update
```

deploy.sh 自动用 `--env-file .env.prod -f docker-compose.prod.yml`。

如果你已经用 dev compose 跑过了，先停掉再切：

```bash
docker compose down                                # 停 dev
./deploy.sh init                                   # 起 prod（会签证书）
```

### 并发跑两份会冲突吗？

会。dev 用 `name: video-platform`，prod 用 `name: video-prod`，容器名也不一样，但都会去抢主机的 80 / 443。**只跑一个**就行。

---

## 9. 常见错误清单

### 9.1 部署相关

| 报错 | 原因 / 解决 |
|---|---|
| `Bind for 0.0.0.0:6379 failed: port is already allocated` | 主机已有 redis，把 docker-compose.yml 里 redis 的 ports 删掉（用 expose 即可）|
| `Traefik 路由出不来` | 跑 `./deploy.sh verify`；多半是 socket endpoint 没显式声明 → 检查 traefik command 有 `--providers.docker.endpoint=unix:///var/run/docker.sock` |
| `Traefik 报 client version 1.24 too old` | Docker Engine 27+ 与 Traefik 的版本协商 bug；compose 已加 `--providers.docker.apiVersion=1.54` 修了。改了得重启 traefik 容器 |
| `想跑 HTTPS 但 https://your-domain 不通` | 用错 compose 文件了。dev 那份没 443；用 `./deploy.sh init` 跑 prod 那份 |
| `Let's Encrypt 签证书失败` | 域名 DNS 没解析到服务器 / 80 端口被占；先注释掉 `caserver=...staging...` 那行用 staging 测 |
| `502 Bad Gateway` | 后端容器没起；`./deploy.sh logs api` 看下 |

### 9.2 admin / Laravel

| 报错 | 解决 |
|---|---|
| `Could not open input file: artisan` | git pull 拉到 artisan 这个文件；或者从 Laravel 11 仓库 curl 一份过来 |
| `Pusher\Pusher::__construct(): ... null given` | `BROADCAST_CONNECTION=log` 在 .env 里设上；并清缓存 |
| `Class "Spatie\\Permission\\..." not found` | composer install 没跑；或 PHP 版本 < 8.2 跑不动 |
| `Table 'video_platform.cache' doesn't exist` | 迁移没跑全 → `php artisan migrate --force` |
| `SQLSTATE[HY000] [2002] Connection refused` | `.env` 里 `DB_HOST` 写 `127.0.0.1` 不要写 `localhost` |
| `permission denied: storage/...` | `chown -R www-data:www-data storage bootstrap/cache && chmod -R 775` |

### 9.3 前端 / 上传

| 报错 | 解决 |
|---|---|
| `Network Error` | API 地址不对；改 `web/.env` 的 `VITE_API_BASE` |
| `CORS error` | Go 端 CORS 没放开你的域名；改 `api/.env` 的 `CORS_ORIGINS=https://你的域名` |
| `WebSocket 一直连不上` | 检查 Traefik 是否转发 WS（Traefik 默认支持，但反代里 Cloudflare 等中间层要打开 WS 选项）|
| `上传到 99% 卡住` | 多半是合并阶段超时；检查 Go API 容器内存 / 磁盘 |
| `chunkCheck 总是 ifExist=false` | 存储卷没挂或者每次重启容器都清了，断点续传失效 → docker compose volume 挂上 |

### 9.4 群聊 WebSocket

| 现象 | 解决 |
|---|---|
| 顶部一直显示 "○连接中" | token 失效，退出重新登录；或后端没起 |
| 别人发的消息收不到 | 自己发的能在自己屏幕看到说明 send API 通了；收不到别人的看 ws_url 对不对（应该是 ws://api.../ws/chat） |
| 频繁断开 | 网络不稳；client 已经实现指数退避自动重连，看 console 有 close 事件 |

### 9.5 视频审核 / 敏感词

| 现象 | 解决 |
|---|---|
| 我上传的视频用户看不到 | 默认 `audit_status=pending`，进 admin 审核 → 通过 |
| 想关掉自动审核 | 改 `api/internal/handlers/upload.go` 里 INSERT 时把 `'pending'` 改成 `'approved'` |
| 改了敏感词没生效 | 5 分钟缓存；admin 里随便编辑一条会触发刷缓存；或重启 api |
| `内容包含违禁词，无法发布` | 命中了 severity=block 的敏感词；让用户改文案 |

---

## 附：联系 / 扩展

- **加新 Resource 后导航没出来** → `php artisan filament:upgrade` + 清缓存
- **想加视频转码** → 后端 `chunksMerge` 完成后起一个 ffmpeg goroutine，转 HLS 切片到 `STORAGE_DIR/hls/`
- **想接微信 / 短信登录** → 在 `api/internal/handlers/auth.go` 加 OAuth 入口
- **想推送通知** → Capacitor 接 APNs / FCM；后端用 Go-FCM 发推

下次遇到没列出来的错，把报错原文贴出来就行。
