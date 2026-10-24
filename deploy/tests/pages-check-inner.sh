#!/usr/bin/env bash
# shellcheck disable=SC2015,SC2016  # test helpers: "a && ok || bad" is intended; PHP for `wp eval` is deliberately single-quoted
# Runs INSIDE the wordpress:cli container (see pages-check.sh). Throwaway.
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
expect_lacks()    { grep -qF -- "$3" <<<"$2" && bad "$1 (should not contain: $3)" || ok "$1"; }
setup_fail() { echo "SETUP FAILED: $1"; exit 1; }
pages() { wp post list --post_type=page --post_status=any --format=count; }
S=/tmp/scripts; cp -R /repo/scripts "$S"   # a copy, so BREAK can damage it
create() { bash "$S/create-pages.sh" "$W" "$@" 2>&1; }

echo "== Setup: real WordPress, Twenty Twenty-Five, our theme, the sermon plugin"
wp core download --quiet >/dev/null || setup_fail "core download"
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=pn-pages-db --skip-check --quiet
for _ in $(seq 1 45); do mysql -h pn-pages-db -uroot -proot -e 'select 1' wp >/dev/null 2>&1 && break; sleep 2; done
wp core install --url="$BASE" --title="Pella Nazarene" --admin_user=boss --admin_password='Admin-pass-1!' --admin_email=boss@example.test --skip-email --quiet || setup_fail "core install"
wp theme install twentytwentyfive --quiet || setup_fail "installing Twenty Twenty-Five"
cp -R /repo/wp-content/themes/pella-nazarene wp-content/themes/
mkdir -p wp-content/plugins; cp -R /repo/wp-content/plugins/pn-sermons wp-content/plugins/
wp theme activate pella-nazarene --quiet || setup_fail "activating our theme"
wp plugin activate pn-sermons --quiet || setup_fail "activating the sermon plugin"
wp rewrite structure '/%postname%/' --hard --quiet
wp post delete 1 2 3 --force --quiet >/dev/null 2>&1   # WordPress's sample post, sample page, and privacy-policy draft

# BREAK=<name> damages the copy under test; the checks must then fail.
T=wp-content/themes/pella-nazarene
break_it() {  # file, exact old text, new text
	php -r '[$f, $a, $b] = array_slice($argv, 1); $t = file_get_contents($f); if (substr_count($t, $a) !== 1) { exit(1); } file_put_contents($f, str_replace($a, $b, $t));' "$@" || setup_fail "break '$BREAK' did not apply"
}
case "${BREAK:-}" in
	"") ;;
	hash-link)   break_it $T/patterns/page-new-here.php 'href="#plan-your-visit">Plan a Visit' 'href="#">Plan a Visit' ;;
	eastern)     break_it $T/patterns/page-contact.php '<strong>Address:</strong>' '<strong>Address (Eastern Time):</strong>' ;;
	lorem)       break_it $T/patterns/page-giving.php 'Thank you for your support!' 'Lorem ipsum dolor sit amet.' ;;
	dead-link)   break_it $T/patterns/page-home.php 'href="/kids/">Pella Nazarene Kids' 'href="/kidz/">Pella Nazarene Kids' ;;
	bad-anchor)  break_it $T/patterns/page-home.php '/new-here/#sunday-questions' '/new-here/#sunday-qustions' ;;
	old-phone)   break_it $T/patterns/page-contact.php 'tel:+16416281818' 'tel:641628-1818' ;;
	two-h1)      break_it $T/patterns/page-about-us.php '<!-- wp:heading -->
<h2 class="wp-block-heading">Our mission</h2>' '<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Our mission</h1>' ;;
	lost-link)   break_it $T/patterns/page-resources.php '<li><a href="https://nazarene.org/the-cause/">The Cause</a></li>' '' ;;
	iframe)      break_it $T/patterns/page-new-here.php '[pn_sermon_video id="EK0MuwDegjI"]' '<iframe src="https://www.youtube.com/embed/EK0MuwDegjI"></iframe>' ;;
	hardcolor)   break_it $T/patterns/page-kids.php '<p class="is-style-subheading">Your kids' '<p class="is-style-subheading" style="color:#ff0000">Your kids' ;;
	no-header)   break_it $T/templates/archive-pn_sermon.html '{"slug":"header","tagName":"header"}' '{"slug":"header","theme":"twentytwentyfive"}' ;;
	no-alt)      break_it $T/assets/images/photos/manifest.json '"alt": "Todd Olson, co-pastor"' '"alt": ""' ;;
	missing-photo) break_it $T/assets/images/photos/manifest.json '"slug": "home-hero"' '"slug": "missing-photo"' ;;
	no-photos)   break_it $T/functions.php "require_once get_theme_file_path( 'inc/photos.php' );" "" ;;
	eager-photos) break_it $T/inc/photos.php 'return is_front_page() ? 1 : 0;' 'return 3;' ;;
	no-preload)  break_it $T/inc/photos.php "\$id     = pn_front_page_hero_id();" "\$id     = 0;" ;;
	big-sizes)   break_it $T/inc/photos.php "'col'    => '(max-width: 781px) 100vw, 340px'," "'col'    => ''," ;;
	plain-links) break_it $S/create-pages.php "'/%postname%/' );
	WP_CLI::log( 'Permalinks were" "'' );
	WP_CLI::log( 'Permalinks were" ;;
	same-words)  break_it $T/patterns/page-home.php '>Upcoming events</a>' '>Give online</a>' ;;
	*) setup_fail "unknown BREAK" ;;
esac

if [[ "${BREAK:-}" == missing-photo ]]; then
	echo "== An incomplete photo import does not create or replace pages"
	wp post create --post_type=page --post_status=publish --post_name=home --post_title="Existing Home" --post_content="Staff edit" --quiet
	out="$(create --replace home 2>&1 || true)"
	expect_contains "the missing photo slug is reported" "$out" "Could not load manifest photos: missing-photo"
	expect_eq "no pages are created after an incomplete import" "$(pages)" "1"
	expect_eq "an existing page is not replaced" "$(wp post list --post_type=page --name=home --field=post_content)" "Staff edit"
	echo
	echo "Passed: $PASS   Failed: $FAIL"
	[[ $FAIL -eq 0 ]]
	exit
fi

echo "== 1. First run creates the seven pages"
wp rewrite structure "" --hard --quiet   # a new server starts on Plain permalinks
out="$(create)"
expect_contains "it fixes Plain permalinks" "$out" "set them to /%postname%/"
expect_eq "pretty permalinks are on" "$(wp option get permalink_structure)" "/%postname%/"
rules="$(wp rewrite list --format=csv)"
expect_contains "sermon archive rewrite rule exists" "$rules" "index.php?post_type=pn_sermon"
expect_contains "single sermon rewrite rule exists" "$rules" 'index.php?pn_sermon=$matches[1]'
expect_contains "sermon series rewrite rule exists" "$rules" 'index.php?pn_series=$matches[1]'
expect_contains "sermon speaker rewrite rule exists" "$rules" 'index.php?pn_speaker=$matches[1]'
expect_contains "sermon scripture rewrite rule exists" "$rules" 'index.php?pn_scripture=$matches[1]'
for s in home new-here about-us kids giving contact resources; do expect_contains "created $s" "$out" "Created '$s'"; done
expect_eq "seven pages exist" "$(pages)" "7"
expect_eq "Home is the front page" "$(wp option get show_on_front) $(wp post get "$(wp option get page_on_front)" --field=post_name)" "page home"
expect_eq "all are published" "$(wp post list --post_type=page --post_status=publish --format=count)" "7"

expect_eq "all 12 photos are in the media library" "$(wp post list --post_type=attachment --format=count)" "12"
expect_eq "each photo has its alt text from the manifest" "$(wp post meta get "$(wp post list --post_type=attachment --meta_key=_pn_photo --meta_value=staff-floyd-ware --field=ID)" _wp_attachment_image_alt)" "Floyd Ware, associate pastor"

echo "== 2. Running it again changes nothing"
before="$(wp post list --post_type=page --format=csv --fields=ID,post_modified | md5sum)"
out="$(create)"
expect_eq "no new pages" "$(pages)" "7"
expect_contains "says it kept them" "$out" "Kept 'about-us'"
expect_lacks "creates nothing the second time" "$out" "Created"
expect_eq "no photo is added twice" "$(wp post list --post_type=attachment --format=count)" "12"
expect_eq "no page was modified" "$(wp post list --post_type=page --format=csv --fields=ID,post_modified | md5sum)" "$before"
echo "   (staff edit a page)"
id="$(wp post list --post_type=page --name=kids --field=ID)"
wp post update "$id" --post_content="<!-- wp:paragraph --><p>Edited by staff</p><!-- /wp:paragraph -->" --quiet
create >/dev/null
expect_contains "a staff edit survives a re-run" "$(wp post get "$id" --field=post_content)" "Edited by staff"
out="$(create --replace kids)"
expect_contains "--replace resets that page" "$out" "Replaced the text of 'kids'"
expect_contains "...to the pattern text" "$(wp post get "$id" --field=post_content)" "birth through age 12"
out="$(create --replace nonsense)"
expect_contains "an unknown page name is refused" "$out" "Unknown page 'nonsense'"
res_id="$(wp post list --post_type=page --name=resources --field=ID)"
wp post update "$res_id" --post_status=trash --quiet
out="$(create)"
expect_contains "a trashed page is not recreated" "$out" "'resources' is in the trash"
expect_eq "still no duplicate (6 live + 1 in the trash)" "$(wp post list --post_type=page --post_status=publish --format=count) $(wp post list --post_type=page --post_status=trash --format=count)" "6 1"
wp post update "$res_id" --post_status=publish --quiet
echo "   (staff choose a different front page first)"
wp option update show_on_front posts --quiet; wp option update page_on_front 0 --quiet
create >/dev/null
expect_eq "front page is set when none was chosen" "$(wp option get show_on_front)" "page"
wp option update page_on_front "$(wp post list --post_type=page --name=about-us --field=ID)" --quiet
create >/dev/null
expect_eq "a front page staff chose is not overridden" "$(wp post get "$(wp option get page_on_front)" --field=post_name)" "about-us"
wp option update page_on_front "$(wp post list --post_type=page --name=home --field=ID)" --quiet
wp option update show_on_front posts --quiet; wp option update page_on_front 0 --quiet
home_id="$(wp post list --post_type=page --name=home --field=ID)"
wp post update "$home_id" --post_status=draft --quiet
create >/dev/null
expect_eq "an unpublished Home leaves the current front-page setting alone" "$(wp option get show_on_front) $(wp option get page_on_front)" "posts 0"
wp post update "$home_id" --post_status=publish --quiet
create >/dev/null
expect_eq "a published Home can become the front page" "$(wp option get show_on_front) $(wp post get "$(wp option get page_on_front)" --field=post_name)" "page home"

echo "   (media slug conflicts with a page)"
kids_id="$(wp post list --post_type=page --name=kids --field=ID)"
wp post delete "$kids_id" --force --quiet
media_id="$(wp post create --post_type=attachment --post_status=inherit --post_title="Media collision" --post_name=kids --post_content="Keep this description." --porcelain)"
out="$(create --replace kids 2>&1 || true)"
expect_contains "an attachment slug conflict is reported" "$out" "conflicts with an attachment"
expect_eq "the attachment description is preserved" "$(wp post get "$media_id" --field=post_content)" "Keep this description."
wp post delete "$media_id" --force --quiet
out="$(create)"
expect_contains "Kids is created after the attachment is removed" "$out" "Created 'kids'"

echo "== 3. Block markup is clean"
bad_blocks="$(wp eval '
$bad = array();
foreach (get_pages(array("post_status" => "publish")) as $p) {
	$walk = function ($blocks) use (&$walk, &$bad, $p) {
		foreach ($blocks as $b) {
			if (null === $b["blockName"]) { if (trim($b["innerHTML"]) !== "") { $bad[] = $p->post_name . ": loose HTML outside blocks"; } continue; }
			if (!WP_Block_Type_Registry::get_instance()->is_registered($b["blockName"])) { $bad[] = $p->post_name . ": unknown block " . $b["blockName"]; }
			$walk($b["innerBlocks"]);
		}
	};
	$walk(parse_blocks($p->post_content));
}
echo implode("; ", $bad);')"
expect_eq "every block is a real WordPress block with nothing loose between them" "$bad_blocks" ""

echo "== 4. Home shows the latest published sermon, never a draft"
wp post create --post_type=pn_sermon --post_status=publish --post_title="Latest published sermon" --post_date="2026-05-24 12:00:00" --meta_input='{"pn_youtube_id":"Nll1UzWZHQA"}' --quiet
wp post create --post_type=pn_sermon --post_status=draft --post_title="A draft sermon" --post_date="2026-06-07 12:00:00" --meta_input='{"pn_youtube_id":"vTKuOza2c68"}' --quiet
( wp server --host=127.0.0.1 --port=8080 >/tmp/server.log 2>&1 & )
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/" && break; sleep 1; done

echo "== 5. The pages as visitors see them"
wp option update page_on_front "$(wp post list --post_type=page --name=about-us --field=ID)" --quiet
alternate_front="$(curl -s "$BASE/")"
expect_contains "a staff-selected front page shows its page title" "$alternate_front" '<h1 class="wp-block-post-title">About Us</h1>'
expect_lacks "a front page without a hero does not preload an image" "$alternate_front" 'rel="preload" as="image"'
wp option update page_on_front "$home_id" --quiet
facts="$(php /repo/deploy/tests/pages-facts.php "$BASE" 2>&1)" || setup_fail "page checks crashed: $facts"
while IFS='|' read -r kind name detail; do
	case "$kind" in ok) ok "$name" ;; FAIL) bad "$name ($detail)" ;; *) [[ -n "$kind" ]] && echo "   $kind|$name|$detail" ;; esac
done <<< "$facts"

echo
echo "Passed: $PASS   Failed: $FAIL"
[[ $FAIL -eq 0 ]]
