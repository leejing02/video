# syntax=docker/dockerfile:1.6
# ============================================================
# 数据库备份容器
#   - mysql-client：mysqldump
#   - mc (MinIO Client)：兼容 Aliyun OSS / AWS S3 / MinIO
#   - tini：信号转发，让 docker stop 干净
#   - busybox crond：定时调度
# ============================================================
FROM alpine:3.20

RUN apk add --no-cache \
        bash \
        ca-certificates \
        curl \
        gzip \
        mysql-client \
        tini \
        tzdata \
    && cp /usr/share/zoneinfo/Asia/Shanghai /etc/localtime \
    && echo "Asia/Shanghai" > /etc/timezone

# 装 mc（MinIO Client，兼容 S3/OSS）
ARG MC_VERSION=RELEASE.2024-06-12T14-34-03Z
RUN curl -fsSL "https://dl.min.io/client/mc/release/linux-amd64/archive/mc.${MC_VERSION}" \
        -o /usr/local/bin/mc \
    && chmod +x /usr/local/bin/mc

WORKDIR /app
COPY backup.sh   /app/backup.sh
COPY restore.sh  /app/restore.sh
COPY entrypoint.sh /app/entrypoint.sh
COPY crontab     /etc/crontabs/root

RUN chmod +x /app/*.sh && mkdir -p /backups

VOLUME ["/backups"]

ENTRYPOINT ["/sbin/tini", "--", "/app/entrypoint.sh"]
CMD ["crond", "-f", "-d", "8"]
