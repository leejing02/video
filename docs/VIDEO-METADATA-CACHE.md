# 视频数据两层缓存

## 读取路径

公共长/短视频列表、视频详情和分类：本机内存 → 本机 JSON 文件 → Redis → MySQL。
本机内存与文件属于本地缓存层。数据库返回成功后写入 Redis，并通过临时文件加原子重命名写入本机文件；Redis 故障不阻断数据库回源和本机缓存。

缓存的是数据库原始元数据。每次读取反序列化出独立副本，再应用当前媒体域名、EFV 播放鉴权。不会把某个用户的签名地址或响应中的地址改写保存回公共缓存。

- 分类、关键词、页大小、偏移、视频类型、视频 ID 均参与对应缓存键。
- 个人视频列表直接查库，避免私人内容进入公共缓存。
- 播放器进入、切换视频和恢复前台时，直接查询当前账号点赞/收藏状态及公共计数；可见时每 15 秒更新一次，不经过元数据缓存。评论数量按实际评论记录统计，包含所有层级。
- 评论面板打开即查询，可见时每 15 秒同步首屏；观看、点赞、评论写入仍直接写数据库。
- 下架、删除、封面修改和列表上的计数在后台刷新后生效，不承诺即时一致。播放鉴权仍实时计算。

## 自动刷新

Go API 内置定时任务，API 启动即运行，不依赖宿主机 crontab 或 Laravel schedule。

- 每 60 秒从数据库重新读取最近 10 分钟访问过的缓存键，并更新 Redis、本机文件和内存。
- 本轮没有后台编辑时也会刷新，以覆盖 SQL 批量同步、EFV 导入、删除、用户昵称/头像和计数变化，不依赖 Eloquent 模型事件。
- API 启动会预热短视频分类、长视频分类、全部短视频首屏 30 条、长视频首屏 20 条。其他分页/筛选按首次访问加入任务。
- 缓存有效期 90 秒，定时任务通常在一分钟内传播后台更新。刷新失败不延长旧数据有效期；到期后回源失败会返回错误，不无限展示旧的已下架视频。
- 同一个键的并发冷请求共用一次查询；后台刷新不阻塞仍在有效期内的本机命中。
- 最多保留 128 个近期键，单项大于 512 KiB 时不缓存；清理超过 10 分钟未更新的旧文件。
- 多个 API 实例分别维护本机缓存，最长以 90 秒有效期收敛；当前部署是单 API 容器。

## 配置与部署

生产 Compose 已配置 Redis DB 2 与持久卷 `video_metadata_cache`，目录 `/var/cache/video-metadata`。Redis 连接每次操作超时 200ms，不重试拖慢用户请求。Redis 的缓存键使用 `video-metadata:v1:` 前缀及数据库命名空间摘要。

可在 `.env.prod` 设置 `VIDEO_CACHE_ENABLED=false` 后重建 API，临时回到直接查库。
独立运行 Go API 可用以下环境变量：

```dotenv
VIDEO_CACHE_ENABLED=true
VIDEO_CACHE_DIR=/var/cache/video-metadata
VIDEO_CACHE_REDIS_ADDR=redis:6379
VIDEO_CACHE_REDIS_DB=2
VIDEO_CACHE_REDIS_PASSWORD=
```

服务器更新：

```bash
cd /www/wwwroot/video
git pull --ff-only origin codex/social-chat-integration-20261103
docker compose --project-directory . --env-file .env.prod -f deploy/docker-compose.prod.yml -f deploy/docker-compose.source.yml up -d redis
docker compose --project-directory . --env-file .env.prod -f deploy/docker-compose.prod.yml -f deploy/docker-compose.source.yml up -d --build --no-deps api
```

只需更新 API，不需要清空 Redis、不需要重建数据库或后台。查看任务日志及缓存落盘：

```bash
docker logs --since=5m video-api 2>&1 | grep 'video cache:'
docker exec video-api ls -lh /var/cache/video-metadata
docker exec video-redis redis-cli -n 2 --scan --pattern 'video-metadata:v1:*'
```

## 验证

- SQL mock 验证：同一分类连续 100 次请求只读取一次数据库，刷新删除后列表正确清空。
- 验证筛选键隔离、私人列表绕过缓存、并发冷加载、Redis 故障回源、本机文件重启复用、Redis 命中回填本机。
- 验证缓存结果改写不污染下一次读取、刷新失败不延长过期内容、后台刷新不阻塞有效的本机命中。
- 以上查询计数为自动化测试结果，线上响应时间和命中率需部署后按实际流量观测。
