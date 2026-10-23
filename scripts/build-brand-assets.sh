#!/usr/bin/env bash
# Rebuild the web brand assets (SVG logos/icons, favicon set) from the designer's PNG masters in
# docs/branding/source/. Needs Docker and network (installs potrace in a throwaway container).
# Fails if any traced shape strays from its master. Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")/.."
OUT="wp-content/themes/pella-nazarene/assets/images"
PREVIEW="${1:-}"   # optional: a folder to write contact.png into, for a quick visual check
mkdir -p "$OUT"
args=(-v "$PWD/docs/branding/source":/src:ro -v "$PWD/$OUT":/out -v "$PWD/scripts/brand-assets/build.py":/build.py:ro)
if [[ -n "$PREVIEW" ]]; then
  mkdir -p "$PREVIEW"
  PREVIEW="$(cd "$PREVIEW" && pwd)"
  args+=(-v "$PREVIEW":/prev)
fi
docker run --rm "${args[@]}" python:3.12-slim sh -c \
  "apt-get update -qq >/dev/null && apt-get install -y -qq potrace libcairo2 >/dev/null && pip install -q pillow numpy scipy cairosvg 2>&1 | grep -v -i 'notice' ; python /build.py"
