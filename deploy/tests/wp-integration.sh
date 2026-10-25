#!/usr/bin/env bash
# End-to-end test of wp-content/mu-plugins/pn-security.php against real WordPress and the real
# Two Factor plugin, with real HTTP logins. Needs Docker and network access (downloads WordPress,
# the plugin, and two images). Everything is throwaway and removed afterwards.
#
# Run from the repo root:  deploy/tests/wp-integration.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
NET=pn-test-net; DB=pn-test-db
cleanup() { docker rm -f "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:11 >/dev/null
docker run --rm --network "$NET" -e DEBUG="${DEBUG:-}" -e BREAK="${BREAK:-}" -v "$PWD":/repo:ro --entrypoint bash wordpress:cli /repo/deploy/tests/wp-integration-inner.sh
