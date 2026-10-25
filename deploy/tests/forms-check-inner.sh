#!/usr/bin/env bash
# shellcheck disable=SC2015,SC2016  # test helpers: "a && ok || bad" is intended; PHP for `wp eval` is deliberately single-quoted
# Runs INSIDE the wordpress:cli container (see forms-check.sh). Throwaway.
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
sql() { mysql -h pn-forms-db -uroot -proot wp -N -e "$1" 2>/dev/null; }
FF_VERSION="$(awk '$1 == "fluentform" {print $2}' /repo/plugins.txt)"
[[ -n "$FF_VERSION" ]] || setup_fail "plugins.txt does not pin fluentform"
CONTACT_TO=hello@example.test
PRAYER_TO="pastor.one@example.test,pastor.two@example.test"

echo "== Setup: real WordPress, our theme, Fluent Forms $FF_VERSION"
wp core download --quiet >/dev/null || setup_fail "core download"
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=pn-forms-db --skip-check --quiet
for _ in $(seq 1 45); do mysql -h pn-forms-db -uroot -proot -e 'select 1' wp >/dev/null 2>&1 && break; sleep 2; done
wp core install --url="$BASE" --title="Pella Nazarene" --admin_user=boss --admin_password='Admin-pass-1!' --admin_email=boss@example.test --skip-email --quiet || setup_fail "core install"
wp theme install twentytwentyfive --quiet || setup_fail "installing Twenty Twenty-Five"
mkdir -p wp-content/mu-plugins
cp -R /repo/wp-content/themes/pella-nazarene wp-content/themes/
cp /repo/wp-content/mu-plugins/pn-forms.php /repo/deploy/tests/fixtures/pn-test-mail-capture.php wp-content/mu-plugins/
wp theme activate pella-nazarene --quiet || setup_fail "activating our theme"
wp plugin install fluentform --version="$FF_VERSION" --activate --quiet || setup_fail "installing Fluent Forms"
wp rewrite structure '/%postname%/' --hard --quiet
wp post delete 1 2 3 --force --quiet >/dev/null 2>&1

# A copy of the scripts and form files, so BREAK can damage them without touching the repo.
S=/tmp/repo; mkdir -p "$S"; cp -R /repo/scripts /repo/deploy "$S"/ 2>/dev/null; rm -rf "$S"/deploy/tests "$S"/deploy/nginx
break_it() {  # file, exact old text, new text
	php -r '[$f, $a, $b] = array_slice($argv, 1); $t = file_get_contents($f); if (substr_count($t, $a) !== 1) { exit(1); } file_put_contents($f, str_replace($a, $b, $t));' "$@" || setup_fail "break '$BREAK' did not apply"
}
case "${BREAK:-}" in
	"") ;;
	prayer-kept)  break_it wp-content/mu-plugins/pn-forms.php "'fluentform/submission_inserted'," "'fluentform/submission_inserted_disabled'," ;;
	no-timing)    break_it wp-content/mu-plugins/pn-forms.php "< (int) apply_filters( 'pn_forms_min_milliseconds', 3000 )" "< 0" ;;
	no-rate-limit) break_it wp-content/mu-plugins/pn-forms.php '$count >= $limit' 'false' ;;
	no-honeypot)  break_it $S/scripts/create-forms.php "\$pn_global['misc']['honeypotStatus'] = 'yes';" "\$pn_global['misc']['honeypotStatus'] = 'no';" ;;
	wrong-recipient) break_it $S/scripts/create-forms.php "'email'   => \$pn_to[ \$pn_name ]," "'email'   => \$pn_to['contact']," ;;
	no-reply-to)  break_it $S/scripts/create-forms.php "'replyTo'      => '{inputs.email}'," "'replyTo'      => ''," ;;
	*) setup_fail "unknown BREAK" ;;
esac
forms() { PN_CONTACT_TO="$CONTACT_TO" PN_PRAYER_TO="$PRAYER_TO" bash "$S/scripts/create-forms.sh" "$W" "$@" 2>&1; }

echo "== 1. The forms are created from the files in the repo"
out="$(forms)"
expect_contains "creates the contact form" "$out" "Created the 'contact' form"
expect_contains "creates the prayer form" "$out" "Created the 'prayer' form"
expect_contains "turns on the hidden spam trap" "$out" "Turned on the hidden spam trap"
expect_contains "removes the plugin's sample forms" "$out" "Removed the sample form"
expect_eq "only our two forms exist" "$(sql "select group_concat(title order by id) from wp_fluentform_forms")" "Contact,Prayer Request"
CONTACT_ID="$(wp option get pn_forms --format=json | php -r 'echo json_decode(stream_get_contents(STDIN), true)["contact"];')"
PRAYER_ID="$(wp option get pn_forms --format=json | php -r 'echo json_decode(stream_get_contents(STDIN), true)["prayer"];')"
expect_eq "their IDs are recorded" "$([[ $CONTACT_ID -gt 0 && $PRAYER_ID -gt 0 && $CONTACT_ID != "$PRAYER_ID" ]] && echo yes || echo no)" "yes"
out="$(forms)"
expect_contains "a second run keeps them" "$out" "Kept the 'contact' form"
expect_lacks "a second run creates nothing" "$out" "Created"
expect_eq "no duplicate forms" "$(sql "select count(*) from wp_fluentform_forms")" "2"
sql "update wp_fluentform_forms set title='Edited by staff' where id=$CONTACT_ID"
forms >/dev/null
expect_eq "a staff edit survives a re-run" "$(sql "select title from wp_fluentform_forms where id=$CONTACT_ID")" "Edited by staff"
out="$(forms --replace contact)"
expect_contains "--replace resets one form" "$out" "Replaced the 'contact' form"
expect_eq "the replaced form has its own title back" "$(sql "select title from wp_fluentform_forms where id=$CONTACT_ID")" "Contact"
out="$(forms --replace nonsense)"
expect_contains "an unknown form name is refused" "$out" "Unknown form 'nonsense'"
expect_eq "contact mail goes to the contact address" "$(sql "select json_unquote(json_extract(value,'\$.sendTo.email')) from wp_fluentform_form_meta where form_id=$CONTACT_ID and meta_key='notifications'")" "$CONTACT_TO"
expect_eq "prayer mail goes to both pastors" "$(sql "select json_unquote(json_extract(value,'\$.sendTo.email')) from wp_fluentform_form_meta where form_id=$PRAYER_ID and meta_key='notifications'")" "$PRAYER_TO"
expect_eq "replies go to the sender" "$(sql "select json_unquote(json_extract(value,'\$.replyTo')) from wp_fluentform_form_meta where form_id=$CONTACT_ID and meta_key='notifications'")" "{inputs.email}"

echo "== 2. The pages show the forms"
bash /repo/scripts/create-pages.sh "$W" >/dev/null 2>&1
( wp server --host=127.0.0.1 --port=8080 >/tmp/server.log 2>&1 & )
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/" && break; sleep 1; done
curl -s "$BASE/contact/" > /tmp/contact.html; curl -s "$BASE/prayer/" > /tmp/prayer.html
expect_contains "Contact shows a form" "$(cat /tmp/contact.html)" "id=\"fluentform_$CONTACT_ID\""
expect_contains "Prayer shows a form" "$(cat /tmp/prayer.html)" "id=\"fluentform_$PRAYER_ID\""
for f in "name" "email" "message"; do expect_contains "Contact has the '$f' field" "$(cat /tmp/contact.html)" "name=\"$f\""; done
for f in "name" "email" "request"; do expect_contains "Prayer has the '$f' field" "$(cat /tmp/prayer.html)" "name=\"$f\""; done
expect_contains "Contact has the hidden spam trap" "$(cat /tmp/contact.html)" "item_${CONTACT_ID}__fluent_sf"
expect_contains "Prayer has the hidden spam trap" "$(cat /tmp/prayer.html)" "item_${PRAYER_ID}__fluent_sf"
expect_contains "Contact notes when the page loaded" "$(cat /tmp/contact.html)" 'name="pn_loaded"'
expect_contains "the page-load script is on the form page" "$(cat /tmp/contact.html)" "pn_loaded"
expect_contains "Contact says messages are kept for a year" "$(cat /tmp/contact.html)" "keep messages for a year"
expect_contains "Prayer says nothing is kept" "$(cat /tmp/prayer.html)" "not kept on this website"
for p in about-us kids giving events blog new-here; do
	expect_lacks "/$p/ does not load the forms plugin" "$(curl -s "$BASE/$p/")" "fluentform"
done
expect_contains "New Here has the Connect Card button" "$(curl -s "$BASE/new-here/")" "churchcenter.com/people/forms/962834"

# --- submitting, the way the page does it
ff_submit() {  # name page "fields" age_ms honeypot(value or __missing__) -> the JSON answer
	local name="$1" page="$2" fields="$3" age="$4" trap="$5" id html nonce post now data
	id="$([[ $name == contact ]] && echo "$CONTACT_ID" || echo "$PRAYER_ID")"
	html="$(curl -s "$BASE/$page/")"
	nonce="$(grep -o "name=\"_fluentform_${id}_fluentformnonce\" value=\"[a-z0-9]*\"" <<<"$html" | sed 's/.*value="//;s/"//')"
	post="$(grep -o "name='__fluent_form_embded_post_id' value='[0-9]*'" <<<"$html" | sed "s/.*value='//;s/'//")"
	now="$(php -r 'echo (int) (microtime(true) * 1000);')"
	data="__fluent_form_embded_post_id=$post&_fluentform_${id}_fluentformnonce=$nonce&_wp_http_referer=%2F$page%2F&$fields"
	[[ "$age" == __missing__ ]] || data="$data&pn_loaded=$((now - age))"
	[[ "$trap" == __missing__ ]] || data="$data&item_${id}__fluent_sf=$trap"
	curl -s -X POST "$BASE/wp-admin/admin-ajax.php" --data-urlencode "action=fluentform_submit" --data-urlencode "form_id=$id" --data-urlencode "data=$data"
}
mails()   { [[ -f /tmp/mail.log ]] && wc -l < /tmp/mail.log | tr -d ' ' || echo 0; }
entries() { sql "select count(*) from wp_fluentform_submissions where form_id=$1"; }
GOOD='name=Jane+Visitor&email=jane%40example.test&message=Hello+from+Jane'
PGOOD='name=&email=&request=Please+pray+for+my+family'

echo "== 3. Contact: a real message"
rm -f /tmp/mail.log
out="$(ff_submit contact contact "$GOOD" 10000 "")"
expect_contains "the visitor sees a thank-you" "$out" "Thank you! We got your message"
expect_eq "exactly one email was sent" "$(mails)" "1"
mail="$(cat /tmp/mail.log)"
expect_eq "it went to the contact address" "$(php -r '$j = json_decode(file("/tmp/mail.log")[0], true); echo implode(",", (array) $j["to"]);')" "$CONTACT_TO"
expect_contains "the message text is in it" "$mail" "Hello from Jane"
expect_contains "the subject names the sender" "$mail" "Website message from Jane Visitor"
expect_eq "replying goes to the visitor (Reply-To header)" "$(php -r '$j = json_decode(file("/tmp/mail.log")[0], true); $h = is_array($j["headers"]) ? implode("\n", $j["headers"]) : (string) $j["headers"]; echo preg_match("/^Reply-To:.*jane@example\.test/mi", $h) ? "yes" : "no";')" "yes"
expect_eq "the message is saved for staff" "$(entries "$CONTACT_ID")" "1"

echo "== 4. Contact: spam and mistakes are turned away, and nothing is sent or saved"
before_mail="$(mails)"; before_entries="$(entries "$CONTACT_ID")"
out="$(ff_submit contact contact "$GOOD" 500 "")";               expect_contains "sent too fast" "$out" "little quick"
out="$(ff_submit contact contact "$GOOD" __missing__ "")";       expect_contains "no page-load note" "$out" "little quick"
out="$(ff_submit contact contact "$GOOD" 10000 "gotcha")";       expect_contains "spam trap filled in" "$out" "can not submit"
out="$(ff_submit contact contact "$GOOD" 10000 __missing__)";    expect_contains "spam trap missing" "$out" "can not submit"
out="$(ff_submit contact contact 'name=Jane&email=not-an-email&message=Hi' 10000 "")"; expect_contains "an invalid email" "$out" "valid email"
out="$(ff_submit contact contact 'name=Jane&email=jane%40example.test&message=' 10000 "")"; expect_contains "an empty message" "$out" "required"
out="$(ff_submit contact contact 'name=&email=jane%40example.test&message=Hi' 10000 "")"; expect_contains "a missing name" "$out" "required"
expect_eq "no email was sent for any of them" "$(mails)" "$before_mail"
expect_eq "nothing was saved for any of them" "$(entries "$CONTACT_ID")" "$before_entries"

echo "== 5. Prayer requests go to the pastors and are not kept"
rm -f /tmp/mail.log
out="$(ff_submit prayer prayer "$PGOOD" 10000 "")"
expect_contains "the visitor sees a thank-you" "$out" "Your request has gone to our pastors"
expect_eq "exactly one email was sent" "$(mails)" "1"
expect_eq "it went to both pastors, not the contact address" "$(php -r '$j = json_decode(file("/tmp/mail.log")[0], true); echo implode(",", (array) $j["to"]);')" "$PRAYER_TO"
expect_contains "the request is in the email" "$(cat /tmp/mail.log)" "Please pray for my family"
expect_eq "the request is not saved" "$(entries "$PRAYER_ID")" "0"
expect_eq "its field values are not saved" "$(sql "select count(*) from wp_fluentform_entry_details where form_id=$PRAYER_ID")" "0"
expect_eq "its log lines are not saved" "$(sql "select count(*) from wp_fluentform_logs where parent_source_id=$PRAYER_ID")" "0"
out="$(ff_submit prayer prayer 'name=Sam&email=sam%40example.test&request=Please+pray' 10000 "")"
expect_contains "a request with a name and email works" "$out" "Your request has gone to our pastors"
expect_eq "...and is still not saved" "$(entries "$PRAYER_ID")" "0"
before_mail="$(mails)"
out="$(ff_submit prayer prayer "$PGOOD" 500 "")";              expect_contains "prayer: sent too fast" "$out" "little quick"
out="$(ff_submit prayer prayer "$PGOOD" 10000 "gotcha")";      expect_contains "prayer: spam trap filled in" "$out" "can not submit"
out="$(ff_submit prayer prayer 'name=&email=bad&request=Hi' 10000 "")"; expect_contains "prayer: an invalid email" "$out" "valid email"
out="$(ff_submit prayer prayer 'name=&email=&request=' 10000 "")";      expect_contains "prayer: an empty request" "$out" "required"
expect_eq "prayer: no email for any of those" "$(mails)" "$before_mail"

echo "== 6. Too many from one connection"
wp transient delete --all --quiet >/dev/null 2>&1; wp option update pn_test_rate_limit 2 --quiet
for i in 1 2; do out="$(ff_submit contact contact "$GOOD" 10000 "")"; expect_contains "message $i of 2 is allowed" "$out" "Thank you"; done
out="$(ff_submit contact contact "$GOOD" 10000 "")"; expect_contains "the third is turned away, with a helpful message" "$out" "try again in an hour"
expect_contains "...that gives the office phone number" "$out" "628-1818"
out="$(ff_submit prayer prayer "$PGOOD" 10000 "")"; expect_contains "the prayer form has its own count" "$out" "Your request has gone to our pastors"
wp option delete pn_test_rate_limit --quiet; wp transient delete --all --quiet >/dev/null 2>&1

echo "== 7. Contact messages are deleted after a year"
sql "insert into wp_fluentform_submissions (form_id, response, status, created_at, updated_at) values ($CONTACT_ID, '{}', 'unread', date_sub(now(), interval 400 day), now()), ($CONTACT_ID, '{}', 'unread', date_sub(now(), interval 100 day), now())"
old_id="$(sql "select max(id) - 1 from wp_fluentform_submissions")"
sql "insert into wp_fluentform_entry_details (form_id, submission_id, field_name, field_value) values ($CONTACT_ID, $old_id, 'message', 'old message')"
total_before="$(entries "$CONTACT_ID")"
wp cron event run pn_forms_cleanup >/dev/null 2>&1
expect_eq "exactly the old message was deleted" "$(entries "$CONTACT_ID")" "$((total_before - 1))"
expect_eq "its field values went too" "$(sql "select count(*) from wp_fluentform_entry_details where submission_id=$old_id")" "0"
expect_eq "a 100-day-old message is kept" "$(sql "select count(*) from wp_fluentform_submissions where form_id=$CONTACT_ID and created_at < date_sub(now(), interval 90 day) and created_at > date_sub(now(), interval 110 day)")" "1"

echo
echo "Passed: $PASS   Failed: $FAIL"
[[ $FAIL -eq 0 ]]
