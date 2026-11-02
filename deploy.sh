#!/usr/bin/env bash
# ============================================================
# 一键部署 / 更新脚本
#
# prod：
#   ./deploy.sh init            首次部署
#   ./deploy.sh update          更新（pull GHCR 镜像 + 滚动重启）
#   IMAGE_TAG=sha-abc ./deploy.sh update  指定镜像版本
#
# staging：
#   ./deploy.sh staging-init    staging 首次部署
#   ./deploy.sh staging-update  更新 staging
#
# 其他：
#   ./deploy.sh logs [service]
#   ./deploy.sh status / down / verify / backup / restore <path>
# ============================================================
set -euo pipefail

cd "$(dirname "$0")"

# ─── 环境配置 ───────────────────────────────────────────────
PROD_ENV=".env.prod"
STAGING_ENV=".env.staging"

# IMAGE_TAG 由 GitHub Actions 注入（如 sha-a1b2c3）或手动设置
# 不设置则用 compose 文件中的默认 tag（main / develop）
IMAGE_TAG="${IMAGE_TAG:-}"

COMPOSE_PROD="docker compose --env-file ${PROD_ENV} -f deploy/docker-compose.prod.yml"
COMPOSE_STAGING="docker compose --env-file ${STAGING_ENV} -f deploy/docker-compose.staging.yml"

# IMAGE_TAG 注入到 compose（通过环境变量）
if [ -n "${IMAGE_TAG}" ]; then
    export IMAGE_TAG
fi

# ─── 工具函数 ───────────────────────────────────────────────
require_env() {
    local env_file="$1"
    if [ ! -f "${env_file}" ]; then
        local example="env/.env.prod.example"
        [[ "${env_file}" == *staging* ]] && example="env/.env.staging.example"
        echo "❌ 缺少 ${env_file}。先 cp ${example} ${env_file} 并填值。"
        exit 1
    fi
}

gen_app_key() {
    docker run --rm php:8.3-cli-alpine php -r \
        "echo 'base64:'.base64_encode(random_bytes(32));"
}

wait_mysql() {
    local container="$1"
    local env_file="$2"
    local root_pw
    root_pw=$(grep ^DB_ROOT_PASSWORD "${env_file}" | cut -d= -f2)
    echo "    等 MySQL 起来..."
    for i in $(seq 1 30); do
        if docker exec "${container}" mysqladmin ping -h 127.0.0.1 -uroot -p"${root_pw}" --silent 2>/dev/null; then
            echo "    MySQL ok"
            return 0
        fi
        sleep 2
    done
    echo "❌ MySQL 30s 内未就绪"
    exit 1
}

# ─── Prod 命令 ──────────────────────────────────────────────
cmd_init() {
    require_env "${PROD_ENV}"

    echo "==> 1) 检查 APP_KEY"
    if ! grep -q "^APP_KEY=base64:" "${PROD_ENV}"; then
        echo "    生成 APP_KEY..."
        KEY=$(gen_app_key)
        echo "    APP_KEY = ${KEY}"
        echo "    写入 ${PROD_ENV} 后重新运行 ./deploy.sh init"
        exit 0
    fi

    echo "==> 2) 拉取 GHCR 镜像（tag: ${IMAGE_TAG:-main}）"
    ${COMPOSE_PROD} pull api admin web

    echo "==> 3) 启动 mysql / redis"
    ${COMPOSE_PROD} up -d mysql redis
    wait_mysql "video-mysql" "${PROD_ENV}"

    echo "==> 4) Laravel migration"
    ${COMPOSE_PROD} run --rm admin php artisan migrate --force

    echo "==> 5) 启动所有服务"
    ${COMPOSE_PROD} up -d

    echo "==> 6) 等待 TLS 证书（最长 60s）"
    sleep 5
    ${COMPOSE_PROD} logs --tail=30 traefik | grep -i "obtained certificate" || true

    ADMIN_DOMAIN=$(grep ^ADMIN_DOMAIN "${PROD_ENV}" | cut -d= -f2)
    API_DOMAIN=$(grep ^API_DOMAIN "${PROD_ENV}" | cut -d= -f2)
    echo ""
    echo "✅ Prod 部署完成"
    echo "   后台:  https://${ADMIN_DOMAIN}/admin"
    echo "   API:   https://${API_DOMAIN}/api/up"
}

cmd_update() {
    require_env "${PROD_ENV}"

    echo "==> 1) 拉取最新镜像（tag: ${IMAGE_TAG:-main}）"
    ${COMPOSE_PROD} pull api admin web

    echo "==> 2) 滚动重启 api / admin / web（mysql / redis / traefik 不动）"
    ${COMPOSE_PROD} up -d --no-deps api admin web

    echo "==> 3) 跑迁移"
    ${COMPOSE_PROD} exec admin php artisan migrate --force || true

    echo "==> 4) 清缓存"
    ${COMPOSE_PROD} exec admin php artisan config:cache || true
    ${COMPOSE_PROD} exec admin php artisan route:cache  || true

    echo "✅ Prod 更新完成（IMAGE_TAG=${IMAGE_TAG:-main}）"
}

# ─── Staging 命令 ───────────────────────────────────────────
cmd_staging_init() {
    require_env "${STAGING_ENV}"

    echo "==> 1) 检查 APP_KEY"
    if ! grep -q "^APP_KEY=base64:" "${STAGING_ENV}"; then
        KEY=$(gen_app_key)
        echo "    APP_KEY = ${KEY}"
        echo "    写入 ${STAGING_ENV} 后重新运行 ./deploy.sh staging-init"
        exit 0
    fi

    echo "==> 2) 拉取 GHCR 镜像（tag: ${IMAGE_TAG:-develop}）"
    ${COMPOSE_STAGING} pull api admin web

    echo "==> 3) 启动 staging mysql / redis"
    ${COMPOSE_STAGING} up -d mysql redis
    wait_mysql "video-staging-mysql" "${STAGING_ENV}"

    echo "==> 4) Laravel migration（staging）"
    ${COMPOSE_STAGING} run --rm admin php artisan migrate --force

    echo "==> 5) 启动所有 staging 服务"
    ${COMPOSE_STAGING} up -d

    ADMIN_DOMAIN=$(grep ^ADMIN_DOMAIN "${STAGING_ENV}" | cut -d= -f2)
    API_DOMAIN=$(grep ^API_DOMAIN "${STAGING_ENV}" | cut -d= -f2)
    echo ""
    echo "✅ Staging 部署完成"
    echo "   后台:  https://staging-${ADMIN_DOMAIN}/admin"
    echo "   API:   https://staging-${API_DOMAIN}/api/up"
}

cmd_staging_update() {
    require_env "${STAGING_ENV}"

    echo "==> 1) 拉取最新镜像（tag: ${IMAGE_TAG:-develop}）"
    ${COMPOSE_STAGING} pull api admin web

    echo "==> 2) 滚动重启 staging 服务"
    ${COMPOSE_STAGING} up -d --no-deps api admin web

    echo "==> 3) 迁移"
    ${COMPOSE_STAGING} exec admin php artisan migrate --force || true

    echo "✅ Staging 更新完成（IMAGE_TAG=${IMAGE_TAG:-develop}）"
}

# ─── 通用命令 ───────────────────────────────────────────────
cmd_logs() {
    ${COMPOSE_PROD} logs -f --tail=200 "${1:-}"
}

cmd_down() {
    ${COMPOSE_PROD} down
}

cmd_status() {
    echo "=== PROD ===" && ${COMPOSE_PROD} ps 2>/dev/null || true
    echo ""
    echo "=== STAGING ===" && ${COMPOSE_STAGING} ps 2>/dev/null || true
}

cmd_backup() {
    require_env "${PROD_ENV}"
    echo "==> 手动触发备份"
    ${COMPOSE_PROD} exec backup bash -c "set -a; . /app/.env; set +a; /app/backup.sh"
}

cmd_restore() {
    require_env "${PROD_ENV}"
    if [ -z "${2:-}" ]; then
        echo "用法: $0 restore <备份路径或 oss://bucket/key>"
        exit 1
    fi
    ${COMPOSE_PROD} exec backup bash -c "set -a; . /app/.env; set +a; /app/restore.sh '$2'"
}

cmd_verify() {
    echo "==> 1) Docker socket 权限"
    ls -l /var/run/docker.sock 2>/dev/null || {
        echo "   ❌ /var/run/docker.sock 不存在"
        exit 1
    }
    echo "   ✅ socket 存在"

    echo ""
    echo "==> 2) Docker context"
    docker context ls

    echo ""
    echo "==> 3) Traefik provider 状态"
    if docker logs video-traefik 2>&1 | grep -q "provider docker"; then
        echo "   ✅ Traefik 已加载 docker provider"
    else
        echo "   ⚠️  未见 'provider docker'，查看完整日志："
        docker logs --tail 50 video-traefik
    fi

    echo ""
    echo "==> 4) 容器健康"
    ${COMPOSE_PROD} ps --format 'table {{.Service}}\t{{.Status}}\t{{.Ports}}'
}

# ─── 入口 ───────────────────────────────────────────────────
case "${1:-update}" in
    init)            cmd_init           ;;
    update)          cmd_update         ;;
    staging-init)    cmd_staging_init   ;;
    staging-update)  cmd_staging_update ;;
    logs)            shift || true; cmd_logs "${1:-}" ;;
    down)            cmd_down           ;;
    status)          cmd_status         ;;
    verify)          cmd_verify         ;;
    backup)          cmd_backup         ;;
    restore)         cmd_restore "$@"   ;;
    *)
        echo "用法: $0 <命令>"
        echo ""
        echo "  Prod:"
        echo "    init            首次部署"
        echo "    update          拉 GHCR 镜像 + 滚动更新"
        echo ""
        echo "  Staging:"
        echo "    staging-init    staging 首次部署"
        echo "    staging-update  拉 develop 镜像 + 滚动更新"
        echo ""
        echo "  通用:"
        echo "    logs [service]  查看 prod 日志"
        echo "    status          prod + staging 容器状态"
        echo "    verify          Traefik 路由诊断"
        echo "    backup          立即备份 → OSS"
        echo "    restore <path>  从备份恢复"
        echo "    down            停掉 prod 所有容器"
        echo ""
        echo "环境变量："
        echo "    IMAGE_TAG=sha-a1b2c3 ./deploy.sh update  指定镜像版本"
        exit 1
        ;;
esac
