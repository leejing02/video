# 项目概览

视频平台：用户网站（群聊、私聊、短视频、长视频、个人中心）+ 管理后台 + EFV 云转码对接。
详细需求与验收清单见 [REQUIREMENTS-2026-11-05.md](REQUIREMENTS-2026-11-05.md)（以该文档最后一次明确要求为准）。

## 仓库与分支

- GitHub：`leejing02/video`（公开仓库，不提交 `.env*`、SQL 备份、压缩包、密钥）
- 当前整合分支：`codex/social-chat-integration-20261103`（本地 worktree：`.worktrees/social-chat-integration-20261103`）
- 线上基线备份分支：`codex/server-baseline-20261102`
- `main`：较旧，主目录有大量未提交改动，**不要在主目录开发或直接 pull**
- 提交作者：仓库级配置 `leejing02 <leejing20220919@gmail.com>`

## 模块

| 目录 | 技术 | 职责 |
| --- | --- | --- |
| `web/` | Vue 3 + Vite + Tailwind | 用户网站；`index.html` 普通页面（白色），`player.html` iPhone 独立黑色播放页 |
| `api/` | Go 1.25 + Gin + sqlx | 公开接口、聊天 WebSocket、短视频列表/播放签名、EFV 封面地址改写、`/api/efv-media` |
| `admin/` | Laravel 11 + Filament | 后台、批量上传到 EFV、EFV 同步/导入（`app/Services/Efv*.php`、`routes/console.php`） |
| `deploy/` | Docker Compose | `docker-compose.prod.yml` + `docker-compose.source.yml`（源码构建） |

Go 与 Laravel 共用同一个 MySQL；Redis 用于 Go 的元数据/消息缓存。

## 关键数据流

- 短视频存在 `videos` 表（`type='short'`），没有单独的短视频表。
- EFV 上传 → 后台 `efv:sync-short-videos`（定时任务）补齐 M3U8、封面、时长 → 前台展示。
- 封面：后台把 EFV 封面下载为本站副本 `storage/shorts/covers/efv-<sha256>`；Go 接口把已同步封面改写为
  `image_origin/videos/<日期>/<ID>/cover.webp?v=<哈希前12位>`，浏览器按 `/api/efv-media` 的 AES 配置解密为 Blob 显示，失败退回 `cover_fallback` 本站副本。
- 媒体域名配置：`deploy/efv-media/domains.json`（`image_origin`、`m3u8_origin`），运行时读取。
- 播放：`m3u8_origin` + EFV 防盗链签名（`EFV_PLAYBACK_KEY`）。

## 测试命令

- 前端：`npm --prefix web run build`、`npm --prefix web run test:chat`
- Go：`cd api && go test ./...`
- 后台（需 PHP 8.3）：`"$HOME/Library/Application Support/Herd/bin/php83" admin/tests/efv-import-sync.php`
