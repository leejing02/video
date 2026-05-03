#!/bin/bash
# 把环境变量写进一个 cron 能读到的文件
set -e

# crond 的进程不继承 docker run 的 env，需要手动导出到 /etc/environment
{
    echo "DB_HOST=${DB_HOST:-}"
    echo "DB_NAME=${DB_NAME:-}"
    echo "DB_USER=${DB_USER:-}"
    echo "DB_PASSWORD=${DB_PASSWORD:-}"
    echo "OSS_ENDPOINT=${OSS_ENDPOINT:-}"
    echo "OSS_BUCKET=${OSS_BUCKET:-}"
    echo "OSS_AK=${OSS_AK:-}"
    echo "OSS_SK=${OSS_SK:-}"
    echo "LOCAL_KEEP_DAYS=${LOCAL_KEEP_DAYS:-7}"
    echo "OSS_KEEP_DAYS=${OSS_KEEP_DAYS:-30}"
    echo "NOTIFY_WEBHOOK=${NOTIFY_WEBHOOK:-}"
    echo "BACKUP_DIR=${BACKUP_DIR:-/backups}"
} > /app/.env

# 启动时跑一次（可选 — 通过 RUN_ON_START=true 触发）
if [ "${RUN_ON_START:-false}" = "true" ]; then
    set -a; . /app/.env; set +a
    /app/backup.sh || echo "首次备份失败，但继续启动 cron"
fi

exec "$@"
