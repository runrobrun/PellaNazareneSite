#!/usr/bin/env bash
# One-time (re-runnable) server setup for deploys from GitHub Actions.
# Run as the administrative deploy user from that site's repo checkout:
#   scripts/setup-ci-deploy.sh staging    "ssh-ed25519 AAAA... pella-ci-deploy"
#   scripts/setup-ci-deploy.sh production "ssh-ed25519 AAAA... pella-ci-deploy-production"
# (With only the key, the site is staging, as before.) Use a different key for each site.
#
# Creates a separate, unprivileged account for each site's CI key. Its only sudo commands
# are the root-owned wrapper for that site and its own cache clearer. The wrapper runs
# repository code and WP-CLI as this account, never as root.
set -euo pipefail

if [[ $# -eq 1 ]]; then
	TARGET="staging"
	PUBKEY="$1"
elif [[ $# -eq 2 ]]; then
	TARGET="$1"
	PUBKEY="$2"
else
	echo "Usage: $0 [staging|production] \"ssh-ed25519 AAAA... comment\"" >&2
	exit 1
fi
if [[ "$TARGET" != "staging" && "$TARGET" != "production" ]]; then
	echo "The site must be staging or production, not: $TARGET" >&2
	exit 1
fi
REPO="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="/var/www/$([[ "$TARGET" == "production" ]] && echo pellanazarene || echo staging)"
CI_USER="pn-$TARGET-deploy"
PHP_USER="pn-$TARGET-php"
ADMIN_USER="$(id -un)"

[[ $EUID -ne 0 ]] || { echo "Run as the administrative deploy user, not root." >&2; exit 1; }
sudo -n true || { echo "deploy needs passwordless sudo." >&2; exit 1; }

# Validate the key and make sure it is a single plain public key line.
if [[ "$PUBKEY" == *$'\n'* || ! "$PUBKEY" =~ ^ssh-ed25519\ [A-Za-z0-9+/=]+(\ [^\"]*)?$ ]]; then
	echo "Expected one line: ssh-ed25519 <key> [comment]" >&2
	exit 1
fi
tmp="$(mktemp)"
trap 'rm -f "$tmp" "$tmp.sudoers" "$tmp.ssh-config" "$tmp.old-authorized-keys"' EXIT
printf '%s\n' "$PUBKEY" > "$tmp"
ssh-keygen -lf "$tmp" >/dev/null || { echo "That is not a valid public key." >&2; exit 1; }
BLOB="$(awk '{print $2}' "$tmp")"
OTHER_TARGET="$([[ "$TARGET" == "production" ]] && echo staging || echo production)"
OTHER_USER="pn-$OTHER_TARGET-deploy"
if id "$OTHER_USER" &>/dev/null; then
	OTHER_HOME="$(getent passwd "$OTHER_USER" | cut -d: -f6)"
	if [[ -f "$OTHER_HOME/.ssh/authorized_keys" ]] && sudo grep -qF "$BLOB" "$OTHER_HOME/.ssh/authorized_keys"; then
		echo "Use a different CI key for $TARGET; this key is already assigned to $OTHER_TARGET." >&2
		exit 1
	fi
fi

echo "==> Creating the site's unprivileged CI account"
if ! id "$CI_USER" &>/dev/null; then
	sudo useradd --create-home --user-group --shell /bin/bash "$CI_USER"
fi
getent group "$CI_USER" >/dev/null || sudo groupadd "$CI_USER"
sudo usermod -g "$CI_USER" -G "$CI_USER" "$CI_USER"
sudo passwd --lock "$CI_USER" >/dev/null
for php_user in pn-staging-php pn-production-php; do
	if ! id "$php_user" &>/dev/null; then
		sudo useradd --system --user-group --no-create-home --shell /usr/sbin/nologin "$php_user"
	fi
	getent group "$php_user" >/dev/null || sudo groupadd --system "$php_user"
	sudo usermod -g "$php_user" -G "$php_user" "$php_user"
done
CI_HOME="$(getent passwd "$CI_USER" | cut -d: -f6)"
CI_AUTH="$CI_HOME/.ssh/authorized_keys"
sudo install -d -o "$CI_USER" -g "$CI_USER" -m 700 "$CI_HOME/.ssh"
sudo touch "$CI_AUTH"
sudo chown "$CI_USER:$CI_USER" "$CI_AUTH"
sudo chmod 600 "$CI_AUTH"
if [[ ! -s "$HOME/.ssh/github_deploy" ]]; then
	echo "Missing the read-only GitHub deploy key at $HOME/.ssh/github_deploy." >&2
	exit 1
fi
sudo install -m 600 -o "$CI_USER" -g "$CI_USER" "$HOME/.ssh/github_deploy" "$CI_HOME/.ssh/github_deploy"
cat > "$tmp.ssh-config" <<EOF
Host github.com
    IdentityFile $CI_HOME/.ssh/github_deploy
    IdentitiesOnly yes
    StrictHostKeyChecking accept-new
EOF
sudo install -m 600 -o "$CI_USER" -g "$CI_USER" "$tmp.ssh-config" "$CI_HOME/.ssh/config"

echo "==> Installing the root-owned deployment tools"
sudo install -m 755 -o root -g root "$REPO/deploy/ci-deploy.sh" /usr/local/bin/pn-ci-deploy
sudo install -m 755 -o root -g root "$REPO/deploy/clear-cache.sh" /usr/local/sbin/pn-clear-cache
sudo install -d -o root -g root -m 755 /usr/local/libexec
sudo install -m 755 -o root -g root "$REPO/deploy/deploy.sh" "/usr/local/libexec/pn-$TARGET-deploy"
sudo install -d -o root -g root -m 700 "/var/backups/pn-$TARGET-db"

# Site code is writable only by its own CI identity; PHP-FPM can still read it.
if [[ -d "$ROOT/repo" && -d "$ROOT/public" ]]; then
	sudo chown -R "$CI_USER:$PHP_USER" "$ROOT/repo" "$ROOT/public"
	sudo chmod -R go-w "$ROOT/repo" "$ROOT/public"
	if [[ -f "$ROOT/public/wp-config.php" ]]; then
		sudo chown "$CI_USER:$PHP_USER" "$ROOT/public/wp-config.php"
		sudo chmod 640 "$ROOT/public/wp-config.php"
		sudo setfacl -m "u:$ADMIN_USER:r--" "$ROOT/public/wp-config.php"
	fi
	if [[ -d "$ROOT/public/wp-content/uploads" ]]; then
		sudo chown -R www-data:www-data "$ROOT/public/wp-content/uploads"
		sudo find "$ROOT/public/wp-content/uploads" -type d \
			-exec setfacl -m "u:$PHP_USER:rwx,d:u:$PHP_USER:rwx" {} +
		sudo find "$ROOT/public/wp-content/uploads" -type f \
			-exec setfacl -m "u:$PHP_USER:rw-" {} +
	fi
fi
sudo touch "$ROOT/.deploy.lock"
sudo chown "$CI_USER:$CI_USER" "$ROOT/.deploy.lock"
sudo chmod 600 "$ROOT/.deploy.lock"

echo "==> Restricting sudo to this site's deployment"
cat > "$tmp.sudoers" <<EOF
Defaults:$CI_USER env_keep += "SSH_ORIGINAL_COMMAND"
$CI_USER ALL=(root) NOPASSWD: /usr/local/bin/pn-ci-deploy $TARGET, /usr/local/sbin/pn-clear-cache $TARGET
EOF
sudo install -m 440 -o root -g root "$tmp.sudoers" "/etc/sudoers.d/90-$CI_USER"
sudo visudo -cf "/etc/sudoers.d/90-$CI_USER" >/dev/null

echo "==> Authorizing the CI key (locked to the wrapper and to $TARGET)"
if sudo grep -F "$BLOB" "$CI_AUTH" | grep -Fq "command=\"sudo -n /usr/local/bin/pn-ci-deploy $TARGET\""; then
	echo "Key already present for $TARGET; leaving authorized_keys unchanged."
elif sudo grep -qF "$BLOB" "$CI_AUTH"; then
	echo "This key is already in the CI account for something else. Make a separate key for $TARGET." >&2
	exit 1
else
	sudo cp -a "$CI_AUTH" "$CI_AUTH.bak-$(date +%Y%m%d-%H%M%S)"
	printf '\nrestrict,command="sudo -n /usr/local/bin/pn-ci-deploy %s" %s\n' "$TARGET" "$PUBKEY" \
		| sudo tee -a "$CI_AUTH" >/dev/null
	sudo chown "$CI_USER:$CI_USER" "$CI_AUTH"
fi

# Move the supplied key out of the administrative account if it was previously used there.
ADMIN_AUTH="$HOME/.ssh/authorized_keys"
if [[ -f "$ADMIN_AUTH" ]] && grep -qF "$BLOB" "$ADMIN_AUTH"; then
	cp -a "$ADMIN_AUTH" "$ADMIN_AUTH.bak-$(date +%Y%m%d-%H%M%S)"
	grep -Fv "$BLOB" "$ADMIN_AUTH" > "$tmp.old-authorized-keys"
	cat "$tmp.old-authorized-keys" > "$ADMIN_AUTH"
fi

echo
echo "Done. CI key installed for $CI_USER; its only sudo commands are site-bound."
echo "Host key fingerprint to pin in the GitHub secret (compare with what your computer sees):"
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
