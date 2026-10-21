#!/usr/bin/env bash
# First-time setup of a fresh Ubuntu 24.04 Linode. Run as root, once, over SSH.
# Safe to re-run.
#
# Usage:
#   bootstrap-server.sh DEPLOY_USER            # install, harden, create deploy user
#   bootstrap-server.sh DEPLOY_USER --lock-ssh # ALSO disable root login and passwords
#
# Do the first run WITHOUT --lock-ssh. Open a second terminal and confirm
# `ssh DEPLOY_USER@server` works and `sudo -n true` succeeds (not `sudo -v`, which always asks for a password). Only then re-run with
# --lock-ssh, or you can lock yourself out (the Linode LISH console is the way back in).
set -euo pipefail

DEPLOY_USER="${1:?Usage: $0 DEPLOY_USER [--lock-ssh]}"
LOCK_SSH="${2:-}"

[[ $EUID -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
# shellcheck source=/dev/null
[[ "$(. /etc/os-release && echo "$VERSION_ID")" == "24.04" ]] || { echo "Expected Ubuntu 24.04." >&2; exit 1; }

export DEBIAN_FRONTEND=noninteractive

echo "==> Packages"
apt-get update -qq
apt-get upgrade -y -qq
apt-get install -y -qq \
	nginx mariadb-server redis-server unattended-upgrades ufw fail2ban curl git rsync unzip \
	php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-gd php8.3-imagick php8.3-intl \
	php8.3-mbstring php8.3-xml php8.3-zip php8.3-redis

echo "==> Timezone and automatic security updates"
timedatectl set-timezone America/Chicago
dpkg-reconfigure -f noninteractive unattended-upgrades

echo "==> WP-CLI"
if ! command -v wp >/dev/null; then
	tmp="$(mktemp -d)"
	curl -fsSL -o "$tmp/wp" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	curl -fsSL -o "$tmp/wp.sha512" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar.sha512
	echo "$(tr -d '[:space:]' < "$tmp/wp.sha512")  $tmp/wp" | sha512sum -c -
	install -m 0755 "$tmp/wp" /usr/local/bin/wp
	rm -rf "$tmp"
fi

echo "==> Deploy user: $DEPLOY_USER"
if ! id "$DEPLOY_USER" &>/dev/null; then
	adduser --disabled-password --gecos "" "$DEPLOY_USER"
fi
usermod -aG sudo,www-data "$DEPLOY_USER"
# Copy root's key only the first time; never overwrite a key that is already set up.
if [[ -f /root/.ssh/authorized_keys && ! -s "/home/$DEPLOY_USER/.ssh/authorized_keys" ]]; then
	install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
	install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /root/.ssh/authorized_keys "/home/$DEPLOY_USER/.ssh/authorized_keys"
fi
if [[ -s "/home/$DEPLOY_USER/.ssh/authorized_keys" ]]; then
	ssh-keygen -lf "/home/$DEPLOY_USER/.ssh/authorized_keys" >/dev/null \
		|| { echo "ERROR: $DEPLOY_USER's authorized_keys is not a valid public key file." >&2; exit 1; }
fi
# The account has no password, so sudo must not ask for one.
echo "$DEPLOY_USER ALL=(ALL) NOPASSWD:ALL" > "/etc/sudoers.d/90-$DEPLOY_USER"
chmod 440 "/etc/sudoers.d/90-$DEPLOY_USER"
visudo -cf "/etc/sudoers.d/90-$DEPLOY_USER" >/dev/null

echo "==> Host firewall (in addition to the Linode Cloud Firewall)"
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

echo "==> fail2ban (SSH)"
cat > /etc/fail2ban/jail.d/sshd.local <<'EOF'
[sshd]
enabled  = true
maxretry = 5
findtime = 10m
bantime  = 1h
EOF
systemctl enable --now fail2ban
systemctl restart fail2ban

echo "==> Services bound to localhost only"
grep -Eq '^bind 127\.0\.0\.1' /etc/redis/redis.conf || echo "WARNING: check 'bind' in /etc/redis/redis.conf"
ss -ltn | awk '$4 ~ /:(3306|6379)$/ && $4 !~ /^(127\.0\.0\.1|\[::1\])/ {print "WARNING: " $4 " is listening publicly"}'

systemctl enable --now nginx php8.3-fpm mariadb redis-server

if [[ "$LOCK_SSH" == "--lock-ssh" ]]; then
	echo "==> Locking SSH (no root login, no passwords)"
	[[ -s "/home/$DEPLOY_USER/.ssh/authorized_keys" ]] || { echo "No key for $DEPLOY_USER; refusing to lock SSH." >&2; exit 1; }
	# sshd uses the FIRST value it reads and reads sshd_config.d in name order, so this must sort
	# before Ubuntu cloud images' 50-cloud-init.conf (which sets PasswordAuthentication yes).
	rm -f /etc/ssh/sshd_config.d/99-hardening.conf
	cat > /etc/ssh/sshd_config.d/00-hardening.conf <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
EOF
	sshd -t
	systemctl reload ssh
	# Prove it: check what sshd will actually enforce, not just what we wrote.
	effective="$(sshd -T)"
	for expect in "permitrootlogin no" "passwordauthentication no" "kbdinteractiveauthentication no"; do
		grep -qi "^$expect\$" <<<"$effective" || { echo "ERROR: sshd is not enforcing '$expect'. Check the files in /etc/ssh/sshd_config.d/." >&2; exit 1; }
	done
	echo "Verified: root login and password logins are off."
else
	echo "==> SSH not locked yet. Test the deploy user, then re-run with --lock-ssh."
fi

echo "Done. Reboot if the kernel was upgraded: [[ -f /var/run/reboot-required ]] && reboot"
