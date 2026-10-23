#!/usr/bin/env bash
# Checks the logo/icon assets that ship with the theme against the designer's masters (colors, SVG safety,
# fidelity, favicon set). Needs Docker and network (installs Python libraries in a throwaway container).
# Run from the repo root: deploy/tests/brand-assets-check.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
docker run --rm -v "$PWD":/repo:ro python:3.12-slim sh -c \
  "apt-get update -qq >/dev/null && apt-get install -y -qq libcairo2 >/dev/null 2>&1; pip install -q pillow numpy scipy cairosvg 2>&1 | grep -iv 'notice\|warning'; python /repo/deploy/tests/brand-assets-check.py"
