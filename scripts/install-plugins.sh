#!/usr/bin/env bash
# Install or update the third-party plugins listed in plugins.txt.
# Usage: scripts/install-plugins.sh /path/to/wordpress
set -euo pipefail

WP_PATH="${1:?Usage: $0 /path/to/wordpress}"
LIST="$(dirname "$0")/../plugins.txt"

grep -Ev '^\s*(#|$)' "$LIST" | while read -r slug version; do
	if [[ -n "${version:-}" ]]; then
		wp --path="$WP_PATH" plugin install "$slug" --version="$version" --force --activate
	elif wp --path="$WP_PATH" plugin is-installed "$slug"; then
		wp --path="$WP_PATH" plugin activate "$slug" >/dev/null
		wp --path="$WP_PATH" plugin update "$slug"
	else
		wp --path="$WP_PATH" plugin install "$slug" --activate
	fi
done
