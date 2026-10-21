#!/usr/bin/env bash
# Forced command for the GitHub Actions deploy key (staging).
#
# Installed root-owned at /usr/local/bin/pn-ci-deploy by scripts/setup-ci-deploy.sh.
# The CI key in ~deploy/.ssh/authorized_keys is pinned to this script with
# `restrict,command="/usr/local/bin/pn-ci-deploy"`, so that key can run this and nothing else.
#
# Input (SSH_ORIGINAL_COMMAND): "staging <40-character commit sha>"
# Steps: validate -> back up the database -> deploy that commit -> health check -> roll back on failure.
set -euo pipefail

ROOT="/var/www/staging"
WP="$ROOT/public"
REPO="$ROOT/repo"
CACHE_DIR="/var/cache/nginx/pellanazarene-staging"
DOMAIN="staging.pellanazarene.org"
BACKUPS="/var/backups/pn-staging-db"
SECRETS_FILE="/root/pella-secrets/staging-basic-auth.txt"

TARGET="" SHA="" EXTRA=""
read -r TARGET SHA EXTRA <<<"${SSH_ORIGINAL_COMMAND:-}" || true
if [[ "$TARGET" != "staging" || ! "$SHA" =~ ^[0-9a-f]{40}$ || -n "$EXTRA" ]]; then
	echo "Usage: staging <40-character commit sha>" >&2
	exit 2
fi

# Share the lock with direct staging deploys, including setup-staging.sh.
exec 9>"$ROOT/.deploy.lock"
flock -n 9 || { echo "Another deploy is already running." >&2; exit 4; }

# Only deploy commits that are on main (a PR branch can never be deployed this way).
git -C "$REPO" fetch --quiet origin main
if ! git -C "$REPO" merge-base --is-ancestor "$SHA" origin/main; then
	echo "Refusing to deploy $SHA: it is not on origin/main." >&2
	exit 3
fi

PREVIOUS="$(git -C "$REPO" rev-parse HEAD)"
echo "==> Deploying ${SHA:0:7} (currently ${PREVIOUS:0:7})"

echo "==> Database backup"
sudo install -d -o "$(id -un)" -g "$(id -gn)" -m 750 "$BACKUPS"
BACKUP_FILE="$BACKUPS/pre-${SHA:0:12}-$(date +%Y%m%d-%H%M%S).sql.gz"
# Owner-only: the dump contains password hashes.
( umask 077; wp --path="$WP" db export - --quiet | gzip > "$BACKUP_FILE" )
# Keep the five most recent.
find "$BACKUPS" -name 'pre-*.sql.gz' -type f -printf '%T@ %p\n' | sort -rn | tail -n +6 | cut -d' ' -f2- | xargs -r rm -f

rollback() {
	local reason="$1" status="${2:-1}"
	echo "!! $reason; rolling back to ${PREVIOUS:0:7}" >&2
	if ! git -C "$REPO" reset --hard --quiet "$PREVIOUS"; then
		echo "!! Could not reset the checkout to ${PREVIOUS:0:7}" >&2
		exit 1
	fi
	if ! PN_DEPLOY_LOCK_HELD=1 PN_SKIP_PLUGIN_INSTALL=1 \
		/usr/local/libexec/pn-staging-deploy "$ROOT" "$CACHE_DIR" "$PREVIOUS"; then
		echo "!! Rollback deployment failed; inspect staging before retrying." >&2
		exit 1
	fi
	echo "Rolled back to ${PREVIOUS:0:7}. Pre-deploy database backup: $BACKUP_FILE (not restored automatically)." >&2
	exit "$status"
}

echo "==> Deploy"
if PN_DEPLOY_LOCK_HELD=1 PN_SKIP_PLUGIN_INSTALL=1 \
	/usr/local/libexec/pn-staging-deploy "$ROOT" "$CACHE_DIR" "$SHA"; then
	:
else
	deploy_status=$?
	rollback "Deployment exited with status $deploy_status" "$deploy_status"
fi

health_check() {
	local path body code password
	password="$(sudo -n sed -n 's/^password: //p' "$SECRETS_FILE")"
	[[ -n "$password" ]] || { echo "health: could not read the staging basic-auth password" >&2; return 1; }
	for path in / /wp-login.php; do
		body="$(curl -sS --max-time 25 --resolve "$DOMAIN:443:127.0.0.1" \
			--config <(printf 'user = "church:%s"\n' "$password") \
			-w '\n%{http_code}' "https://$DOMAIN$path")" || { echo "health: request to $path failed" >&2; return 1; }
		code="${body##*$'\n'}"
		body="${body%$'\n'*}"
		if [[ "$code" != "200" ]]; then
			echo "health: $path returned HTTP $code" >&2
			return 1
		fi
		if grep -qi "critical error on this website" <<<"$body"; then
			echo "health: $path shows the WordPress critical-error page" >&2
			return 1
		fi
	done
}

echo "==> Health check"
for attempt in 1 2 3; do
	if health_check; then
		echo "Healthy. Deployed ${SHA:0:7}."
		exit 0
	fi
	echo "Attempt $attempt failed."
	sleep 4
done

rollback "Health check failed"
