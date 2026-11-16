#!/usr/bin/env bash
# Installs WordPress core maintenance and security releases (7.1.3 -> 7.1.4, never 7.1 -> 7.2) for one site.
#
# Installed root-owned at /usr/local/sbin/pn-core-update by scripts/setup-core-updates.sh, which also
# turns on a daily systemd timer per site (pn-core-update@staging.timer, pn-core-update@production.timer).
# Why it exists: since SEC-5 the site's PHP user cannot write the site's files, so WordPress's own
# background updates can never install anything. This runs WP-CLI as the site's deploy user instead.
#
# Steps: skip if up to date -> back up the core files and the database -> update -> update the
# database -> verify every core file against WordPress.org's checksums -> clear caches -> health check.
# If anything after the backup fails, the old core files are put back from the local copy (no
# download needed). The database backup is imported only if the update changed the database
# version, so a rollback does not undo unrelated changes (a staff edit, a sermon import) made in
# the meantime. The run then fails, so `systemctl --failed` and the journal show it. If the files
# cannot be put back, it stops with RECOVERY NEEDED and leaves the database alone.
set -euo pipefail

[[ $EUID -eq 0 ]] || { echo "pn-core-update runs as root (from its systemd timer, or with sudo)." >&2; exit 1; }
[[ $# -eq 1 ]] || { echo "Usage: $0 staging|production" >&2; exit 2; }

# Where things live. A test can rewrite these lines to point at a sandbox.
WWW_DIR="/var/www"
NGINX_CACHE_DIR="/var/cache/nginx"
BACKUP_DIR="/var/backups"
SECRETS_DIR="/root/pella-secrets"

SITE="$1"
case "$SITE" in
	staging)
		ROOT="$WWW_DIR/staging"
		CACHE_DIR="$NGINX_CACHE_DIR/pellanazarene-staging"
		DOMAIN="staging.pellanazarene.org"
		# Staging sits behind basic auth; the health check needs its password.
		SECRETS_FILE="$SECRETS_DIR/staging-basic-auth.txt"
		;;
	production)
		ROOT="$WWW_DIR/pellanazarene"
		CACHE_DIR="$NGINX_CACHE_DIR/pellanazarene"
		DOMAIN="pellanazarene.org"
		SECRETS_FILE=""
		;;
	*) echo "Site must be staging or production, not: $SITE" >&2; exit 2 ;;
esac
DEPLOY_USER="pn-$SITE-deploy"
WP="$ROOT/public"
BACKUPS="$BACKUP_DIR/pn-$SITE-db"

[[ -f "$WP/wp-config.php" ]] || { echo "$SITE: no WordPress at $WP; nothing to update."; exit 0; }
id "$DEPLOY_USER" &>/dev/null || { echo "$SITE: missing $DEPLOY_USER (run scripts/setup-ci-deploy.sh)." >&2; exit 1; }

# Wait for a running deploy of this site to finish rather than updating underneath it.
exec 9>"$ROOT/.deploy.lock"
flock -w 900 9 || { echo "$SITE: a deploy has held the lock for 15 minutes; trying again tomorrow." >&2; exit 4; }

# WP-CLI as the site's deploy user, never as root.
pn_wp() {
	runuser -u "$DEPLOY_USER" -- env WP_CLI_CACHE_DIR="/tmp/wp-cli-$DEPLOY_USER" wp --path="$WP" --quiet "$@"
}

OLD="$(pn_wp core version)"
# WP-CLI says "latest version" when it cannot reach WordPress.org, so check that first: an outage
# must show up as a failed run, never as "up to date".
if ! curl -sf -o /dev/null --max-time 30 "https://api.wordpress.org/core/version-check/1.7/"; then
	echo "$SITE: cannot reach api.wordpress.org to check for updates (WordPress $OLD)." >&2
	exit 3
fi
if ! COUNT="$(pn_wp core check-update --minor --format=count)" || [[ ! "$COUNT" =~ ^[0-9]+$ ]]; then
	echo "$SITE: the update check failed (WordPress $OLD); changing nothing." >&2
	exit 3
fi
if [[ "$COUNT" == "0" ]]; then
	echo "$SITE: WordPress $OLD is up to date."
	exit 0
fi

echo "$SITE: updating WordPress $OLD"
install -d -o root -g root -m 700 "$BACKUPS"
STAMP="core-$OLD-$(date +%Y%m%d-%H%M%S)"
BACKUP_FILE="$BACKUPS/$STAMP.sql.gz"
FILES_BACKUP="$BACKUPS/$STAMP.files.tar.gz"
OLD_DB_VERSION="$(pn_wp option get db_version)"
# Owner-only: the dump contains password hashes. The core files (everything but wp-content and
# wp-config.php) are copied too, so a rollback never depends on downloading anything.
( umask 077
  pn_wp db export - | gzip > "$BACKUP_FILE"
  runuser -u "$DEPLOY_USER" -- tar -C "$WP" --exclude=./wp-content --exclude=./wp-config.php -czf - . > "$FILES_BACKUP" )
tar -tzf "$FILES_BACKUP" ./wp-includes/version.php >/dev/null || { echo "$SITE: the core file backup is unreadable; changing nothing." >&2; exit 1; }
# Keep the five most recent update backups of each kind.
for kind in sql.gz files.tar.gz; do
	find "$BACKUPS" -name "core-*.$kind" -type f -printf '%T@ %p\n' | sort -rn | tail -n +6 | cut -d' ' -f2- | xargs -r rm -f
done

clear_caches() {
	pn_wp cache flush || return 1
	if [[ -d "$CACHE_DIR" ]]; then
		find "$CACHE_DIR" -type f -delete || return 1
	fi
}

# One request over this machine's own loopback; prints the status code.
status_of() {
	local url="https://$DOMAIN$1" password
	if [[ -n "$SECRETS_FILE" ]]; then
		password="$(sed -n 's/^password: //p' "$SECRETS_FILE")"
		[[ -n "$password" ]] || { echo "000"; return 0; }
		curl -s -o /dev/null --max-time 25 --resolve "$DOMAIN:443:127.0.0.1" \
			--config <(printf 'user = "church:%s"\n' "$password") -w '%{http_code}' "$url" || echo "000"
	else
		# Production: curl does not trust a Cloudflare origin certificate, and loopback needs no check.
		curl -s -o /dev/null --max-time 25 --resolve "$DOMAIN:443:127.0.0.1" --insecure \
			-w '%{http_code}' "$url" || echo "000"
	fi
}

healthy() {
	local path code
	for path in / /wp-login.php; do
		code="$(status_of "$path")"
		if [[ "$code" != "200" ]]; then
			echo "$SITE: health check: $path returned HTTP $code" >&2
			return 1
		fi
	done
}

roll_back() {
	echo "$SITE: update failed; putting back WordPress $OLD from $FILES_BACKUP" >&2
	# Remove the two core folders first so no file from the new version is left behind in them.
	if ! runuser -u "$DEPLOY_USER" -- rm -rf "$WP/wp-admin" "$WP/wp-includes" \
		|| ! runuser -u "$DEPLOY_USER" -- tar -C "$WP" -xzf - < "$FILES_BACKUP"; then
		echo "$SITE: RECOVERY NEEDED: could not put back the $OLD files. The database was left as it is." >&2
		echo "$SITE: restore by hand from $FILES_BACKUP (and $BACKUP_FILE if needed); see docs/setup.md section 5." >&2
		exit 5
	fi
	# Import the database backup only if the update changed the database version; otherwise the
	# database is still right for $OLD, and importing would undo changes made since the backup.
	if [[ "$(pn_wp option get db_version 2>/dev/null || echo unknown)" != "$OLD_DB_VERSION" ]]; then
		echo "$SITE: the update changed the database; restoring it from $BACKUP_FILE" >&2
		gunzip -c "$BACKUP_FILE" | pn_wp db import - || { echo "$SITE: RECOVERY NEEDED: could not restore the database from $BACKUP_FILE." >&2; exit 5; }
	fi
	clear_caches || echo "$SITE: could not clear the caches after the rollback; clear them by hand." >&2
	exit 1
}

pn_wp core update --minor || roll_back
pn_wp core update-db || roll_back
pn_wp core verify-checksums || roll_back
clear_caches || roll_back
healthy || roll_back
echo "$SITE: WordPress $OLD -> $(pn_wp core version)"
