#!/usr/bin/env bash
# Rebuilds the web photos in the theme from the originals (see docs/photos.md). Needs Docker; nothing else.
# Run from anywhere:  scripts/build-photos.sh
set -euo pipefail
cd "$(dirname "$0")/.."
docker run --rm -v "$PWD":/repo -w /repo python:3.12-slim sh -c \
	"pip install -q --disable-pip-version-check pillow >/dev/null 2>&1 && python scripts/build-photos.py"
