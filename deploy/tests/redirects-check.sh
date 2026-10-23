#!/usr/bin/env bash
# Every old URL does what deploy/redirects/expected.csv says, tested on a stack that matches the server
# (Nginx + PHP-FPM + WordPress + the sermon plugin + fixture content), through Nginx and through PHP alone.
# Needs Docker and network. Run from the repo root: deploy/tests/redirects-check.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
python3 deploy/tests/redirects-coverage.py | tail -1
ID="$$"; NET="pn-redir-net-$ID"; DB="pn-redir-db-$ID"
cleanup() { docker rm -f "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" --network-alias db -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:11 >/dev/null
docker run --rm --network "$NET" -v "$PWD":/repo:ro ubuntu:24.04 bash /repo/deploy/tests/redirects-stack.sh
