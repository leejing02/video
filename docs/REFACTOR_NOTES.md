# 后台重构后 Go API 同步变更清单

本次后台（admin/Laravel）完成的 5 个结构性重构：

1. **categories 合并** —— 长 / 短 / 直播 / 聊天 分类合并到一张 `categories` 表，靠 `type` 字段（`video` / `short` / `live` / `chat`）区分。删除 `short_categories` 和 `chat_room_categories` 表。
2. **chat_rooms.kind → type** —— 列名 `kind` 改为 `type`，枚举值 `global/group/direct` 简化为 `public/group`。
3. **DDD 重构** —— Laravel models 从 `app/Models/` 迁到 `app/Domains/{Chat,Video,Live,User,Moderation}/Models/`（不影响数据库）。
4. **chat_messages 列名** —— `chat_room_id` 改名为 `room_id`。
5. **后台账号分离** —— 新增 `admin_users` 表，与 C 端 `users` 表物理隔离；后台 RBAC 走 `admin` guard。

下面是 Go 端必须跟着改的地方，按文件列出。

---

## 1. `api/migrations/schema.sql`

### `categories` 表（重建）

```sql
-- 旧
-- CREATE TABLE `categories` (
--   `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--   `name` VARCHAR(60) NOT NULL,
--   `slug` VARCHAR(80) NOT NULL,
--   `kind` ENUM('long','short','both') NOT NULL DEFAULT 'both',
--   ...
--   KEY `idx_kind_active` (`kind`, `is_active`)
-- );

-- 新
CREATE TABLE IF NOT EXISTS `categories` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(60) NOT NULL,
  `slug` VARCHAR(80) NOT NULL,
  `type` ENUM('video','short','live','chat') NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `icon` VARCHAR(255) NULL,
  `cover` VARCHAR(255) NULL,
  `description` TEXT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `uk_categories_slug` (`slug`),
  KEY `idx_type_active_sort` (`type`, `is_active`, `sort`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`)
    REFERENCES `categories`(`id`) ON DELETE SET NULL
);
```

### 删除：`short_categories`、`chat_room_categories`

```sql
DROP TABLE IF EXISTS `short_categories`;
DROP TABLE IF EXISTS `chat_room_categories`;
```

`short_videos.category_id` 的 FK 改为指向 `categories(id)`（type='short' 由应用层保证）：

```sql
ALTER TABLE `short_videos`
  DROP FOREIGN KEY `fk_sv_category`,
  ADD CONSTRAINT `fk_sv_category`
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE;
```

### `chat_rooms` 表

```sql
ALTER TABLE `chat_rooms`
  CHANGE COLUMN `kind` `type` ENUM('public','group') NOT NULL DEFAULT 'group',
  DROP INDEX `idx_kind_active`,
  ADD INDEX `idx_type_active` (`type`, `is_active`);

-- 历史 'global' → 'public'，'direct' 没有对应类型，建议手工清理或并入 'group'
UPDATE `chat_rooms` SET `type` = 'public' WHERE `type` = 'global';
-- direct 类型不再支持，先报警再决定
```

`chat_rooms.category_id` 现在指向统一的 `categories` 表：

```sql
ALTER TABLE `chat_rooms`
  DROP FOREIGN KEY IF EXISTS `fk_chat_rooms_category`,
  ADD CONSTRAINT `fk_chat_rooms_category`
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL;
```

### `chat_messages` 表

```sql
ALTER TABLE `chat_messages`
  DROP FOREIGN KEY `fk_msg_room`,
  DROP INDEX `idx_room_created`,
  CHANGE COLUMN `chat_room_id` `room_id` BIGINT UNSIGNED NOT NULL,
  ADD INDEX `idx_room_created` (`room_id`, `created_at`),
  ADD CONSTRAINT `fk_msg_room`
    FOREIGN KEY (`room_id`) REFERENCES `chat_rooms`(`id`) ON DELETE CASCADE;
```

注意 **`chat_room_user` 透视表的 `chat_room_id` 列不动**（本次重构仅命名了 `chat_messages.room_id`）。

### 新增：`admin_users` 表

```sql
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(60) NOT NULL,
  `username` VARCHAR(60) NOT NULL,
  `email` VARCHAR(120) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` TIMESTAMP NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `two_factor_secret` TEXT NULL,
  `two_factor_recovery_codes` TEXT NULL,
  `remember_token` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  `deleted_at` TIMESTAMP NULL,
  UNIQUE KEY `uk_admin_users_username` (`username`),
  UNIQUE KEY `uk_admin_users_email` (`email`)
);

CREATE TABLE IF NOT EXISTS `admin_password_reset_tokens` (
  `email` VARCHAR(255) NOT NULL PRIMARY KEY,
  `token` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL
);
```

Go API 不直接读 admin_users 表（后台账号只在 Filament 用），但如果你要做 admin-side JWT，需要这张表。

### 删除 / 移动：`users.role` 列

Laravel 端已不再读 `users.role`。一个发布周期之后可以安全 drop：

```sql
ALTER TABLE `users` DROP COLUMN `role`;
```

Go 端如果还在判断 `users.role`，先迁移到 RBAC 再删列。

---

## 2. `api/migrations/seed.sql`

把 `kind` 全部换成 `type`，把 `'long'` 改成 `'video'`，把 `'global'` 改成 `'public'`：

```sql
-- categories
INSERT INTO `categories` (`name`, `slug`, `type`, `icon`, `sort`, `is_active`, ...)
VALUES
  ('影视剧', 'movies',  'video', 'film.fill',    0, 1, ...),
  ('搞笑',   'funny',   'short', 'face.smiling', 0, 1, ...),
  ...;

-- chat_rooms
INSERT INTO `chat_rooms` (`id`, `name`, `slug`, `description`, `type`, `is_active`, `owner_id`, ...)
VALUES (1, '广场', 'public-square', '...', 'public', 1, 1, ...);

-- chat_messages
INSERT INTO `chat_messages` (`room_id`, `user_id`, `type`, `content`, ...) VALUES ...;

-- 维护语
UPDATE `chat_rooms` SET last_message_at = NOW() WHERE type = 'public';
```

---

## 3. `api/internal/models/models.go`

```go
// 旧
type Category struct {
    ID        int64
    Name      string
    Slug      string
    Kind      string    `db:"kind"      json:"kind"`
    ...
}

// 新
type Category struct {
    ID          int64
    Name        string
    Slug        string
    Type        string  `db:"type"       json:"type"`        // video/short/live/chat
    ParentID    *int64  `db:"parent_id"  json:"parent_id"`
    Description *string `db:"description" json:"description,omitempty"`
    ...
}

// 旧
type ChatRoom struct {
    ...
    Kind          string  `db:"kind"  json:"kind"`
}

// 新
type ChatRoom struct {
    ...
    Type          string  `db:"type"  json:"type"`   // public/group
}

// 旧
type ChatMessage struct {
    ...
    ChatRoomID int64  `db:"chat_room_id" json:"chat_room_id"`
}

// 新
type ChatMessage struct {
    ...
    RoomID int64  `db:"room_id" json:"room_id"`
}
```

**Note**: `short_video_likes.kind` 是另一个独立的 `kind`（like / favorite），跟分类的 `kind` 没关系，保持不动。

---

## 4. `api/internal/repos/category_repo.go`

```go
// 旧签名
func (r *CategoryRepo) List(kind string) ([]models.Category, error) {
    // ... WHERE kind IN (?, 'both')
}

// 新签名
func (r *CategoryRepo) List(categoryType string) ([]models.Category, error) {
    if categoryType == "" {
        return r.listAll()
    }
    var cats []models.Category
    err := r.DB.Select(&cats, `
        SELECT id, name, slug, type, parent_id, icon, sort, is_active
        FROM categories
        WHERE is_active = 1 AND type = ?
        ORDER BY sort ASC, id ASC
    `, categoryType)
    return cats, err
}
```

去掉 `'both'` 这种"通用"分支 —— 新模型里分类必须明确归属一个 type。

---

## 5. `api/internal/repos/chat_repo.go`

把所有 `chat_messages.chat_room_id` 改成 `room_id`，把 `chat_rooms.kind = 'global'` 改成 `chat_rooms.type = 'public'`：

```go
// 列出可见聊天室
const listRoomsSQL = `
    SELECT r.*, COUNT(m.id) AS unread_count
    FROM chat_rooms r
    LEFT JOIN chat_room_user pu ON pu.chat_room_id = r.id AND pu.user_id = ?
    LEFT JOIN chat_messages m ON m.room_id = r.id AND m.created_at > pu.last_read_at
    WHERE r.is_active = 1 AND (r.type = 'public' OR pu.user_id = ?)
    GROUP BY r.id
`

// 全局/首页聊天室
const getPublicRoomSQL = `
    SELECT * FROM chat_rooms WHERE type = 'public' AND is_active = 1 LIMIT 1
`

// 消息列表
const listMessagesSQL = `
    SELECT m.*, u.id AS user_id, u.name AS user_name, u.avatar AS user_avatar
    FROM chat_messages m
    JOIN users u ON u.id = m.user_id
    WHERE m.room_id = ?
    ORDER BY m.id DESC
    LIMIT ? OFFSET ?
`

// 写入消息
const insertMessageSQL = `
    INSERT INTO chat_messages
      (room_id, user_id, type, content, reply_to_id, attachment_url, meta, created_at, updated_at)
    VALUES
      (:room_id, :user_id, :type, :content, :reply_to_id, :attachment_url, :meta, NOW(), NOW())
`

// 更新最后活跃时间（用新字段 RoomID）
tx.Exec(`UPDATE chat_rooms SET last_message_at = NOW() WHERE id = ?`, m.RoomID)
```

**`chat_room_user` 透视表的 `chat_room_id` 列不变**，不要误改。

---

## 6. `api/internal/repos/short_video_repo.go`

`short_categories` 表没了，改成查 `categories WHERE type='short'`：

```go
// 旧
err := r.DB.Select(&cats,
    `SELECT * FROM short_categories WHERE is_active = true ORDER BY sort ASC, id ASC`)

// 新
err := r.DB.Select(&cats, `
    SELECT id, name, slug, type, parent_id, icon, sort, is_active
    FROM categories
    WHERE type = 'short' AND is_active = 1
    ORDER BY sort ASC, id ASC
`)
```

JOIN 也要换：

```go
// 旧
// JOIN short_categories c ON c.id = v.category_id

// 新
JOIN categories c ON c.id = v.category_id AND c.type = 'short'
```

---

## 7. `api/internal/handlers/chat.go`

```go
// 旧
if r.Kind == "global" { ... }
ChatRoomID: roomID,

// 新
if r.Type == "public" { ... }
RoomID: roomID,
```

WS endpoint `/ws/chat?room_id=...` 已经是 `room_id`，跟新列名对齐，不用改 query 名。

---

## 8. `api/internal/handlers/category.go`

```go
// 旧
kind := c.Query("kind") // long / short / 空
// 调用 repo.List(kind)

// 新
categoryType := c.Query("type") // video / short / live / chat / 空
// 调用 repo.List(categoryType)
```

如果对外要做版本兼容，可同时接受 `type` 和 `kind`（再做 long→video 映射）：

```go
categoryType := c.Query("type")
if categoryType == "" {
    if k := c.Query("kind"); k != "" {
        categoryType = map[string]string{"long": "video", "short": "short"}[k]
    }
}
```

并把 iOS / Web 客户端切到 `type` 之后清理掉这段 fallback。

---

## 9. WebSocket 广播负载（前后端约定）

Laravel `App\Events\MessageSent` 现在广播的 JSON：

```json
{
  "id": 123,
  "room_id": 1,
  "type": "text",
  "content": "...",
  "user": { "id": 5, "name": "Alice", "username": "alice", "avatar": "..." },
  "reply_to_id": null,
  "attachment_url": null,
  "created_at": "2026-05-11T..."
}
```

**Breaking change**：原来叫 `chat_room_id` 的字段现在叫 `room_id`。iOS / Web 客户端的解码模型同步修改。

---

## 10. JWT payload（建议但非必须）

Go API 当前 JWT 应该只承载 C 端 user 身份。建议加 `type` 字段防止越界：

```json
{
  "sub": 1,
  "type": "user",
  "exp": ...
}
```

后台账号永远不发 JWT（后台是 session-based）。如果未来要做 admin 调试用 API token，发独立 token，`type: "admin"`，并走独立的 admin 路由前缀（如 `/api/admin/...`）。

---

## 11. 部署顺序（避免 downtime）

数据库 schema 变更对 Go 服务有破坏性。按这个顺序最稳：

1. **冻结写入**（或上 read-only 维护页）
2. **执行迁移**：跑 Laravel `php artisan migrate:fresh --force --seed`（开发环境）或写 ALTER 脚本（生产环境）
3. **部署新 Go 二进制**（包含新模型 + 新 SQL）
4. **解冻**

如果客户端旧版（iOS / Web）还在用 `chat_room_id` / `kind=long`，等服务端发完版本再升客户端。短期可以在 Go handler 层做 alias 兼容（输出双字段），但加技术债，建议尽快切完。

---

## 12. 全部命名映射速查表

| 旧（admin pre-refactor & Go） | 新 |
| --- | --- |
| `categories.kind` ('long','short','both') | `categories.type` ('video','short','live','chat') |
| —（无） | `categories.parent_id` |
| 表 `short_categories` | 不存在；用 `categories WHERE type='short'` |
| 表 `chat_room_categories` | 不存在；用 `categories WHERE type='chat'` |
| `chat_rooms.kind` ('global','group','direct') | `chat_rooms.type` ('public','group') |
| `chat_messages.chat_room_id` | `chat_messages.room_id` |
| `chat_room_user.chat_room_id` | **不变** |
| WS payload `chat_room_id` | WS payload `room_id` |
| Go: `ChatRoom.Kind` | `ChatRoom.Type` |
| Go: `Category.Kind` | `Category.Type` + 新增 `Category.ParentID` |
| Go: `ChatMessage.ChatRoomID` | `ChatMessage.RoomID` |
| query string `kind=long` | `type=video` |
| query string `kind=short` | `type=short` |
| 表 `admin_users` | **新增** |
| `users.role` 列 | 弃用，下个版本 drop |
