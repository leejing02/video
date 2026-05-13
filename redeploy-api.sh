#!/usr/bin/env bash
# ============================================================
# Go API 一键重新部署脚本
#
# 用途：源码改了 / 路由更新了 / 二进制要重编，跑这个就行。
# 它会：
#   1. (可选) git pull
#   2. 自动判断部署形态：docker compose / systemd / supervisor / 裸进程
#   3. 重新编译 / 重建镜像
#   4. 重启服务
#   5. 验证关键路由确实注册了（包含修这次 bug 的几条短视频路由）
#
# 用法：
#   ./redeploy-api.sh             # 默认 update：build + restart + verify
#   ./redeploy-api.sh --pull      # 先 git pull 再走
#   ./redeploy-api.sh --force     # 跳过所有交互确认
#   ./redeploy-api.sh verify      # 只跑路由验证，不动服务
#   ./redeploy-api.sh routes      # 打印当前二进制里注册的所有 /api 路由
# ============================================================
set -euo pipefail

# --- 配置（不对就改这里） -----------------------------------
PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
API_DIR="${PROJECT_DIR}/api"
BIN_NAME="video-api"
BIN_PATH="${API_DIR}/${BIN_NAME}"
SYSTEMD_UNIT="video-api"           # systemctl status video-api
SUPERVISOR_PROG="video-api"        # supervisorctl status video-api
API_DOMAIN_DEFAULT="https://api.hkho567.eu.cc"
HEALTH_PATHS=(
    "/api/short-categories"
    "/api/short-videos?per_page=1"
    "/up"
)
# 优先使用环境变量里的 API_BASE，没有就用上面那个默认值
API_BASE="${API_BASE:-${API_DOMAIN_DEFAULT}}"
# --- 配置结束 ------------------------------------------------

DO_PULL=0
FORCE=0
SUBCMD="update"

for arg in "$@"; do
    case "$arg" in
        --pull)   DO_PULL=1 ;;
        --force)  FORCE=1 ;;
        update|verify|routes|logs|status) SUBCMD="$arg" ;;
        -h|--help)
            sed -n '2,28p' "$0"
            exit 0
            ;;
        *) echo "未知参数: $arg"; exit 1 ;;
    esac
done

c_red()   { printf '\033[31m%s\033[0m\n' "$*"; }
c_green() { printf '\033[32m%s\033[0m\n' "$*"; }
c_yellow(){ printf '\033[33m%s\033[0m\n' "$*"; }
c_blue()  { printf '\033[34m%s\033[0m\n' "$*"; }
step()    { echo; c_blue "==> $*"; }

confirm() {
    [ "$FORCE" = "1" ] && return 0
    read -r -p "$1 [y/N] " ans
    [[ "$ans" =~ ^[Yy]$ ]]
}

# ----------- 部署形态检测 -----------
# 返回值放进全局 MODE：
#   docker     有 docker-compose.prod.yml 且 api 容器在跑
#   systemd    有 /etc/systemd/system/${SYSTEMD_UNIT}.service
#   supervisor 有 supervisorctl 且配置了 ${SUPERVISOR_PROG}
#   bare       裸进程（ps 找到 ${BIN_NAME}）
#   none       啥都没找到
detect_mode() {
    MODE="none"

    if command -v docker >/dev/null 2>&1 && [ -f "${PROJECT_DIR}/docker-compose.prod.yml" ]; then
        if docker ps --format '{{.Names}}' 2>/dev/null | grep -qE '^video-api$|video.*api'; then
            MODE="docker"
            return
        fi
    fi

    if command -v systemctl >/dev/null 2>&1 \
       && systemctl list-unit-files 2>/dev/null | grep -q "^${SYSTEMD_UNIT}\.service"; then
        MODE="systemd"
        return
    fi

    if command -v supervisorctl >/dev/null 2>&1 \
       && supervisorctl status "${SUPERVISOR_PROG}" >/dev/null 2>&1; then
        MODE="supervisor"
        return
    fi

    if pgrep -f "${BIN_NAME}" >/dev/null 2>&1; then
        MODE="bare"
        return
    fi
}

# ----------- 编译 -----------
build_go() {
    step "编译 Go 二进制"
    cd "${API_DIR}"
    if ! command -v go >/dev/null 2>&1; then
        c_red "未找到 go 命令"
        echo "   docker 模式不需要本地有 go；如果你想用 docker 部署，跳过这步"
        return 1
    fi
    echo "   go version: $(go version)"
    GOOS_DST="${GOOS:-linux}"
    GOARCH_DST="${GOARCH:-amd64}"
    CGO_ENABLED=0 GOOS="${GOOS_DST}" GOARCH="${GOARCH_DST}" \
        go build -trimpath -ldflags="-s -w" -o "${BIN_PATH}" ./cmd/server
    c_green "   ✅ 编译完成: ${BIN_PATH}"
    ls -lh "${BIN_PATH}"
}

# ----------- 重启 -----------
restart_service() {
    step "重启服务 (mode=${MODE})"
    case "${MODE}" in
        docker)
            cd "${PROJECT_DIR}"
            docker compose --env-file .env.prod -f docker-compose.prod.yml build api
            docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --no-deps api
            ;;
        systemd)
            sudo systemctl restart "${SYSTEMD_UNIT}"
            sudo systemctl status "${SYSTEMD_UNIT}" --no-pager | head -10
            ;;
        supervisor)
            sudo supervisorctl restart "${SUPERVISOR_PROG}"
            sudo supervisorctl status "${SUPERVISOR_PROG}"
            ;;
        bare)
            c_yellow "   裸进程模式：kill 旧进程 + nohup 起新进程"
            confirm "确认要 kill 当前在跑的 ${BIN_NAME} 进程？" || { echo "已取消"; exit 1; }
            pkill -f "${BIN_NAME}" || true
            sleep 1
            cd "${API_DIR}"
            # 用 .env 加载环境变量（如果存在）
            ENV_LOAD=""
            [ -f "${API_DIR}/.env" ] && ENV_LOAD="set -a; . ${API_DIR}/.env; set +a;"
            nohup bash -c "${ENV_LOAD} ${BIN_PATH}" > "${API_DIR}/video-api.log" 2>&1 &
            sleep 2
            if pgrep -f "${BIN_NAME}" >/dev/null; then
                c_green "   ✅ 新进程已起来 (pid=$(pgrep -f ${BIN_NAME} | head -1))"
                echo "   日志: ${API_DIR}/video-api.log"
            else
                c_red "   ❌ 进程没起来，看日志:"
                tail -30 "${API_DIR}/video-api.log"
                exit 1
            fi
            ;;
        none)
            c_red "没检测到任何部署形态。"
            cat <<'EOF'
建议二选一：
1) 把 video-api 注册成 systemd（推荐生产）。创建 /etc/systemd/system/video-api.service：

    [Unit]
    Description=Video API
    After=network.target

    [Service]
    Type=simple
    WorkingDirectory=/www/wwwroot/video/api
    EnvironmentFile=/www/wwwroot/video/api/.env
    ExecStart=/www/wwwroot/video/api/video-api
    Restart=always
    RestartSec=3
    User=root

    [Install]
    WantedBy=multi-user.target

   然后：
       sudo systemctl daemon-reload
       sudo systemctl enable --now video-api
   重跑本脚本就能识别成 systemd 模式了。

2) 用现有的 docker 部署：./deploy.sh update
EOF
            exit 1
            ;;
    esac
}

# ----------- 路由验证 -----------
print_routes_in_binary() {
    step "二进制里注册的 /api 路由"
    if [ ! -f "${BIN_PATH}" ]; then
        c_yellow "   二进制不存在: ${BIN_PATH}（如果是 docker 模式可以忽略）"
        return
    fi
    # main.go 里的字符串字面量都会编进二进制
    strings "${BIN_PATH}" 2>/dev/null | grep -E '^/(api|ws|up|storage)' | sort -u || true
}

probe_http() {
    step "HTTP 健康探针: ${API_BASE}"
    if ! command -v curl >/dev/null 2>&1; then
        c_yellow "   没 curl，跳过 HTTP 验证"
        return
    fi
    local fail=0
    for path in "${HEALTH_PATHS[@]}"; do
        local url="${API_BASE}${path}"
        local code body
        code=$(curl -s -o /tmp/.api-body -w '%{http_code}' --max-time 8 "${url}" || echo "000")
        body=$(head -c 100 /tmp/.api-body 2>/dev/null || true)
        if [ "${code}" = "200" ] || [ "${code}" = "401" ]; then
            c_green "   ✅ ${code}  ${path}"
        else
            c_red "   ❌ ${code}  ${path}"
            [ -n "${body}" ] && echo "        body: ${body}"
            fail=1
        fi
    done
    rm -f /tmp/.api-body
    if [ "${fail}" = "1" ]; then
        c_yellow "   有路由没通。常见原因："
        echo "     1) 反代（nginx/traefik）把这些路径单独拦了 / 缓存了"
        echo "     2) 二进制没真正替换：再跑一次 './redeploy-api.sh routes' 看 strings 输出"
        echo "     3) 数据库连接挂了导致 500（看进程日志）"
        return 1
    fi
}

# ----------- 入口 -----------
detect_mode
c_blue "检测到部署形态: ${MODE}"
c_blue "项目目录:       ${PROJECT_DIR}"
c_blue "API 域名:       ${API_BASE}"

case "${SUBCMD}" in
    update)
        if [ "${DO_PULL}" = "1" ]; then
            step "git pull"
            cd "${PROJECT_DIR}"
            git pull --rebase
        fi
        # docker 模式由 dockerfile 内部 go build，不在宿主机编
        if [ "${MODE}" != "docker" ]; then
            build_go
            print_routes_in_binary
        fi
        restart_service
        sleep 3
        probe_http
        c_green "✅ 部署完成"
        ;;
    verify)
        probe_http
        ;;
    routes)
        print_routes_in_binary
        ;;
    logs)
        case "${MODE}" in
            docker)     docker logs -f --tail=200 video-api ;;
            systemd)    sudo journalctl -u "${SYSTEMD_UNIT}" -f --no-pager ;;
            supervisor) sudo supervisorctl tail -f "${SUPERVISOR_PROG}" stderr ;;
            bare)       tail -f "${API_DIR}/video-api.log" ;;
            none)       c_red "未检测到部署形态"; exit 1 ;;
        esac
        ;;
    status)
        case "${MODE}" in
            docker)     docker ps --filter name=video-api --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}' ;;
            systemd)    sudo systemctl status "${SYSTEMD_UNIT}" --no-pager ;;
            supervisor) sudo supervisorctl status "${SUPERVISOR_PROG}" ;;
            bare)       pgrep -af "${BIN_NAME}" || echo "进程未运行" ;;
            none)       c_red "未检测到部署形态"; exit 1 ;;
        esac
        ;;
esac
