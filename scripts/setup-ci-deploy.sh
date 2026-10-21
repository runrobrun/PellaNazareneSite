#!/usr/bin/env bash
# One-time (re-runnable) server setup for deploys from GitHub Actions.
# Run as the deploy user from the staging repo checkout:
#   scripts/setup-ci-deploy.sh "ssh-ed25519 AAAA... pella-ci-deploy"
# The argument is the PUBLIC half of a key made just for CI. Its private half goes
# into the GitHub `staging` environment secrets and nowhere else.
#
# Installs the wrapper (deploy/ci-deploy.sh) root-owned and appends the CI key to
# ~deploy/.ssh/authorized_keys, locked to that wrapper. Append only: existing keys
# are never changed, so this cannot lock you out.
set -euo pipefail

PUBKEY="${1:?Usage: $0 \"ssh-ed25519 AAAA... comment\"}"
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
sudo install -d -o "$(id -un)" -g "$(id -gn)" -m 750 /var/backups/pn-staging-db

echo "==> Authorizing the CI key (locked to the wrapper)"
mkdir -p "$HOME/.ssh" && chmod 700 "$HOME/.ssh"
touch "$AUTH" && chmod 600 "$AUTH"
if grep -qF "$BLOB" "$AUTH"; then
	echo "Key already present; leaving authorized_keys unchanged."
else
	cp -a "$AUTH" "$AUTH.bak-$(date +%Y%m%d-%H%M%S)"
	printf 'restrict,command="/usr/local/bin/pn-ci-deploy" %s\n' "$PUBKEY" >> "$AUTH"
fi

echo
echo "Done. Existing login keys are untouched (backup beside authorized_keys)."
echo "Host key fingerprint to pin in the GitHub secret (compare with what your computer sees):"
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
