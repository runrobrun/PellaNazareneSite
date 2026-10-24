#!/usr/bin/env bash
# Creates the seven site pages (Home, New Here, About Us, Kids, Giving, Contact, Resources) from the
# theme's page patterns, and makes Home the front page. Safe to re-run: pages that already exist are
# left alone, so staff edits are never overwritten.
#
# Usage:
#   create-pages.sh WP_PATH                     # e.g. /var/www/staging/public
#   create-pages.sh WP_PATH --replace about-us  # also reset one page to the pattern's current text
set -euo pipefail

WP_PATH="${1:?Usage: $0 WP_PATH [--replace PAGE...]}"
shift
replace=()
if [[ "${1:-}" == "--replace" ]]; then
	shift
	[[ $# -gt 0 ]] || { echo "--replace needs at least one page name." >&2; exit 1; }
	replace=("$@")
elif [[ $# -gt 0 ]]; then
	echo "Unknown option: $1" >&2; exit 1
fi

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
wp --path="$WP_PATH" eval-file "$here/create-pages.php" "${replace[@]}"
