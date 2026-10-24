#!/usr/bin/env bash
# Tests scripts/create-pages.sh and the seven migrated pages against real WordPress, Twenty Twenty-Five, our
# theme, and the sermon plugin. Needs Docker and network access. Throwaway.
#
# Run from the repo root:  deploy/tests/pages-check.sh      (BREAK=<name> deliberately damages something; see the inner script)
set -euo pipefail
cd "$(dirname "$0")/../.."
NET=pn-pages-net; DB=pn-pages-db
cleanup() { docker rm -f "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:11 >/dev/null
docker run --rm --network "$NET" -e BREAK="${BREAK:-}" -v "$PWD":/repo:ro --entrypoint bash wordpress:cli /repo/deploy/tests/pages-check-inner.sh
