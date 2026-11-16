#!/usr/bin/env bash
# One-time (re-runnable) setup of automatic WordPress core security updates.
# Run as the administrative deploy user from a repo checkout, after scripts/setup-ci-deploy.sh:
#   scripts/setup-core-updates.sh staging
#   scripts/setup-core-updates.sh production
#
# Installs /usr/local/sbin/pn-core-update (deploy/core-update.sh) and a daily systemd timer for
# the site. WordPress cannot update itself since SEC-5 (its PHP user cannot write its files), so
# this does it as the site's deploy user. Only maintenance and security releases (7.1.x), never a
# new major version; those stay a deliberate, tested change.
set -euo pipefail

[[ $# -eq 1 && ( "$1" == "staging" || "$1" == "production" ) ]] || { echo "Usage: $0 staging|production" >&2; exit 2; }
TARGET="$1"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

[[ $EUID -ne 0 ]] || { echo "Run as the administrative deploy user, not root." >&2; exit 1; }
sudo -n true || { echo "deploy needs passwordless sudo." >&2; exit 1; }
id "pn-$TARGET-deploy" &>/dev/null || { echo "Run scripts/setup-ci-deploy.sh $TARGET first." >&2; exit 1; }

echo "==> Installing the updater and its timer"
sudo install -m 755 -o root -g root "$REPO/deploy/core-update.sh" /usr/local/sbin/pn-core-update
sudo install -m 644 -o root -g root "$REPO/deploy/systemd/pn-core-update@.service" /etc/systemd/system/
sudo install -m 644 -o root -g root "$REPO/deploy/systemd/pn-core-update@.timer" /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now "pn-core-update@$TARGET.timer"

echo "==> Checking now"
if sudo systemctl start "pn-core-update@$TARGET.service"; then
	sudo journalctl -u "pn-core-update@$TARGET.service" -n 5 --no-pager -o cat
else
	sudo journalctl -u "pn-core-update@$TARGET.service" -n 20 --no-pager -o cat
	echo "The first check failed; see above." >&2
	exit 1
fi
systemctl list-timers "pn-core-update@$TARGET.timer" --no-pager
