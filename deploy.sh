#!/usr/bin/env bash
# ============================================================
# 一键部署 / 更新脚本
#
# 首次部署：
#   ./deploy.sh init
#
# 后续更新：
#   git pull && ./deploy.sh
# ============================================================
set -euo pipefail

cd "$(dirname "$0")"

ENV_FILE=".env.prod"
COMPOSE="docker compose --env-file ${ENV_FILE} -f docker-compose.prod.yml"

require_env() {
    if [ ! -f "${ENV_FILE}" ]; then
        echo "❌ 缺少 ${ENV_FILE}。先 cp .env.prod.example ${ENV_FILE} 并填值。"
        exit 1
    fi
}

cmd_init() {
    require_env
    echo "==> 1) 检查 APP_KEY"
    if ! grep -q "^APP_KEY=base64:" "${ENV_FILE}"; then
        echo "    APP_KEY 未生成，临时启动 admin 容器生成..."
        # 临时容器直接打印一个 key 出来
        KEY=$(docker run --rm php:8.3-cli-alpine sh -c "
            apk add --no-cache git unzip > /dev/null;
            cd /tmp; mkdir l && cd l;
            printf '<?php echo \"base64:\".base64_encode(random_bytes(32));' > k.php;
            php k.php
        ")
        echo "    生成的 APP_KEY = ${KEY}"
        echo "    把它写进 ${ENV_FILE} 的 APP_KEY=... 行后再跑一次 ./deploy.sh init"
        exit 0
    fi

    echo "==> 2) 构建镜像"
    ${COMPOSE} build

    echo "==> 3) 拉起 mysql / redis 等待 healthy"
    ${COMPOSE} up -d mysql redis
    echo "    等 mysql 起来..."
    for i in $(seq 1 30); do
        if docker exec video-mysql mysqladmin ping -h 127.0.0.1 -uroot -p"$(grep ^DB_ROOT_PASSWORD ${ENV_FILE} | cut -d= -f2)" --silent 2>/dev/null; then
            echo "    mysql ok"
            break
        fi
        sleep 2
    done

    echo "==> 4) 跑 Laravel migration"
    ${COMPOSE} run --rm admin php artisan migrate --force

    echo "==> 5) 拉起所有服务"
    ${COMPOSE} up -d

    echo "==> 6) 等证书签发（最长 60s）"
    sleep 5
    ${COMPOSE} logs --tail=30 traefik | grep -i "obtained certificate" || true

    echo ""
    echo "✅ 部署完成"
    echo "   后台:  https://$(grep ^ADMIN_DOMAIN ${ENV_FILE} | cut -d= -f2)/admin"
    echo "   API:   https://$(grep ^API_DOMAIN   ${ENV_FILE} | cut -d= -f2)/api/up"
}

cmd_update() {
    require_env
    echo "==> 1) 构建新镜像"
    ${COMPOSE} build

    echo "==> 2) 滚动重启 api / admin（mysql / redis / traefik 不动）"
    ${COMPOSE} up -d --no-deps api admin

    echo "==> 3) 跑迁移（如果有新的）"
    ${COMPOSE} exec admin php artisan migrate --force || true

    echo "==> 4) 清缓存"
    ${COMPOSE} exec admin php artisan config:cache  || true
    ${COMPOSE} exec admin php artisan route:cache   || true

    echo "✅ 更新完成"
}

cmd_logs() {
    ${COMPOSE} logs -f --tail=200 "${1:-}"
}

cmd_down() {
    ${COMPOSE} down
}

cmd_status() {
    ${COMPOSE} ps
}

# 立即跑一次备份（手动触发）
cmd_backup() {
    require_env
    echo "==> 手动触发备份"
    ${COMPOSE} exec backup bash -c "set -a; . /app/.env; set +a; /app/backup.sh"
}

# 从备份恢复
cmd_restore() {
    require_env
    if [ -z "${2:-}" ]; then
        echo "用法: $0 restore <备份路径或 oss://bucket/key>"
        exit 1
    fi
    ${COMPOSE} exec backup bash -c "set -a; . /app/.env; set +a; /app/restore.sh '$2'"
}

# Traefik / Docker socket 排错
cmd_verify() {
    echo "==> 1) Docker socket 权限"
    ls -l /var/run/docker.sock 2>/dev/null || {
        echo "   ❌ 找不到 /var/run/docker.sock"
        echo "      若是 rootless docker，socket 在 \$XDG_RUNTIME_DIR/docker.sock"
        echo "      改 compose 里 socket 路径或切换到 rootful docker"
        exit 1
    }
    echo "   ✅ socket 存在"

    echo ""
    echo "==> 2) Docker context"
    docker context ls

    echo ""
    echo "==> 3) Traefik 是否已识别 docker provider"
    if docker logs video-traefik 2>&1 | grep -q "provider docker"; then
        echo "   ✅ Traefik 已加载 docker provider"
    else
        echo "   ⚠️  Traefik 日志没看到 'provider docker'，看下完整日志："
        docker logs --tail 50 video-traefik
        return 1
    fi

    echo ""
    echo "==> 4) Traefik 路由表（应该看到 admin@docker / api@docker）"
    # 通过 Traefik API 拿路由列表（dev 暴露在 :8081，prod 在 traefik.${ADMIN_DOMAIN}）
    if curl -fsS http://localhost:8081/api/http/routers 2>/dev/null | grep -E '"name"|"service"' | head -20; then
        :
    else
        echo "   （:8081 不可用，可能是 prod 模式，请打开 https://traefik.\${ADMIN_DOMAIN}/dashboard 查看）"
    fi

    echo ""
    echo "==> 5) 容器健康"
    ${COMPOSE} ps --format 'table {{.Service}}\t{{.Status}}\t{{.Ports}}'
}

case "${1:-update}" in
    init)    cmd_init   ;;
    update)  cmd_update ;;
    logs)    shift || true; cmd_logs "${1:-}" ;;
    down)    cmd_down   ;;
    status)  cmd_status ;;
    verify)  cmd_verify ;;
    backup)  cmd_backup ;;
    restore) cmd_restore "$@" ;;
    *)
        echo "用法: $0 {init|update|logs [service]|down|status|verify|backup|restore <path>}"
        echo ""
        echo "  init     首次部署（生成 APP_KEY / migrate / 启全部）"
        echo "  update   拉新代码后重建 api/admin"
        echo "  logs     看日志（可指定服务名）"
        echo "  status   docker compose ps"
        echo "  verify   排查 Traefik / Docker socket / 路由问题"
        echo "  backup   立即跑一次 mysqldump → OSS"
        echo "  restore  从备份恢复（参数：本地文件 或 oss://bucket/key）"
        echo "  down     停掉所有容器"
        exit 1
        ;;
esac
