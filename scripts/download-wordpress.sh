#!/usr/bin/env bash
# Put a fresh copy of WordPress core into a folder, the safe way (used by setup-staging.sh and
# setup-production.sh). Run it as the account that should own the files:
#   scripts/download-wordpress.sh /var/www/site/public [VERSION]     (default: latest)
#
# Why not `wp core download`: WP-CLI 2.12 and older unpack WordPress with PHP's tar reader, which cuts
# file names at 100 characters. Since 7.0 that silently breaks 25 files in wp-includes/php-ai-client
# (wp-cli/core-command#347; it happened here on 2026-11-18, even when given the .zip URL). This uses the
# official zip, checks it against WordPress.org's published SHA-1, and unpacks it with unzip(1).
# An existing wp-content and wp-config.php are never touched.
set -euo pipefail

DEST="${1:?Usage: $0 /path/to/wordpress [VERSION]}"
VERSION="${2:-latest}"
case "$VERSION" in
	latest) ZIP="latest.zip" ;;
	[0-9]*.[0-9]*) [[ "$VERSION" =~ ^[0-9]+(\.[0-9]+){1,2}$ ]] || { echo "Bad version: $VERSION" >&2; exit 2; }; ZIP="wordpress-$VERSION.zip" ;;
	*) echo "Bad version: $VERSION" >&2; exit 2 ;;
esac
[[ -d "$DEST" && -w "$DEST" ]] || { echo "$DEST must exist and be writable by $(id -un)." >&2; exit 1; }

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
curl -fsSL --max-time 300 -o "$work/$ZIP" "https://wordpress.org/$ZIP"
sum="$(curl -fsSL --max-time 30 "https://wordpress.org/$ZIP.sha1")"
[[ "$sum" =~ ^[0-9a-f]{40}$ ]] || { echo "WordPress.org did not return a checksum for $ZIP." >&2; exit 1; }
echo "$sum  $work/$ZIP" | sha1sum -c --quiet - || { echo "The download does not match WordPress.org's checksum." >&2; exit 1; }
unzip -q "$work/$ZIP" -d "$work"
# On an existing site, replace only core; on a new one, take everything (default themes included).
if [[ -d "$DEST/wp-content" ]]; then
	rsync -a --exclude=/wp-content/ "$work/wordpress/" "$DEST/"
else
	rsync -a "$work/wordpress/" "$DEST/"
fi
echo "WordPress $(sed -n "s/^\$wp_version = '\(.*\)';/\1/p" "$DEST/wp-includes/version.php") is in $DEST"
