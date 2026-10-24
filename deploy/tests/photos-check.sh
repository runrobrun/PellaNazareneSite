#!/usr/bin/env bash
# Checks the built photos against their manifest (see photos-check.py). Needs Docker. Run from the repo root:
#   deploy/tests/photos-check.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
docker run --rm -v "$PWD":/repo:ro -w /repo python:3.12-slim sh -c \
	"pip install -q --disable-pip-version-check pillow >/dev/null 2>&1 && python deploy/tests/photos-check.py"
