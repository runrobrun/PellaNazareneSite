#!/usr/bin/env bash
# Build the live site on the Linode, beside staging, before the DNS switch (docs/cutover.md step 2).
# Run as the deploy user (not root), from the staging checkout or any up-to-date checkout:
#   scripts/setup-production.sh ADMIN_USER ADMIN_EMAIL
# Safe to re-run; each step checks before it changes anything. Nothing here touches staging, and
# visitors keep reaching the old site until the DNS records change on cutover day.
#
# Prerequisites (the script stops and says which one is missing):
#   - scripts/bootstrap-server.sh and scripts/apply-server-tuning.sh have run (they did for staging)
#   - scripts/setup-ci-deploy.sh production "<production CI public key>" has run, so the live
#     site's code is owned by pn-production-deploy from the start (SEC-5), never by deploy
#   - /root/pella-secrets/pellanazarene.pw (database password) and redis-production.pw exist
#   - the Cloudflare origin certificate is at /etc/ssl/cloudflare/pellanazarene.pem and .key
#   - optional now, needed before cutover: /root/pella-secrets/gmail-app-password (a Google app
#     password for pellanazarene@gmail.com, docs/forms.md); without it the forms can't email
#
# Differences from staging: environment "production" (turns email and Google Analytics on), the
# production Redis user and prefix, no password gate, visible to search engines, Cloudflare origin
# certificate and real visitor IPs, Gmail settings, nightly database backup. Secrets are read from
# /root/pella-secrets and never printed.
set -euo pipefail

ADMIN_USER="${1:?Usage: $0 ADMIN_USER ADMIN_EMAIL}"
ADMIN_EMAIL="${2:?Usage: $0 ADMIN_USER ADMIN_EMAIL}"

DOMAIN="pellanazarene.org"
ROOT="/var/www/pellanazarene"
WP="$ROOT/public"
PHP_USER="pn-production-php"
CI_USER="pn-production-deploy"
DB="pellanazarene"
REPO_URL="git@github.com:runrobrun/PellaNazareneSite.git"
CACHE_DIR="/var/cache/nginx/pellanazarene"
BACKUP_DIR="/var/backups/pellanazarene"
SECRETS="/root/pella-secrets"
CERT="/etc/ssl/cloudflare/pellanazarene.pem"
CERT_KEY="/etc/ssl/cloudflare/pellanazarene.key"
REALIP="/etc/nginx/snippets/cloudflare-realip.conf"
VHOST="/etc/nginx/sites-available/pellanazarene.conf"
YOUTUBE_CHANNEL="UCzv2v5YupU-E2blu_0BAhAg"   # public channel ID (docs/sermons.md)
SRC="$(cd "$(dirname "$0")/.." && pwd)"      # the checkout this script runs from

echo "==> Checking prerequisites"
[[ $EUID -ne 0 ]] || { echo "Run as the deploy user, not root." >&2; exit 1; }
sudo -n true || { echo "deploy needs passwordless sudo." >&2; exit 1; }
id "$CI_USER" &>/dev/null || { echo "Run scripts/setup-ci-deploy.sh production \"<production CI public key>\" first (docs/setup.md section 5)." >&2; exit 1; }
id "$PHP_USER" &>/dev/null || { echo "Missing $PHP_USER: run scripts/apply-server-tuning.sh first." >&2; exit 1; }
for f in "$DB.pw" redis-production.pw; do
	sudo test -s "$SECRETS/$f" || { echo "Missing $SECRETS/$f" >&2; exit 1; }
done
CI_HOME="$(getent passwd "$CI_USER" | cut -d: -f6)"
sudo test -s "$CI_HOME/.ssh/github_deploy" || { echo "Missing $CI_USER's GitHub checkout key (setup-ci-deploy.sh installs it)." >&2; exit 1; }

as_ci() { sudo -n -u "$CI_USER" env "WP_CLI_CACHE_DIR=/tmp/wpcli-$CI_USER" "$@"; }
run_wp() { as_ci wp --path="$WP" "$@"; }

echo "==> Database"
if ! sudo mysql -N -e "SELECT 1 FROM mysql.user WHERE user='$DB' AND host='localhost'" | grep -q 1; then
	# The password goes to MariaDB on stdin, never on a command line.
	{ printf "CREATE DATABASE IF NOT EXISTS \`%s\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n" "$DB"
	  printf "CREATE USER '%s'@'localhost' IDENTIFIED BY '%s';\n" "$DB" "$(sudo cat "$SECRETS/$DB.pw")"
	  printf "GRANT ALL ON \`%s\`.* TO '%s'@'localhost';\n" "$DB" "$DB"; } | sudo mysql
else
	sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`$DB\`.* TO '$DB'@'localhost';"
fi

echo "==> Directories (owned by $CI_USER, readable by $PHP_USER)"
sudo install -d -o deploy -g www-data -m 755 "$ROOT"
sudo install -d -o "$CI_USER" -g "$PHP_USER" -m 755 "$WP"
sudo install -d -o www-data -g www-data -m 755 "$CACHE_DIR"
sudo install -d -o "$PHP_USER" -g "$PHP_USER" -m 700 "$BACKUP_DIR"

echo "==> Clone repo (as $CI_USER)"
if ! sudo test -d "$ROOT/repo/.git"; then
	sudo install -d -o "$CI_USER" -g "$PHP_USER" -m 755 "$ROOT/repo"
	as_ci git clone --quiet "$REPO_URL" "$ROOT/repo"
fi

echo "==> WordPress core"
if ! sudo test -f "$WP/wp-settings.php"; then
	run_wp core download --locale=en_US --quiet
fi

echo "==> wp-config.php"
if ! sudo test -f "$WP/wp-config.php"; then
	# Fresh, unique salts come with config create. The password reaches WP-CLI on stdin.
	sudo cat "$SECRETS/$DB.pw" | as_ci wp --path="$WP" config create --dbname="$DB" --dbuser="$DB" \
		--dbcharset=utf8mb4 --prompt=dbpass --quiet
fi
run_wp config set WP_ENVIRONMENT_TYPE production
run_wp config set WP_DEBUG false --raw
run_wp config set DISABLE_WP_CRON true --raw
run_wp config set DISALLOW_FILE_EDIT true --raw
run_wp config set FORCE_SSL_ADMIN true --raw
run_wp config set WP_AUTO_UPDATE_CORE false --raw
run_wp config set WP_REDIS_HOST 127.0.0.1
run_wp config set WP_REDIS_USERNAME pn-production
REDIS_PASSWORD="$(sudo cat "$SECRETS/redis-production.pw")"
run_wp config set WP_REDIS_PASSWORD "$REDIS_PASSWORD"
unset REDIS_PASSWORD
run_wp config set WP_REDIS_DATABASE 0 --raw
run_wp config set WP_REDIS_PREFIX pn_production_
run_wp config set WP_REDIS_SELECTIVE_FLUSH true --raw
run_wp config set PN_YOUTUBE_CHANNEL_ID "$YOUTUBE_CHANNEL"
# Email through the church Gmail account (docs/forms.md). Staging never gets these.
run_wp config set WPMS_ON true --raw
run_wp config set WPMS_MAILER smtp
run_wp config set WPMS_MAIL_FROM pellanazarene@gmail.com
run_wp config set WPMS_MAIL_FROM_FORCE true --raw
run_wp config set WPMS_MAIL_FROM_NAME "Pella Nazarene website"
run_wp config set WPMS_SMTP_HOST smtp.gmail.com
run_wp config set WPMS_SMTP_PORT 587 --raw
run_wp config set WPMS_SSL tls
run_wp config set WPMS_SMTP_AUTH true --raw
run_wp config set WPMS_SMTP_USER pellanazarene@gmail.com
if sudo test -s "$SECRETS/gmail-app-password"; then
	GMAIL="$(sudo cat "$SECRETS/gmail-app-password" | tr -d ' \n')"
	run_wp config set WPMS_SMTP_PASS "$GMAIL"
	unset GMAIL
	GMAIL_STATUS="set"
else
	GMAIL_STATUS="MISSING: save the Google app password to $SECRETS/gmail-app-password, then re-run (forms can't email until then)"
fi

echo "==> Install WordPress (temporary content; staging's database replaces it on copy day)"
if ! run_wp core is-installed 2>/dev/null; then
	ADMINPASS="$(openssl rand -base64 18 | tr -d '/+=')"
	printf '%s' "$ADMINPASS" | sudo tee "$SECRETS/production-wp-admin.pw" >/dev/null
	sudo chmod 600 "$SECRETS/production-wp-admin.pw"
	printf '%s' "$ADMINPASS" | as_ci wp --path="$WP" core install --url="https://$DOMAIN" --title="Pella Nazarene" \
		--admin_user="$ADMIN_USER" --admin_email="$ADMIN_EMAIL" --prompt=admin_password --skip-email --quiet
	unset ADMINPASS
fi
run_wp option update blog_public 1 --quiet
run_wp option update users_can_register 0 --quiet
run_wp option update timezone_string America/Chicago --quiet
run_wp theme install twentytwentyfive --quiet 2>/dev/null || true
run_wp plugin delete akismet hello --quiet 2>/dev/null || true

echo "==> File ownership (code: $CI_USER:$PHP_USER, not writable by PHP; uploads: www-data + ACL)"
sudo chown -R "$CI_USER:$PHP_USER" "$WP" "$ROOT/repo"
sudo find "$WP" -type d -exec chmod 755 {} +
sudo find "$WP" -type f -exec chmod 644 {} +
sudo chmod 640 "$WP/wp-config.php"
sudo setfacl -m u:deploy:r-- "$WP/wp-config.php"
sudo install -d -o www-data -g www-data -m 755 "$WP/wp-content/uploads"
sudo chown -R www-data:www-data "$WP/wp-content/uploads"
sudo find "$WP/wp-content/uploads" -type d -exec setfacl -m "u:$PHP_USER:rwx,d:u:$PHP_USER:rwx" {} +
sudo find "$WP/wp-content/uploads" -type f -exec setfacl -m "u:$PHP_USER:rw-" {} +
sudo touch "$ROOT/.deploy.lock"
sudo chown "$CI_USER:$CI_USER" "$ROOT/.deploy.lock"
sudo chmod 600 "$ROOT/.deploy.lock"

echo "==> Plugins at the pinned versions, then our code"
as_ci "$ROOT/repo/scripts/install-plugins.sh" "$WP"
as_ci "$ROOT/repo/deploy/deploy.sh" "$ROOT" "$CACHE_DIR"
run_wp theme activate pella-nazarene --quiet
run_wp redis enable --quiet 2>/dev/null || echo "NOTE: Redis object cache not enabled yet; check 'wp redis status'."

echo "==> Cloudflare: real visitor IPs"
tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT
{ echo "# Real visitor IPs behind Cloudflare. Written by scripts/setup-production.sh from https://www.cloudflare.com/ips/"
  echo "# on $(date +%F); re-run the script to refresh. Only Cloudflare's own ranges may set the visitor address."
  for list in ips-v4 ips-v6; do
	curl -fsS --max-time 20 "https://www.cloudflare.com/$list" | tr -d '\r' | while read -r range; do
		[[ -z "$range" ]] && continue
		[[ "$range" =~ ^[0-9a-fA-F:.]+/[0-9]{1,3}$ ]] || { echo "Unexpected line from Cloudflare: $range" >&2; exit 1; }
		echo "set_real_ip_from $range;"
	done
  done
  echo "real_ip_header CF-Connecting-IP;"; } > "$tmp"
[[ "$(grep -c '^set_real_ip_from' "$tmp")" -ge 10 ]] || { echo "Cloudflare's IP list looks incomplete; nothing changed." >&2; exit 1; }
sudo install -m 644 -o root -g root "$tmp" "$REALIP"

echo "==> Nginx live site"
if ! sudo test -s "$CERT" || ! sudo test -s "$CERT_KEY"; then
	echo
	echo "STOPPED: the Cloudflare origin certificate is not on the server yet ($CERT and $CERT_KEY)."
	echo "Everything before this step is done. Install the certificate (docs/cutover.md step 2), then re-run."
	exit 1
fi
sudo chmod 600 "$CERT_KEY"
sed -e 's|^\t# include /etc/nginx/snippets/cloudflare-realip.conf;|\tinclude /etc/nginx/snippets/cloudflare-realip.conf;|' \
	"$ROOT/repo/deploy/nginx.conf.example" > "$tmp"
grep -q '^	include /etc/nginx/snippets/cloudflare-realip.conf;' "$tmp" || { echo "Could not enable the real-IP include in the vhost." >&2; exit 1; }
[[ -f "$VHOST" ]] && sudo cp -a "$VHOST" "$VHOST.before-$(date +%Y%m%d-%H%M%S)"
sudo install -m 644 -o root -g root "$tmp" "$VHOST"
sudo ln -sf "$VHOST" /etc/nginx/sites-enabled/pellanazarene.conf
if ! sudo nginx -t; then
	sudo rm -f /etc/nginx/sites-enabled/pellanazarene.conf
	echo "nginx -t failed; the live site was disabled again and Nginx was not reloaded." >&2
	exit 1
fi
sudo systemctl reload nginx

echo "==> Cron (as $PHP_USER): WP-Cron every 5 minutes, database backup nightly at 2:15"
sudo tee /etc/cron.d/pella-production >/dev/null <<CRON
*/5 * * * * $PHP_USER cd $WP && WP_CLI_CACHE_DIR=/tmp/wp-cli-$PHP_USER /usr/local/bin/wp cron event run --due-now --quiet
15 2 * * * $PHP_USER cd $WP && /usr/local/bin/wp db export - --quiet | gzip > $BACKUP_DIR/db-\$(date +\%F).sql.gz && find $BACKUP_DIR -name 'db-*.sql.gz' -mtime +14 -delete
CRON
sudo chmod 644 /etc/cron.d/pella-production

echo "==> Automatic WordPress security updates"
"$SRC/scripts/setup-core-updates.sh" production

echo
echo "The live site is built at $WP. Visitors still reach the old site until the DNS switch."
echo "Gmail password: $GMAIL_STATUS"
echo "Temporary admin password: sudo cat $SECRETS/production-wp-admin.pw   (user: $ADMIN_USER; replaced on copy day)"
echo "Look at it privately: add '$(curl -fsS --max-time 10 -4 https://ifconfig.me 2>/dev/null || echo '<server IP>') $DOMAIN' to your computer's hosts file."
