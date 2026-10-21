#!/usr/bin/env bash
# Loads the repo's real staging Nginx config into the same Nginx version the server runs and sends
# real requests (blocked files, headers, login throttle). Needs Docker and network (installs nginx
# in a throwaway ubuntu:24.04 container). Run from the repo root: deploy/tests/nginx-config.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
docker run --rm -v "$PWD":/repo:ro ubuntu:24.04 bash /repo/deploy/tests/nginx-config-inner.sh
