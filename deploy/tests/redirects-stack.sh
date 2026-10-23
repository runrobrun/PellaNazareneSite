#!/usr/bin/env bash
# shellcheck disable=SC2015  # test helpers: "a && ok || FAIL" is intended
# Runs INSIDE an ubuntu:24.04 container (see redirects-check.sh): the same stack as the server (Nginx 1.24, PHP 8.3
# FPM with our pool and ini, WordPress, the sermon plugin, our mu-plugin and theme) with fixture content, then runs
# scripts/check-redirects.sh against it twice: through Nginx (what visitors get) and through PHP alone (the safety
# net used in local development). Throwaway.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
D=staging.example.org; SITE=/var/www/site
step() { echo "== $*"; }

step "Installing Nginx, PHP, tools"
apt-get update -qq >/dev/null
apt-get install -y -qq nginx php8.3-fpm php8.3-cli php8.3-mysql php8.3-xml php8.3-mbstring php8.3-curl php8.3-gd php8.3-zip php8.3-intl mariadb-client curl unzip openssl ca-certificates >/dev/null 2>&1 \
	|| { echo "SETUP FAILED: apt"; exit 1; }
tmp="$(mktemp -d)"
curl -fsSL -o "$tmp/wp" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
curl -fsSL -o "$tmp/wp.sha512" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar.sha512
echo "$(tr -d '[:space:]' < "$tmp/wp.sha512")  $tmp/wp" | sha512sum -c - >/dev/null || { echo "SETUP FAILED: WP-CLI checksum"; exit 1; }
install -m 0755 "$tmp/wp" /usr/local/bin/wp
wp() { php -d memory_limit=512M /usr/local/bin/wp --allow-root --path="$SITE" "$@"; }

step "Installing WordPress with fixture content"
mkdir -p "$SITE"
wp core download --quiet >/dev/null
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=db --skip-check --quiet
for _ in $(seq 1 60); do mysql -h db -uroot -proot -e 'select 1' wp >/dev/null 2>&1 && break; sleep 2; done
wp core install --url="https://$D" --title="Pella Nazarene" --admin_user=boss --admin_password='x-Pass-1!' --admin_email=b@e.test --skip-email --quiet
wp config set WP_ENVIRONMENT_TYPE staging --quiet
wp theme install twentytwentyfive --quiet
cp -R /repo/wp-content/themes/pella-nazarene "$SITE/wp-content/themes/"
cp -R /repo/wp-content/plugins/pn-sermons "$SITE/wp-content/plugins/"
mkdir -p "$SITE/wp-content/mu-plugins" && cp /repo/wp-content/mu-plugins/pn-site.php "$SITE/wp-content/mu-plugins/"
wp theme activate pella-nazarene --quiet
wp plugin activate pn-sermons --quiet
wp rewrite structure '/%postname%/' --hard --quiet
# Fixtures: what the migrated site will contain
sermon() { wp post create --post_type=pn_sermon --post_status="${3:-publish}" --post_title="$1" --post_name="$2" --porcelain; }
sermon "Pentecost Promised, Proclaimed, and Practiced" pentecost-promised-proclaimed-and-practiced >/dev/null
sermon "Vision Sunday #1" vision-sunday-1-living-out-our-mission >/dev/null
sermon "Vision Sunday #2" vision-sunday-2-living-out-our-mission >/dev/null
id="$(sermon "God's Messenger" gods-messenger-malachi-3-1-4)";  wp post meta add "$id" pn_legacy_slug gods-messenger-malachi-31-4 >/dev/null
id="$(sermon "The New Title" the-new-title)";                    wp post meta add "$id" pn_legacy_slug old-title-with-a-different-slug >/dev/null
sermon "A draft nobody may see" draft-sermon draft >/dev/null
for n in "Todd Olson" "Cindy Olson" "Rob Hammann" "Floyd Ware"; do wp term create pn_speaker "$n" --slug="$(tr 'A-Z ' 'a-z-' <<<"$n")" >/dev/null; done
wp term create pn_series "Holiness in the House: A Study in Titus" --slug=holiness-in-the-house-a-study-in-titus >/dev/null
wp rewrite flush --hard --quiet
chown -R www-data:www-data "$SITE"

step "Configuring PHP-FPM and Nginx from the repo"
mkdir -p /run/php /var/cache/nginx/t "/etc/letsencrypt/live/$D"
rm -f /etc/php/8.3/fpm/pool.d/www.conf
cp /repo/deploy/php/pn-pool.conf /etc/php/8.3/fpm/pool.d/pn.conf
cp /repo/deploy/php/pn-php.ini /etc/php/8.3/fpm/conf.d/99-pn.ini
php-fpm8.3 -t >/dev/null 2>&1 || { echo "SETUP FAILED: php-fpm config"; php-fpm8.3 -t; exit 1; }
php-fpm8.3 -D
chown www-data:www-data /var/cache/nginx/t
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$D" -keyout "/etc/letsencrypt/live/$D/privkey.pem" -out "/etc/letsencrypt/live/$D/fullchain.pem" >/dev/null 2>&1
printf 'church:%s\n' "$(openssl passwd -apr1 secret)" > /etc/nginx/htpasswd-staging
cp /repo/deploy/nginx/pn-performance.conf /repo/deploy/nginx/pn-security.conf /repo/deploy/nginx/pn-redirects.conf /etc/nginx/conf.d/
echo "fastcgi_cache_path /var/cache/nginx/t levels=1:2 keys_zone=PELLA_STG:8m inactive=60m max_size=64m;" > /etc/nginx/conf.d/pn-staging-cache.conf
cp /repo/deploy/nginx/pn-security-headers.conf /repo/deploy/nginx/pn-fastcgi-cache.conf /repo/deploy/nginx/pn-redirects-server.conf /etc/nginx/snippets/
rm -f /etc/nginx/sites-enabled/default
sed -e "s|@DOMAIN@|$D|g" -e "s|@WEBROOT@|$SITE|g" /repo/deploy/nginx/staging.conf.template > /etc/nginx/sites-enabled/staging.conf
nginx -t >/dev/null 2>&1 || { echo "SETUP FAILED: nginx config"; nginx -t; exit 1; }
nginx
sleep 1
code="$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$D:443:127.0.0.1" -u church:secret "https://$D/sermons/")"
[[ "$code" == 200 ]] || { echo "SETUP FAILED: the new /sermons/ list should answer 200 (got $code)"; exit 1; }
echo "   stack is up: /sermons/ answers 200 through Nginx and PHP-FPM"

status=0
step "A. Through Nginx + PHP-FPM (what visitors get): strict, no fallbacks allowed"
/repo/scripts/check-redirects.sh "https://$D" --user church:secret --insecure --resolve "$D:443:127.0.0.1" \
	--table /repo/deploy/redirects/expected.csv --table /repo/deploy/tests/redirects-extra.csv || status=1

step "A2. With PHP STOPPED: every row Nginx is supposed to answer on its own must still work"
pkill -f php-fpm || true; sleep 1
# Prove PHP is really down: no process, and a page that must come from PHP fails. (A query string skips Nginx's
# page cache; without it a cached page would still be served and prove nothing.)
if pgrep -f php-fpm >/dev/null; then echo "  FAIL  PHP-FPM is still running"; status=1; else echo "  (confirmed: no PHP-FPM process is running)"; fi
probe="$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$D:443:127.0.0.1" -u church:secret "https://$D/sermons/?probe=$RANDOM")"
[[ "$probe" == 502 ]] && echo "  (confirmed: a page that needs PHP now returns 502)" || { echo "  FAIL  with PHP stopped a PHP page returned $probe, not 502"; status=1; }
/repo/scripts/check-redirects.sh "https://$D" --user church:secret --insecure --resolve "$D:443:127.0.0.1" --layer nginx \
	--table /repo/deploy/redirects/expected.csv --table /repo/deploy/tests/redirects-extra.csv || status=1
php-fpm8.3 -D; sleep 1

step "B. The 410 page a visitor would see"
body="$(curl -sk --resolve "$D:443:127.0.0.1" -u church:secret "https://$D/sermons-t/u_topic/holiness/")"
grep -qi "has been removed" <<<"$body" && echo "  ok    410 page explains the page was removed" || { echo "  FAIL  410 page text missing"; status=1; }
grep -q 'href="/"' <<<"$body" && echo "  ok    ...and links home" || { echo "  FAIL  410 page has no link home"; status=1; }

step "C. Through PHP alone, no Nginx (the safety net for local development)"
( wp server --host=127.0.0.1 --port=8081 >/tmp/php-server.log 2>&1 & )
for _ in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8081/ && break; sleep 1; done
wp option update home http://127.0.0.1:8081 --quiet; wp option update siteurl http://127.0.0.1:8081 --quiet
/repo/scripts/check-redirects.sh http://127.0.0.1:8081 \
	--table /repo/deploy/redirects/expected.csv --table /repo/deploy/tests/redirects-extra.csv || status=1

[[ $status == 0 ]] && echo "ALL REDIRECT CHECKS PASSED" || echo "REDIRECT CHECKS FAILED"
exit $status
