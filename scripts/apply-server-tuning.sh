#!/usr/bin/env bash
# Install the Nginx and PHP-FPM tuning from deploy/ onto this server.
# Run as the deploy user, from a checkout of this repo. Safe to re-run.
# Every file it replaces is backed up first; if the Nginx or PHP-FPM config test
# fails, everything is rolled back and the services are left as they were.
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
STAMP="$(date +%F-%H%M%S)"
BACKUP="/var/backups/pn-tuning-$STAMP"
POOL_DIR="/etc/php/8.3/fpm/pool.d"

[[ $EUID -ne 0 ]] || { echo "Run as the deploy user, not root." >&2; exit 1; }
sudo -n true || { echo "deploy needs passwordless sudo." >&2; exit 1; }

# source (relative to repo) -> destination
FILES=(
	"deploy/nginx/pn-performance.conf:/etc/nginx/conf.d/pn-performance.conf"
	"deploy/nginx/pn-security-headers.conf:/etc/nginx/snippets/pn-security-headers.conf"
	"deploy/nginx/pn-fastcgi-cache.conf:/etc/nginx/snippets/pn-fastcgi-cache.conf"
	"deploy/php/pn-pool.conf:$POOL_DIR/pn.conf"
	"deploy/php/pn-php.ini:/etc/php/8.3/fpm/conf.d/99-pn.ini"
)

sudo install -d -m 700 "$BACKUP"
echo "==> Backup directory: $BACKUP"

rollback() {
	echo "!! Rolling back" >&2
	for pair in "${FILES[@]}"; do
		dest="${pair#*:}"
		if sudo test -f "$BACKUP$dest"; then
			sudo cp -a "$BACKUP$dest" "$dest"
		else
			sudo rm -f "$dest"
		fi
	done
	if sudo test -f "$BACKUP/www.conf.orig"; then
		sudo mv "$BACKUP/www.conf.orig" "$POOL_DIR/www.conf"
	fi
	sudo systemctl restart php8.3-fpm || true
	sudo systemctl reload nginx || true
}

echo "==> Installing config files"
for pair in "${FILES[@]}"; do
	src="$REPO/${pair%%:*}"
	dest="${pair#*:}"
	[[ -f "$src" ]] || { echo "Missing $src" >&2; exit 1; }
	if sudo test -f "$dest"; then
		sudo install -d "$BACKUP$(dirname "$dest")"
		sudo cp -a "$dest" "$BACKUP$dest"
	fi
	sudo install -m 644 "$src" "$dest"
done

# The packaged pool is also named [www]; two pools with one name is an error.
if sudo test -f "$POOL_DIR/www.conf"; then
	sudo mv "$POOL_DIR/www.conf" "$BACKUP/www.conf.orig"
fi

echo "==> Testing config"
if ! sudo php-fpm8.3 -t || ! sudo nginx -t; then
	rollback
	exit 1
fi

echo "==> Restarting PHP-FPM, reloading Nginx"
if ! sudo systemctl restart php8.3-fpm || ! sudo systemctl is-active --quiet php8.3-fpm; then
	rollback
	exit 1
fi
sudo systemctl reload nginx

echo "==> Swap file (safety net against out-of-memory kills)"
if [[ -z "$(swapon --show --noheadings)" ]]; then
	sudo fallocate -l 2G /swapfile
	sudo chmod 600 /swapfile
	sudo mkswap /swapfile >/dev/null
	sudo swapon /swapfile
	grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab >/dev/null
	echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-pn-swap.conf >/dev/null
	sudo sysctl --system >/dev/null
else
	echo "Swap already configured; leaving it alone."
fi

echo "Done. Previous config saved in $BACKUP"
