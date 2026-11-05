#!/usr/bin/env bash
set -euo pipefail

[[ $EUID -eq 0 ]] || { echo "Run this through its site-specific sudo rule." >&2; exit 1; }
[[ $# -eq 1 ]] || { echo "Usage: $0 staging|production" >&2; exit 2; }

case "$1" in
	staging) CACHE_DIR="/var/cache/nginx/pellanazarene-staging" ;;
	production) CACHE_DIR="/var/cache/nginx/pellanazarene" ;;
	*) echo "Site must be staging or production." >&2; exit 2 ;;
esac

find "$CACHE_DIR" -type f -delete
