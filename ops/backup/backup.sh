#!/bin/bash
# ============================================================
# 备份逻辑：
#   1. mysqldump 全量
#   2. gzip 压缩
#   3. 上传 OSS（保留 30 天，本地保留 7 天）
#   4. 本地清理超期文件
# ============================================================
set -euo pipefail

# 必填环境变量（在 docker-compose 或 .env 里配）
: "${DB_HOST:?must set DB_HOST}"
: "${DB_NAME:?must set DB_NAME}"
: "${DB_USER:?must set DB_USER}"
: "${DB_PASSWORD:?must set DB_PASSWORD}"

: "${OSS_ENDPOINT:?must set OSS_ENDPOINT, e.g. https://oss-cn-hangzhou.aliyuncs.com}"
: "${OSS_BUCKET:?must set OSS_BUCKET}"
: "${OSS_AK:?must set OSS_AK}"
: "${OSS_SK:?must set OSS_SK}"

# 可选
LOCAL_KEEP_DAYS=${LOCAL_KEEP_DAYS:-7}
OSS_KEEP_DAYS=${OSS_KEEP_DAYS:-30}
NOTIFY_WEBHOOK=${NOTIFY_WEBHOOK:-}
BACKUP_DIR=${BACKUP_DIR:-/backups}

DATE=$(date +%Y%m%d-%H%M%S)
DAY=$(date +%Y/%m/%d)
FILE="${BACKUP_DIR}/${DB_NAME}-${DATE}.sql.gz"
OSS_KEY="backups/${DAY}/${DB_NAME}-${DATE}.sql.gz"

log() { echo "[$(date '+%F %T')] $*"; }

notify() {
    [ -z "$NOTIFY_WEBHOOK" ] && return 0
    curl -fsS -X POST -H 'Content-Type: application/json' \
        -d "{\"text\":\"$1\"}" \
        "$NOTIFY_WEBHOOK" >/dev/null 2>&1 || true
}

main() {
    mkdir -p "$BACKUP_DIR"

    # 1) 配 mc alias（每次都配，幂等）
    mc alias set oss "$OSS_ENDPOINT" "$OSS_AK" "$OSS_SK" --api S3v4 >/dev/null

    # 2) mysqldump | gzip
    log "开始 dump：$FILE"
    mysqldump \
        -h "$DB_HOST" \
        -u "$DB_USER" \
        -p"$DB_PASSWORD" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        --set-gtid-purged=OFF \
        --column-statistics=0 \
        "$DB_NAME" 2>/tmp/dump.err | gzip -9 > "$FILE"

    if [ ! -s "$FILE" ]; then
        log "❌ dump 输出为空"
        cat /tmp/dump.err
        notify "❌ MySQL 备份失败：dump 为空 ($(date))"
        exit 1
    fi

    SIZE=$(du -h "$FILE" | cut -f1)
    log "✅ dump 完成：$FILE ($SIZE)"

    # 3) 上传 OSS
    log "上传：oss/${OSS_BUCKET}/${OSS_KEY}"
    if ! mc cp "$FILE" "oss/${OSS_BUCKET}/${OSS_KEY}"; then
        log "❌ OSS 上传失败"
        notify "❌ MySQL 备份上传 OSS 失败：${OSS_KEY}"
        exit 2
    fi
    log "✅ OSS 上传成功"

    # 4) 清理本地（保留 N 天）
    find "$BACKUP_DIR" -name "${DB_NAME}-*.sql.gz" -type f -mtime +${LOCAL_KEEP_DAYS} -delete || true

    # 5) 清理 OSS（保留 M 天）
    # mc rm --recursive --force --older-than 不支持嵌套日期前缀，用 mc find + 时间过滤
    mc find "oss/${OSS_BUCKET}/backups" --older-than "${OSS_KEEP_DAYS}d" 2>/dev/null \
        | xargs -r -n1 mc rm --quiet || true

    notify "✅ MySQL 备份成功：${DB_NAME} ${SIZE} → oss://${OSS_BUCKET}/${OSS_KEY}"
    log "完成"
}

main "$@"
