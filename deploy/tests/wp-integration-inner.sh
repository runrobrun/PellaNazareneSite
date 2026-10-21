#!/usr/bin/env bash
# Runs INSIDE the wordpress:cli container (see wp-integration.sh). Real WordPress + the real
# Two Factor plugin + our must-use plugin, exercised with real HTTP logins. Throwaway; nothing persists.
# shellcheck disable=SC2015,SC2016,SC2001  # test helpers: "a && ok || bad" is intended; PHP for `wp eval` is deliberately single-quoted
set -uo pipefail
BASE=http://127.0.0.1:8080
W=/tmp/wp; mkdir -p "$W"; cd "$W" || exit 1
export WP_CLI_CACHE_DIR=/tmp/wpcache
# Unpacking WordPress needs more than the image's default 128M.
wp() { php -d memory_limit=512M /usr/local/bin/wp "$@"; }
setup_fail() { echo "SETUP FAILED: $1"; exit 1; }
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ok    $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
expect_eq()       { [[ "$2" == "$3" ]] && ok "$1" || bad "$1 (got '$2', wanted '$3')"; }
expect_contains() { grep -qF -- "$3" <<<"$2" && ok "$1" || bad "$1 (missing: $3)"; }
expect_lacks()    { grep -qF -- "$3" <<<"$2" && bad "$1 (should not contain: $3)" || ok "$1"; }

echo "== Setup: real WordPress, Two Factor 0.17.0, our mu-plugin"
wp core download --quiet >/dev/null || setup_fail "core download"
[[ -f wp-settings.php ]] || setup_fail "WordPress files missing"
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=pn-test-db --skip-check --quiet
for _ in $(seq 1 45); do mysql -h pn-test-db -uroot -proot -e 'select 1' wp >/dev/null 2>&1 && break; sleep 2; done
wp core install --url="$BASE" --title=Test --admin_user=boss --admin_password='Admin-pass-1!' --admin_email=boss@example.test --skip-email --quiet || setup_fail "core install"
mkdir -p wp-content/mu-plugins && cp /repo/wp-content/mu-plugins/pn-*.php wp-content/mu-plugins/
wp plugin install two-factor --version=0.17.0 --activate --quiet || setup_fail "installing Two Factor"
wp user create ed ed@example.test --role=editor --user_pass='Editor-pass-1!' --quiet
wp user create pastor pastor@example.test --role=author --user_pass='Pastor-pass-1!' --quiet
wp user create reader reader@example.test --role=subscriber --user_pass='Reader-pass-1!' --quiet
wp rewrite structure '/%postname%/' --hard --quiet
wp post create --post_title=Hello --post_status=publish --post_author=1 --quiet >/dev/null
( wp server --host=127.0.0.1 --port=8080 >/tmp/server.log 2>&1 & )
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/" && break; sleep 1; done

login() {  # user pass jar -> prints the login response body
  curl -s -c "$3" -b "$3" -b 'wordpress_test_cookie=WP%20Cookie%20check' \
    --data-urlencode "log=$1" --data-urlencode "pwd=$2" -d "wp-submit=Log+In&redirect_to=$BASE/wp-admin/&testcookie=1" "$BASE/wp-login.php"
}
rest_nonce() {  # jar user -> a valid REST nonce for that logged-in session (what a stolen session could obtain)
  local c; c="$(awk '$6 ~ /^wordpress_logged_in_/ {print $7}' "$1" | head -1)"
  PN_COOKIE="$(php -r 'echo urldecode($argv[1]);' "$c")" wp eval 'wp_set_current_user(get_user_by("login","'"$2"'")->ID); $_COOKIE[LOGGED_IN_COOKIE] = getenv("PN_COOKIE"); echo wp_create_nonce("wp_rest");'
}
rest_get()  { curl -s -b "$2" -H "X-WP-Nonce: $3" -w '\n%{http_code}' "$BASE/wp-json$1"; }
rest_post() { curl -s -b "$2" -H "X-WP-Nonce: $3" -X POST -d 'title=sneaky&status=publish' -w '\n%{http_code}' "$BASE/wp-json$1"; }
get_code() { curl -s -o /dev/null -b "$2" -w '%{http_code}' "$1"; }
get_loc()  { curl -s -o /dev/null -b "$2" -w '%{redirect_url}' "$1"; }

echo "== 1. Methods: authenticator app and backup codes only"
providers="$(wp eval 'echo implode(",", array_keys(Two_Factor_Core::get_providers()));')"
expect_eq "providers" "$providers" "Two_Factor_Totp,Two_Factor_Backup_Codes"

echo "== 2. Who must use two-factor"
for pair in boss:1 ed:1 pastor:1 reader:; do
  u="${pair%%:*}"; want="${pair##*:}"
  got="$(wp eval "echo pn_user_must_use_two_factor(get_user_by('login','$u')) ? '1' : '';")"
  expect_eq "$u must use 2FA = '${want:-no}'" "$got" "$want"
done

echo "== 3. Wrong username and wrong password give the same, generic error"
r1="$(login nobody-here 'whatever' /tmp/j0)"; r2="$(login ed 'wrong-password' /tmp/j0)"
expect_contains "unknown user: generic message" "$r1" "Incorrect username or password"
expect_contains "known user, bad password: generic message" "$r2" "Incorrect username or password"
expect_lacks "does not say the user is unknown" "$r1" "not registered"
expect_lacks "does not confirm the username" "$r2" "is incorrect"

echo "== 4. Username leaks closed (anonymous visitor)"
expect_eq "/?author=1 redirects" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?author=1")" "301"
expect_eq "/author/boss/ redirects" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/author/boss/")" "301"
expect_eq "/author/boss/ goes to the home page" "$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/author/boss/")" "$BASE/"
expect_eq "user sitemap is gone" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/wp-sitemap-users-1.xml")" "404"
expect_lacks "sitemap index does not list users" "$(curl -s "$BASE/wp-sitemap.xml")" "users"
expect_lacks "REST users list is not public" "$(curl -s "$BASE/wp-json/wp/v2/users")" '"slug"'
expect_eq "application passwords unavailable" "$(wp eval 'var_export(wp_is_application_passwords_available());')" "false"

echo "== 5. Editor WITHOUT two-factor: sent to set it up, nothing else reachable"
rm -f /tmp/j_ed; login ed 'Editor-pass-1!' /tmp/j_ed >/dev/null
expect_eq "posts list redirects" "$(get_code "$BASE/wp-admin/edit.php" /tmp/j_ed)" "302"
expect_contains "...to the profile page, 2FA section" "$(get_loc "$BASE/wp-admin/edit.php" /tmp/j_ed)" "profile.php#two-factor-options"
expect_eq "dashboard redirects too" "$(get_code "$BASE/wp-admin/" /tmp/j_ed)" "302"
expect_eq "profile page loads (setup possible)" "$(get_code "$BASE/wp-admin/profile.php" /tmp/j_ed)" "200"
expect_contains "profile shows the requirement" "$(curl -s -b /tmp/j_ed "$BASE/wp-admin/profile.php")" "Two-factor sign-in is required"
expect_contains "setup page offers the authenticator app" "$(curl -s -b /tmp/j_ed "$BASE/wp-admin/profile.php")" "two-factor-options"
expect_eq "admin-ajax is blocked until 2FA is set up" "$(get_code "$BASE/wp-admin/admin-ajax.php?action=rest-nonce" /tmp/j_ed)" "403"
n_ed="$(rest_nonce /tmp/j_ed ed)"
[[ -n "$n_ed" ]] && ok "(test setup) got a REST nonce for the un-enrolled editor, as a session thief could" || bad "could not build a REST nonce"
r="$(rest_get /wp/v2/users/me /tmp/j_ed "$n_ed")"
expect_contains "REST read is blocked until 2FA is set up" "$r" "pn_two_factor_required"
r="$(rest_post /wp/v2/posts /tmp/j_ed "$n_ed")"
expect_contains "REST publish is blocked until 2FA is set up" "$r" "pn_two_factor_required"
expect_eq "no post was created" "$(wp post list --post_status=any --s=sneaky --format=count)" "0"
r="$(rest_post /two-factor/1.0/totp /tmp/j_ed "$n_ed")"
expect_lacks "the 2FA setup route itself stays open" "$r" "pn_two_factor_required"

echo "== 6. Subscriber (cannot edit content): not forced"
rm -f /tmp/j_rd; login reader 'Reader-pass-1!' /tmp/j_rd >/dev/null
expect_eq "profile loads, no redirect" "$(get_code "$BASE/wp-admin/profile.php" /tmp/j_rd)" "200"
n_rd="$(rest_nonce /tmp/j_rd reader)"
expect_eq "subscriber REST works (not forced)" "$(rest_get /wp/v2/users/me /tmp/j_rd "$n_rd" | tail -1)" "200"
expect_lacks "no 2FA nag for subscribers" "$(curl -s -b /tmp/j_rd "$BASE/wp-admin/profile.php")" "Two-factor sign-in is required"

echo "== 7. Editor WITH authenticator app: must give a code, then works normally"
wp eval '
$u = get_user_by("login","ed");
$key = Two_Factor_Totp::generate_key();
Two_Factor_Totp::get_instance()->set_user_totp_key($u->ID, $key);
update_user_meta($u->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array("Two_Factor_Totp"));
update_user_meta($u->ID, Two_Factor_Core::PROVIDER_USER_META_KEY, "Two_Factor_Totp");
file_put_contents("/tmp/ed.key", $key);'
expect_eq "needs-setup is now false" "$(wp eval "echo pn_user_needs_two_factor_setup(get_user_by('login','ed')) ? 'yes' : 'no';")" "no"
rm -f /tmp/j_ed2; page="$(login ed 'Editor-pass-1!' /tmp/j_ed2)"
expect_contains "password alone is NOT enough: asks for the code" "$page" 'name="authcode"'
expect_eq "no admin access with password only" "$(get_code "$BASE/wp-admin/profile.php" /tmp/j_ed2)" "302"
field() { grep -o "name=\"$2\"[^>]*value=\"[^\"]*\"" <<<"$1" | head -1 | sed 's/.*value="//;s/"$//'; }
nonce="$(field "$page" wp-auth-nonce)"
uid="$(field "$page" wp-auth-id)"
[[ -n "$nonce" && -n "$uid" ]] || bad "could not read the 2FA form fields (nonce='$nonce' uid='$uid')"
bad_resp="$(curl -s -c /tmp/j_ed2 -b /tmp/j_ed2 -d "provider=Two_Factor_Totp&wp-auth-id=$uid&wp-auth-nonce=$nonce&authcode=000000&redirect_to=$BASE/wp-admin/" "$BASE/wp-login.php?action=validate_2fa")"
expect_contains "a wrong code is rejected" "$bad_resp" 'name="authcode"'
[[ -n "${DEBUG:-}" ]] && { echo "--- DEBUG wrong-code response (tags stripped):"; sed 's/<[^>]*>/ /g' <<<"$bad_resp" | tr -s ' \n' | head -c 700; echo; echo "--- DEBUG nonce=[$nonce] uid=[$uid]"; }
code="$(wp eval 'echo Two_Factor_Totp::calc_totp(file_get_contents("/tmp/ed.key"));')"
submit_code() {  # fresh password login, then submit $1 as the code; prints the response body
  local pg n
  pg="$(login ed 'Editor-pass-1!' /tmp/j_ed2)"; n="$(field "$pg" wp-auth-nonce)"
  curl -s -c /tmp/j_ed2 -b /tmp/j_ed2 -d "provider=Two_Factor_Totp&wp-auth-id=$uid&wp-auth-nonce=$n&authcode=$1&redirect_to=$BASE/wp-admin/" "$BASE/wp-login.php?action=validate_2fa"
}
expect_contains "guessing is throttled (the plugin's brute-force protection)" "$(submit_code "$code")" "Too many invalid verification codes"
sleep 4
submit_code "$code" >/dev/null
expect_eq "after the wait, the right code logs in: posts list loads" "$(get_code "$BASE/wp-admin/edit.php" /tmp/j_ed2)" "200"
expect_eq "enrolled editor: admin-ajax works" "$(get_code "$BASE/wp-admin/admin-ajax.php?action=rest-nonce" /tmp/j_ed2)" "200"
n_ed2="$(rest_nonce /tmp/j_ed2 ed)"
expect_eq "enrolled editor: REST works" "$(rest_get /wp/v2/users/me /tmp/j_ed2 "$n_ed2" | tail -1)" "200"

echo "== 8. Emergency off-switch and fail-open"
expect_eq "needs setup normally (pastor)" "$(wp eval "echo pn_user_needs_two_factor_setup(get_user_by('login','pastor')) ? 'yes' : 'no';")" "yes"
expect_eq "PN_REQUIRE_2FA=false turns enforcement off" "$(wp --exec='define("PN_REQUIRE_2FA", false);' eval "echo pn_user_needs_two_factor_setup(get_user_by('login','pastor')) ? 'yes' : 'no';")" "no"
wp plugin deactivate two-factor --quiet
rm -f /tmp/j_pa; login pastor 'Pastor-pass-1!' /tmp/j_pa >/dev/null
expect_eq "Two Factor plugin inactive: nobody is locked out" "$(get_code "$BASE/wp-admin/edit.php" /tmp/j_pa)" "200"
expect_contains "...and admins are warned" "$(curl -s -c /tmp/j_boss -b /tmp/j_boss -b 'wordpress_test_cookie=WP%20Cookie%20check' --data-urlencode 'log=boss' --data-urlencode 'pwd=Admin-pass-1!' -d "wp-submit=Log+In&redirect_to=$BASE/wp-admin/" -L "$BASE/wp-login.php")" "NOT being enforced"
wp plugin activate two-factor --quiet

echo
echo "Passed: $PASS   Failed: $FAIL"
[[ $FAIL == 0 ]]
