# 视频与聊天改版：更新与验收

## 本次行为

- 主导航：群聊、短视频、长视频、我的。直播前台入口移除，`/live` 重定向到短视频。
- 深色橙红视频应用外观；聊天采用蓝灰双栏和气泡，手机显示会话列表/详情两级。
- 固定视口，内容内部滚动，16px 输入，移动端缩放限制与键盘高度适配。浏览器菜单缩放不能强制禁止。
- 后台维护的活动群组显示在会话列表，点击群组自动加入；点击群消息头像或昵称发起私聊。
- 私聊使用双方唯一标识复用会话，事务创建两名成员；不可自聊或与非共享群成员发起会话。
- 单条鉴权通知连接接收授权房间的新消息，消息按 ID 去重。断线重连、页面重新可见及每 15 秒同步当前会话的缺失消息；新会话列表也同步。
- 历史消息使用 before_id，断线恢复使用 after_id（含 0），正序分批补回。

## API

- `GET /api/chat/rooms`：原有房间字段 + `last_message`、`unread_count`、`member_count`；私聊 name 为对方昵称。
- `POST /api/chat/direct`：`{user_id, context_room_id}`，要求当前用户能访问上下文群，目标用户是活动的群成员或群发言者。
- `GET /api/chat/rooms/:id/messages?after_id=0&limit=100`：按 ID 正序恢复；与 before_id 互斥。
- `GET /ws/chat?room_id=0&token=...`：个人会话通知；非全局消息仅推送给该房间当前成员。
- 原有 room_id>0 的 WebSocket 仍兼容。

复用现有 chat_rooms、chat_room_user、chat_messages 表，不需要新增列。当前实时广播为单个 Go API 进程，不支持跨多个 API 实例广播。

## 服务器更新

1. 线上基线已保存在 `codex/server-baseline-20261102`。整合分支为 `codex/social-chat-integration-20261103`。
2. 先检查 `git status --short`，保留任何线上改动。工作区可切换后执行：

```bash
cd /www/wwwroot/video
git fetch origin
git switch --track origin/codex/social-chat-integration-20261103
```

若 Git 提示本地文件会被覆盖，停止并比较，不使用 reset --hard。以后在该分支同步可执行 `bash scripts/git-sync-server.sh codex/social-chat-integration-20261103`。

3. 用当前已经配置好的生产与源码构建配置更新两个服务：

```bash
docker compose --project-directory . --env-file .env.prod -f deploy/docker-compose.prod.yml -f deploy/docker-compose.source.yml up -d --build --no-deps api web
```

4. 浏览器强制刷新，两个前台账号进入同一群聊，确认消息无刷新到达。点击另一账号头像发起私聊；另一方应收到会话和消息。切到其他群后返回，确认历史不串群；断网后重新联网确认补回消息。
5. 后台无需重建，数据库卷不删除，群由后台管理。

整合提交仅修改 api/web 源码、测试、同步文档和工作流；保留基线中的后台、HTTPS 与 Nginx 修复。自动部署的 push 触发已取消；人工触发也只允许在干净的 main 分支快进更新，不再执行强制重置。

## 本地验证记录

- `make test-api vet build-api`：通过，包括私聊共享群限制、双方会话复用、事务失败回滚、停用群拒绝访问、第三方通知隔离、after_id=0 及普通游标正序补消息。
- `node --test web/test/ws.test.mjs`：通过，旧连接消息和关闭回调不影响新连接。
- `web/node_modules/.bin/vue-tsc --noEmit -p web/tsconfig.app.json`：通过。
- Vite 生产构建：通过，输出至临时目录，没有覆盖用户现有 web/dist。
- 独立本机 HTTP/WebSocket 测试服务中，两个浏览器会话完成群聊消息实时更新、从头像创建私聊、私聊双向收发；检查桌面及 390px 手机布局。
- 本机 Docker daemon 未运行，未运行真实 MySQL 全栈测试，也未操作生产服务器；SQL 单元测试使用 sqlmock，浏览器联调用受控测试服务。
