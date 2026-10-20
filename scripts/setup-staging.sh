#!/usr/bin/env bash
# Build the staging site on the Linode. Run as the deploy user (not root).
# Safe to re-run; each step checks before it changes anything.
#
# Usage: scripts/setup-staging.sh ADMIN_USER ADMIN_EMAIL
#
# Prerequisites:
#   - scripts/bootstrap-server.sh has been run
#   - /root/pella-secrets/pellanazarene_staging.pw exists (database password)
#   - staging.pellanazarene.org points at this server
#   - this repo, with the latest changes, is pushed to GitHub
#
# Run 1 creates a read-only GitHub deploy key and stops so you can add it on GitHub.
# Run 2 does the rest. Secrets are written to /root/pella-secrets/ and never printed.
set -euo pipefail

ADMIN_USER="${1:?Usage: $0 ADMIN_USER ADMIN_EMAIL}"
ADMIN_EMAIL="${2:?Usage: $0 ADMIN_USER ADMIN_EMAIL}"

DOMAIN="staging.pellanazarene.org"
ROOT="/var/www/staging"
WP="$ROOT/public"
DB="pellanazarene_staging"
REPO_URL="git@github.com:runrobrun/PellaNazareneSite.git"
CACHE_DIR="/var/cache/nginx/pellanazarene-staging"
SECRETS="/root/pella-secrets"
KEY="$HOME/.ssh/github_deploy"

[[ $EUID -ne 0 ]] || { echo "Run as the deploy user, not root." >&2; exit 1; }
sudo -n true || { echo "deploy needs passwordless sudo." >&2; exit 1; }
sudo test -s "$SECRETS/$DB.pw" || { echo "Missing $SECRETS/$DB.pw" >&2; exit 1; }

echo "==> GitHub deploy key"
if [[ ! -f "$KEY" ]]; then
	ssh-keygen -t ed25519 -N "" -C "pella-web deploy key" -f "$KEY"
	echo
	echo "Add this PUBLIC key on GitHub: repo > Settings > Deploy keys > Add deploy key."
	echo "Title: pella-web. Leave 'Allow write access' UNCHECKED."
	echo
	cat "$KEY.pub"
	echo
	echo "Then run this script again."
	exit 0
fi
mkdir -p ~/.ssh && chmod 700 ~/.ssh
if ! grep -q "Host github.com" ~/.ssh/config 2>/dev/null; then
	cat >> ~/.ssh/config <<CONF
Host github.com
    IdentityFile $KEY
    IdentitiesOnly yes
    StrictHostKeyChecking accept-new
CONF
	chmod 600 ~/.ssh/config
fi

echo "==> Directories"
sudo install -d -o deploy -g www-data -m 755 "$ROOT"
sudo install -d -o www-data -g www-data -m 755 "$CACHE_DIR"

echo "==> Clone repo"
if [[ ! -d "$ROOT/repo/.git" ]]; then
	git clone "$REPO_URL" "$ROOT/repo"
fi
echo "GitHub host key the clone trusted (compare with https://docs.github.com/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints):"
ssh-keygen -lF github.com | grep -v '^#' | head -3 || true

echo "==> WordPress core"
mkdir -p "$WP"
if [[ ! -f "$WP/wp-settings.php" ]]; then
	wp core download --path="$WP" --locale=en_US
fi

echo "==> wp-config.php"
if [[ ! -f "$WP/wp-config.php" ]]; then
	DBPASS="$(sudo cat "$SECRETS/$DB.pw")"
	wp config create --path="$WP" --dbname="$DB" --dbuser="$DB" --dbpass="$DBPASS" --dbcharset=utf8mb4
	unset DBPASS
fi
wp config set --path="$WP" WP_ENVIRONMENT_TYPE staging
wp config set --path="$WP" WP_DEBUG false --raw
wp config set --path="$WP" DISABLE_WP_CRON true --raw
wp config set --path="$WP" DISALLOW_FILE_EDIT true --raw
wp config set --path="$WP" WP_REDIS_HOST 127.0.0.1
wp config set --path="$WP" WP_REDIS_DATABASE 1 --raw
wp config set --path="$WP" WP_REDIS_PREFIX pn_staging_

echo "==> Install WordPress"
if ! wp core is-installed --path="$WP" 2>/dev/null; then
	ADMINPASS="$(openssl rand -base64 18 | tr -d '/+=')"
	wp core install --path="$WP" --url="https://$DOMAIN" --title="Pella Nazarene (staging)" \
		--admin_user="$ADMIN_USER" --admin_email="$ADMIN_EMAIL" --admin_password="$ADMINPASS" --skip-email
	printf '%s' "$ADMINPASS" | sudo tee "$SECRETS/staging-wp-admin.pw" >/dev/null
	sudo chmod 600 "$SECRETS/staging-wp-admin.pw"
	unset ADMINPASS
fi
wp option update --path="$WP" blog_public 0
wp option update --path="$WP" timezone_string America/Chicago
wp theme install twentytwentyfive --path="$WP" 2>/dev/null || true

echo "==> File ownership"
# deploy owns code (so deploys and WP-CLI work); www-data can read it and write uploads only.
sudo chown -R deploy:www-data "$WP"
sudo find "$WP" -type d -exec chmod 755 {} +
sudo find "$WP" -type f -exec chmod 644 {} +
sudo chmod 640 "$WP/wp-config.php"
sudo install -d -o www-data -g www-data -m 755 "$WP/wp-content/uploads"
sudo chown -R www-data:www-data "$WP/wp-content/uploads"

echo "==> Nginx base config"
sudo tee /etc/nginx/conf.d/pn-staging-cache.conf >/dev/null <<NGX
fastcgi_cache_path $CACHE_DIR levels=1:2 keys_zone=PELLA_STG:32m inactive=60m max_size=256m;
NGX
sudo tee /etc/nginx/sites-available/00-default-deny.conf >/dev/null <<'NGX'
# Anything that isn't a known hostname (e.g. the bare IP) gets nothing.
server {
	listen 80 default_server;
	listen [::]:80 default_server;
	listen 443 ssl default_server;
	listen [::]:443 ssl default_server;
	ssl_reject_handshake on;
	return 444;
}
NGX
sudo ln -sf /etc/nginx/sites-available/00-default-deny.conf /etc/nginx/sites-enabled/00-default-deny.conf
sudo rm -f /etc/nginx/sites-enabled/default

# Port-80 server only (serves Let's Encrypt challenges, redirects everything else).
sudo tee /etc/nginx/sites-available/staging.conf >/dev/null <<NGX
server {
	listen 80;
	listen [::]:80;
	server_name $DOMAIN;
	location ^~ /.well-known/acme-challenge/ { root $WP; auth_basic off; }
	location / { return 301 https://\$host\$request_uri; }
}
NGX
sudo ln -sf /etc/nginx/sites-available/staging.conf /etc/nginx/sites-enabled/staging.conf
sudo nginx -t
sudo systemctl reload nginx

echo "==> TLS certificate (Let's Encrypt)"
sudo apt-get install -y -qq certbot
if [[ ! -d "/etc/letsencrypt/live/$DOMAIN" ]]; then
	sudo certbot certonly --webroot -w "$WP" -d "$DOMAIN" -m "$ADMIN_EMAIL" --agree-tos --no-eff-email -n
fi
sudo install -d /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' | sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh >/dev/null
sudo chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

echo "==> Basic auth"
if ! sudo test -f /etc/nginx/htpasswd-staging; then
	AUTHPASS="$(openssl rand -base64 18 | tr -d '/+=')"
	printf 'church:%s\n' "$(openssl passwd -apr1 "$AUTHPASS")" | sudo tee /etc/nginx/htpasswd-staging >/dev/null
	sudo chown root:www-data /etc/nginx/htpasswd-staging
	sudo chmod 640 /etc/nginx/htpasswd-staging
	printf 'user: church\npassword: %s\n' "$AUTHPASS" | sudo tee "$SECRETS/staging-basic-auth.txt" >/dev/null
	sudo chmod 600 "$SECRETS/staging-basic-auth.txt"
	unset AUTHPASS
fi

echo "==> Nginx staging site"
sudo tee /etc/nginx/sites-available/staging.conf >/dev/null <<NGX
server {
	listen 80;
	listen [::]:80;
	server_name $DOMAIN;
	location ^~ /.well-known/acme-challenge/ { root $WP; auth_basic off; }
	location / { return 301 https://\$host\$request_uri; }
}

server {
	listen 443 ssl;
	listen [::]:443 ssl;
	http2 on;
	server_name $DOMAIN;

	ssl_certificate     /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
	ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;
	ssl_protocols TLSv1.2 TLSv1.3;

	root  $WP;
	index index.php;
	client_max_body_size 64m;

	# Private and hidden from search engines.
	auth_basic "Staging";
	auth_basic_user_file /etc/nginx/htpasswd-staging;
	add_header X-Robots-Tag "noindex, nofollow, noarchive" always;

	# Same page-cache rules as production.
	set \$skip_cache 0;
	if (\$request_method = POST)  { set \$skip_cache 1; }
	if (\$query_string != "")     { set \$skip_cache 1; }
	if (\$request_uri ~* "/wp-admin/|/wp-json/|/xmlrpc.php|wp-.*\.php|/feed/|sitemap") { set \$skip_cache 1; }
	if (\$http_cookie ~* "comment_author|wordpress_[a-f0-9]+|wp-postpass|wordpress_logged_in") { set \$skip_cache 1; }

	location / {
		try_files \$uri \$uri/ /index.php?\$args;
	}

	location ~ \.php\$ {
		include snippets/fastcgi-php.conf;
		fastcgi_pass unix:/run/php/php8.3-fpm.sock;
		fastcgi_cache PELLA_STG;
		fastcgi_cache_key "\$scheme\$request_method\$host\$request_uri";
		fastcgi_cache_valid 200 301 302 60m;
		fastcgi_cache_bypass \$skip_cache;
		fastcgi_no_cache \$skip_cache;
		add_header X-Cache \$upstream_cache_status;
		add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
	}

	location = /xmlrpc.php { deny all; }
	location ~ /\.(?!well-known) { deny all; }
	location ~* /wp-content/uploads/.*\.php\$ { deny all; }

	location ~* \.(?:css|js|webp|jpg|jpeg|png|gif|svg|woff2?|mp4)\$ {
		expires 30d;
		add_header Cache-Control "public";
		add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
		access_log off;
	}
}
NGX
sudo nginx -t
sudo systemctl reload nginx

echo "==> Deploy site code"
"$ROOT/repo/deploy/deploy.sh" "$ROOT" "$CACHE_DIR"
wp theme activate pella-nazarene --path="$WP"
wp redis enable --path="$WP" 2>/dev/null || echo "NOTE: Redis object cache not enabled yet; run 'wp redis enable' after checking the plugin."

echo "==> WP-Cron (system cron instead of site traffic)"
echo "*/5 * * * * www-data cd $WP && /usr/local/bin/wp cron event run --due-now --quiet" | sudo tee /etc/cron.d/pella-staging >/dev/null
sudo chmod 644 /etc/cron.d/pella-staging

echo
echo "Staging is up: https://$DOMAIN"
echo "Basic-auth login:  sudo cat $SECRETS/staging-basic-auth.txt"
echo "WP admin password: sudo cat $SECRETS/staging-wp-admin.pw   (user: $ADMIN_USER, at /wp-admin)"
