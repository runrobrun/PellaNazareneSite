#!/usr/bin/env bash
# shellcheck disable=SC2015  # test helpers: "a && ok || bad" is intended
# Runs INSIDE an ubuntu:24.04 container (see nginx-config.sh): the same Nginx version as the server,
# loaded with the repo's real staging config, then hit with real requests. Throwaway.
set -uo pipefail
export DEBIAN_FRONTEND=noninteractive
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ok    $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
expect_eq() { [[ "$2" == "$3" ]] && ok "$1" || bad "$1 (got '$2', wanted '$3')"; }

apt-get update -qq >/dev/null && apt-get install -y -qq nginx openssl curl >/dev/null 2>&1 || { echo "SETUP FAILED: apt"; exit 1; }
echo "== Nginx $(nginx -v 2>&1 | sed 's/.*nginx\///')"

D=staging.example.org; ROOT=/var/www/t/public
mkdir -p "$ROOT/wp-content/uploads" /var/cache/nginx/t "/etc/letsencrypt/live/$D"
echo '<?php echo "page";' > "$ROOT/index.php"
echo body > "$ROOT/style.css"; echo x > "$ROOT/readme.html"; echo x > "$ROOT/license.txt"
echo '<?php // secrets' > "$ROOT/wp-config.php"; echo '<?php echo "pwned";' > "$ROOT/wp-content/uploads/evil.php"
mkdir -p "$ROOT/.git"; echo x > "$ROOT/.git/config"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$D" \
  -keyout "/etc/letsencrypt/live/$D/privkey.pem" -out "/etc/letsencrypt/live/$D/fullchain.pem" >/dev/null 2>&1
printf 'church:%s\n' "$(openssl passwd -apr1 secret)" > /etc/nginx/htpasswd-staging

cp /repo/deploy/nginx/pn-performance.conf /repo/deploy/nginx/pn-security.conf /etc/nginx/conf.d/
echo "fastcgi_cache_path /var/cache/nginx/t levels=1:2 keys_zone=PELLA_STG:8m inactive=60m max_size=64m;" > /etc/nginx/conf.d/pn-staging-cache.conf
cp /repo/deploy/nginx/pn-security-headers.conf /repo/deploy/nginx/pn-fastcgi-cache.conf /etc/nginx/snippets/
rm -f /etc/nginx/sites-enabled/default
sed -e "s|@DOMAIN@|$D|g" -e "s|@WEBROOT@|$ROOT|g" /repo/deploy/nginx/staging.conf.template > /etc/nginx/sites-enabled/staging.conf

nginx -t >/tmp/nginx-t.txt 2>&1 && ok "config passes nginx -t" || { bad "config fails nginx -t"; cat /tmp/nginx-t.txt; exit 1; }
nginx
sleep 1
req() { curl -sk -o /dev/null -w '%{http_code}' --resolve "$D:443:127.0.0.1" -u church:secret "https://$D$1"; }

echo "== Access"
expect_eq "no password: 401" "$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$D:443:127.0.0.1" "https://$D/style.css")" "401"
expect_eq "http redirects to https" "$(curl -s -o /dev/null -w '%{http_code}' --resolve "$D:80:127.0.0.1" "http://$D/x")" "301"
expect_eq "static file served" "$(req /style.css)" "200"

echo "== Files that must never be served (the PHP handler must not win)"
expect_eq "wp-config.php"                 "$(req /wp-config.php)" "403"
expect_eq "readme.html (version leak)"    "$(req /readme.html)" "403"
expect_eq "license.txt"                   "$(req /license.txt)" "403"
expect_eq "PHP file in uploads"           "$(req /wp-content/uploads/evil.php)" "403"
expect_eq "PHP file in uploads, odd case" "$(req /wp-content/uploads/evil.PHP)" "403"
expect_eq "xmlrpc.php"                    "$(req /xmlrpc.php)" "403"
expect_eq ".git directory"                "$(req /.git/config)" "403"

echo "== Headers"
hdrs="$(curl -sk -D - -o /dev/null --resolve "$D:443:127.0.0.1" -u church:secret "https://$D/style.css" | tr -d '\r')"
for h in "x-content-type-options: nosniff" "x-frame-options: SAMEORIGIN" "referrer-policy:" "permissions-policy:" "x-robots-tag: noindex"; do
  grep -qiF "$h" <<<"$hdrs" && ok "header present: $h" || bad "header missing: $h"
done
grep -qiE '^server: nginx$' <<<"$hdrs" && ok "Nginx version hidden" || bad "Server header shows a version: $(grep -i '^server' <<<"$hdrs")"

echo "== Login throttle: /wp-login.php limited per IP, everything else untouched"
limited=0; allowed=0
for _ in $(seq 1 25); do c="$(req /wp-login.php)"; [[ "$c" == "429" ]] && limited=$((limited+1)) || allowed=$((allowed+1)); done
echo "       25 quick requests to /wp-login.php: $allowed allowed, $limited throttled"
[[ $allowed -ge 1 && $allowed -le 8 ]] && ok "only a short burst is allowed" || bad "burst size wrong: $allowed allowed"
[[ $limited -ge 15 ]] && ok "the rest get HTTP 429" || bad "not throttled enough: $limited throttled"
other=0; for _ in $(seq 1 25); do [[ "$(req /index.php)" == "429" ]] && other=$((other+1)); done
expect_eq "other PHP pages are never throttled" "$other" "0"

echo; echo "Passed: $PASS   Failed: $FAIL"; [[ $FAIL == 0 ]]
