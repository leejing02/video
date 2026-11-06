# 部署

由用户本人在服务器执行；AI 没有服务器 SSH 权限，不得索要或输入服务器密码。

## 服务器

- 站点 `www.e3y5.cc` 解析到 `39.109.116.154`（2026-11-07 核对；旧地址 `39.109.117.168` 已不用）。
- 项目目录 `/www/wwwroot/video`，生产配置 `/www/wwwroot/video/.env.prod`（不入库）。
- 服务器当前分支应为 `codex/social-chat-integration-20261103`。

## 必需的环境变量（只列名称）

`EFV_ENDPOINT`、`EFV_API_KEY`、`EFV_CMS_API_KEY`、`EFV_UPLOAD_KEY`、`EFV_API_SECRET`、`EFV_PLAYBACK_KEY`、
`EFV_IMAGE_AES_KEY`、`EFV_IMAGE_AES_IV`、`EFV_IMAGE_AES_CIPHER`（api 与 admin 都需要后三项）。
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
- 出现 `Not possible to fast-forward` 时停止，提供 `git status` 与 `git log --oneline -5` 再处理。
- `deploy/` 或 `.env.prod` 变化时，命令不会自动处理，需人工判断重建范围。

## 部署后检查

- 版本：`git -C /www/wwwroot/video log --oneline -1`
- 封面配置：`curl -s https://www.e3y5.cc/api/efv-media`（返回含密钥，勿外传）
- 线上前端版本：查看 `https://www.e3y5.cc/short` 引用的 `/assets/main-*.css` 是否包含新规则。

## 回滚

服务器上 `git log --oneline` 找到上一个可用提交，在**新分支**上检出后按上面的重建命令重建对应服务；不改写远程历史。（待用户确认回滚流程）
