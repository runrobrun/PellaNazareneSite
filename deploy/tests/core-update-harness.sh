#!/usr/bin/env bash
# Exercises deploy/core-update.sh with stubbed wp/curl inside a throwaway container: already up to
# date; WordPress.org unreachable and a failed update check (both must change nothing); a clean
# update with file and database backups; failures after the update (update, checksums, caches,
# health) rolled back from the local file copy, with the database restored only when the update
# changed its version, so writes made meanwhile survive; a rollback that cannot put the files back
# (RECOVERY NEEDED, database untouched); and waiting for a deploy holding the lock.
# Nothing touches a real server.
#
# Run from the repo root:
#   docker run --rm -v "$PWD":/work:ro ubuntu:24.04 bash /work/deploy/tests/core-update-harness.sh
set -u
T=/t; W=$T/www/staging/public
mkdir -p $T/www/staging $T/www/pellanazarene/public $T/bin $T/backups $T/cache/pellanazarene-staging $T/secrets $T/state
useradd --system --no-create-home --shell /bin/bash pn-staging-deploy 2>/dev/null
useradd --system --no-create-home --shell /bin/bash pn-production-deploy 2>/dev/null
printf 'password: stub\n' > $T/secrets/staging-basic-auth.txt
touch $T/www/staging/.deploy.lock
# --- stubs ---------------------------------------------------------------
# The installed version lives in the site's own files (wp-includes/version.php), so a rollback
# from the file backup really changes it. /t/state holds the database.
cat > $T/bin/wp <<'EOF2'
#!/usr/bin/env bash
S=/t/state; W=/t/www/staging/public; args=()
for a in "$@"; do [[ "$a" == --path=* || "$a" == --quiet ]] || args+=("$a"); done
echo "$(id -un) wp ${args[*]}" >> /t/wp.log
case "${args[*]}" in
  "core version") cat $W/wp-includes/version.php;;
  "core check-update --minor --format=count")
    [[ -n "${FAKE_CHECK_FAIL:-}" ]] && exit 1
    [[ "$(cat $W/wp-includes/version.php)" == "$(cat $S/offer)" ]] && echo 0 || echo 1;;
  "db export -") cat $S/db;;
  "db import -") cat > $S/db;;
  "core update --minor")
    [[ -n "${FAKE_UPDATE_FAIL:-}" ]] && exit 1
    cp $S/offer $W/wp-includes/version.php; echo new > $W/wp-includes/new-in-update.php
    [[ -n "${FAKE_STAFF_EDIT:-}" ]] && echo "staff-edit" > $S/db
    if [[ -n "${FAKE_BREAK_RESTORE:-}" ]]; then rm -f $W/index.php; mkdir -p $W/index.php/x; fi
    exit 0;;
  "core update-db") [[ -n "${FAKE_SCHEMA:-}" ]] && echo "db-after-update" > $S/db; exit 0;;
  "core verify-checksums") [[ -n "${FAKE_CHECKSUM_FAIL:-}" ]] && exit 1; exit 0;;
  "cache flush") [[ -n "${FAKE_CACHE_FAIL:-}" ]] && exit 1; exit 0;;
  *) echo "unexpected wp call: ${args[*]}" >&2; exit 9;;
esac
EOF2
cat > $T/bin/curl <<'EOF2'
#!/usr/bin/env bash
for a in "$@"; do [[ "$a" == *api.wordpress.org* ]] && { [[ -n "${FAKE_OFFLINE:-}" ]] && exit 7; exit 0; }; done
printf '%s' "${FAKE_CODE:-200}"
EOF2
chmod 755 $T/bin/*
sed -e 's#^WWW_DIR=.*#WWW_DIR="/t/www"#' -e 's#^NGINX_CACHE_DIR=.*#NGINX_CACHE_DIR="/t/cache"#' \
    -e 's#^BACKUP_DIR=.*#BACKUP_DIR="/t/backups"#' -e 's#^SECRETS_DIR=.*#SECRETS_DIR="/t/secrets"#' \
    /work/deploy/core-update.sh > $T/core-update.sh
export PATH="$T/bin:$PATH"
# --- helpers -------------------------------------------------------------
pass=0; fail=0
check() { if [[ "$2" == "$3" ]]; then echo "  ok    $1"; pass=$((pass+1)); else echo "  FAIL  $1 (expected '$3', got '$2')"; fail=$((fail+1)); fi; }
reset() {  # reset <installed> <offered>
	rm -rf "$W" $T/backups/pn-staging-db; mkdir -p "$W/wp-admin" "$W/wp-includes" "$W/wp-content/uploads"
	echo "$1" > "$W/wp-includes/version.php"; echo admin > "$W/wp-admin/admin.php"; echo index > "$W/index.php"
	echo photo > "$W/wp-content/uploads/photo.jpg"; echo secret > "$W/wp-config.php"
	chown -R pn-staging-deploy: "$W"
	echo "$2" > $T/state/offer; echo "db-before" > $T/state/db
	chmod -R a+rwX $T/state; : > $T/wp.log; chmod a+rw $T/wp.log; touch $T/cache/pellanazarene-staging/page
}
run() { OUT="$(env "$@" bash $T/core-update.sh staging 2>&1)"; RC=$?; }
version() { cat "$W/wp-includes/version.php"; }
backups() { find $T/backups -name "*.$1" -type f | wc -l; }
# --- cases ---------------------------------------------------------------
echo "Up to date"
reset 7.1.4 7.1.4; run X=1
check "exits 0" "$RC" 0
check "says up to date" "$(grep -c 'is up to date' <<<"$OUT")" 1
check "makes no backup" "$(backups gz)" 0

echo "WordPress.org unreachable"
reset 7.1.3 7.1.4; run FAKE_OFFLINE=1
check "fails (exit 3)" "$RC" 3
check "does not claim up to date" "$(grep -c 'up to date' <<<"$OUT")" 0
check "changes nothing" "$(version)/$(backups gz)" 7.1.3/0

echo "The update check itself fails"
reset 7.1.3 7.1.4; run FAKE_CHECK_FAIL=1
check "fails (exit 3)" "$RC" 3
check "changes nothing" "$(version)/$(backups gz)" 7.1.3/0

echo "Clean update"
reset 7.1.3 7.1.4; run X=1
check "exits 0" "$RC" 0
check "is now 7.1.4" "$(version)" 7.1.4
check "reports old -> new" "$(grep -c '7.1.3 -> 7.1.4' <<<"$OUT")" 1
check "backed up the database first" "$(zcat $T/backups/pn-staging-db/core-7.1.3-*.sql.gz)" db-before
check "database backup is owner-only" "$(stat -c %a $T/backups/pn-staging-db/core-7.1.3-*.sql.gz)" 600
check "file backup is owner-only" "$(stat -c %a $T/backups/pn-staging-db/core-7.1.3-*.files.tar.gz)" 600
check "file backup holds the old core" "$(tar -xzOf $T/backups/pn-staging-db/core-7.1.3-*.files.tar.gz ./wp-includes/version.php)" 7.1.3
check "file backup leaves out wp-content and wp-config.php" \
	"$(tar -tzf $T/backups/pn-staging-db/core-7.1.3-*.files.tar.gz | grep -c 'wp-content\|wp-config')" 0
check "cleared the page cache" "$(find $T/cache/pellanazarene-staging -type f | wc -l)" 0
check "ran WP-CLI only as the deploy user" "$(grep -vc '^pn-staging-deploy ' $T/wp.log)" 0
check "updated the database and verified checksums" "$(grep -c 'update-db\|verify-checksums' $T/wp.log)" 2

echo "The update itself fails"
reset 7.1.3 7.1.4; run FAKE_UPDATE_FAIL=1
check "fails" "$RC" 1
check "still 7.1.3" "$(version)" 7.1.3
check "database untouched" "$(cat $T/state/db)" db-before

echo "Checksums fail; a staff edit happened during the update; no database change"
reset 7.1.3 7.1.4; run FAKE_CHECKSUM_FAIL=1 FAKE_STAFF_EDIT=1
check "fails" "$RC" 1
check "files back to 7.1.3, from the local copy" "$(version)" 7.1.3
check "no file from the new version left behind" "$(test -e "$W/wp-includes/new-in-update.php" && echo left || echo gone)" gone
check "uploads and wp-config.php untouched" "$(cat "$W/wp-content/uploads/photo.jpg")/$(cat "$W/wp-config.php")" photo/secret
check "the staff edit is kept (database not restored)" "$(cat $T/state/db)" staff-edit
check "no download needed for the rollback" "$(grep -c 'core download' $T/wp.log)" 0

echo "Site unhealthy after an update that changed the database"
reset 7.1.3 7.1.4; run FAKE_CODE=500 FAKE_SCHEMA=1
check "fails" "$RC" 1
check "says which page failed" "$(grep -c 'returned HTTP 500' <<<"$OUT")" 1
check "files back to 7.1.3" "$(version)" 7.1.3
check "database is retained for review" "$(cat $T/state/db)" db-after-update
check "database is not restored automatically" "$(grep -c 'not restored automatically' <<<"$OUT")" 1

echo "Clearing the caches fails"
reset 7.1.3 7.1.4; run FAKE_CACHE_FAIL=1
check "fails and rolls back" "$RC/$(version)" 1/7.1.3

echo "The rollback cannot put the files back"
reset 7.1.3 7.1.4; run FAKE_BREAK_RESTORE=1 FAKE_CHECKSUM_FAIL=1 FAKE_SCHEMA=1
check "fails with exit 5" "$RC" 5
check "says RECOVERY NEEDED" "$(grep -c 'RECOVERY NEEDED' <<<"$OUT")" 1
check "leaves the database alone" "$(cat $T/state/db)" db-after-update

echo "A deploy holds the lock"
reset 7.1.3 7.1.4
( exec 9>$T/www/staging/.deploy.lock; flock 9; sleep 3 ) & sleep 1
start=$(date +%s); run X=1; waited=$(( $(date +%s) - start ))
check "waits for the deploy, then updates" "$RC/$(version)" 0/7.1.4
check "waited" "$(( waited >= 1 ))" 1

echo "No site installed (production before cutover)"
OUT="$(bash $T/core-update.sh production 2>&1)"; RC=$?
check "exits 0 and says nothing to update" "$RC/$(grep -c 'nothing to update' <<<"$OUT")" 0/1

echo "Bad argument"
OUT="$(bash $T/core-update.sh prod 2>&1)"; RC=$?
check "refuses" "$RC" 2

echo; echo "Passed: $pass   Failed: $fail"
[[ $fail -eq 0 ]]
