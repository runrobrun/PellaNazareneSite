#!/usr/bin/env bash
# Exercises deploy/core-update.sh with stubbed wp/curl inside a throwaway container: already up to
# date, WordPress.org unreachable (must fail, not claim "up to date"), a clean update with backup
# and cache clearing, a failed checksum check and a failed health check (both roll back the files
# and the database), and that it waits for a deploy holding the lock. Nothing touches a real server.
#
# Run from the repo root:
#   docker run --rm -v "$PWD":/work:ro ubuntu:24.04 bash /work/deploy/tests/core-update-harness.sh
set -u
T=/t; mkdir -p $T/www/staging/public $T/www/pellanazarene/public $T/bin $T/backups $T/cache/pellanazarene-staging $T/secrets
useradd --system --no-create-home --shell /bin/bash pn-staging-deploy
useradd --system --no-create-home --shell /bin/bash pn-production-deploy
printf 'password: stub\n' > $T/secrets/staging-basic-auth.txt
touch $T/www/staging/public/wp-config.php $T/www/staging/.deploy.lock
chmod -R a+rwX $T
# --- stubs ---------------------------------------------------------------
# State in /t/state: version (installed), offer (newest 7.1.x), db (database contents).
cat > $T/bin/wp <<'EOF2'
#!/usr/bin/env bash
S=/t/state; args=(); for a in "$@"; do [[ "$a" == --path=* || "$a" == --quiet ]] || args+=("$a"); done
echo "$(id -un) wp ${args[*]}" >> /t/wp.log
case "${args[*]}" in
  "core version") cat $S/version;;
  "core check-update --minor --format=count") [[ "$(cat $S/version)" == "$(cat $S/offer)" ]] && echo 0 || echo 1;;
  "db export -") cat $S/db;;
  "db import -") cat > $S/db;;
  "core update --minor") [[ -n "${FAKE_UPDATE_FAIL:-}" ]] && exit 1; cp $S/offer $S/version;;
  "core update-db") echo "db-after-update" > $S/db;;
  "core verify-checksums") [[ -n "${FAKE_CHECKSUM_FAIL:-}" ]] && exit 1; exit 0;;
  "cache flush") exit 0;;
  core\ download\ --version=*) v="${args[2]#--version=}"; echo "$v" > $S/version;;
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
reset() { mkdir -p $T/state; echo "$1" > $T/state/version; echo "$2" > $T/state/offer; echo "db-before" > $T/state/db
  chmod -R a+rwX $T/state; : > $T/wp.log; chmod a+rw $T/wp.log; rm -rf $T/backups/pn-staging-db; touch $T/cache/pellanazarene-staging/page; }
run() { OUT="$(env "$@" bash $T/core-update.sh staging 2>&1)"; RC=$?; }
# --- cases ---------------------------------------------------------------
echo "Up to date"
reset 7.1.4 7.1.4; run X=1
check "exits 0" "$RC" 0
check "says up to date" "$(grep -c 'is up to date' <<<"$OUT")" 1
check "makes no backup" "$(find $T/backups -name '*.sql.gz' | wc -l)" 0

echo "WordPress.org unreachable"
reset 7.1.3 7.1.4; run FAKE_OFFLINE=1
check "fails (exit 3)" "$RC" 3
check "does not claim up to date" "$(grep -c 'up to date' <<<"$OUT")" 0
check "leaves the version alone" "$(cat $T/state/version)" 7.1.3

echo "Clean update"
reset 7.1.3 7.1.4; run X=1
check "exits 0" "$RC" 0
check "is now 7.1.4" "$(cat $T/state/version)" 7.1.4
check "reports old -> new" "$(grep -c '7.1.3 -> 7.1.4' <<<"$OUT")" 1
check "backed up the database first" "$(zcat $T/backups/pn-staging-db/core-7.1.3-*.sql.gz)" db-before
check "backup is owner-only" "$(stat -c %a $T/backups/pn-staging-db/core-7.1.3-*.sql.gz)" 600
check "updated the database" "$(cat $T/state/db)" db-after-update
check "cleared the page cache" "$(find $T/cache/pellanazarene-staging -type f | wc -l)" 0
check "ran WP-CLI only as the deploy user" "$(grep -vc '^pn-staging-deploy ' $T/wp.log)" 0
check "verified checksums" "$(grep -c 'verify-checksums' $T/wp.log)" 1

echo "Update itself fails"
reset 7.1.3 7.1.4; run FAKE_UPDATE_FAIL=1
check "fails" "$RC" 1
check "back on 7.1.3" "$(cat $T/state/version)" 7.1.3
check "database restored" "$(cat $T/state/db)" db-before

echo "Checksums fail after the update"
reset 7.1.3 7.1.4; run FAKE_CHECKSUM_FAIL=1
check "fails" "$RC" 1
check "files rolled back to 7.1.3" "$(cat $T/state/version)" 7.1.3
check "database restored" "$(cat $T/state/db)" db-before

echo "Site unhealthy after the update"
reset 7.1.3 7.1.4; run FAKE_CODE=500
check "fails" "$RC" 1
check "says which page failed" "$(grep -c 'returned HTTP 500' <<<"$OUT")" 1
check "files rolled back to 7.1.3" "$(cat $T/state/version)" 7.1.3
check "database restored" "$(cat $T/state/db)" db-before

echo "A deploy holds the lock"
reset 7.1.3 7.1.4
( exec 9>$T/www/staging/.deploy.lock; flock 9; sleep 3 ) & sleep 1
start=$(date +%s); run X=1; waited=$(( $(date +%s) - start ))
check "waits for the deploy, then updates" "$RC/$(cat $T/state/version)" 0/7.1.4
check "waited" "$(( waited >= 1 ))" 1

echo "No site installed (production before cutover)"
OUT="$(bash $T/core-update.sh production 2>&1)"; RC=$?
check "exits 0 and says nothing to update" "$RC/$(grep -c 'nothing to update' <<<"$OUT")" 0/1

echo "Bad argument"
OUT="$(bash $T/core-update.sh prod 2>&1)"; RC=$?
check "refuses" "$RC" 2

echo; echo "Passed: $pass   Failed: $fail"
[[ $fail -eq 0 ]]
