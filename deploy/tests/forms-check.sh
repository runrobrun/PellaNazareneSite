#!/usr/bin/env bash
# Tests the Contact and Prayer Request forms against real WordPress and the pinned Fluent Forms: the scripts that
# create them, real submissions, spam checks, who gets the email, and that prayer requests are not kept.
# Needs Docker and network access. Throwaway.
#
# Run from the repo root:  deploy/tests/forms-check.sh      (BREAK=<name> deliberately damages something)
set -euo pipefail
cd "$(dirname "$0")/../.."
NET=pn-forms-net; DB=pn-forms-db
cleanup() { docker rm -f "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:11 >/dev/null
docker run --rm --network "$NET" -e BREAK="${BREAK:-}" -v "$PWD":/repo:ro --entrypoint bash wordpress:cli /repo/deploy/tests/forms-check-inner.sh
