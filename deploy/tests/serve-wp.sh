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
mkdir -p wp-content/plugins; cp -R /repo/wp-content/plugins/pn-sermons wp-content/plugins/
wp plugin activate pn-sermons --quiet
# The forms: the pinned Fluent Forms, our form code, and a stand-in that records emails instead of sending them.
mkdir -p wp-content/mu-plugins; cp /repo/wp-content/mu-plugins/pn-forms.php /repo/wp-content/mu-plugins/pn-analytics.php /repo/deploy/tests/fixtures/pn-test-mail-capture.php wp-content/mu-plugins/
# This site counts as 'production' (the default), so the analytics tag is on: give it a made-up ID so a test visit can never be recorded in the church's real Google Analytics.
wp config set PN_GA_MEASUREMENT_ID G-TEST000000 --type=constant --quiet
wp plugin install fluentform --version="$(awk '$1 == "fluentform" {print $2}' /repo/plugins.txt)" --activate --quiet
PN_CONTACT_TO=hello@example.test PN_PRAYER_TO=pastor@example.test bash /repo/scripts/create-forms.sh "$W" >/dev/null
wp rewrite structure '/%postname%/' --hard --quiet
# The brand fixture page uses every brand element; it lives at /brand-check/. The real pages come from the theme's patterns.
wp post create --post_type=page --post_title="Brand check" --post_name=brand-check --post_status=publish --post_content="$(cat /repo/deploy/tests/fixtures/brand-check-page.html)" --quiet
bash /repo/scripts/create-pages.sh "$W" >/dev/null
# BREAK_BLOCK=1 (a test of the test) puts markup the editor rejects on the Kids page.
[[ -z "${BREAK_BLOCK:-}" ]] || wp post update "$(wp post list --post_type=page --name=kids --field=ID)" --post_content='<!-- wp:separator --><div class="wp-block-separator">not a separator</div><!-- /wp:separator -->' --quiet
wp post create --post_type=post --post_status=publish --post_title='Welcome to our new website' --post_content='<!-- wp:paragraph --><p>We are glad you are here. Come and see what is happening at Pella Nazarene.</p><!-- /wp:paragraph -->' --post_author=1 --quiet
wp post create --post_type=pn_sermon --post_status=publish --post_title="Pentecost Promised, Proclaimed, and Practiced" --post_date="2026-05-24 12:00:00" --meta_input='{"pn_youtube_id":"Nll1UzWZHQA"}' --quiet
touch /tmp/ready
exec php -d memory_limit=512M /usr/local/bin/wp server --host=0.0.0.0 --port=8080
