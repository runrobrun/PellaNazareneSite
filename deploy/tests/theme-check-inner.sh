#!/usr/bin/env bash
# shellcheck disable=SC2015,SC2016  # test helpers: "a && ok || bad" is intended; PHP for `wp eval` is deliberately single-quoted
# Runs INSIDE the wordpress:cli container (see theme-check.sh). Real WordPress + the real parent theme +
# our theme, then looks at what WordPress actually generates. Throwaway.
set -uo pipefail
BASE=http://127.0.0.1:8080
W=/tmp/wp; mkdir -p "$W"; cd "$W" || exit 1
export WP_CLI_CACHE_DIR=/tmp/wpcache
wp() { php -d memory_limit=512M /usr/local/bin/wp "$@"; }
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ok    $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
expect_eq() { [[ "$2" == "$3" ]] && ok "$1" || bad "$1 (got '$2', wanted '$3')"; }
hasx()  { grep -qF -- "$3" <<<"$2" && ok "$1" || bad "$1 (missing, case-sensitive: $3)"; }   # case-sensitive: use when another part of the page could match by accident
has()   { grep -qiF -- "$3" <<<"$2" && ok "$1" || bad "$1 (missing: $3)"; }
lacks() { grep -qiF -- "$3" <<<"$2" && bad "$1 (found: $3)" || ok "$1"; }
setup_fail() { echo "SETUP FAILED: $1"; exit 1; }

echo "== Setup: real WordPress, Twenty Twenty-Five, our theme"
wp core download --quiet >/dev/null || setup_fail "core download"
[[ -f wp-settings.php ]] || setup_fail "WordPress files missing"
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=pn-test-db --skip-check --quiet
for _ in $(seq 1 45); do mysql -h pn-test-db -uroot -proot -e 'select 1' wp >/dev/null 2>&1 && break; sleep 2; done
wp core install --url="$BASE" --title=Test --admin_user=boss --admin_password='Admin-pass-1!' --admin_email=boss@example.test --skip-email --quiet || setup_fail "core install"
wp theme install twentytwentyfive --quiet || setup_fail "installing Twenty Twenty-Five"
cp -R /repo/wp-content/themes/pella-nazarene wp-content/themes/
wp theme activate pella-nazarene --quiet || setup_fail "activating our theme"
wp rewrite structure '/%postname%/' --hard --quiet
( wp server --host=127.0.0.1 --port=8080 >/tmp/server.log 2>&1 & )
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/" && break; sleep 1; done

# A page that uses every brand element (headings, subheading, buttons, quote, tinted group...). WordPress only
# prints block-level CSS for blocks that are on the page, so the checks need a page that has them.
wp post create --post_type=page --post_title="Brand check" --post_name=brand-check --post_status=publish --post_content="$(cat /repo/deploy/tests/fixtures/brand-check-page.html)" --quiet
wp option update show_on_front page --quiet; wp option update page_on_front "$(wp post list --post_type=page --name=brand-check --field=ID)" --quiet
RAWCSS="$(wp eval 'echo wp_get_global_stylesheet();')"
CSS="$(tr -d ' \n\t' <<<"$RAWCSS")"           # WordPress prints "--x: #fff;", compare without spaces
HTML="$(curl -s "$BASE/")"
ALLCSS="${CSS}$(tr -d ' \n\t' <<<"$HTML")"   # global styles plus the per-block styles printed in the page
FONTCSS="$(tr -d ' \n\t' <<<"$(tr '\n' ' ' <<<"$HTML" | grep -o "<style class=.wp-fonts-local.[^>]*>[^<]*</style>")")"   # @font-face rules are printed in the page head, only for the styles the page uses

echo "== 1. Brand colors reach the generated CSS"
has "navy"            "$CSS" "--wp--preset--color--accent-3:#2D3D54"
has "dark navy"       "$CSS" "--wp--preset--color--primary-dark:#263447"
has "black text"      "$CSS" "--wp--preset--color--contrast:#000000"
has "white"           "$CSS" "--wp--preset--color--base:#FFFFFF"
lacks "no parent yellow left" "$CSS" "#FFEE58"
lacks "no parent purple left" "$CSS" "#503AA8"
lacks "no old placeholder blue" "$CSS" "#2f5d8c"
lacks "no WordPress default palette" "$CSS" "vivid-red"
lacks "no default gradients" "$CSS" "vivid-cyan-blue-to-vivid-purple"

echo "== 2. Brand typeface"
has "@font-face rules are in the page head" "$FONTCSS" "@font-face"
has "Plus Jakarta Sans regular is declared"  "$FONTCSS" "plus-jakarta-sans-normal.woff2"
expect_eq "italic face is registered for the Subheading style" "$(wp eval '$f = wp_get_global_settings(["typography","fontFamilies","theme"]); $n = 0; foreach ($f as $fam) { foreach (($fam["fontFace"] ?? []) as $face) { if (($face["fontStyle"] ?? "") === "italic" && str_contains(implode(",", (array) $face["src"]), "plus-jakarta-sans-italic.woff2")) { $n++; } } } echo $n;')" "1"
has "weight range of the variable font"      "$FONTCSS" "font-weight:200800"
has "font-display: swap"                     "$FONTCSS" "font-display:swap"
has "headings use Extra Bold"                "$CSS" "h1,h2,h3,h4,h5,h6{"
has "...with weight 800"                       "$(grep -o "h1,h2,h3,h4,h5,h6{[^}]*}" <<<"$CSS" | head -1)" "font-weight:800"
lacks "the parent's Manrope is gone"         "$FONTCSS" "manrope"
lacks "no Fira Code font files"              "$FONTCSS" "fira"

echo "== 3. Self-hosted and fast: nothing third-party, fonts reachable, one preload"
lacks "no Google Fonts request in the page"  "$HTML" "fonts.googleapis.com"
lacks "no gstatic request in the page"       "$HTML" "fonts.gstatic.com"
has "regular font is preloaded"              "$HTML" 'rel="preload" href='
has "...as a font, with crossorigin"         "$HTML" 'as="font" type="font/woff2" crossorigin'
expect_one() { n="$(grep -o 'rel="preload"[^>]*woff2' <<<"$HTML" | wc -l | tr -d ' ')"; [[ "$n" == "1" ]] && ok "exactly one font is preloaded" || bad "preloaded fonts: $n"; }; expect_one
for f in normal italic; do
  url="$BASE/wp-content/themes/pella-nazarene/assets/fonts/plus-jakarta-sans-$f.woff2"
  code="$(curl -s -o /tmp/f.woff2 -w '%{http_code}' "$url")"; size="$(wc -c </tmp/f.woff2 | tr -d ' ')"; magic="$(head -c 4 /tmp/f.woff2)"
  [[ "$code" == "200" && "$magic" == "wOF2" && "$size" -gt 20000 ]] && ok "$f font is served ($size bytes, valid WOFF2)" || bad "$f font: HTTP $code, $size bytes, magic '$magic'"
done

echo "== 3a. Brand icon (browser tab, bookmarks, phone home screen)"
has "page links the ICO favicon"        "$HTML" "assets/images/favicon.ico"
has "page links the SVG icon"           "$HTML" "assets/images/icon.svg"
has "page links the Apple touch icon"   "$HTML" "assets/images/apple-touch-icon.png"
lacks "no WordPress default site-icon request" "$HTML" "w-logo-blue"
magic() { curl -s "$1" | head -c "$2" | od -An -tx1 | tr -d ' \n'; }
for spec in "favicon.ico:4:00000100" "apple-touch-icon.png:4:89504e47" "icon.svg:4:3c737667"; do
  f="${spec%%:*}"; rest="${spec#*:}"; n="${rest%%:*}"; want="${rest##*:}"
  code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE/wp-content/themes/pella-nazarene/assets/images/$f")"
  expect_eq "$f is served and is the right kind of file" "$code $(magic "$BASE/wp-content/themes/pella-nazarene/assets/images/$f" "$n")" "200 $want"
done
echo "       (on a site with an icon chosen in WordPress, that icon wins and these links are skipped)"

echo "== 3b. Component styles"
has "headings get breathing room above (beats WordPress's block-gap rule)" "$ALLCSS" "h2.wp-block-heading:not(:first-child){margin-block-start:2.5rem}"
has "buttons are navy"                  "$(grep -o '\.wp-block-button__link){[^}]*}' <<<"$ALLCSS" | head -1)" "background-color:var(--wp--preset--color--accent-3)"
has "button hover goes to dark navy"    "$ALLCSS" "--wp--preset--color--primary-dark"
has "outline buttons are navy"          "$(grep -o 'is-style-outline[^{]*{[^}]*}' <<<"$ALLCSS" | tr -d '\n')" "border-color:var(--wp--preset--color--accent-3)"
OUTLINE_HOVER="$(grep -o '\.wp-block-button\.is-style-outline[^{}]*:hover{[^}]*}' <<<"$ALLCSS" | tr -d '\n')"
has "outline button hover gets tinted background" "$OUTLINE_HOVER" "background-color:var(--wp--preset--color--accent-2)"
has "outline button hover gets dark navy text" "$OUTLINE_HOVER" "color:var(--wp--preset--color--primary-dark)"
has "quote rule is navy"                "$(grep -o 'wp-block-quote[^{]*{[^}]*}' <<<"$ALLCSS" | tr -d '\n')" "border-left-color:var(--wp--preset--color--accent-3)"
has "Subheading style is Light Italic"  "$ALLCSS" "font-style:italic"
has "...at weight 300"                  "$(grep -o 'is-style-subheading[^{]*{[^}]*}' <<<"$ALLCSS" | tr -d '\n')" "font-weight:300"
has "links are underlined"              "$ALLCSS" "text-decoration:underline"
has "keyboard focus ring on links/buttons" "$ALLCSS" ":focus-visible"

echo "== 4. Nothing points at a variable that no longer exists"
undefined="$( { echo "$CSS"; curl -s "$BASE/" | tr '>' '>\n'; } | python3 -c '
import re,sys
t=sys.stdin.read()
used=set(re.findall(r"var\(--wp--preset--([a-z-]+--[a-z0-9-]+)",t))
defined=set(re.findall(r"--wp--preset--([a-z-]+--[a-z0-9-]+):",t))
print(" ".join(sorted(used-defined)))' 2>/dev/null || echo "python-unavailable" )"
if [[ "$undefined" == "python-unavailable" ]]; then
  undefined="$(wp eval '
    $css = wp_get_global_stylesheet();
    preg_match_all("/var\(--wp--preset--([a-z-]+--[a-z0-9-]+)/", $css, $u);
    preg_match_all("/--wp--preset--([a-z-]+--[a-z0-9-]+):/", $css, $d);
    echo implode(" ", array_diff(array_unique($u[1]), array_unique($d[1])));')"
fi
[[ -z "${undefined// /}" ]] && ok "every preset variable that is used is defined" || bad "used but undefined: $undefined"

echo "== 5. Staff can't wander off-brand"
expect_eq "custom colors disabled" "$(wp eval 'echo wp_get_global_settings(["color","custom"]) ? "on" : "off";')" "off"
expect_eq "custom gradients disabled" "$(wp eval 'echo wp_get_global_settings(["color","customGradient"]) ? "on" : "off";')" "off"
expect_eq "arbitrary font sizes disabled" "$(wp eval 'echo wp_get_global_settings(["typography","customFontSize"]) ? "on" : "off";')" "off"
expect_eq "9 palette colors, nothing else" "$(wp eval 'echo count(wp_get_global_settings(["color","palette","theme"]));')" "9"
expect_eq "WordPress default palette not offered" "$(wp eval 'echo wp_get_global_settings(["color","defaultPalette"]) ? "on" : "off";')" "off"

echo "== 6. Style variations (whole-site looks) and block styles"
echo "       block style variations: $(wp eval '$v = WP_Theme_JSON_Resolver::get_style_variations("block"); echo count($v) . " [" . implode(", ", array_map(fn($x) => $x["title"] ?? "?", $v)) . "]";')"
expect_eq "Subheading block style is registered" "$(wp eval '$v = WP_Theme_JSON_Resolver::get_style_variations("block"); echo in_array("Subheading", array_column($v, "title"), true) ? "yes" : "no";')" "yes"
expect_eq "administrators are offered no whole-site variations (REST, as the Site Editor asks)" "$(wp eval 'wp_set_current_user(1); $r = rest_do_request(new WP_REST_Request("GET", "/wp/v2/global-styles/themes/pella-nazarene/variations")); echo count($r->get_data());')" "0"
expect_eq "...but the parent still ships them (proving the filter is what hides them)" "$(wp eval 'echo count(WP_Theme_JSON_Resolver::get_style_variations()) > 0 ? "yes" : "no";')" "yes"

echo "== 6b. Header, menu, footer, and page templates (the home page, as a visitor sees it)"
N="$(tr '\n' ' ' <<<"$HTML" | sed 's/  */ /g')"
logo_tag="$(grep -o '<img[^>]*logo-primary\.svg[^>]*>' <<<"$N" | head -1)"
has "header shows the primary logo"                  "$logo_tag" "logo-primary.svg"
has "...with width and height (no layout shift)"      "$logo_tag" 'width="220" height="75"'
has "...and a description for screen readers"         "$logo_tag" 'alt="Pella Church of the Nazarene, home page"'
has "...that links to the home page"                  "$N" "<a class=\"pn-logo\" href=\"$BASE/\">"
has "footer shows the white logo"                     "$N" "logo-primary-white.svg"
for path in new-here about-us sermons events kids giving contact; do has "main menu links to /$path/" "$N" "href=\"$BASE/$path/\""; done
for label in "New Here" "About Us" "Sermons" "Events" "Kids" "Giving" "Contact"; do has "main menu label: $label" "$N" ">$label<"; done
has "Plan a Visit button in the menu"                 "$N" "pn-cta"
has "...pointing at New Here"                         "$(grep -o '<li[^>]*pn-cta[^>]*>.\{0,250\}' <<<"$N" | head -1)" "href=\"$BASE/new-here/\""
has "main menu is labelled Primary"                   "$N" 'aria-label="Primary"'
has "footer menu is labelled Footer"                  "$N" 'aria-label="Footer"'
has "mobile menu button exists"                       "$N" "wp-block-navigation__responsive-container-open"
has "Facebook link"                                   "$N" 'href="https://www.facebook.com/PellaNazarene"'
has "Instagram link"                                  "$N" 'href="https://www.instagram.com/pellanazarene"'
has "YouTube link"                                    "$N" 'href="https://www.youtube.com/@pellanazarene"'
has "service times are in the footer"                 "$N" "Worship 10:30"
has "street address is in the footer"                 "$N" "303 University Street"
has "phone number is a tap-to-call link"              "$N" 'href="tel:+16416281818"'
has "office hours are in the footer"                  "$N" "Monday 1"
has "copyright year is current"                       "$N" "© $(date +%Y) Pella Church of the Nazarene"
expect_eq "exactly one header landmark" "$(grep -o '<header' <<<"$N" | wc -l | tr -d ' ')" "1"
expect_eq "exactly one main landmark"   "$(grep -o '<main' <<<"$N" | wc -l | tr -d ' ')" "1"
expect_eq "exactly one footer landmark" "$(grep -o '<footer' <<<"$N" | wc -l | tr -d ' ')" "1"
has "skip-to-content link"                            "$N" 'class="skip-link'
has "...and the place it jumps to exists"             "$N" 'id="wp--skip-link--target"'
expect_eq "every image has an alt attribute" "$(grep -o '<img[^>]*>' <<<"$N" | grep -vc ' alt=' | tr -d ' ')" "0"
external="$(grep -o '\(src\|href\)="https\?://[^"]*"' <<<"$N" | grep -v "$BASE" | grep -v 'facebook.com/PellaNazarene\|instagram.com/pellanazarene\|youtube.com/@pellanazarene' | head -3 | tr '\n' ' ')"
expect_eq "no third-party scripts, styles, or images on the page" "$external" ""
code404="$(curl -s -o /tmp/404.html -w '%{http_code}' "$BASE/does-not-exist/")"
expect_eq "unknown address is a real 404" "$code404" "404"
has "404 page is friendly"                            "$(cat /tmp/404.html)" "find that page"
hasx "...and offers a Plan a visit button of its own"  "$(tr '\n' ' ' </tmp/404.html)" 'href="/new-here/">Plan a visit</a>'
hasx "...and a Watch a sermon button"                 "$(cat /tmp/404.html)" "Watch a sermon"
hasx "...and a Contact us button"                     "$(cat /tmp/404.html)" "Contact us"
expect_eq "404 page has one header landmark" "$(tr '\n' ' ' </tmp/404.html | grep -o '<header' | wc -l | tr -d ' ')" "1"
expect_eq "404 page has one main landmark"   "$(tr '\n' ' ' </tmp/404.html | grep -o '<main' | wc -l | tr -d ' ')" "1"
expect_eq "404 page has one footer landmark" "$(tr '\n' ' ' </tmp/404.html | grep -o '<footer' | wc -l | tr -d ' ')" "1"
has "...and a search box"                             "$(cat /tmp/404.html)" "wp-block-search"
has "...with the same header"                         "$(cat /tmp/404.html)" "logo-primary.svg"
expect_eq "search works" "$(curl -s -o /tmp/search.html -w '%{http_code}' "$BASE/?s=love")" "200"
has "search results keep the header and footer"       "$(tr '\n' ' ' </tmp/search.html)" "logo-primary-white.svg"

echo "== 7. WCAG AA contrast of every pairing the theme uses"
php /repo/deploy/tests/brand-contrast.php /repo/wp-content/themes/pella-nazarene/theme.json | tail -2 | sed 's/^/       /'
php /repo/deploy/tests/brand-contrast.php /repo/wp-content/themes/pella-nazarene/theme.json >/dev/null && ok "contrast test" || bad "contrast test"

echo "== 8. What a visitor downloads (informational)"
htmlgz="$(curl -s "$BASE/" | gzip -9 | wc -c | tr -d ' ')"; cssgz="$(wp eval 'echo wp_get_global_stylesheet();' | gzip -9 | wc -c | tr -d ' ')"
echo "       page HTML, gzipped:                $htmlgz bytes (includes the inline global styles)"
echo "       global styles alone, gzipped:      $cssgz bytes"
echo "       fonts: regular 27272 bytes (preloaded), italic 29524 bytes (only when a page uses italic)"

echo; echo "Passed: $PASS   Failed: $FAIL"
[[ $FAIL == 0 ]]
