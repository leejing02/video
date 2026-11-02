# ============================================================
# Docker 快捷命令（在项目根目录执行 make -f docker/Makefile <target>）
# ============================================================
.PHONY: dev-up dev-down dev-logs prod-up prod-down prod-logs status

DEV  = docker compose -f deploy/docker-compose.dev.yml
PROD = docker compose --env-file .env.prod -f deploy/docker-compose.prod.yml

dev-up:
	$(DEV) up -d --build

dev-down:
	$(DEV) down -v

dev-logs:
	$(DEV) logs -f --tail=100

prod-up:
	$(PROD) up -d

prod-down:
	$(PROD) down

prod-logs:
	$(PROD) logs -f --tail=200

status:
	$(DEV) ps 2>/dev/null || $(PROD) ps

shell-api:
	$(DEV) exec api sh

shell-admin:
	$(DEV) exec admin bash

migrate:
	$(PROD) exec admin php artisan migrate --force
