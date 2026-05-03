# syntax=docker/dockerfile:1.6
# ============================================================
# Go API 多阶段构建：builder → distroless 运行时镜像（~15 MB）
# ============================================================

FROM golang:1.22-alpine AS builder

# 国内网络可解锁；不需要可删
ENV GOPROXY=https://proxy.golang.org,direct \
    CGO_ENABLED=0 \
    GOOS=linux

WORKDIR /src

# 利用 Docker 缓存：先拷 go.mod / go.sum 单独跑 download
COPY go.mod ./
# go.sum 会在第一次 build 时由 go mod download 生成，先尝试拷
COPY go.sum* ./
RUN go mod download || true

COPY . .

RUN go mod tidy && \
    go build -ldflags="-s -w" -o /out/video-api ./cmd/server

# ------------------------------------------------------------
# Runtime stage
# ------------------------------------------------------------
FROM alpine:3.20

RUN apk add --no-cache ca-certificates tzdata && \
    cp /usr/share/zoneinfo/Asia/Shanghai /etc/localtime && \
    echo "Asia/Shanghai" > /etc/timezone

WORKDIR /app
COPY --from=builder /out/video-api /app/video-api

EXPOSE 8000

# 健康检查（命中 /up）
HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD wget --spider -q http://127.0.0.1:8000/up || exit 1

ENTRYPOINT ["/app/video-api"]
