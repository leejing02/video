# Deploy workflow audit record (P0)

Baseline: `.github/workflows/deploy.yml` at `main` commit `0cb870e584e54f02c3967e7d08d53b24d8a6daca`.

## Findings in the previous workflow

| Item | Previous behaviour | Risk |
| --- | --- | --- |
| Trigger | `push: branches: [main]` plus `workflow_dispatch` | Every push or merge to `main` started a production deployment. |
| Host key | `ssh-keyscan -H "$SSH_HOST" >> ~/.ssh/known_hosts`, then `ssh -o StrictHostKeyChecking=no` | Trust on first use with no verification; a spoofed host would receive the deploy key session. |
| Remote command | `cd "$DEPLOY_PATH"; git fetch --all; git reset --hard origin/main; ./deploy.sh update` | Discards every uncommitted file in the production working tree and updates all services (and their migrations) at once. |
| Health check | `docker compose --env-file /www/wwwroot/video/.env.prod -f /www/wwwroot/video/docker-compose.prod.yml ps` | Hard-coded paths that no longer match the production layout (`deploy/docker-compose.*.yml`, project `video-prod`). |
| Secrets in scripts | `${{ secrets.* }}` expanded directly inside `run:` scripts | Values become part of the generated script text. |
| Environment | `environment: production` | At audit time (2026-11-09) the environment had no protection rules, no deployment branch policy and admin bypass enabled; repository and environment secret counts were 0, so all six push-triggered runs (2026-05-03 to 2026-05-13) failed before deploying. |

Previous step bodies, verbatim:

```yaml
on:
  push:
    branches: [main]
  workflow_dispatch:        # 也允许手动触发

      - name: 写入 SSH 私钥
        run: |
          mkdir -p ~/.ssh
          echo "${{ secrets.SSH_PRIVATE_KEY }}" > ~/.ssh/id_ed25519
          chmod 600 ~/.ssh/id_ed25519
          ssh-keyscan -H ${{ secrets.SSH_HOST }} >> ~/.ssh/known_hosts

      - name: 触发服务器更新
        run: |
          ssh -o StrictHostKeyChecking=no -i ~/.ssh/id_ed25519 \
            "${SSH_USER}@${SSH_HOST}" \
            "set -e; cd '${DEPLOY_PATH}'; \
             git fetch --all; \
             git reset --hard origin/main; \
             ./deploy.sh update"

      - name: 健康检查
        run: |
          ssh -o StrictHostKeyChecking=no -i ~/.ssh/id_ed25519 \
            "${SSH_USER}@${SSH_HOST}" \
            "docker compose --env-file /www/wwwroot/video/.env.prod \
               -f /www/wwwroot/video/docker-compose.prod.yml ps"
```

## Current workflow

- Trigger: `workflow_dispatch` only, from `refs/heads/main`, with the typed input `deploy-production`.
- `permissions: contents: read`; no checkout; secrets only passed through `env:`.
- Refuses when any of `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`, `SSH_HOST`, `SSH_USER`, `DEPLOY_PATH`
  (secrets) or `SSH_HOST_KEY_SHA256` (environment variable) is empty.
- Verifies the pinned host key: `SSH_KNOWN_HOSTS` must hold exactly one entry for `SSH_HOST` whose
  SHA256 fingerprint equals `SSH_HOST_KEY_SHA256`. No `ssh-keyscan`.
- Rejects `@cert-authority` and `@revoked` marker lines (a pinned CA would trust every host it
  signs) and host patterns containing `*`, `?` or `!` (the entry must name one host literally).
  Hashed entries (`|1|salt|hash`) are accepted.
- Limitation: non-default SSH ports are not supported. A `[host]:port` entry does not match the bare
  `SSH_HOST` lookup, so the workflow refuses; supporting another port needs its own change and review.
- The notification step discards the webhook response body (`-o /dev/null`, `--max-time 10`) and
  ignores delivery failures (`|| true`); it sends only the job status, the GitHub actor and the
  commit SHA. It runs with `if: always()` after the refusal steps and cannot change their outcome.
- The legacy remote command is not executed; the job fails with an explanation. A replacement
  release step needs its own review and must use
  `ssh -o StrictHostKeyChecking=yes -o UserKnownHostsFile=<pinned file> -o BatchMode=yes`.

## Repository settings this workflow relies on (not changed by this patch)

1. Environment `production`: required reviewers; deployment branches limited to `main`;
   disable "allow administrators to bypass".
2. Environment secret `SSH_KNOWN_HOSTS`: one known_hosts line for the production host, taken from
   the server console (`/etc/ssh/ssh_host_ed25519_key.pub`), never from `ssh-keyscan`.
3. Environment variable `SSH_HOST_KEY_SHA256`: the matching `SHA256:...` fingerprint, obtained
   out of band and compared by a second person.
4. Other deployment secrets move to the `production` environment, not repository scope.
