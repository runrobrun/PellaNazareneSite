#!/usr/bin/env bash
# Tests the sermon importer (wp pn-sermons backfill) against real WordPress and a fake YouTube API.
# No real key, no network to YouTube. Needs Docker and network access (downloads WordPress and two images).
#
# Run from the repo root:  deploy/tests/sermon-import-check.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
NET=pn-sermon-net; DB=pn-sermon-db
cleanup() { docker rm -f "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:11 >/dev/null
docker run --rm --network "$NET" -e BREAK="${BREAK:-}" -e DEBUG="${DEBUG:-}" -v "$PWD":/repo:ro --entrypoint bash wordpress:cli /repo/deploy/tests/sermon-import-inner.sh
