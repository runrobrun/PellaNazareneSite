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
mkdir -p wp-content/mu-plugins && cp /repo/wp-content/mu-plugins/pn-forms.php /repo/wp-content/mu-plugins/pn-analytics.php /repo/wp-content/mu-plugins/pn-seo.php wp-content/mu-plugins/   # Fluent Forms is not installed in this test: [pn_form] must quietly show nothing
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
	silent-photos) break_it $S/create-pages.php 'WP_CLI::warning( "Photo ' 'WP_CLI::warning( "' ;;
	old-spelling) break_it $T/patterns/page-events.php 'Every Wednesday, 7:00' 'Every Wedensday, 7:00' ;;
	no-groups)    break_it $T/patterns/page-small-groups.php 'churchcenter.com/groups' 'churchcenter.com/group' ;;
	blog-nav)    break_it $T/patterns/header.php "echo \$pn_link( __( 'Blog', 'pella-nazarene' ), '/blog/' ), \"\\n\\t\\t\\t\";" "" ;;
	blog-empty)  break_it $T/templates/home.html 'No posts yet.' 'Nothing.' ;;
	ga-everywhere) break_it wp-content/mu-plugins/pn-analytics.php "'production' === wp_get_environment_type()" "true" ;;
	ga-for-staff)  break_it wp-content/mu-plugins/pn-analytics.php "&& ! is_user_logged_in()" "" ;;
	ga-ignores-gpc) break_it wp-content/mu-plugins/pn-analytics.php "navigator.globalPrivacyControl === true || " "" ;;
	wrong-hours)  break_it $T/parts/service-info.html 'Wednesday 9&nbsp;AM–&#8288;12&nbsp;PM' 'Wednesday 1–&#8288;4&nbsp;PM' ;;
	hours-drift)  break_it wp-content/mu-plugins/pn-seo.php "array( 'Friday', '09:00', '12:00' )," "array( 'Friday', '13:00', '16:00' )," ;;
	no-description) break_it wp-content/mu-plugins/pn-seo.php "return pn_seo_shorten( \$text );" "return '';" ;;
	no-share-image) break_it wp-content/mu-plugins/pn-seo.php "photos/og-default.jpg" "photos/missing.jpg" ;;
	same-words)  break_it $T/patterns/page-home.php '>Upcoming events</a>' '>Give online</a>' ;;
	*) setup_fail "unknown BREAK" ;;
esac

if [[ "${BREAK:-}" == missing-photo ]]; then
	echo "== An incomplete photo import does not create or replace pages"
	wp post create --post_type=page --post_status=publish --post_name=home --post_title="Existing Home" --post_content="Staff edit" --quiet
	out="$(create --replace home 2>&1 || true)"
	expect_contains "the missing photo slug is reported" "$out" "Photo 'missing-photo'"
	expect_contains "the missing photo file is reported" "$out" "assets/images/photos/missing-photo.webp is not in the theme"
	expect_contains "reports that no pages were created" "$out" "No pages were created or replaced"
	expect_eq "no pages are created after an incomplete import" "$(pages)" "1"
	expect_eq "an existing page is not replaced" "$(wp post list --post_type=page --name=home --field=post_content)" "Staff edit"
	echo
	echo "Passed: $PASS   Failed: $FAIL"
	[[ $FAIL -eq 0 ]]
	exit
fi

echo "== 0. A photo that cannot be saved is explained, and nothing is half-done"
rm -rf wp-content/uploads && touch wp-content/uploads   # a file where the folder should be: nothing can be saved, whoever runs this
out="$(create)"
rm -f wp-content/uploads && mkdir -p wp-content/uploads
expect_contains "names the photo that failed" "$out" "Photo 'home-hero'"
expect_contains "gives the real reason" "$out" "writable"
expect_contains "points at the web user" "$out" "PN_WP_USER"
expect_contains "stops" "$out" "No pages were created or replaced"
expect_eq "no page was created" "$(pages)" "0"
expect_eq "no photo was added" "$(wp post list --post_type=attachment --format=count)" "0"

echo "== 1. First run creates the nine pages"
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
for s in home new-here about-us kids giving contact resources events small-groups blog prayer privacy; do expect_contains "created $s" "$out" "Created '$s'"; done
expect_eq "twelve pages exist" "$(pages)" "12"
expect_eq "the Blog page is the posts page" "$(wp post get "$(wp option get page_for_posts)" --field=post_name)" "blog"
expect_eq "Home is the front page" "$(wp option get show_on_front) $(wp post get "$(wp option get page_on_front)" --field=post_name)" "page home"
expect_eq "all are published" "$(wp post list --post_type=page --post_status=publish --format=count)" "12"

expect_eq "all 12 photos are in the media library" "$(wp post list --post_type=attachment --format=count)" "12"
expect_eq "each photo has its alt text from the manifest" "$(wp post meta get "$(wp post list --post_type=attachment --meta_key=_pn_photo --meta_value=staff-floyd-ware --field=ID)" _wp_attachment_image_alt)" "Floyd Ware, associate pastor"

echo "== 2. Running it again changes nothing"
before="$(wp post list --post_type=page --format=csv --fields=ID,post_modified | md5sum)"
out="$(create)"
expect_eq "no new pages" "$(pages)" "12"
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
expect_eq "still no duplicate (11 live + 1 in the trash)" "$(wp post list --post_type=page --post_status=publish --format=count) $(wp post list --post_type=page --post_status=trash --format=count)" "11 1"
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
wp post create --post_type=pn_sermon --post_status=publish --post_title="Latest published sermon" --post_excerpt="Pastor Rob Hammann on the Holy Spirit at Pentecost." --post_date="2026-05-24 12:00:00" --meta_input='{"pn_youtube_id":"Nll1UzWZHQA"}' --quiet
wp post create --post_type=pn_sermon --post_status=draft --post_title="A draft sermon" --post_date="2026-06-07 12:00:00" --meta_input='{"pn_youtube_id":"vTKuOza2c68"}' --quiet
( wp server --host=127.0.0.1 --port=8080 >/tmp/server.log 2>&1 & )
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/" && break; sleep 1; done

echo "== 4b. The blog starts empty, shows only published posts, and has a feed"
empty_blog="$(curl -s "$BASE/blog/")"
expect_contains "an empty blog says so" "$empty_blog" "No posts yet"
wp post create --post_type=post --post_status=publish --post_title="Welcome to our new website" --post_content="We are glad you are here." --post_author=1 --quiet
blog_post="$(wp post list --post_type=post --name=welcome-to-our-new-website --field=ID)"
hero_id="$(wp post list --post_type=attachment --meta_key=_pn_photo --meta_value=home-hero --field=ID)"
PN_POST_ID="$blog_post" PN_IMAGE_ID="$hero_id" wp eval 'set_post_thumbnail( (int) getenv("PN_POST_ID"), (int) getenv("PN_IMAGE_ID") );'
wp post create --post_type=post --post_status=draft --post_title="A draft post" --post_content="Not ready." --post_author=1 --quiet

echo "== 4c. Google Analytics: the live site only"
tag_count() { grep -o 'googletagmanager.com/gtag/js' <<<"$1" | wc -l | tr -d ' '; }
live="$(curl -s "$BASE/")"
expect_eq "the live site has the tag exactly once" "$(tag_count "$live")" "1"
expect_contains "it uses the church's measurement ID" "$live" 'G-3E45KJPG3G'
expect_contains "it stays quiet for Global Privacy Control and Do Not Track" "$live" 'navigator.globalPrivacyControl === true || navigator.doNotTrack'
expect_contains "it loads asynchronously" "$live" 's.async = true'
expect_eq "it comes after the footer, at the very end of the page" "$(php -r '$h = stream_get_contents(STDIN); echo strpos($h, "Google Analytics (live site only)") > strrpos($h, "pn-footer") ? "yes" : "no";' <<<"$live")" "yes"
for p in about-us blog contact privacy prayer does-not-exist; do expect_eq "/$p/ has the tag exactly once" "$(tag_count "$(curl -s "$BASE/$p/")")" "1"; done
set_env() { wp config set WP_ENVIRONMENT_TYPE "$1" --type=constant --quiet; sleep 3; }   # the test web server re-reads wp-config.php only every couple of seconds
for e in staging local development; do
	set_env "$e"
	expect_eq "a '$e' site never has the tag" "$(tag_count "$(curl -s "$BASE/")")" "0"
done
set_env production
expect_eq "back on 'production' the tag returns" "$(tag_count "$(curl -s "$BASE/")")" "1"
expect_eq "the feed never has it" "$(tag_count "$(curl -s "$BASE/feed/")")" "0"
expect_eq "the login page never has it" "$(tag_count "$(curl -s "$BASE/wp-login.php")")" "0"
rm -f /tmp/jar
curl -s -c /tmp/jar -b 'wordpress_test_cookie=WP%20Cookie%20check' --data-urlencode "log=boss" --data-urlencode "pwd=Admin-pass-1!" -d "wp-submit=Log+In&redirect_to=$BASE/wp-admin/&testcookie=1" "$BASE/wp-login.php" -o /dev/null
expect_eq "signed in as staff, the site has no tag" "$(tag_count "$(curl -s -b /tmp/jar "$BASE/")")" "0"
expect_eq "...nor on a post page" "$(tag_count "$(curl -s -b /tmp/jar "$BASE/blog/")")" "0"
expect_eq "...nor in the admin" "$(tag_count "$(curl -s -b /tmp/jar "$BASE/wp-admin/")")" "0"
expect_eq "the signed-in visit really was signed in" "$(grep -c 'wp-admin-bar\|wpadminbar' <<<"$(curl -s -b /tmp/jar "$BASE/")" | tr -d ' ' | awk '{print ($1 > 0) ? "yes" : "no"}')" "yes"

echo "== 5. The pages as visitors see them"
wp option update page_on_front "$(wp post list --post_type=page --name=about-us --field=ID)" --quiet
alternate_front="$(curl -s "$BASE/")"
expect_contains "a staff-selected front page shows its page title" "$alternate_front" '<h1 class="wp-block-post-title">About Us</h1>'
expect_lacks "a front page without a hero does not preload an image" "$alternate_front" 'rel="preload" as="image"'
wp option update page_on_front "$home_id" --quiet
home_content="$(wp post get "$home_id" --field=post_content)"
wp post update "$home_id" --post_content='<!-- wp:image {"id":123,"className":"pn-photo--hero"} --><figure class="wp-block-image pn-photo--hero"><img src="/missing.webp" alt=""></figure><!-- /wp:image -->' --quiet
home_without_h1="$(curl -s "$BASE/")"
expect_contains "a hero without a replacement h1 keeps the template title" "$home_without_h1" '<h1 class="wp-block-post-title">A church in Pella, Iowa</h1>'
wp post update "$home_id" --post_content='<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">A staff-written home headline</h1><!-- /wp:heading -->' --quiet
home_without_photo="$(curl -s "$BASE/")"
expect_contains "a replacement h1 without a hero renders" "$home_without_photo" '<h1 class="wp-block-heading">A staff-written home headline</h1>'
expect_lacks "a replacement h1 without a hero suppresses the template title" "$home_without_photo" '<h1 class="wp-block-post-title">A church in Pella, Iowa</h1>'
wp post update "$home_id" --post_content="$home_content" --quiet
facts="$(php /repo/deploy/tests/pages-facts.php "$BASE" 2>&1)" || setup_fail "page checks crashed: $facts"
while IFS='|' read -r kind name detail; do
	case "$kind" in ok) ok "$name" ;; FAIL) bad "$name ($detail)" ;; *) [[ -n "$kind" ]] && echo "   $kind|$name|$detail" ;; esac
done <<< "$facts"

echo
echo "Passed: $PASS   Failed: $FAIL"
[[ $FAIL -eq 0 ]]
