#!/usr/bin/env bash
# Accessibility check with axe-core in a real browser: WCAG 2.2 A/AA and best-practice rules on the home page
# (desktop, phone, and with the mobile menu OPEN), the 404 page, and search results.
# Needs Docker and network. Run from the repo root: deploy/tests/a11y-check.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
ID="$$"; NET="pn-a11y-net-$ID"; DB="pn-a11y-db-$ID"; WP="pn-a11y-wp-$ID"
cleanup() { docker rm -f "$WP" "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" --network-alias pn-test-db -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:11 >/dev/null
docker run -d --name "$WP" --network "$NET" --network-alias pn-wp -v "$PWD":/repo:ro --entrypoint bash wordpress:cli /repo/deploy/tests/serve-wp.sh >/dev/null
for _ in $(seq 1 90); do docker exec "$WP" test -f /tmp/ready 2>/dev/null && break; sleep 3; done
docker exec "$WP" test -f /tmp/ready || { echo "SETUP FAILED: the test site did not start"; docker logs "$WP" 2>&1 | tail -5; exit 1; }
sleep 2
docker run --rm --network "$NET" --user root -e BREAK_FOCUS="${BREAK_FOCUS:-}" -v "$PWD/deploy/tests/a11y/axe-run.mjs":/work/axe-run.mjs:ro --entrypoint sh zenika/alpine-chrome:with-puppeteer -c \
  'mkdir -p /tmp/a && cd /tmp/a && cp /work/axe-run.mjs . && npm init -y >/dev/null 2>&1 && npm install axe-core puppeteer-core >/dev/null 2>&1 && PUPPETEER_EXECUTABLE_PATH=$(command -v chromium-browser) node axe-run.mjs'
