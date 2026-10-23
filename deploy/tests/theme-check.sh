#!/usr/bin/env bash
# Installs real WordPress + Twenty Twenty-Five + our theme in throwaway containers and checks what
# WordPress actually generates from theme.json (brand colors, fonts, variables, preload, style limits).
# Needs Docker and network. Run from the repo root: deploy/tests/theme-check.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
NET=pn-test-net; DB=pn-test-db
cleanup() { docker rm -f "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:11 >/dev/null
docker run --rm --network "$NET" -v "$PWD":/repo:ro --entrypoint bash wordpress:cli /repo/deploy/tests/theme-check-inner.sh
