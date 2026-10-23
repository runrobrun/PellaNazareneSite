#!/usr/bin/env bash
# Runs INSIDE the wordpress:cli container (see sermon-import-check.sh). Real WordPress + our sermon plugin +
# a fake YouTube API (deploy/tests/fixtures/youtube-mock.php). Throwaway; nothing persists.
# shellcheck disable=SC2015,SC2016  # test helpers: "a && ok || bad" is intended; PHP for `wp eval` is deliberately single-quoted
set -uo pipefail
W=/tmp/wp; mkdir -p "$W"; cd "$W" || exit 1
export WP_CLI_CACHE_DIR=/tmp/wpcache
wp() { php -d memory_limit=512M /usr/local/bin/wp "$@"; }
setup_fail() { echo "SETUP FAILED: $1"; exit 1; }
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ok    $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
expect_eq()       { [[ "$2" == "$3" ]] && ok "$1" || bad "$1 (got '$2', wanted '$3')"; }
expect_contains() { grep -qF -- "$3" <<<"$2" && ok "$1" || bad "$1 (missing: $3)"; }
expect_lacks()    { grep -qF -- "$3" <<<"$2" && bad "$1 (should not contain: $3)" || ok "$1"; }
KEY=TESTKEYTESTKEYTESTKEY1234
count() { wp post list --post_type=pn_sermon --post_status="${1:-any}" --format=count; }
meta()  { wp post meta get "$1" "$2" 2>/dev/null; }
# shellcheck disable=SC2086  # the IDs are a space-separated list
wipe() { local ids; ids="$(wp post list --post_type=pn_sermon --post_status=any --format=ids)"; [[ -z "$ids" ]] || wp post delete ${ids} --force --quiet; }
by_yt() { wp post list --post_type=pn_sermon --post_status=any --meta_key=pn_youtube_id --meta_value="$1" --field=ID | head -1; }

echo "== Setup: real WordPress, the sermon plugin, a fake YouTube"
wp core download --quiet >/dev/null || setup_fail "core download"
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=pn-sermon-db --skip-check --quiet
for _ in $(seq 1 45); do mysql -h pn-sermon-db -uroot -proot -e 'select 1' wp >/dev/null 2>&1 && break; sleep 2; done
wp core install --url=http://127.0.0.1:8080 --title=Test --admin_user=boss --admin_password='Admin-pass-1!' --admin_email=boss@example.test --skip-email --quiet || setup_fail "core install"
cat >> wp-config.php <<PHPEND
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'PN_YOUTUBE_API_KEY', getenv( 'YT_KEY' ) ?: '$KEY' );
define( 'PN_YOUTUBE_API_BASE', 'http://127.0.0.1:8099' );
define( 'PN_YOUTUBE_CHANNEL_ID', 'UCtest00000000000000000A' );
PHPEND
mkdir -p wp-content/plugins wp-content/mu-plugins
cp -r /repo/wp-content/plugins/pn-sermons wp-content/plugins/
# BREAK=<name> deliberately damages the copy of the plugin under test; the test must then fail.
I=wp-content/plugins/pn-sermons/includes
break_it() {  # file, exact old text, new text (fails loudly if the old text is not there)
	php -r '[$f, $a, $b] = array_slice($argv, 1); $t = file_get_contents($f); if (substr_count($t, $a) !== 1) { exit(1); } file_put_contents($f, str_replace($a, $b, $t));' "$@" || setup_fail "break '$BREAK' did not apply"
}
case "${BREAK:-}" in
	"") ;;
	no-dedupe)   break_it $I/class-youtube-import.php "if ( isset( \$existing[ \$video['id'] ] ) ) {" "if ( false ) {" ;;
	ignore-trash)
		break_it $I/class-youtube-import.php "p.post_status <> 'auto-draft'" "p.post_status NOT IN ('auto-draft','trash')"
		break_it $I/class-youtube-import.php "add_action( 'wp_trash_post', array( __CLASS__, 'remember_trashed_video' ) );" "// disabled by test fault injection"
		break_it $I/class-youtube-import.php "add_action( 'before_delete_post', array( __CLASS__, 'remember_purged_video' ) );" "// disabled by test fault injection"
		;;
	dry-run-writes) break_it $I/class-youtube-import.php "if ( \$opts['dry_run'] ) {" "if ( false ) {" ;;
	leak-key)    break_it $I/class-youtube-api.php "return sprintf( '%s (HTTP %d%s)', \$message, \$code," "return sprintf( '%s [key=' . self::key() . '] (HTTP %d%s)', \$message, \$code," ;;
	rest-exposed) break_it $I/class-post-types.php "'show_in_rest'  => false," "'show_in_rest'  => true," ;;
	no-pages)    break_it $I/class-youtube-api.php "\$token = (string) ( \$body['nextPageToken'] ?? '' );" "\$token = '';" ;;
	short-clips) break_it $I/class-youtube-import.php "if ( \$opts['min_seconds'] && isset" "if ( false && isset" ;;
	publish-all) break_it $I/class-youtube-import.php "'post_status'   => \$plan['status']," "'post_status'   => 'publish'," ;;
	*) setup_fail "unknown BREAK" ;;
esac
cp /repo/wp-content/mu-plugins/pn-site.php wp-content/mu-plugins/
wp rewrite structure '/%postname%/' --hard --quiet
wp plugin activate pn-sermons --quiet || setup_fail "activate pn-sermons"
( php -S 127.0.0.1:8099 /repo/deploy/tests/fixtures/youtube-mock.php >/tmp/mock.log 2>&1 & )
for _ in $(seq 1 20); do curl -s -o /dev/null http://127.0.0.1:8099/ && break; sleep 0.5; done
FILTER='Sunday Morning Service|Sunday AM Worship'
ARGS=(--filter="$FILTER" --skip-thumbnails)

echo "== 1. Status"
out="$(wp pn-sermons status 2>&1)"
expect_contains "reports the key is set" "$out" "set in wp-config.php"
expect_lacks "never prints the key" "$out" "$KEY"
expect_contains "shows the uploads playlist" "$out" "UUtest00000000000000000A"

echo "== 2. Dry run changes nothing"
wp option add pn_sermons_youtube_import_lock 1 --autoload=no --quiet
wp pn-sermons backfill --dry-run "${ARGS[@]}" >/dev/null 2>&1
expect_eq "a concurrent import creates nothing" "$(count)" "0"
expect_eq "a concurrent import does not release the other import's lock" "$(wp option get pn_sermons_youtube_import_lock)" "1"
wp option delete pn_sermons_youtube_import_lock --quiet
out="$(wp pn-sermons backfill --dry-run "${ARGS[@]}" 2>&1)"; [[ -n "${DEBUG:-}" ]] && echo "$out" | head -30
expect_contains "says it is a dry run" "$out" "Dry run: nothing was created"
expect_contains "would create" "$out" "would create"
expect_eq "no sermons exist after a dry run" "$(count)" "0"
expect_eq "the lock is released after import" "$(wp option get pn_sermons_youtube_import_lock 2>/dev/null || echo absent)" "absent"
expect_contains "read every page (more than 100 videos)" "$out" "page 3:"
expect_lacks "never prints the key" "$out" "$KEY"
json="$(wp pn-sermons backfill --dry-run "${ARGS[@]}" --format=json)"
if php -r 'exit( is_array( json_decode( stream_get_contents( STDIN ), true ) ) ? 0 : 1 );' <<<"$json"; then
	ok "JSON output contains only a valid report"
else
	bad "JSON output contains only a valid report"
fi
csv="$(wp pn-sermons backfill --dry-run "${ARGS[@]}" --format=csv)"
if php -r 'exit( fgetcsv( STDIN ) === array( "date", "id", "action", "reason", "speaker", "series", "title", "scripture" ) ? 0 : 1 );' <<<"$csv"; then
	ok "CSV output starts with the report header"
else
	bad "CSV output starts with the report header"
fi

echo "== 3. The real import"
out="$(wp pn-sermons backfill "${ARGS[@]}" 2>&1)"
expect_contains "finishes" "$out" "Done."
expect_eq "117 sermons: 112 services + 4 specials, including a duplicate Vision Sunday recording" "$(count)" "117"
expect_eq "3 hand-described sermons published" "$(count publish)" "3"
expect_eq "everything else is a draft" "$(count draft)" "114"
expect_contains "the Short is skipped" "$out" "too short"
expect_contains "the live stream is skipped" "$out" "live stream not finished"
expect_contains "the private video is skipped" "$out" "not public"
expect_contains "the youth clip does not match the filter" "$out" "title does not match the filter"
expect_eq "the duplicate is one sermon" "$(wp post list --post_type=pn_sermon --post_status=any --meta_key=pn_youtube_id --meta_value=SVC00000005 --format=count)" "1"
expect_lacks "never prints the key" "$out" "$KEY"

echo "== 4. Speakers, series, scripture, dates"
expect_eq "series is created with its full name" "$(wp term list pn_series --field=name --slug=holiness-in-the-house-a-study-in-titus)" "Holiness in the House: A Study in Titus"
expect_eq "the series has both of its sermons" "$(wp eval 'echo count( get_objects_in_term( get_term_by( "slug", "holiness-in-the-house-a-study-in-titus", "pn_series" )->term_id, "pn_series" ) );')" "2"
for s in "Cindy Olson" "Rob Hammann"; do
	expect_contains "speaker $s exists" "$(wp term list pn_speaker --field=name)" "$s"
done
pent="$(by_yt Nll1UzWZHQA)"
expect_eq "Pentecost keeps its real title" "$(wp post get "$pent" --field=post_title)" "Pentecost Promised, Proclaimed, and Practiced"
expect_contains "Pentecost keeps its real description" "$(wp post get "$pent" --field=post_content)$(wp post get "$pent" --field=post_excerpt)" "In Acts 1–3, we see Pentecost promised"
expect_eq "Pentecost is published" "$(wp post get "$pent" --field=post_status)" "publish"
expect_eq "Pentecost scripture" "$(meta "$pent" pn_scripture_ref)" "Acts 1–3"
expect_eq "Pentecost is dated its service day" "$(wp post get "$pent" --field=post_date | cut -c1-10)" "2026-05-24"
hol="$(by_yt vTKuOza2c68)"
expect_eq "the featured series sermon is a draft for review" "$(wp post get "$hol" --field=post_status)" "draft"
expect_eq "its title is the message, not the video title" "$(wp post get "$hol" --field=post_title)" "The Crisis and Process of Grace"
expect_eq "its speaker" "$(wp post term list "$hol" pn_speaker --field=name)" "Cindy Olson"
vis="$(by_yt VISION00001)"
expect_eq "Vision Sunday is matched by its service date" "$(wp post get "$vis" --field=post_title)" "Vision Sunday 1: Living Out Our Mission"
vis_repeat="$(by_yt VISION00002)"
expect_eq "a second Vision Sunday recording does not reuse the hand-written title" "$(wp post get "$vis_repeat" --field=post_title)" "Sunday Morning Service May 7, 2023 (repeat)"
expect_eq "a second Vision Sunday recording stays a draft" "$(wp post get "$vis_repeat" --field=post_status)" "draft"
expect_eq "a second Vision Sunday recording does not claim the old address" "$(meta "$vis_repeat" pn_legacy_slug 2>/dev/null || true)" ""
expect_eq "a title without a date uses videos.list publication date" "$(wp post get "$(by_yt SVC00000000)" --field=post_date | cut -c1-10)" "2026-10-20"
msg="$(by_yt 3JdIij39S2g)"
expect_eq "God's Messenger keeps its new slug" "$(wp post get "$msg" --field=post_name)" "gods-messenger-malachi-3-1-4"
expect_eq "and remembers the old one" "$(meta "$msg" pn_legacy_slug)" "gods-messenger-malachi-31-4"
plain="$(by_yt SVC00000005)"
expect_eq "a plain service with no details is a draft with a reviewer note" "$(wp post get "$plain" --field=post_status)" "draft"
expect_eq "it keeps the YouTube title" "$(meta "$plain" pn_youtube_title)" "$(wp post get "$plain" --field=post_title)"

echo "== 5. Old addresses redirect to the new sermons"
expect_eq "old God's Messenger address → new sermon" "$(wp eval 'echo pn_legacy_url_action("sermons-t/gods-messenger-malachi-31-4")["url"] ?? "none";')" "$(wp post get "$msg" --field=url)"
expect_eq "a draft is never revealed (falls back to the sermons list)" "$(wp eval 'echo pn_legacy_url_action("sermons-t/'"$(wp post get "$plain" --field=post_name)"'")["url"] ?? "none";')" "http://127.0.0.1:8080/sermons/"

echo "== 6. Safe to run again"
out="$(wp pn-sermons backfill "${ARGS[@]}" 2>&1)"
expect_eq "second run creates nothing" "$(count)" "117"
expect_contains "says so" "$out" "already imported"
wp post update "$plain" --post_title="Edited by staff" --quiet
trashed="$(by_yt SVC00000006)"
wp post update "$trashed" --post_status=trash --quiet
out="$(wp pn-sermons backfill "${ARGS[@]}" 2>&1)"
expect_eq "a sermon moved to the trash is not brought back (116 live + 1 in the trash)" "$(count) $(count trash)" "116 1"
expect_eq "an edited sermon is untouched" "$(wp post get "$plain" --field=post_title)" "Edited by staff"
expect_eq "no duplicate speakers or series" "$(wp term list pn_series --field=slug | sort | uniq -d | wc -l)" "0"
wp post delete "$trashed" --force --quiet
wp pn-sermons backfill "${ARGS[@]}" >/dev/null 2>&1
expect_eq "a permanently deleted sermon remains rejected" "$(by_yt SVC00000006)" ""
expect_eq "the rejected video is not recreated" "$(count)" "116"

echo "== 7. Options"
wipe
out="$(wp pn-sermons backfill "${ARGS[@]}" --limit=5 2>&1)"
expect_eq "--limit stops at five" "$(count)" "5"
wipe
wp pn-sermons backfill "${ARGS[@]}" --since=2026-10-01 --until=2026-10-31 >/dev/null 2>&1
expect_eq "--since/--until: the three October services (the fourth is a Short)" "$(count)" "3"
wipe
wp pn-sermons backfill "${ARGS[@]}" --status=publish --limit=2 >/dev/null 2>&1
expect_eq "--status=publish publishes" "$(count publish)" "2"

echo "== 8. Failures are explained and never leak the key"
echo quota > /tmp/yt-mode
out="$(wp pn-sermons backfill "${ARGS[@]}" 2>&1)"
expect_contains "quota" "$out" "quota is used up"
expect_lacks "no key in the quota error" "$out" "$KEY"
echo badjson > /tmp/yt-mode
out="$(wp pn-sermons backfill "${ARGS[@]}" 2>&1)"
expect_contains "bad JSON" "$out" "not valid JSON"
: > /tmp/yt-mode
out="$(YT_KEY=WRONGKEYWRONGKEYWRONGKEY1 wp pn-sermons backfill "${ARGS[@]}" 2>&1)"
expect_contains "wrong key" "$out" "does not accept the API key"
expect_lacks "no key in the wrong-key error" "$out" "WRONGKEYWRONGKEYWRONGKEY1"
out="$(wp pn-sermons backfill "${ARGS[@]}" --channel=UCnotthere0000000000000A 2>&1)"
expect_contains "unknown channel" "$out" "cannot find that playlist"
out="$(wp pn-sermons backfill "${ARGS[@]}" --since=yesterday 2>&1)"
expect_contains "bad date" "$out" "--since must look like"
out="$(YT_KEY=short wp pn-sermons backfill "${ARGS[@]}" 2>&1)"
expect_contains "a malformed key is refused before any request" "$out" "No YouTube API key"

echo "== 9. The key and imported text stay private"
expect_eq "the key is not in the database" "$(mysql -h pn-sermon-db -uroot -proot wp -N -e "select count(*) from wp_options o, wp_postmeta m where o.option_value like '%$KEY%' or m.meta_value like '%$KEY%'")" "0"
expect_eq "the REST API does not expose the raw YouTube description" "$(wp eval 'echo strpos(wp_json_encode(rest_do_request(new WP_REST_Request("GET","/wp/v2/pn_sermon"))->get_data()), "pn_youtube_description") === false ? "hidden" : "exposed";')" "hidden"
echo
echo "Passed: $PASS   Failed: $FAIL"
[[ $FAIL -eq 0 ]]
