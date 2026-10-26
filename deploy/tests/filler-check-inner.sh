#!/usr/bin/env bash
# shellcheck disable=SC2015,SC2016  # test helpers: "a && ok || bad" is intended
# Runs INSIDE the wordpress:cli container (see filler-check.sh). Throwaway.
set -uo pipefail
BASE=http://127.0.0.1:8080
W=/tmp/wp; mkdir -p "$W"; cd "$W" || exit 1
export WP_CLI_CACHE_DIR=/tmp/wpcache
wp() { php -d memory_limit=512M /usr/local/bin/wp "$@"; }
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ok    $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
expect_eq()       { [[ "$2" == "$3" ]] && ok "$1" || bad "$1 (got '$2', wanted '$3')"; }
expect_contains() { grep -qF -- "$3" <<<"$2" && ok "$1" || bad "$1 (missing: $3)"; }
setup_fail() { echo "SETUP FAILED: $1"; exit 1; }

echo "== Setup: real WordPress (with its sample post, page and comment)"
wp core download --quiet >/dev/null || setup_fail "core download"
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=pn-filler-db --skip-check --quiet
for _ in $(seq 1 45); do mysql -h pn-filler-db -uroot -proot -e 'select 1' wp >/dev/null 2>&1 && break; sleep 2; done
wp core install --url="$BASE" --title="Pella Nazarene" --admin_user=boss --admin_password='Admin-pass-1!' --admin_email=boss@example.test --skip-email --quiet || setup_fail "core install"
wp rewrite structure '/%postname%/' --hard --quiet

# Curly quotes, built from their bytes (so this file stays plain ASCII).
RSQ="$(printf '\342\200\231')"; LSQ="$(printf '\342\200\230')"; LDQ="$(printf '\342\200\234')"; RDQ="$(printf '\342\200\235')"

# A copy of the scanner, so BREAK can damage it without touching the repo.
S=/tmp/s; mkdir -p "$S"; cp -R /repo/scripts "$S"/; mkdir -p "$S/deploy"; cp /repo/deploy/filler.txt "$S/deploy/"
break_it() {  # file, exact old text, new text
	php -r '[$f, $a, $b] = array_slice($argv, 1); $t = file_get_contents($f); if (substr_count($t, $a) !== 1) { exit(1); } file_put_contents($f, str_replace($a, $b, $t));' "$@" || setup_fail "break '$BREAK' did not apply"
}
case "${BREAK:-}" in
	"") ;;
	skip-titles)  break_it $S/scripts/check-filler.php 'foreach ( $pn_posts as $pn_post ) {' 'foreach ( array() as $pn_post ) {' ;;
	skip-options) break_it $S/scripts/check-filler.php 'foreach ( $pn_tables as $pn_table ) {' 'foreach ( $pn_tables as $pn_table ) { if ( $pn_table === $wpdb->options ) { continue; }' ;;
	no-quotes)    break_it $S/scripts/check-filler.php "array( '$RSQ', '$LSQ', '$LDQ', '$RDQ' )" "array( 'ZZ1', 'ZZ2', 'ZZ3', 'ZZ4' )" ;;
	skip-pages)   break_it $S/scripts/check-filler.sh 'hit="$(grep -oiF -m1 -- "$word" <<<"$body" || true)"' 'hit=""' ;;
	skip-code)    break_it $S/scripts/check-filler.sh 'grep -nioF -f "$code_words" -- "$REPO/${file#./}"' 'true' ;;
	*) setup_fail "unknown BREAK" ;;
esac
scan() { bash "$S/scripts/check-filler.sh" "$W" "$@" 2>&1; }
RC=0
run() { RC=0; OUT="$(scan "$@")" || RC=$?; }

echo "== 1. WordPress's own sample content is leftover filler"
run
expect_eq "a fresh WordPress is not clean" "$RC" "1"
expect_contains "the sample post is found" "$OUT" '"Hello world!" in wp_posts.post_title'
expect_contains "the sample page is found" "$OUT" '"Sample Page" in wp_posts.post_title'
expect_contains "the draft privacy policy is found" "$OUT" '"Privacy Policy" in wp_posts.post_title'
wp post delete 1 2 3 --force --quiet >/dev/null 2>&1
run
expect_eq "after deleting them the site is clean" "$RC" "0"
expect_contains "it says so" "$OUT" "Clean"
expect_contains "it reports how much it looked through" "$OUT" "text columns in"

echo "== 2. Real content is not a false alarm"
real="$(wp post create --post_type=page --post_title="Giving" --post_status=publish --post_content="<p>Pella Nazarene is involved in world missions. Our Year in Review is on the way, with Men and Women together. Worship night is a good idea. Central time.</p>" --porcelain)"
wp post create --post_title="World Missions Update" --post_status=publish --post_content="Hello" --quiet
run
expect_eq "similar words and longer titles are fine" "$RC" "0"

plant() {  # name, expected text in the report, command that plants it (prints nothing), command that removes it
	local name="$1" expect="$2" plant_cmd="$3" remove_cmd="$4"
	eval "$plant_cmd" >/dev/null 2>&1
	run
	[[ "$RC" == 1 ]] && ok "$name: the scan fails" || bad "$name: the scan should fail (exit $RC)"
	expect_contains "$name: it says where" "$OUT" "$expect"
	eval "$remove_cmd" >/dev/null 2>&1
	run
	[[ "$RC" == 0 ]] && ok "$name: clean again once removed" || bad "$name: still failing after removal: $OUT"
}

echo "== 3. Leftovers planted in many places are each found"
plant "page text"       '"Lorem ipsum" in wp_posts.post_content' 'P=$(wp post create --post_title="About" --post_status=publish --post_content="<p>Lorem ipsum dolor sit.</p>" --porcelain)' 'wp post delete $P --force'
plant "a summary"       '"Omega Church" in wp_posts.post_excerpt' 'P=$(wp post create --post_title="Notes" --post_status=publish --post_excerpt="From Omega Church" --porcelain)' 'wp post delete $P --force'
plant "a draft"         '"Brunson" in wp_posts.post_content' 'P=$(wp post create --post_title="Group" --post_status=draft --post_content="Paul and Olivia Brunson lead it" --porcelain)' 'wp post delete $P --force'
plant "the trash"       '"Gloria Jackson" in wp_posts.post_content' 'P=$(wp post create --post_title="Old" --post_status=trash --post_content="Will and Gloria Jackson" --porcelain)' 'wp post delete $P --force'
plant "an old typo"     '"Wedensday" in wp_posts.post_content' 'P=$(wp post create --post_title="Prayer" --post_status=publish --post_content="Every Wedensday at 7" --porcelain)' 'wp post delete $P --force'
plant "a leftover title" '"Faith over Fear" in wp_posts.post_title' 'P=$(wp post create --post_title="Faith over Fear" --post_status=publish --post_content="A fine post" --porcelain)' 'wp post delete $P --force'
plant "a title with curly quotes" "\"Men${RSQ}s Group\" in wp_posts.post_title" 'P=$(wp post create --post_title="Men${RSQ}s Group" --post_status=publish --post_content="Meets" --porcelain)' 'wp post delete $P --force'
plant "a trashed filler page" '"Movie Night" in wp_posts.post_title' 'P=$(wp post create --post_type=page --post_title="Movie Night" --post_status=trash --porcelain)' 'wp post delete $P --force'
plant "a menu item"     '"Kids Event" in wp_posts.post_title' 'wp menu create "Main" >/dev/null; wp menu item add-custom main "Kids Event" "/kids-event/"' 'wp menu delete main'
plant "a custom field"  '"Brunson" in wp_postmeta.meta_value' 'P=$(wp post create --post_title="Staff" --post_status=publish --porcelain); wp post meta add $P leaders "Paul & Olivia Brunson"' 'wp post delete $P --force'
plant "a site setting"  '"Ministry Designs" in wp_options.option_value' 'wp option update blogdescription "Powered by Ministry Designs"' 'wp option update blogdescription "Just another WordPress site"'
plant "a comment"       '"Gloria Jackson" in wp_comments.comment_content' 'C=$(wp comment create --comment_post_ID='"$real"' --comment_content="Gloria Jackson was here" --porcelain)' 'wp comment delete $C --force'
plant "a profile"       '"Lorem ipsum" in wp_usermeta.meta_value' 'wp user meta update 1 description "Lorem ipsum"' 'wp user meta delete 1 description'
plant "a category"      '"Lorem ipsum" in wp_term_taxonomy.description' 'T=$(wp term create category "Real" --description="Lorem ipsum" --porcelain)' 'wp term delete category $T'
plant "a category name" "\"Men's Group\" in wp_terms.name" 'T=$(wp term create category "Men'"'"'s Group" --porcelain)' 'wp term delete category $T'
plant "an edited footer/header (template part)" '"Eastern Time" in wp_posts.post_content' 'P=$(wp post create --post_type=wp_template_part --post_name=service-info --post_title="Service info" --post_status=publish --post_content="<p>Office hours 8:30-5 Eastern Time</p>" --porcelain)' 'wp post delete $P --force'
plant "the old uploads folder" '"mt-content" in wp_posts.post_content' 'P=$(wp post create --post_title="Photo" --post_status=publish --post_content="<img src=\"https://pellanazarene.org/mt-content/uploads/2021/01/kids-1.jpg\">" --porcelain)' 'wp post delete $P --force'

echo "== 4. What a visitor sees: every published page"
mkdir -p wp-content/mu-plugins
( wp server --host=127.0.0.1 --port=8080 >/tmp/server.log 2>&1 & )
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/" && break; sleep 1; done
run --url "$BASE"
expect_eq "a clean site passes the page check too" "$RC" "0"
expect_contains "it says how many pages it looked at" "$OUT" "looked at"
echo '<?php add_action( "wp_footer", function () { echo "<p>Open Mon-Fri 8:30-5 Eastern Time</p>"; } );' > wp-content/mu-plugins/leak.php
run --url "$BASE"
expect_eq "filler that is in the code, not the database, fails the page check" "$RC" "1"
expect_contains "it names the words (capitals aside: this container's grep prints lower case)" "$(tr '[:upper:]' '[:lower:]' <<<"$OUT")" '"eastern time" on'
expect_contains "and the page" "$OUT" "$BASE/"
rm -f wp-content/mu-plugins/leak.php
run --url "$BASE"
expect_eq "clean again once the leak is gone" "$RC" "0"
expect_contains "a wrong password is reported, not ignored" "$(bash "$S/scripts/check-filler.sh" "$W" --url http://127.0.0.1:9 2>&1 || true)" "could not read"

echo "== 5. Failed URL enumeration and HTTP errors fail the page check"
mkdir -p /tmp/fail-wp
cat >/tmp/fail-wp/wp <<'EOF'
#!/usr/bin/env bash
case " $* " in
	*" post list "*) exit 1 ;;
esac
exec php -d memory_limit=512M /usr/local/bin/wp "$@"
EOF
chmod +x /tmp/fail-wp/wp
RC=0
OUT="$(PATH="/tmp/fail-wp:$PATH" bash "$S/scripts/check-filler.sh" "$W" --url "$BASE" 2>&1)" || RC=$?
expect_eq "failed WP-CLI URL enumeration aborts" "$RC" "2"
expect_contains "enumeration error is reported" "$OUT" "Could not enumerate published URLs"
echo '<?php add_action( "template_redirect", function () { status_header( 401 ); echo "Unauthorized"; exit; } );' > wp-content/mu-plugins/leak.php
run --url "$BASE"
expect_eq "an HTTP error fails the page check" "$RC" "1"
expect_contains "HTTP error is reported, not scanned" "$OUT" "could not read"
rm -f wp-content/mu-plugins/leak.php

echo "== 6. The code"
run --repo /repo
expect_eq "this repository's code is clean" "$RC" "0"
expect_contains "it says so" "$OUT" "nothing in the code"
mkdir -p /tmp/rc && cp -R /repo/wp-content /repo/scripts /repo/deploy /tmp/rc/ 2>/dev/null
echo "<p>Lorem ipsum dolor</p>" >> /tmp/rc/wp-content/themes/pella-nazarene/templates/404.html
run --repo /tmp/rc
expect_eq "filler planted in a theme file fails" "$RC" "1"
expect_contains "it names the file" "$OUT" "wp-content/themes/pella-nazarene/templates/404.html"
expect_eq "it did not flag the scanner's own word list" "$(grep -c 'deploy/filler.txt:' <<<"$OUT" | tr -d ' ')" "0"
RC=0
OUT="$(bash "$S/scripts/check-filler.sh" "$W" --repo /tmp/not-a-repository 2>&1)" || RC=$?
expect_eq "a missing repository fails the code check" "$RC" "2"
expect_contains "repository enumeration error is reported" "$OUT" "Could not enumerate code files"
rm -rf /tmp/rc/deploy/forms
RC=0
OUT="$(bash "$S/scripts/check-filler.sh" "$W" --repo /tmp/rc 2>&1)" || RC=$?
expect_eq "a missing scan directory fails the code check" "$RC" "2"
expect_contains "missing directory error is reported" "$OUT" "Could not enumerate code files"
mkdir -p /tmp/fail-grep
cat >/tmp/fail-grep/grep <<'EOF'
#!/usr/bin/env bash
if [[ "${1:-}" == "-nioF" ]]; then exit 2; fi
exec /usr/bin/grep "$@"
EOF
chmod +x /tmp/fail-grep/grep
RC=0
OUT="$(PATH="/tmp/fail-grep:$PATH" bash "$S/scripts/check-filler.sh" "$W" --repo /repo 2>&1)" || RC=$?
expect_eq "a file-scan error fails the code check" "$RC" "2"
expect_contains "file-scan error is reported" "$OUT" "Could not scan code file"

echo
echo "Passed: $PASS   Failed: $FAIL"
[[ $FAIL -eq 0 ]]
