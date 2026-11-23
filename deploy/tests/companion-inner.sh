#!/usr/bin/env bash
# Runs INSIDE the wordpress:cli container (see companion-check.sh). Real WordPress + our sermon plugin + fakes for
# yt-dlp (fixtures/fake-yt-dlp.sh), Claude and Pexels (fixtures/companion-mock.php) and image downloads
# (fixtures/pn-test-pexels-images.php). Emails are captured, never sent. Throwaway; nothing persists.
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
CLAUDE_KEY=sk-test-CLAUDEKEY-0000
PEXELS_KEY=PEXELSTESTKEY0000
HOOK=pn_sermons_draft_companion

echo "== Setup: real WordPress, the sermon plugin, fake yt-dlp, Claude and Pexels"
wp core download --quiet >/dev/null || setup_fail "core download"
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=pn-companion-db --skip-check --quiet
for _ in $(seq 1 45); do mysql -h pn-companion-db -uroot -proot -e 'select 1' wp >/dev/null 2>&1 && break; sleep 2; done
wp core install --url=http://127.0.0.1:8080 --title=Test --admin_user=boss --admin_password='Admin-pass-1!' --admin_email=boss@example.test --skip-email --quiet || setup_fail "core install"
wp config set WP_DEBUG true --raw --quiet
mkdir -p /tmp/fakebin && cp /repo/deploy/tests/fixtures/fake-yt-dlp.sh /tmp/fakebin/yt-dlp && chmod +x /tmp/fakebin/yt-dlp
cat >> wp-config.php <<PHPEND
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG_LOG', '/tmp/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'PN_ANTHROPIC_API_KEY', '$CLAUDE_KEY' );
define( 'PN_ANTHROPIC_API_URL', 'http://127.0.0.1:8098/v1/messages' );
define( 'PN_PEXELS_API_KEY', '$PEXELS_KEY' );
define( 'PN_PEXELS_API_BASE', 'http://127.0.0.1:8098/v1' );
define( 'PN_YTDLP_PATH', '/tmp/fakebin/yt-dlp' );
define( 'PN_YTDLP_TIMEOUT', 3 );
PHPEND
mkdir -p wp-content/plugins wp-content/mu-plugins
cp -r /repo/wp-content/plugins/pn-sermons wp-content/plugins/
I=wp-content/plugins/pn-sermons/includes
break_it() {  # file, exact old text, new text (fails loudly if the old text is not there)
	php -r '[$f, $a, $b] = array_slice($argv, 1); $t = file_get_contents($f); if (substr_count($t, $a) !== 1) { exit(1); } file_put_contents($f, str_replace($a, $b, $t));' "$@" || setup_fail "break '$BREAK' did not apply"
}
case "${BREAK:-}" in
	"") ;;
	publish)     break_it $I/class-companion.php "'post_status'   => 'draft'," "'post_status'   => 'publish'," ;;
	not-recent)  break_it $I/class-companion.php "return \$date && \$date->getTimestamp() >= time() - self::RECENT_DAYS * DAY_IN_SECONDS;" "return true;" ;;
	no-kses)     break_it $I/class-companion.php "\$html = wp_kses(" "\$html = (fn( \$h, \$a ) => \$h)(" ;;
	any-host)    break_it $I/class-pexels.php "&& self::IMAGE_HOST === strtolower( (string) ( \$parts['host'] ?? '' ) )" "" ;;
	ask-twice)   break_it $I/class-companion.php "if ( get_post_meta( \$sermon_id, 'pn_transcript_asked', true ) ) {" "if ( false ) {" ;;
	no-lock)     break_it $I/class-companion.php "if ( 1 !== (int) \$wpdb->get_var(" "if ( 0 && 1 !== (int) \$wpdb->get_var(" ;;
	keep-retry)  break_it $I/class-companion.php "			wp_clear_scheduled_hook( self::HOOK, array( \$sermon_id ) );" "" ;;
	paste-any)   break_it $I/class-companion.php "return \$user && ( current_user_can( 'edit_post', \$sermon_id ) || self::speaker_user( \$sermon_id ) === \$user );" "return (bool) \$user;" ;;
	*) setup_fail "unknown BREAK" ;;
esac
cp /repo/wp-content/mu-plugins/pn-site.php wp-content/mu-plugins/
cp /repo/deploy/tests/fixtures/pn-test-mail-capture.php /repo/deploy/tests/fixtures/pn-test-pexels-images.php wp-content/mu-plugins/
wp rewrite structure '/%postname%/' --hard --quiet
wp plugin activate pn-sermons --quiet || setup_fail "activate pn-sermons"
( php -S 127.0.0.1:8098 /repo/deploy/tests/fixtures/companion-mock.php >/tmp/mock.log 2>&1 & )
for _ in $(seq 1 20); do curl -s -o /dev/null http://127.0.0.1:8098/ && break; sleep 0.5; done

CINDY="$(wp user create cindy cindy@example.test --role=author --porcelain)" || setup_fail "user"
SPK="$(wp term create pn_speaker 'Cindy Olson' --slug=cindy-olson --porcelain)" || setup_fail "speaker"
wp term meta update "$SPK" pn_user_id "$CINDY" --quiet
wp option update pn_sermons_settings '{"companion_enabled":1,"notify_emails":"office@example.test"}' --format=json --quiet

cat > /tmp/mk.php <<'PHPEND'
<?php
// args: title, youtube id, days ago, speaker slug ('' for none), import source
[ $title, $vid, $days, $speaker, $source ] = $args + array( '', '', '0', '', 'rss' );
$date = wp_date( 'Y-m-d 12:00:00', time() - (int) $days * DAY_IN_SECONDS );
// Like the importer: the service date, with its GMT twin, so publishing later keeps it.
$id   = wp_insert_post( array( 'post_type' => 'pn_sermon', 'post_status' => 'draft', 'post_title' => $title, 'post_date' => $date, 'post_date_gmt' => get_gmt_from_date( $date ),
	'meta_input' => array( 'pn_youtube_id' => $vid, 'pn_import_source' => $source ) ) );
if ( $speaker ) {
	wp_set_object_terms( $id, $speaker, 'pn_speaker' );
}
do_action( 'pn_sermons_imported', $id, array( 'id' => $vid ) );
echo $id;
PHPEND
mk()        { wp eval-file /tmp/mk.php "$@"; }
scheduled() { wp eval "echo wp_next_scheduled( '$HOOK', array( $1 ) ) ? 'yes' : 'no';"; }
run_job()   { wp eval "PN_Sermons\\Companion::draft( $1 );"; }
draft_of()  { wp post meta get "$1" pn_companion_post 2>/dev/null; }
mails()     { wc -l < /tmp/mail.log 2>/dev/null | tr -d ' ' || echo 0; }
last_mail() { tail -1 /tmp/mail.log 2>/dev/null; }
mode()      { echo "$2" > "/tmp/$1-mode"; }
: > /tmp/mail.log

echo "== 1. A new sermon from the daily import is queued, old and backfilled ones are not"
S1="$(mk 'Christ the King' 'AAAAAAAAAA1' 1 cindy-olson rss)"
expect_eq "a recent imported sermon with a known speaker is queued" "$(scheduled "$S1")" "yes"
OLD="$(mk 'Old sermon' 'AAAAAAAAAA2' 60 cindy-olson rss)"
expect_eq "a sermon from two months ago is not" "$(scheduled "$OLD")" "no"
BACK="$(mk 'Backfilled' 'AAAAAAAAAA3' 1 cindy-olson api)"
expect_eq "a sermon from the backfill command is not" "$(scheduled "$BACK")" "no"
NOSPK="$(mk 'Who preached' 'AAAAAAAAAA4' 1 '' rss)"
expect_eq "a sermon with no recognised speaker waits" "$(scheduled "$NOSPK")" "no"
wp post term set "$NOSPK" pn_speaker cindy-olson --by=slug --quiet
wp post update "$NOSPK" --post_status=publish --quiet
expect_eq "...and is queued when staff publish it" "$(scheduled "$NOSPK")" "yes"
wp post update "$OLD" --post_status=publish --quiet
expect_eq "publishing an old sermon queues nothing" "$(scheduled "$OLD")" "no"

echo "== 2. The job drafts a post"
run_job "$S1"
P1="$(draft_of "$S1")"
[[ -n "$P1" ]] && ok "the sermon records its draft ($P1)" || bad "no draft was made"
expect_eq "the post is a draft" "$(wp post get "$P1" --field=post_status 2>/dev/null)" "draft"
expect_eq "the preacher is the author" "$(wp post get "$P1" --field=post_author 2>/dev/null)" "$CINDY"
expect_eq "the title is Claude's" "$(wp post get "$P1" --field=post_title 2>/dev/null)" "What Kind of King Is Jesus?"
expect_eq "it is filed under Sermon Companion" "$(wp post term list "$P1" category --field=name 2>/dev/null)" "Sermon Companion"
body="$(wp post get "$P1" --field=post_content 2>/dev/null)"
expect_contains "paragraphs are editor blocks" "$body" "<!-- wp:paragraph -->"
expect_contains "the closing question survives" "$body" "<strong>What would it look like to let Jesus be King?</strong>"
expect_lacks "no script from the model" "$body" "<script"
expect_lacks "no image tags from the model" "$body" "<img"
expect_contains "it links to the full message on YouTube (the sermon is not published yet)" "$body" 'https://www.youtube.com/watch?v=AAAAAAAAAA1'
expect_contains "the transcript was saved on the sermon" "$(wp post meta get "$S1" pn_transcript)" "anchor for the soul"
expect_lacks "sound tags were dropped from the transcript" "$(wp post meta get "$S1" pn_transcript)" "[Music]"

echo "== 3. What was sent to Claude and yt-dlp"
req="$(grep '"/v1/messages"' /tmp/companion-requests.log | tail -1)"
expect_contains "the transcript went to Claude" "$req" "anchor for the soul"
expect_contains "with the standalone-post instructions" "$req" "It is a blog post, not a report of the sermon"
expect_contains "and the preacher's name" "$req" "Preacher: Cindy Olson"
args="$(cat /tmp/ytdlp-args.log)"
expect_contains "yt-dlp is told to skip the video" "$args" "--skip-download"
expect_eq "the video address is the last argument, after --" "$(tail -2 /tmp/ytdlp-args.log | tr '\n' ' ')" "-- https://www.youtube.com/watch?v=AAAAAAAAAA1 "
expect_lacks "yt-dlp does not inherit the site's environment" "$(cat /tmp/ytdlp-env.log)" "WP_CLI"

echo "== 4. The featured image"
IMG="$(wp post meta get "$P1" _thumbnail_id 2>/dev/null)"
[[ -n "$IMG" ]] && ok "the post has a featured image" || bad "no featured image"
expect_eq "it is the first wide, large Pexels photo on Pexels' own host" "$(wp post meta get "$IMG" pn_pexels_id 2>/dev/null)" "777"
expect_eq "the photographer is credited in the caption" "$(wp post get "$IMG" --field=post_excerpt 2>/dev/null)" "Photo by Jane Doe on Pexels"
expect_eq "the alt text is Pexels' description" "$(wp post meta get "$IMG" _wp_attachment_image_alt 2>/dev/null)" "Boats at anchor in a harbor at sunrise"
dl="$(cat /tmp/pexels-downloads.log)"
expect_lacks "nothing was downloaded from another host" "$dl" "evil.example"
expect_lacks "nothing was downloaded over plain http" "$dl" "plain-http"
expect_contains "Claude's search words were used" "$(grep '/v1/search' /tmp/companion-requests.log | tail -1)" "anchor%20harbor%20morning"

echo "== 5. The review email"
m="$(last_mail)"
expect_contains "goes to the preacher" "$m" "cindy@example.test"
expect_contains "and the office" "$m" "office@example.test"
expect_contains "names the post" "$m" "Blog post ready for your review: What Kind of King Is Jesus?"
expect_contains "links to the editor" "$m" "post.php?post=$P1&action=edit"
expect_contains "lists what to check" "$m" "Hebrews 6:19"
expect_contains "explains the stock photo" "$m" "free stock photo from Pexels"

echo "== 6. Running again changes nothing"
n="$(mails)"
run_job "$S1"
wp eval "wp_clear_scheduled_hook( '$HOOK', array( $S1 ) ); PN_Sermons\\Companion::schedule( $S1, 1 );"
expect_eq "no second draft" "$(wp post list --post_type=post --post_status=any --meta_key=pn_sermon_id --meta_value="$S1" --format=count)" "1"
expect_eq "no second email" "$(mails)" "$n"
expect_eq "and nothing new is queued" "$(scheduled "$S1")" "no"

echo "== 7. YouTube blocks the server: ask a person, once"
mode ytdlp blocked
S2="$(mk 'Blocked week' 'BBBBBBBBBB1' 2 cindy-olson rss)"
n="$(mails)"; run_job "$S2"
expect_eq "no draft" "$(draft_of "$S2")" ""
expect_eq "one email" "$(mails)" "$((n+1))"
expect_contains "asking for the transcript" "$(last_mail)" "Transcript needed for this week"
expect_contains "with a link to paste it" "$(last_mail)" "page=pn-paste-transcript&sermon=$S2"
expect_contains "and the YouTube link" "$(last_mail)" "watch?v=BBBBBBBBBB1"
run_job "$S2"
expect_eq "running again does not email again" "$(mails)" "$((n+1))"

echo "== 8. Pasting the transcript makes the draft"
mode ytdlp ok
wp eval-file - "$S2" --user=boss <<'PHPEND'
<?php
$id = (int) $args[0];
$_POST['pn_transcript_nonce'] = wp_create_nonce( 'pn_transcript' );
$_POST['pn_transcript']       = wp_slash( str_repeat( 'Hope is an anchor for the soul, firm and secure. ', 20 ) );
wp_update_post( array( 'ID' => $id, 'post_title' => 'Blocked week' ) );
PHPEND
expect_contains "the pasted transcript is saved" "$(wp post meta get "$S2" pn_transcript)" "anchor for the soul, firm and secure"
expect_eq "and the job is queued" "$(scheduled "$S2")" "yes"
run_job "$S2"
P2="$(draft_of "$S2")"
[[ -n "$P2" ]] && ok "a draft is made from it" || bad "no draft from the pasted transcript"
expect_eq "a photo not used before is chosen" "$(wp post meta get "$(wp post meta get "$P2" _thumbnail_id)" pn_pexels_id 2>/dev/null)" "778"
wp eval-file - "$S2" --user=cindy <<'PHPEND'
<?php
$_POST['pn_transcript_nonce'] = 'wrong';
$_POST['pn_transcript']       = 'tampered';
wp_update_post( array( 'ID' => (int) $args[0], 'post_title' => 'Blocked week' ) );
PHPEND
expect_lacks "a save without the right nonce changes nothing" "$(wp post meta get "$S2" pn_transcript)" "tampered"

echo "== 9. No captions yet: try again later, then ask"
mode ytdlp none
S3="$(mk 'Captions pending' 'CCCCCCCCCC1' 1 cindy-olson rss)"
n="$(mails)"; run_job "$S3"
expect_eq "no draft" "$(draft_of "$S3")" ""
expect_eq "a retry is queued" "$(scheduled "$S3")" "yes"
expect_eq "nobody is emailed yet" "$(mails)" "$n"
for _ in 1 2 3; do wp cron event delete "$HOOK" --quiet >/dev/null 2>&1; run_job "$S3"; done
expect_eq "after four tries, a person is asked" "$(mails)" "$((n+1))"
mode ytdlp slow
S4="$(mk 'Hangs' 'DDDDDDDDDD1' 1 cindy-olson rss)"
start=$(date +%s); run_job "$S4"; took=$(( $(date +%s) - start ))
[[ $took -lt 30 ]] && ok "a hanging yt-dlp is stopped (${took}s)" || bad "yt-dlp was not stopped (${took}s)"
expect_eq "and is retried later" "$(scheduled "$S4")" "yes"
out="$(wp eval 'print_r( PN_Sermons\Transcript::fetch( "x; touch /tmp/pwned #" ) );')"
expect_contains "a bad video ID is refused" "$out" "Not a YouTube video ID"
[[ ! -e /tmp/pwned ]] && ok "and nothing ran" || bad "a command ran"
mode ytdlp ok

echo "== 10. Claude or Pexels trouble"
mode claude garbage
S5="$(mk 'Bad answer' 'EEEEEEEEEE1' 1 cindy-olson rss)"
run_job "$S5"
expect_eq "an unreadable answer makes no draft" "$(draft_of "$S5")" ""
expect_eq "and is retried" "$(scheduled "$S5")" "yes"
mode claude error
wp cron event delete "$HOOK" --quiet >/dev/null 2>&1; run_job "$S5"
expect_eq "an API error makes no draft" "$(draft_of "$S5")" ""
mode claude ok
mode pexels none
wp cron event delete "$HOOK" --quiet >/dev/null 2>&1; run_job "$S5"
P5="$(draft_of "$S5")"
[[ -n "$P5" ]] && ok "with no stock photo the draft is still made" || bad "no draft without a photo"
expect_contains "and the email says so" "$(last_mail)" "No stock photo was found"
mode pexels ok

echo "== 11. Switched off, nothing happens"
wp option update pn_sermons_settings '{"companion_enabled":0}' --format=json --quiet
S6="$(mk 'Off' 'FFFFFFFFFF1' 1 cindy-olson rss)"
expect_eq "nothing is queued" "$(scheduled "$S6")" "no"
run_job "$S6"
expect_eq "and the job does nothing" "$(draft_of "$S6")" ""
wp option update pn_sermons_settings '{"companion_enabled":1}' --format=json --quiet

echo "== 12. Prompt, parsing and safety details"
# The FEAT-1 default prompt, as a site that saved Settings > Sermons before FEAT-9 would have it stored.
LEGACY_PROMPT="$(cat /repo/deploy/tests/fixtures/legacy-companion-prompt.txt)" wp eval 'update_option( "pn_sermons_settings", array( "companion_enabled" => 1, "companion_prompt" => sanitize_textarea_field( getenv( "LEGACY_PROMPT" ) ) ) );'
expect_contains "a site on the old default prompt gets the new one" "$(wp eval 'echo PN_Sermons\Companion::prompt();')" "not a report of the sermon"
wp eval 'update_option( "pn_sermons_settings", array( "companion_enabled" => 1, "companion_prompt" => "Write like a pirate." ) );'
expect_eq "a prompt staff changed is kept" "$(wp eval 'echo PN_Sermons\Companion::prompt();')" "Write like a pirate."
expect_eq "http is not a safe image address" "$(wp eval 'echo PN_Sermons\Pexels::safe_image_url("http://images.pexels.com/a.jpg") ? "y" : "n";')" "n"
expect_eq "another host is not" "$(wp eval 'echo PN_Sermons\Pexels::safe_image_url("https://images.pexels.com.evil.example/a.jpg") ? "y" : "n";')" "n"
expect_eq "a port is not" "$(wp eval 'echo PN_Sermons\Pexels::safe_image_url("https://images.pexels.com:8443/a.jpg") ? "y" : "n";')" "n"
expect_eq "classify: bot check means blocked" "$(wp eval 'echo PN_Sermons\Transcript::classify( 1, "ERROR: Sign in to confirm you\u{2019}re not a bot" );')" "blocked"
expect_eq "classify: rate limit means blocked" "$(wp eval 'echo PN_Sermons\Transcript::classify( 1, "HTTP Error 429: Too Many Requests" );')" "blocked"
expect_eq "classify: a network error is worth a retry" "$(wp eval 'echo PN_Sermons\Transcript::classify( 1, "Unable to download webpage: timed out" );')" "failed"
expect_eq "parse: words around the JSON are tolerated" "$(wp eval 'echo PN_Sermons\Companion::parse_json( "Here you go: {\"title\":\"T\",\"body_html\":\"<p>B</p>\"} Enjoy" )["title"];')" "T"
expect_eq "parse: a missing body is refused" "$(wp eval 'var_export( PN_Sermons\Companion::parse_json( "{\"title\":\"T\"}" ) );')" "NULL"
out="$(wp pn-sermons status 2>&1)"
expect_contains "status reports the Claude key" "$out" "Claude API key:    set in wp-config.php (not shown)"
expect_contains "status reports yt-dlp" "$out" "yt-dlp:            /tmp/fakebin/yt-dlp"
out="$(wp pn-sermons draft-post "$S1" 2>&1)"
expect_contains "draft-post refuses a sermon that already has a draft" "$out" "already has a blog draft"

echo "== 13. Only one draft at a time, and pasting the transcript"
mode ytdlp ok
S7="$(mk 'Locked' 'GGGGGGGGGG1' 1 cindy-olson rss)"
wp eval-file - "$S7" <<'PHPEND'
<?php
// Hold the draft lock from a second database connection, as an overlapping run would, then try to draft.
global $wpdb;
$id   = (int) $args[0];
$name = 'pn_companion_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 12 ) . '_' . $id;
$other = mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
mysqli_query( $other, "SELECT GET_LOCK('" . $name . "', 5)" );
PN_Sermons\Companion::draft( $id );
mysqli_close( $other );
PHPEND
expect_eq "while another run holds the lock, nothing is drafted" "$(draft_of "$S7")" ""
run_job "$S7"
[[ -n "$(draft_of "$S7")" ]] && ok "once it is free, the draft is made" || bad "no draft after the lock was released"

wp user create other other@example.test --role=author --quiet
mode ytdlp none
S8="$(mk 'Paste me' 'HHHHHHHHHH1' 1 cindy-olson rss)"
wp eval "wp_clear_scheduled_hook( '$HOOK', array( $S8 ) );"   # as when cron runs the import's event
run_job "$S8"
later="$(wp eval "echo wp_next_scheduled( '$HOOK', array( $S8 ) ) - time();")"
[[ "$later" -gt 3600 ]] && ok "a caption retry is queued hours out (${later}s)" || bad "no distant retry queued (${later}s)"
paste() {  # user, sermon, nonce ('good' or anything else), text
	wp eval-file - "$2" "$3" "$4" --user="$1" <<'PHPEND' 2>&1
<?php
[ $id, $nonce, $text ] = array( (int) $args[0], $args[1], $args[2] );
$_POST['sermon'] = $_REQUEST['sermon'] = (string) $id;
$_POST['pn_transcript'] = wp_slash( $text );
$_REQUEST['_wpnonce'] = 'good' === $nonce ? wp_create_nonce( PN_Sermons\Companion::PASTE_PAGE . '_' . $id ) : 'nope';
add_filter( 'wp_redirect', function () { echo 'REDIRECTED'; return false; } );
PN_Sermons\Companion::handle_paste();
PHPEND
}
LONG="$(printf 'We have this hope as an anchor for the soul. %.0s' $(seq 1 20))"
out="$(paste other "$S8" good "$LONG")"
expect_contains "an Author who did not preach it may not paste" "$out" "paste a transcript for this sermon"
expect_eq "...and nothing is saved" "$(wp post meta get "$S8" pn_transcript 2>/dev/null)" ""
out="$(paste cindy "$S8" bad "$LONG")"
expect_lacks "a wrong nonce is refused" "$out" "REDIRECTED"
expect_eq "...and nothing is saved" "$(wp post meta get "$S8" pn_transcript 2>/dev/null)" ""
expect_eq "the preacher cannot edit the imported sermon itself (Author)" "$(wp eval "echo user_can( $CINDY, 'edit_post', $S8 ) ? 'y' : 'n';")" "n"
out="$(paste cindy "$S8" good "$LONG")"
expect_contains "but the linked preacher may paste its transcript" "$out" "REDIRECTED"
expect_contains "...and it is saved" "$(wp post meta get "$S8" pn_transcript)" "anchor for the soul"
soon="$(wp eval "echo wp_next_scheduled( '$HOOK', array( $S8 ) ) - time();")"
[[ "$soon" -le 120 ]] && ok "the queued retry is replaced by a draft in a minute (${soon}s)" || bad "the draft still waits for the retry (${soon}s)"
expect_eq "only one job is queued" "$(wp eval "\$n = 0; foreach ( _get_cron_array() as \$hooks ) { foreach ( \$hooks['$HOOK'] ?? array() as \$e ) { \$n += ( array( $S8 ) === \$e['args'] ) ? 1 : 0; } } echo \$n;")" "1"
expect_contains "the transcript-needed email links to the paste page" "$(grep 'Transcript needed' /tmp/mail.log | tail -1)" "page=pn-paste-transcript"
mode ytdlp ok

echo "== 14. Keys stay private"
[[ -s /tmp/debug.log ]] && ok "the plugin's log was written (so the next checks mean something)" || bad "no log at /tmp/debug.log"
all="$(cat /tmp/debug.log /tmp/mail.log /tmp/ytdlp-env.log /tmp/ytdlp-args.log 2>/dev/null)"
expect_lacks "no Claude key in logs, emails or yt-dlp's environment" "$all" "$CLAUDE_KEY"
expect_lacks "no Pexels key in them either" "$all" "$PEXELS_KEY"
expect_eq "no key in the database" "$(mysql -h pn-companion-db -uroot -proot wp -N -e "select (select count(*) from wp_options where option_value like '%CLAUDEKEY%' or option_value like '%$PEXELS_KEY%') + (select count(*) from wp_postmeta where meta_value like '%CLAUDEKEY%' or meta_value like '%$PEXELS_KEY%')")" "0"
wp post meta update "$OLD" pn_transcript "SECRET-TRANSCRIPT-TEXT" --quiet
expect_eq "a published sermon's transcript is not in the REST API" "$(wp eval 'echo strpos( wp_json_encode( rest_do_request( new WP_REST_Request( "GET", "/wp/v2/pn_sermon" ) )->get_data() ), "SECRET-TRANSCRIPT" ) === false ? "hidden" : "exposed";')" "hidden"
expect_eq "no drafted post was ever published" "$(wp post list --post_type=post --post_status=publish --meta_key=pn_sermon_id --format=count)" "0"
[[ -n "${DEBUG:-}" ]] && cat /tmp/debug.log
echo
echo "Passed: $PASS   Failed: $FAIL"
[[ $FAIL -eq 0 ]]
