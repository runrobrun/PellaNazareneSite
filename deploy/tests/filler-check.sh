#!/usr/bin/env bash
# Tests scripts/check-filler.sh against real WordPress: a clean site passes, and leftovers planted in many different
# places (page text, titles, settings, comments, menus, the trash, what a visitor sees, the code) are each found.
# Needs Docker and network access. Throwaway.
#
# Run from the repo root:  deploy/tests/filler-check.sh      (BREAK=<name> deliberately damages the scanner)
set -euo pipefail
cd "$(dirname "$0")/../.."
NET=pn-filler-net; DB=pn-filler-db
cleanup() { docker rm -f "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:11 >/dev/null
docker run --rm --network "$NET" -e BREAK="${BREAK:-}" -v "$PWD":/repo:ro --entrypoint bash wordpress:cli /repo/deploy/tests/filler-check-inner.sh
