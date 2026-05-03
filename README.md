# Video Platform — 三件套

```
go/video/
├── admin/   PHP（Laravel 11 + Filament v3） — 仅后台管理（/admin 网页）
├── api/     Go  （Gin + sqlx + JWT + WebSocket） — iOS App 调用的所有接口
└── ios/     iOS（SwiftUI + UIKit 混合）
```

数据流：

```
iOS App ──HTTP/WS──▶ api (Go :8000)  ──SQL──▶ MySQL ◀──SQL── admin (PHP)
                                                              │
                                                              └─ /admin 后台 UI
```

数据库 **共用一套**，由 Laravel migration 或 `api/migrations/schema.sql` 任一种方式建表。

## 数据模型

```
User ─┬─< Video >─ Category
      ├─< Comment >─ Video（自引用 parent_id 二级回复）
      └─pivot─ ChatRoom ─< ChatMessage
```

- `User`         用户（admin / user）
- `Category`     分类（kind: long / short / both）
- `Video`        视频（type: long / short）
- `Comment`      视频评论（parent_id 自引用）
- `ChatRoom`     聊天室（kind: global / group / direct，首页群聊 = 全局）
- `ChatMessage`  消息（type: text / image / video / system）

## iOS 四个 Tab

1. 群聊（ChatHomeView，连 `/api/chat/global` + `/ws/chat`）
2. 长视频（LongVideoListView，顶部分类水平滑动 + 列表 + AVPlayerVC 详情）
3. 短视频（ShortVideoFeedView，竖屏翻页流，AVPlayerLayer 自循环）
4. 个人中心（ProfileView，资料 + 我的视频 + 登出）

## 一键启动（推荐：Docker Compose + Traefik 反代）

```bash
cd go/video
docker compose up -d --build
```

主机**只暴露 Traefik 一个端口（8088）**，所有服务通过域名路由：

| 入口 | URL |
|---|---|
| Filament 后台 | http://admin.video.localhost:8088/admin |
| Go API | http://api.video.localhost:8088/api/... |
| WebSocket 群聊 | ws://api.video.localhost:8088/ws/chat |
| Traefik Dashboard | http://localhost:8081 |

mysql / redis **不暴露到主机**，只在 docker 内部网络互通——和你机器上现有的 MySQL/Redis 不会撞。

> `*.localhost` 在 macOS 和现代 Linux 上自动指向 127.0.0.1，**不需要改 /etc/hosts**。
> 如果你的系统不支持，往 `/etc/hosts` 加一行：
> `127.0.0.1 admin.video.localhost api.video.localhost`

### 端口冲突处理

| 撞了什么 | 改哪里 |
|---|---|
| 主机 8088 被占 | `docker-compose.yml` 里 `traefik.ports: "8088:80"` 左边改成空闲端口（同步把 admin 的 `APP_URL` 改了） |
| 主机 8081 被占 | Traefik dashboard 的 `"8081:8080"` 左边改 |
| 想让 TablePlus 连 mysql | `cp docker-compose.override.example.yml docker-compose.override.yml`，里面会把 mysql 映射到 3307 |

### 首次启动

mysql 容器初始化时**自动跑** `api/migrations/schema.sql + seed.sql`——表和种子数据都建好。

后台默认账号：`admin@example.com / password`

### iOS 端 baseURL 改法

`ios/VideoApp/Core/Network/APIClient.swift`：

```swift
var baseURL = URL(string: "http://api.video.localhost:8088/api")!
```

`RealtimeChat.swift` 同步改：

```swift
RealtimeChat(host: "api.video.localhost", port: 8088, scheme: "http")
```

模拟器可以直接解析 `*.localhost`；真机调试时把它换成你电脑的局域网 IP，并在路由器或手机改 hosts 把这个域名指过来——或者最简单：临时拷贝 `docker-compose.override.example.yml`，让 api 直接暴露到主机的 8002，iOS 写 `http://<你电脑 IP>:8002/api`。

---

## 生产部署（HTTPS + Let's Encrypt 自动签证书）

### 前置条件

1. 一台 Linux 服务器（开放 80/443 入站）
2. 装好 Docker + Docker Compose（`docker compose version` ≥ v2）
3. 两个域名解析到这台服务器：
   - `admin.example.com  A  <服务器 IP>`
   - `api.example.com    A  <服务器 IP>`
   - 如果想要 Traefik 看板：`traefik.admin.example.com  A  <服务器 IP>`

### 三步走

```bash
# 1. 拷贝并填好 .env.prod
cp .env.prod.example .env.prod
vim .env.prod

# 2. 生成 Traefik Dashboard BasicAuth（admin / 你的密码）
docker run --rm httpd:alpine htpasswd -nbB admin 'YourPass' | sed -e 's/\$/\$\$/g'
# 把输出粘到 .env.prod 的 TRAEFIK_DASHBOARD_AUTH= 行

# 3. 一键部署
./deploy.sh init
```

`deploy.sh init` 做的事：
1. 检查 / 生成 Laravel `APP_KEY`
2. 构建镜像
3. 拉起 mysql + redis 等到 healthy
4. 跑 `php artisan migrate --force`
5. 拉起 api / admin / traefik，等 Let's Encrypt 自动签证书

完成后访问：

| URL | 服务 |
|---|---|
| `https://admin.example.com/admin` | Filament 后台 |
| `https://api.example.com/api/...` | Go API |
| `wss://api.example.com/ws/chat` | WebSocket（自动 TLS） |
| `https://traefik.admin.example.com` | Traefik Dashboard（BasicAuth）|

HTTP 流量自动 301 到 HTTPS。

### 后续更新

```bash
git pull
./deploy.sh update      # 只重建 api/admin，db/traefik 不动
```

其他命令：

```bash
./deploy.sh status              # docker compose ps
./deploy.sh logs                # 全部日志
./deploy.sh logs api            # 只看某个服务
./deploy.sh down                # 停掉
```

### 证书

- 自动续期，无需操心（Traefik 在过期前 30 天会自动重签）
- 证书存在 docker volume `traefik_acme`，**别删**
- 想用 staging 环境（避免被吊销额度）测试一下时，去 `docker-compose.prod.yml` 把 `caserver=...staging...` 那行注释解开重启

### 安全建议

- `.env.prod` 千万别 commit（已经在 `.gitignore`）
- `JWT_SECRET` / `DB_PASSWORD` / `DB_ROOT_PASSWORD` 用 `openssl rand -hex 32` 生成
- Traefik Dashboard 的 BasicAuth 密码也别用弱口令
- 真要严格的话，把 `traefik.${ADMIN_DOMAIN}` 这个路由整个从 prod 文件里删掉，不让外网知道有 Dashboard

### 文件清单

| 文件 | 作用 |
|---|---|
| `docker-compose.yml` | 开发版（localhost + Traefik 反代） |
| `docker-compose.override.example.yml` | 拷一份本地放开 DB 端口调试 |
| `docker-compose.prod.yml` | 生产版（HTTPS + 自动签证书 + 备份）|
| `.env.prod.example` | 生产环境变量模板 |
| `deploy.sh` | 一键部署脚本 |
| `ops/backup/` | 备份容器（mysqldump → OSS） |
| `.github/workflows/` | CI + 自动部署 |

---

## CI / CD（GitHub Actions）

仓库下 `.github/workflows/` 两个 workflow：

- **ci.yml** —— 每次 push / PR 跑：Go 的 `gofmt` + `go vet` + `go build`、Laravel 的 `php -l`、Swift 语法检查、`docker compose config` 验证
- **deploy.yml** —— push 到 `main` 时通过 SSH 自动部署到生产服务器（执行 `git pull && ./deploy.sh update`）

### 配置 GitHub Secrets

`Settings → Secrets and variables → Actions → New repository secret`：

| Secret | 说明 |
|---|---|
| `SSH_HOST` | 服务器 IP 或域名 |
| `SSH_USER` | 部署用户（建议非 root，加入 docker 组）|
| `SSH_PRIVATE_KEY` | SSH 私钥（本地 `cat ~/.ssh/id_ed25519` 复制全部内容） |
| `DEPLOY_PATH` | 仓库在服务器上的绝对路径，如 `/www/wwwroot/video` |
| `NOTIFY_WEBHOOK` | （可选）部署通知 webhook（飞书/钉钉/Slack） |

服务器侧准备：

```bash
# 1. 把仓库 clone 到 DEPLOY_PATH
sudo mkdir -p /www/wwwroot/video
sudo chown $USER /www/wwwroot/video
git clone <你的仓库> /www/wwwroot/video

# 2. 把部署用户的 SSH 公钥加进 ~/.ssh/authorized_keys
# （deploy.yml 里的 SSH_PRIVATE_KEY 对应这把公钥）

# 3. 把 .env.prod 准备好（参见 README 上面的"生产部署"章节）
```

之后每次 `git push origin main`，workflow 自动跑：

1. 跑 CI（lint / 编译）
2. 通过则 SSH 进服务器 `git pull && ./deploy.sh update`
3. 通知 webhook（如果配了）

手动触发：仓库 → Actions → Deploy → Run workflow。

---

## 数据库自动备份（mysqldump → OSS）

prod compose 里已经接好了 `backup` 服务：

- 每天 **03:00** 自动 `mysqldump` 全库
- gzip 压缩，按 `backups/YYYY/MM/DD/<dbname>-<时间戳>.sql.gz` 命名
- 上传到阿里云 OSS（也支持 AWS S3 / MinIO，改 `OSS_ENDPOINT` 即可）
- 本地保留 7 天，OSS 保留 30 天（可配）
- 成功 / 失败可发 webhook 通知

### 配置

`.env.prod` 里填好这几个：

```bash
OSS_ENDPOINT=https://oss-cn-hangzhou.aliyuncs.com
OSS_BUCKET=your-backup-bucket
OSS_AK=your-access-key-id
OSS_SK=your-access-key-secret
LOCAL_KEEP_DAYS=7
OSS_KEEP_DAYS=30
NOTIFY_WEBHOOK=https://open.feishu.cn/open-apis/bot/v2/hook/...   # 可选
```

阿里云控制台先建好 Bucket，**RAM 子账号**只授予该 Bucket 的 `oss:PutObject / GetObject / DeleteObject / ListObjects`，AK/SK 用这个子账号的，别用主账号。

### 手动备份 / 恢复

```bash
./deploy.sh backup
# → 立刻跑一次 mysqldump，能在 `oss://${OSS_BUCKET}/backups/YYYY/MM/DD/...` 看到

# 从 OSS 恢复
./deploy.sh restore oss://${OSS_BUCKET}/backups/2026/05/02/video_platform-20260502-030000.sql.gz

# 从本地文件恢复（容器里 /backups/ 路径）
./deploy.sh restore /backups/video_platform-20260502-030000.sql.gz
```

恢复脚本会要求你输入数据库名做确认，避免误操作。

### 看备份是否在跑

```bash
./deploy.sh logs backup       # 看 cron + backup.sh 输出
docker exec video-backup ls -la /backups   # 看本地副本
```

---

## 手动启动（不用 Docker）

### 1. 数据库（任选）

A. 走 Laravel：
```bash
cd admin
composer create-project laravel/laravel . --prefer-dist  # 第一次部署
# 把仓库里的 app/、database/、routes/、config/ 等覆盖进去
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
```

B. 走纯 SQL（不想跑 PHP 也可以）：
```bash
mysql -u root -p < api/migrations/schema.sql
mysql -u root -p video_platform < api/migrations/seed.sql
```

### 2. Go API

```bash
cd api
cp .env.example .env  # 改 DB 连接
go mod tidy
go run ./cmd/server   # http://localhost:8000
```

### 3. Laravel admin 后台

```bash
cd admin
php artisan filament:install --panels  # 第一次
php artisan serve                       # http://localhost:8000/admin
# 注意：admin 和 api 都默认用 :8000，部署时要分两个端口或两个域名
# 推荐：api → :8000；admin → :8001 或 admin.yourdomain.com
```

> ⚠️ 部署提示：`find /www/wwwroot/video -name artisan` 没结果，是因为
> `artisan` 这个文件由 `composer create-project laravel/laravel` 生成。
> 你需要先在 `admin/` 目录跑一次 `composer create-project laravel/laravel . --prefer-dist`
> 再把仓库里写好的 `app/Models/`、`app/Filament/Resources/`、迁移文件等覆盖进去。

### 4. iOS

把 `ios/VideoApp/` 拖进 Xcode 新建的 App 工程，运行模拟器即可。

## 默认账号（seed）

| 邮箱                | 密码      | 角色  |
|---------------------|-----------|-------|
| admin@example.com   | password  | admin |
| alice@example.com   | password  | user  |
| bob@example.com     | password  | user  |

## 端口建议

| 服务                | 默认端口 | 路径                          |
|---------------------|----------|-------------------------------|
| Go API              | 8000     | /api/* + /ws/chat             |
| Laravel admin       | 8001     | /admin                        |
| MySQL               | 3306     |                               |

iOS `APIClient.baseURL` 默认 `http://localhost:8000/api`。
