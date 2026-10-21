#!/usr/bin/env bash
# Runs on the server (called by GitHub Actions over SSH).
# pn-ci-deploy-contract: 1
# Usage: deploy/deploy.sh /var/www/pellanazarene [/var/cache/nginx/pellanazarene] [COMMIT_SHA]
#   Second argument is this site's Nginx FastCGI cache directory (defaults to
#   the production one). Staging passes its own so it never clears production's.
#   Third argument deploys that exact commit (40 hex characters) instead of the
#   tip of origin/main; the CI wrapper uses it to deploy what CI actually tested.
#
# Expects:
#   $DEPLOY_PATH/repo        a git clone of this repository
#   $DEPLOY_PATH/public      the WordPress install (wp-config.php lives here)
set -euo pipefail

DEPLOY_PATH="${1:?Usage: $0 /deploy/path [/nginx/cache/dir]}"
CACHE_DIR="${2:-/var/cache/nginx/pellanazarene}"
REF="${3:-origin/main}"
if [[ "$REF" != "origin/main" && ! "$REF" =~ ^[0-9a-f]{40}$ ]]; then
	echo "Third argument must be a full 40-character commit SHA." >&2
	exit 2
fi
REPO="$DEPLOY_PATH/repo"
WP="$DEPLOY_PATH/public"

if [[ "$DEPLOY_PATH" == "/var/www/staging" && "${PN_DEPLOY_LOCK_HELD:-0}" != "1" ]]; then
	exec 9>"$DEPLOY_PATH/.deploy.lock"
	flock -n 9 || { echo "Another staging deploy is already running." >&2; exit 4; }
fi

cd "$REPO"
git fetch --quiet origin main
git reset --hard --quiet "$REF"
echo "Deploying $(git rev-parse --short HEAD)"

# Sync only our code. --delete keeps removed files from lingering,
# scoped to our own directories so nothing else is touched.
rsync -a --delete "$REPO/wp-content/plugins/pn-sermons/"      "$WP/wp-content/plugins/pn-sermons/"
rsync -a --delete "$REPO/wp-content/themes/pella-nazarene/"   "$WP/wp-content/themes/pella-nazarene/"
mkdir -p "$WP/wp-content/mu-plugins"
rsync -a "$REPO/wp-content/mu-plugins/pn-site.php"            "$WP/wp-content/mu-plugins/pn-site.php"

if [[ "${PN_SKIP_PLUGIN_INSTALL:-0}" != "1" ]]; then
	"$REPO/scripts/install-plugins.sh" "$WP"
fi

wp --path="$WP" plugin activate pn-sermons >/dev/null
wp --path="$WP" core update-db --quiet
wp --path="$WP" rewrite flush --quiet
wp --path="$WP" cache flush --quiet

# Clear the Nginx FastCGI page cache (path matches the fastcgi_cache_path in Nginx).
if [[ -d "$CACHE_DIR" ]]; then
	# Cache files are owned by www-data; deploy has passwordless sudo.
	sudo -n find "$CACHE_DIR" -type f -delete
fi

echo "Deploy complete."
