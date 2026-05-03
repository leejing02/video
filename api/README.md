# video-api — Go (Gin + sqlx) 后端 API

## 启动

```bash
cd api
cp .env.example .env
# 改 .env 里的数据库连接

# 第一次没有数据库表？跑 schema.sql + seed.sql
mysql -u root -p < migrations/schema.sql
mysql -u root -p video_platform < migrations/seed.sql

go mod tidy
go run ./cmd/server
# → :8000
```

部署到宝塔 `/www/wwwroot/video/api`：

```bash
cd /www/wwwroot/video/api
go mod tidy
go build -o video-api ./cmd/server
nohup ./video-api > video-api.log 2>&1 &
```

## 默认账号（来自 seed.sql）

| 邮箱                  | 密码      | 角色  |
|-----------------------|-----------|-------|
| admin@example.com     | password  | admin |
| alice@example.com     | password  | user  |
| bob@example.com       | password  | user  |

## API 一览（与 Laravel 版完全一致）

公开：
- `POST /api/register`、`POST /api/login`
- `GET  /api/categories?kind=long|short`
- `GET  /api/videos?type=long|short&category_id=&q=&page=&per_page=`
- `GET  /api/videos/:id`
- `GET  /api/videos/:id/comments`
- `GET  /api/chat/global`

需要 Bearer JWT：
- `POST /api/logout`
- `GET  /api/me`、`PATCH /api/me`
- `POST /api/videos/:id/like`
- `GET  /api/me/videos`
- `POST /api/videos/:id/comments`
- `DELETE /api/comments/:id`
- `GET  /api/chat/rooms`
- `GET  /api/chat/rooms/:id/messages?before_id=&limit=`
- `POST /api/chat/rooms/:id/messages`
- `POST /api/chat/rooms/:id/join`
- `POST /api/chat/rooms/:id/read`

WebSocket：
- `GET /ws/chat?room_id=N&token=<JWT>`
- 服务端推送：`{ "event": "message.sent", "data": <ChatMessage> }`

## 目录

```
api/
├── cmd/server/main.go        程序入口（路由 + DI）
├── internal/
│   ├── config/               .env 加载
│   ├── db/                   sqlx 连接
│   ├── auth/                 JWT + bcrypt
│   ├── middleware/           AuthRequired / AuthOptional / AdminOnly
│   ├── models/               与表对应的 struct
│   ├── repos/                数据访问层（sqlx 查询）
│   ├── handlers/             gin handler（auth/video/category/comment/chat）
│   └── ws/                   WebSocket Hub + Client
├── migrations/
│   ├── schema.sql            手动建表（与 Laravel migration 等价）
│   └── seed.sql              种子数据（admin / alice / bob + 视频 + 全局群）
└── go.mod
```

## 与 admin（Laravel + Filament）的关系

- **数据库共用**：两边都连 `video_platform` 同一个 MySQL 库
- **写表方式**：用 Laravel migrations 或直接跑 `schema.sql` 都行，二选一
- **职责切分**：
  - admin 只跑后台管理界面（`/admin`），不对外提供 API
  - api 给 iOS App 提供所有 REST + WebSocket
- **密码兼容**：bcrypt 算法，`$2a` / `$2y` / `$2b` 三种前缀互通，
  Laravel 创建的用户在 Go 这边能直接登录，反之亦然
