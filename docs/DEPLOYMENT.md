# 部署

由用户本人在服务器执行；AI 没有服务器 SSH 权限，不得索要或输入服务器密码。

## 服务器

- 站点 `www.e3y5.cc` 解析到 `39.109.116.154`（2026-11-07 核对；旧地址 `39.109.117.168` 已不用）。
- 项目目录 `/www/wwwroot/video`，生产配置 `/www/wwwroot/video/.env.prod`（不入库）。
- 服务器当前分支应为 `codex/social-chat-integration-20261103`。

## 必需的环境变量（只列名称）

`EFV_ENDPOINT`、`EFV_API_KEY`、`EFV_CMS_API_KEY`、`EFV_UPLOAD_KEY`、`EFV_API_SECRET`、`EFV_PLAYBACK_KEY`、
`EFV_IMAGE_AES_KEY`、`EFV_IMAGE_AES_IV`、`EFV_IMAGE_AES_CIPHER`（admin 下载副本时解密；api 通过 `/api/efv-media` 提供给浏览器解密）。
本机根目录 `.env.prod` 不含 EFV 配置；`env.prod_1106.tar.gz` 里的副本含有。

## 更新命令（只快进，按改动目录重建）

```bash
cd /www/wwwroot/video && \
BR=$(git symbolic-ref --short HEAD) && OLD=$(git rev-parse HEAD) && \
git fetch origin "refs/heads/$BR" && git merge --ff-only FETCH_HEAD && \
CHANGED=$(git diff --name-only "$OLD" HEAD) && echo "$CHANGED" && \
SVCS=$(for s in api admin web; do echo "$CHANGED" | grep -q "^$s/" && echo "$s"; done | xargs) && \
[ -n "$SVCS" ] && docker compose --project-directory . --env-file .env.prod \
  -f deploy/docker-compose.prod.yml -f deploy/docker-compose.source.yml \
  up -d --build --no-deps $SVCS
```

- 不使用 `reset --hard`、`down -v`、`migrate:fresh`。
- 线上数据库由 `schema.sql` 建表，Laravel 迁移记录不全：**不要直接运行 `php artisan migrate`**（会从建 users 表开始重跑并报错），新迁移一律用 `--path=` 指定单个文件。
- 出现 `Not possible to fast-forward` 时停止，提供 `git status` 与 `git log --oneline -5` 再处理。
- `deploy/` 或 `.env.prod` 变化时，命令不会自动处理，需人工判断重建范围。

## 部署后检查

- 版本：`git -C /www/wwwroot/video log --oneline -1`
- 封面：前台封面请求发往图片域名，`img` 的 `src` 为 `blob:`；若为 `/storage/shorts/covers/…` 说明解密失败或该视频尚无 `cover_source`。
- 线上前端版本：查看 `https://www.e3y5.cc/short` 引用的 `/assets/main-*.css` 是否包含新规则。

## 数据库字段 `videos.cover_source`（2026-11-07 新增）

Go 接口会查询该字段，**必须先迁移数据库再重建 api**，否则短视频接口会报错。包含该改动的部署按下面顺序：

```bash
cd /www/wwwroot/video && C="docker compose --project-directory . --env-file .env.prod -f deploy/docker-compose.prod.yml -f deploy/docker-compose.source.yml" && \
git fetch origin "refs/heads/$(git symbolic-ref --short HEAD)" && git merge --ff-only FETCH_HEAD && \
$C up -d --build --no-deps admin && \
$C exec admin php artisan migrate --force --path=database/migrations/2026_11_07_000000_add_cover_source_to_videos.php && \
$C up -d --build --no-deps api web && \
$C exec admin php artisan efv:refresh-covers
```

`efv:refresh-covers` 把已发布 EFV 短视频的封面1 地址写入数据库并更新本站副本；只处理单条时末尾加 `--video=1027`。前台约 90 秒内（Go 元数据缓存刷新）生效。

## 长视频 V1 部署提醒（2026-11-08，尚未部署）

本节只是提醒；本次没有执行任何部署。

- 功能提交 `6005351`（分支 `claude/nav-category-recommend`）同时改了 `web/` 和 `api/`（`api/internal/models/models.go`）。目前只是本地提交，未推送、未合并到服务器分支 `codex/social-chat-integration-20261103`；上面的更新命令只快进服务器当前分支，合并和推送方式需用户先决定。
- 合入后，更新命令会按改动目录同时重建 `api` 和 `web`。部署后核对两个服务来自同一提交；前端的长视频分类、列表、详情页依赖新版 api 正常返回 `/api/videos`。
- 本次**没有数据库迁移**，不需要也不要运行 `php artisan migrate`。
- `models.go` 的修复依赖数据库实际结构：`Video` 结构补齐了 `videos` 表的 7 列（`cover_source`、`md5`、`size_bytes`、`audit_status`、`audit_note`、`audited_by`、`audited_at`），供 `SELECT v.*` 映射。生产库 `videos` 表如果还有这 7 列以外、结构里没有的列，长视频列表和详情仍会返回 500；缺少其中某列则不会因此报错。
- 生产库 `videos` 表的实际列**尚未确认**。部署前应先只读核查（`information_schema.COLUMNS`），不要假设字段已全部确认。

## 回滚

服务器上 `git log --oneline` 找到上一个可用提交，在**新分支**上检出后按上面的重建命令重建对应服务；不改写远程历史。（待用户确认回滚流程）
