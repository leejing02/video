#!/usr/bin/env bash
# Run on the server from anywhere inside this repository.
# Synchronize source only; deployment requires a separate explicit command.
set -euo pipefail

branch="${1:-main}"
if [[ $# -gt 1 || "$branch" == -* ]]; then
  echo "Usage: bash scripts/git-sync-server.sh [branch]" >&2
  exit 2
fi

git check-ref-format --branch "$branch" >/dev/null
cd "$(git rev-parse --show-toplevel)"
current="$(git symbolic-ref --quiet --short HEAD)"
if [[ "$current" != "$branch" ]]; then
  echo "Current branch is '$current', expected '$branch'. No branch changes made." >&2
  exit 1
fi

if [[ -n "$(git status --porcelain --untracked-files=normal)" ]]; then
  echo "Local changes found. Save and review them before synchronizing:" >&2
  git status --short
  exit 1
fi

git fetch origin "refs/heads/$branch"
git merge --ff-only FETCH_HEAD
printf 'Source synchronized: %s\n' "$(git rev-parse --short HEAD)"
printf '%s\n' 'No containers were restarted. Rebuild the intended services separately.'
