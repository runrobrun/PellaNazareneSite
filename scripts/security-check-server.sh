#!/usr/bin/env bash
# ok, warn and fail always return 0, so "test && ok || fail" below is a safe if-then-else.
# shellcheck disable=SC2015
# Read-only security check of the server and one site (SEC-4). Changes nothing; safe to re-run.
# Run as the administrative deploy user:
#   scripts/security-check-server.sh staging      (or production, once it exists)
# Prints "ok", "WARN" or "FAIL" per check and a summary; paste the output into the SEC-4 notes.
set -euo pipefail

[[ $# -eq 1 && ( "$1" == "staging" || "$1" == "production" ) ]] || { echo "Usage: $0 staging|production" >&2; exit 2; }
SITE="$1"
if [[ "$SITE" == "staging" ]]; then ROOT=/var/www/staging; else ROOT=/var/www/pellanazarene; fi
WP="$ROOT/public"
PHP_USER="pn-$SITE-php"
DEPLOY_USER="pn-$SITE-deploy"
sudo -n true 2>/dev/null || { echo "Needs passwordless sudo (run as deploy)." >&2; exit 1; }

okc=0; warnc=0; failc=0
ok()   { echo "  ok    $*"; okc=$((okc + 1)); }
warn() { echo "  WARN  $*"; warnc=$((warnc + 1)); }
fail() { echo "  FAIL  $*"; failc=$((failc + 1)); }
pn_wp() { sudo -u "$PHP_USER" env WP_CLI_CACHE_DIR="/tmp/wp-cli-$PHP_USER" wp --path="$WP" "$@" 2>/dev/null; }

echo "== Firewall and open ports"
if sudo ufw status | grep -q '^Status: active'; then ok "ufw is active"; else fail "ufw is not active"; fi
extra="$(sudo ufw status | awk '/ALLOW/ {print $1}' | sed 's#/.*##' | sort -u | grep -vxE '22|80|443|OpenSSH|Nginx Full|Nginx HTTP|Nginx HTTPS' || true)"
[[ -z "$extra" ]] && ok "ufw allows only 22, 80 and 443" || fail "ufw also allows: $(tr '\n' ' ' <<<"$extra")"
public="$(sudo ss -tlnH | awk '{print $4}' | grep -vE '^(127\.|\[::1\]|::1)' | sed 's/.*://' | sort -un | grep -vxE '22|80|443' || true)"
[[ -z "$public" ]] && ok "nothing else listens on a public address" || fail "also listening publicly on port(s): $(tr '\n' ' ' <<<"$public")"

echo "== SSH"
sshd="$(sudo sshd -T 2>/dev/null)"
grep -qx 'passwordauthentication no' <<<"$sshd" && ok "password logins off" || fail "SSH password logins are allowed (run bootstrap-server.sh deploy --lock-ssh)"
grep -qxE 'permitrootlogin (no|prohibit-password)' <<<"$sshd" && ok "root cannot log in with a password ($(grep '^permitrootlogin' <<<"$sshd"))" || fail "SSH root login with a password is allowed"
grep -qx 'kbdinteractiveauthentication no' <<<"$sshd" && ok "keyboard-interactive logins off" || warn "keyboard-interactive logins are on"
if systemctl is-active --quiet fail2ban; then ok "fail2ban running"; else warn "fail2ban is not running"; fi

echo "== Operating system updates"
if systemctl is-enabled --quiet unattended-upgrades 2>/dev/null; then ok "unattended security upgrades enabled"; else fail "unattended-upgrades is not enabled"; fi
pending="$(apt-get -s upgrade 2>/dev/null | grep -c '^Inst .*security' || true)"
[[ "$pending" == 0 ]] && ok "no security updates waiting" || warn "$pending security update(s) waiting (they install overnight)"
[[ -f /var/run/reboot-required ]] && warn "a reboot is needed to finish updates" || ok "no reboot needed"

echo "== WordPress ($SITE)"
[[ -f "$WP/wp-config.php" ]] || { fail "no WordPress at $WP"; echo; echo "Summary: $okc ok, $warnc warn, $failc FAIL"; exit 1; }
v="$(pn_wp core version)"; ok "WordPress $v"
if pn_wp core verify-checksums >/dev/null; then ok "core files match WordPress.org"; else fail "core files differ from WordPress.org (wp core verify-checksums)"; fi
bad_plugins="$(pn_wp plugin verify-checksums --all --format=csv 2>/dev/null | tail -n +2 | cut -d, -f1 | sort -u | grep -v '^pn-sermons$' || true)"
[[ -z "$bad_plugins" ]] && ok "plugin files match WordPress.org" || warn "plugin files differ or can't be checked: $(tr '\n' ' ' <<<"$bad_plugins")"
while IFS=, read -r name status version update; do
	if [[ "$update" == "available" ]]; then warn "plugin $name $version has an update (review, then pin it in plugins.txt)"; else ok "plugin $name $version ($status)"; fi
done < <(pn_wp plugin list --fields=name,status,version,update --format=csv | tail -n +2)
for c in DISALLOW_FILE_EDIT FORCE_SSL_ADMIN; do
	[[ "$(pn_wp config get "$c" 2>/dev/null)" == "1" ]] && ok "$c is on" || fail "$c is not on in wp-config.php"
done
[[ "$(pn_wp config get WP_DEBUG 2>/dev/null || echo)" =~ ^(|0)$ ]] && ok "WP_DEBUG is off" || fail "WP_DEBUG is on"
[[ -e "$WP/wp-content/debug.log" ]] && warn "wp-content/debug.log exists (Nginx blocks it; delete it if not needed)" || ok "no debug.log"
mode="$(sudo stat -c '%a %U:%G' "$WP/wp-config.php")"
[[ "$mode" =~ ^(640|600|440|400)\  ]] && ok "wp-config.php is $mode" || fail "wp-config.php is $mode (should be 640, not world-readable)"
if sudo -u "$PHP_USER" test -w "$WP/wp-includes/version.php"; then fail "the PHP user can write core files"; else ok "the PHP user cannot write core files"; fi
phpups="$(sudo find "$WP/wp-content/uploads" -type f -iname '*.php*' 2>/dev/null | head -5)"
[[ -z "$phpups" ]] && ok "no PHP files in uploads" || fail "PHP files in uploads: $(tr '\n' ' ' <<<"$phpups")"
admins="$(pn_wp user list --role=administrator --field=user_login | tr '\n' ' ')"
echo "  info  administrators: ${admins:-none}"
pn_wp user list --fields=user_login,roles --format=csv | tail -n +2 | sed 's/^/  info  user /'
if systemctl is-active --quiet "pn-core-update@$SITE.timer"; then ok "automatic core security updates on"; else fail "pn-core-update@$SITE.timer is not active (scripts/setup-core-updates.sh)"; fi

echo "== Secrets"
sudo test -d /root/pella-secrets && [[ "$(sudo stat -c '%a %U' /root/pella-secrets)" == "700 root" ]] && ok "/root/pella-secrets is 700 root" || fail "/root/pella-secrets is missing or not 700 root"
[[ "$(sudo stat -c '%U' "$WP/wp-config.php")" == "$DEPLOY_USER" ]] && ok "wp-config.php is owned by $DEPLOY_USER" || warn "wp-config.php owner is $(sudo stat -c '%U' "$WP/wp-config.php")"

echo
echo "Summary: $okc ok, $warnc warn, $failc FAIL"
[[ $failc -eq 0 ]]
