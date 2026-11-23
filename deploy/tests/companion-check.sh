#!/usr/bin/env bash
# Tests the weekly blog draft from a sermon (FEAT-9) against real WordPress, with a fake yt-dlp, a fake Claude
# API and a fake Pexels API. No real keys, no network to YouTube, Anthropic or Pexels, and no email is sent.
# Needs Docker and network access (downloads WordPress and two images).
#
# Run from the repo root:  deploy/tests/companion-check.sh
# BREAK=<name> damages the plugin copy under test on purpose; the run must then fail (see companion-inner.sh).
set -euo pipefail
cd "$(dirname "$0")/../.."
NET=pn-companion-net; DB=pn-companion-db
cleanup() { docker rm -f "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:11 >/dev/null
docker run --rm --network "$NET" -e BREAK="${BREAK:-}" -e DEBUG="${DEBUG:-}" -v "$PWD":/repo:ro --entrypoint bash wordpress:cli /repo/deploy/tests/companion-inner.sh
