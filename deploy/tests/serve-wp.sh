#!/usr/bin/env bash
# Runs INSIDE a wordpress:cli container (see a11y-check.sh): real WordPress + Twenty Twenty-Five + our theme,
# the "Brand check" fixture page as the home page, served on port 8080 for a browser container to visit.
# Writes /tmp/ready when the site is up. Throwaway.
set -euo pipefail
W=/tmp/wp; mkdir -p "$W"; cd "$W" || exit 1
wp() { php -d memory_limit=512M /usr/local/bin/wp "$@"; }
wp core download --quiet >/dev/null
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost="${DB_HOST:-pn-test-db}" --skip-check --quiet
for _ in $(seq 1 45); do mysql -h "${DB_HOST:-pn-test-db}" -uroot -proot -e 'select 1' wp >/dev/null 2>&1 && break; sleep 2; done
wp core install --url="http://${SITE_HOST:-pn-wp}:8080" --title="Pella Nazarene" --admin_user=boss --admin_password='x-Pass-1!' --admin_email=b@e.test --skip-email --quiet
wp theme install twentytwentyfive --quiet
cp -R /repo/wp-content/themes/pella-nazarene wp-content/themes/
wp theme activate pella-nazarene --quiet
wp rewrite structure '/%postname%/' --hard --quiet
wp post create --post_type=page --post_title="Brand check" --post_name=brand-check --post_status=publish --post_content="$(cat /repo/deploy/tests/fixtures/brand-check-page.html)" --quiet
wp option update show_on_front page --quiet
wp option update page_on_front "$(wp post list --post_type=page --name=brand-check --field=ID)" --quiet
touch /tmp/ready
exec php -d memory_limit=512M /usr/local/bin/wp server --host=0.0.0.0 --port=8080
