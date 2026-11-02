# ============================================================
# Makefile — video platform 统一入口
#
# AI Agent / Claude Code / Cursor 使用说明：
#   make doctor          → 检查本机环境
#   make dev             → 启动本地开发环境
#   make smoke           → 本地冒烟测试
#   make build           → 构建 Go API + 前端
#   make test            → 运行所有测试
#
# 完整列表：make help
# ============================================================

.DEFAULT_GOAL := help
SHELL         := /bin/bash

# 颜色
CYAN  := \033[0;36m
RESET := \033[0m

.PHONY: help
help: ## 显示所有可用命令
	@echo ""
	@echo "  Video Platform — 可用命令"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-22s$(RESET) %s\n", $$1, $$2}'
	@echo ""

# ─── 环境初始化 ───────────────────────────────────────────────
.PHONY: bootstrap
bootstrap: ## 新 Mac 一键初始化（brew/go/node/php/docker/claude）
	bash scripts/bootstrap-mac.sh

.PHONY: doctor
doctor: ## 诊断本机开发环境（工具版本 / 容器 / 端口 / env 文件）
	bash scripts/doctor.sh

# ─── 本地开发 ─────────────────────────────────────────────────
.PHONY: dev
dev: ## 启动本地 Docker Compose 开发环境
	bash local-up.sh

.PHONY: dev-up
dev-up: ## docker compose up（不重建 volume）
	docker compose -f deploy/docker-compose.dev.yml up -d

.PHONY: dev-down
dev-down: ## docker compose down
	docker compose -f deploy/docker-compose.dev.yml down

.PHONY: dev-logs
dev-logs: ## 跟踪所有服务日志
	docker compose -f deploy/docker-compose.dev.yml logs -f

.PHONY: dev-ps
dev-ps: ## 查看容器状态
	docker compose -f deploy/docker-compose.dev.yml ps

# ─── 构建 ─────────────────────────────────────────────────────
.PHONY: build
build: build-api build-web ## 构建所有服务

.PHONY: build-api
build-api: ## 编译 Go API
	cd api && go build ./...

.PHONY: build-web
build-web: ## 构建 Vue 前端
	cd web && npm ci && npm run build

.PHONY: build-gameserver
build-gameserver: ## 编译捕鱼 Game Server
	cd api && go build ./cmd/gameserver/...

# ─── 测试 ─────────────────────────────────────────────────────
.PHONY: test
test: test-api ## 运行所有测试

.PHONY: test-api
test-api: ## Go 单元测试
	cd api && go test ./... -count=1 -timeout 30s

.PHONY: vet
vet: ## go vet 静态检查
	cd api && go vet ./...

.PHONY: fmt-check
fmt-check: ## gofmt 格式检查（不写入）
	@out=$$(cd api && gofmt -l .); \
	[ -z "$$out" ] || { echo "gofmt: 以下文件需格式化:"; echo "$$out"; exit 1; }

.PHONY: mod-check
mod-check: ## go mod tidy 一致性检查（go.mod / go.sum 已 tidy）
	@cd api && \
	  cp go.mod go.mod.bak; cp go.sum go.sum.bak 2>/dev/null || true; \
	  go mod tidy; \
	  if ! diff -q go.mod go.mod.bak >/dev/null 2>&1 || \
	     ! diff -q go.sum go.sum.bak >/dev/null 2>&1; then \
	    mv go.mod.bak go.mod; mv go.sum.bak go.sum 2>/dev/null || true; \
	    echo "ERROR: go.mod/go.sum not tidy — run: cd api && go mod tidy"; \
	    exit 1; \
	  fi; \
	  rm -f go.mod.bak go.sum.bak

.PHONY: php-lint
php-lint: ## PHP 语法检查（admin/，跳过 vendor）
	@STATUS=0; \
	while IFS= read -r f; do \
	    out=$$(php -l "$$f" 2>&1); \
	    echo "$$out" | grep -qv "^No syntax errors" && { echo "$$out"; STATUS=1; } || true; \
	done < <(find admin -name "*.php" ! -path "*/vendor/*"); \
	exit $$STATUS

.PHONY: compose-check
compose-check: ## 验证所有 docker compose 配置（需相关 env 变量）
	docker compose -f deploy/docker-compose.dev.yml     config --quiet
	docker compose -f deploy/docker-compose.prod.yml    config --quiet
	docker compose -f deploy/docker-compose.staging.yml config --quiet

.PHONY: ios-lint
ios-lint: ## Swift 语法检查（ios/，仅解析不链接）
	find ios -name "*.swift" -print0 | xargs -0 swiftc -parse

.PHONY: ci
ci: fmt-check mod-check vet test-api build-api build-web ## CI 核心（格式+mod+vet+test+build，不含 Docker）
	@echo ""
	@echo "  ✓ CI checks passed"
	@echo ""

.PHONY: ci-full
ci-full: ci smoke ## CI 完整（含本地冒烟测试，需 Docker 运行）

# ─── 冒烟测试 ─────────────────────────────────────────────────
.PHONY: smoke
smoke: ## 本地冒烟测试（需先 make dev）
	bash scripts/smoke-test.sh local

.PHONY: smoke-staging
smoke-staging: ## Staging 冒烟测试
	bash scripts/smoke-test.sh staging

.PHONY: smoke-prod
smoke-prod: ## 生产冒烟测试
	bash scripts/smoke-test.sh prod

# ─── 数据库 ───────────────────────────────────────────────────
.PHONY: migrate
migrate: ## 运行数据库迁移（本地）
	docker exec video-mysql mysql -u root video_platform \
		< api/migrations/schema.sql

.PHONY: seed
seed: ## 导入种子数据（本地）
	docker exec -i video-mysql mysql -u root video_platform \
		< api/migrations/seed.sql

.PHONY: db-shell
db-shell: ## 进入 MySQL 命令行（本地）
	docker exec -it video-mysql mysql -u root video_platform

# ─── 容器 Shell ───────────────────────────────────────────────
.PHONY: shell-api
shell-api: ## 进入 Go API 容器
	docker exec -it video-api sh

.PHONY: shell-admin
shell-admin: ## 进入 Laravel Admin 容器
	docker exec -it video-admin sh

# ─── 部署 ─────────────────────────────────────────────────────
.PHONY: deploy
deploy: ## 生产部署（需服务器 SSH 配置）
	bash deploy.sh update

.PHONY: staging-deploy
staging-deploy: ## Staging 部署
	bash deploy.sh staging-update

.PHONY: rollback
rollback: ## 回滚（需指定 IMAGE_TAG）
	@echo "用法: IMAGE_TAG=sha-abc make rollback"
	IMAGE_TAG=$(IMAGE_TAG) bash deploy.sh update

# ─── 清理 ─────────────────────────────────────────────────────
.PHONY: clean
clean: ## 清理本地构建产物
	cd api && rm -f video-api gameserver
	cd web && rm -rf dist

.PHONY: clean-all
clean-all: dev-down clean ## 停容器 + 清构建产物（不删 volume）

# ─── 综合校验 ─────────────────────────────────────────────────
.PHONY: verify
verify: ## 完整校验：doctor --json + make ci + make smoke（需 Docker）
	@bash scripts/doctor.sh --json
	@$(MAKE) ci
	@$(MAKE) smoke
