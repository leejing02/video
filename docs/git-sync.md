# 本地、GitHub、服务器同步

仓库：git@github.com:leejing02/video.git
服务器：39.109.117.168；目录：/www/wwwroot/video；SSH 端口待确认（本机连接 22 超时）。

以 GitHub 为源码汇合点：本地开发 → 功能分支 push → 审查合并 main → 服务器快进同步 → 显式重建容器。

## 首次接入前必须处理

- 本地当前 main 比 origin/main 落后 1 个提交，仅涉及 .gitignore；工作区存在大量未提交源码和未跟踪文件，不直接 pull/强制覆盖。
- Git 已跟踪 web/node_modules（约 5600 文件）和 web/dist。后续清理必须只移除索引，不删除本地依赖目录，保留 package-lock.json。
- SQL 导出、压缩备份、运行日志、密钥和真实环境配置不进入提交。保留 api/migrations/schema.sql、seed.sql 与 Laravel migrations。
- 服务器此前修改过 admin/docker/entrypoint.sh、admin/bootstrap/app.php、admin/Dockerfile、Traefik 版本和源码构建覆盖配置。先检查、备份并将有效修复整合回仓库，再部署。
- 远程现有部署 workflow 在 push main 后可能运行 git reset --hard，且部署方式与当前源码构建不同。首次同步在功能分支完成，并先审查工作流，不能直接 push main。

## 服务器检查（只读）

```bash
cd /www/wwwroot/video
git status --short
git branch --show-current
git remote -v
```

如果不是 Git 仓库，不要直接在现有目录覆盖 clone：先备份现有源码和环境文件，在独立目录 clone 后比较并整合线上修复。

## 日常源码同步

本地确认分支和 diff，提交精确的源码路径，不使用未审查的 git add .；提交前检查暂存区没有凭证和数据库导出。

服务器工作区干净且当前分支与目标相同后：

```bash
bash scripts/git-sync-server.sh main
```

脚本仅 fetch + fast-forward，不 stash、不 reset、不删文件、不自动重启。发现本地改动或分叉时停止，先人工合并。

确认源码无误后，再按服务器已有配置显式部署：

```bash
docker compose --project-directory . --env-file .env.prod -f deploy/docker-compose.prod.yml -f deploy/docker-compose.source.yml up -d --build --no-deps api web
```

如修改了后台，先确保线上修复已经纳入源码，再单独更新 admin。不得运行 down -v 或 migrate:fresh。

生产 .env.prod 应由服务器独立维护。使用只读仓库 Deploy Key 拉取源码；私钥和服务器密码不要通过聊天发送。
