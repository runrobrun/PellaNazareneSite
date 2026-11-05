#!/usr/bin/env bash
# One-time (re-runnable) server setup for deploys from GitHub Actions.
# Run as the deploy user from that site's repo checkout:
#   scripts/setup-ci-deploy.sh staging    "ssh-ed25519 AAAA... pella-ci-deploy"
#   scripts/setup-ci-deploy.sh production "ssh-ed25519 AAAA... pella-ci-deploy-production"
# (With only the key, the site is staging, as before.) The key is the PUBLIC half of a key made
# just for CI and just for that site. Its private half goes into the matching GitHub environment's
# secrets and nowhere else. Use a different key for each site: the staging key deploys without
# approval, so it must not be able to ask for a production deploy.
#
# Installs the wrapper and the site's initial known-good deploy runner root-owned (the wrapper
# refreshes the runner itself after every healthy deploy), then appends the CI key
# to ~deploy/.ssh/authorized_keys, locked to the wrapper and to that one site. Append only:
# existing keys are never changed, so this cannot lock you out.
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
AUTH="$HOME/.ssh/authorized_keys"

[[ $EUID -ne 0 ]] || { echo "Run as the deploy user, not root." >&2; exit 1; }
sudo -n true || { echo "deploy needs passwordless sudo." >&2; exit 1; }

# Validate the key and make sure it is a single plain public key line.
if [[ "$PUBKEY" == *$'\n'* || ! "$PUBKEY" =~ ^ssh-ed25519\ [A-Za-z0-9+/=]+(\ [^\"]*)?$ ]]; then
	echo "Expected one line: ssh-ed25519 <key> [comment]" >&2
	exit 1
fi
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
printf '%s\n' "$PUBKEY" > "$tmp"
ssh-keygen -lf "$tmp" >/dev/null || { echo "That is not a valid public key." >&2; exit 1; }
BLOB="$(awk '{print $2}' "$tmp")"

echo "==> Installing wrapper"
sudo install -m 755 -o root -g root "$REPO/deploy/ci-deploy.sh" /usr/local/bin/pn-ci-deploy
sudo install -d -o root -g root -m 755 /usr/local/libexec
sudo install -m 755 -o root -g root "$REPO/deploy/deploy.sh" "/usr/local/libexec/pn-$TARGET-deploy"
sudo install -d -o "$(id -un)" -g "$(id -gn)" -m 750 "/var/backups/pn-$TARGET-db"

echo "==> Authorizing the CI key (locked to the wrapper and to $TARGET)"
mkdir -p "$HOME/.ssh" && chmod 700 "$HOME/.ssh"
touch "$AUTH" && chmod 600 "$AUTH"
# The original staging key line has no site after the wrapper; it means staging.
if [[ "$TARGET" == "staging" ]]; then
	WANT='command="/usr/local/bin/pn-ci-deploy( staging)?"'
else
	WANT='command="/usr/local/bin/pn-ci-deploy production"'
fi
if grep -F "$BLOB" "$AUTH" | grep -Eq "(^|,)$WANT "; then
	echo "Key already present for $TARGET; leaving authorized_keys unchanged."
elif grep -qF "$BLOB" "$AUTH"; then
	echo "This key is already in authorized_keys for something else. Make a separate key for $TARGET." >&2
	exit 1
else
	cp -a "$AUTH" "$AUTH.bak-$(date +%Y%m%d-%H%M%S)"
	printf '\nrestrict,command="/usr/local/bin/pn-ci-deploy %s" %s\n' "$TARGET" "$PUBKEY" >> "$AUTH"
fi

echo
echo "Done. Existing login keys are untouched (backup beside authorized_keys)."
echo "Host key fingerprint to pin in the GitHub secret (compare with what your computer sees):"
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
