#!/bin/bash
# ============================================================
# 恢复脚本
#
# 用法（在 backup 容器里跑）：
#   docker compose exec backup /app/restore.sh oss://bucket/backups/2026/05/02/video_platform-20260502-030000.sql.gz
#   docker compose exec backup /app/restore.sh /backups/video_platform-20260502-030000.sql.gz
#
# 危险操作！恢复前会要求确认。
# ============================================================
set -euo pipefail

: "${DB_HOST:?}"
: "${DB_NAME:?}"
: "${DB_USER:?}"
: "${DB_PASSWORD:?}"

if [ "${1:-}" = "" ]; then
    echo "用法: $0 <备份文件路径或 oss://bucket/key>"
    exit 1
fi
SRC="$1"

echo "⚠️  即将把 ${SRC} 恢复到 ${DB_HOST}/${DB_NAME}"
echo "⚠️  这会覆盖当前数据库的全部数据！"
read -r -p "输入数据库名 ${DB_NAME} 确认继续: " CONFIRM
[ "$CONFIRM" = "$DB_NAME" ] || { echo "已取消"; exit 1; }

TMP=/tmp/restore.sql.gz

if [[ "$SRC" == oss://* ]]; then
    : "${OSS_ENDPOINT:?}"; : "${OSS_BUCKET:?}"; : "${OSS_AK:?}"; : "${OSS_SK:?}"
    mc alias set oss "$OSS_ENDPOINT" "$OSS_AK" "$OSS_SK" --api S3v4 >/dev/null
    KEY="${SRC#oss://}"
    mc cp "oss/${KEY}" "$TMP"
elif [ -f "$SRC" ]; then
    cp "$SRC" "$TMP"
else
    echo "找不到文件: $SRC"; exit 2
fi

echo "解压 + 导入..."
gunzip -c "$TMP" | mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME"

rm -f "$TMP"
echo "✅ 恢复完成"
