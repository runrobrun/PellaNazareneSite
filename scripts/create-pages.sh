#!/usr/bin/env bash
# Creates the seven site pages (Home, New Here, About Us, Kids, Giving, Contact, Resources) from the
# theme's page patterns, and makes Home the front page. Safe to re-run: pages that already exist are
# left alone, so staff edits are never overwritten.
#
# Usage:
#   create-pages.sh WP_PATH                     # e.g. /var/www/staging/public
#   create-pages.sh WP_PATH --replace about-us  # also reset one page to the pattern's current text
# Set PN_WP_USER=www-data (on the server) to run WordPress as the web user, so the photos can be saved to uploads.
# Set PN_NGINX_CACHE_DIR (staging: /var/cache/nginx/pellanazarene-staging) to clear the Nginx page cache too.
# It also sets pretty permalinks (/%postname%/) when WordPress is still on "Plain", or no menu link would work.
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
# The photos are saved into wp-content/uploads, which belongs to the web user, so run WordPress as that user.
wp_cmd=(wp)
if [[ -n "${PN_WP_USER:-}" ]]; then
	wp_cmd=(sudo -n -u "$PN_WP_USER" env "WP_CLI_CACHE_DIR=/tmp/wpcli-$PN_WP_USER" wp)
fi
"${wp_cmd[@]}" --path="$WP_PATH" eval-file "$here/create-pages.php" ${replace[@]+"${replace[@]}"}   # (this form also works in the older bash on a Mac)
"${wp_cmd[@]}" --path="$WP_PATH" rewrite flush

# The Nginx page cache can keep serving the old pages for up to an hour. Name its folder to clear it now.
if [[ -n "${PN_NGINX_CACHE_DIR:-}" ]]; then
	sudo -n find "$PN_NGINX_CACHE_DIR" -type f -delete
	echo "Cleared the Nginx page cache."
else
	echo "Reminder: clear the Nginx page cache or wait up to an hour. Staging: PN_NGINX_CACHE_DIR=/var/cache/nginx/pellanazarene-staging $0 $WP_PATH"
fi
