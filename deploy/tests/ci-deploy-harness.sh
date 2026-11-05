#!/usr/bin/env bash
# Exercises deploy/ci-deploy.sh with stubbed git/curl/wp/sudo inside a throwaway container,
# covering: managed MU-plugin synchronization, healthy deploy, broken site healed by rollback,
# broken site NOT healed (exit 6), a failing deploy script, a production deploy (its own paths,
# no staging password), and that each key can only deploy its own site. Nothing touches a real server.
#
# Run from the repo root:
#   docker run --rm -v "$PWD":/work:ro ubuntu:24.04 bash /work/deploy/tests/ci-deploy-harness.sh
set -u
T=/t; mkdir -p $T/www/staging/repo $T/www/staging/public $T/www/pellanazarene/repo $T/www/pellanazarene/public \
  $T/bin $T/libexec $T/backups $T/cache $T/secrets
useradd --system --no-create-home --shell /bin/bash pn-staging-deploy
useradd --system --no-create-home --shell /bin/bash pn-production-deploy
printf 'password: stub\n' > $T/secrets/staging-basic-auth.txt
# --- stubs ---------------------------------------------------------------
cat > $T/bin/git <<'EOF'
#!/usr/bin/env bash
args=("$@"); [[ "$1" == "-C" ]] && args=("${@:3}")
case "${args[0]}" in
  fetch|merge-base|reset) echo "git ${args[*]}" >> /t/git.log; exit 0;;
  rev-parse) printf 'b%.0s' {1..40}; echo;;
  show) printf '# pn-ci-deploy-contract: 1\n#!/usr/bin/env bash\necho "[candidate deploy.sh ran]"\nexit ${FAKE_DEPLOY_FAIL:-0}\n';;
esac
EOF
cat > $T/bin/sudo <<'EOF'
#!/usr/bin/env bash
[[ "$1" == "-n" ]] && shift
if [[ "$1" == "/usr/local/sbin/pn-clear-cache" ]]; then
  echo "sudo $*" >> /t/sudo.log
  exit 0
fi
exec "$@"
EOF
cat > $T/bin/runuser <<'EOF'
#!/usr/bin/env bash
[[ "$1" == "-u" ]] || exit 2
identity="$2"; shift 2
[[ "$1" == "--" ]] && shift
echo "$identity $*" >> /t/runuser.log
exec "$@"
EOF
cat > $T/bin/wp <<'EOF'
#!/usr/bin/env bash
echo "-- SQL dump --"
EOF
cat > $T/bin/curl <<'EOF'
#!/usr/bin/env bash
# FAKE_CURL: ok = always healthy | broken = always 500 | heals = 500 until the rollback runner has run
echo "curl $*" >> /t/curl.log
case "$FAKE_CURL" in
  ok) printf '<html>fine</html>\n200';;
  broken) printf '<html>boom</html>\n500';;
  heals) if [[ -f /t/rolled ]]; then printf '<html>fine</html>\n200'; else printf '<html>boom</html>\n500'; fi;;
esac
EOF
cat > $T/bin/rsync <<'EOF'
#!/usr/bin/env bash
args=("$@"); source="${args[-2]}"; target="${args[-1]}"
mkdir -p "$target"
include_only=0; delete=0
for arg in "${args[@]}"; do
  [[ "$arg" == "--include=pn-*.php" ]] && include_only=1
  [[ "$arg" == "--delete" ]] && delete=1
done
if [[ "$include_only" == 1 ]]; then
  for file in "$source"pn-*.php; do
    [[ -f "$file" ]] && cp -a "$file" "$target"
  done
else
  if [[ "$delete" == 1 ]]; then
    find "$target" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  fi
  cp -a "${source%/}/." "$target"
fi
EOF
printf '#!/usr/bin/env bash\ntouch /t/rolled\necho "[known-good RUNNER ran]"\n' > $T/libexec/known-good.orig
chmod +x $T/bin/*
# --- a copy of the wrapper pointed at the sandbox --------------------------
sed -e 's|^WWW_DIR=.*|WWW_DIR="/t/www"|' -e 's|^NGINX_CACHE_DIR=.*|NGINX_CACHE_DIR="/t/cache"|' \
    -e 's|^BACKUP_DIR=.*|BACKUP_DIR="/t/backups"|' -e 's|^LIBEXEC_DIR=.*|LIBEXEC_DIR="/t/libexec"|' \
    -e 's|^SECRETS_DIR=.*|SECRETS_DIR="/t/secrets"|' -e 's|sleep 4|sleep 0|' \
    /work/deploy/ci-deploy.sh > $T/wrapper.sh
if ! { grep -q '^WWW_DIR="/t/www"' $T/wrapper.sh && grep -q '^LIBEXEC_DIR="/t/libexec"' $T/wrapper.sh; }; then
  echo "FAIL: could not point the wrapper at the sandbox"; exit 1
fi
SHA=$(printf 'a%.0s' {1..40})
FAILED=0
echo "== cache helper is confined to the requested site's cache"
sed -e 's|/var/cache/nginx/pellanazarene-staging|/t/cache/pellanazarene-staging|' \
    -e 's|/var/cache/nginx/pellanazarene"|/t/cache/pellanazarene"|' \
    /work/deploy/clear-cache.sh > "$T/clear-cache.sh"
mkdir -p "$T/cache/pellanazarene-staging" "$T/cache/pellanazarene"
touch "$T/cache/pellanazarene-staging/staging" "$T/cache/pellanazarene/production"
bash "$T/clear-cache.sh" staging
[[ ! -e "$T/cache/pellanazarene-staging/staging" && -e "$T/cache/pellanazarene/production" ]] \
  || { echo "FAIL [cache helper]: staging cleared production or left its own cache"; FAILED=1; }
echo "ok   [cache helper]"

echo "== deploy.sh reconciles managed MU plugins and preserves unrelated ones"
R="$T/www/staging"
mkdir -p "$R/repo/wp-content/plugins/pn-sermons" "$R/repo/wp-content/themes/pella-nazarene" \
  "$R/repo/wp-content/mu-plugins" "$R/public/wp-content/mu-plugins" "$T/cache"
printf 'source site\n' > "$R/repo/wp-content/mu-plugins/pn-site.php"
printf 'installed site\n' > "$R/public/wp-content/mu-plugins/pn-site.php"
printf 'stale security\n' > "$R/public/wp-content/mu-plugins/pn-security.php"
printf 'unrelated\n' > "$R/public/wp-content/mu-plugins/custom.php"
PATH=$T/bin:$PATH PN_SKIP_PLUGIN_INSTALL=1 bash /work/deploy/deploy.sh "$R" "$T/cache" "$SHA" >/dev/null
[[ ! -e "$R/public/wp-content/mu-plugins/pn-security.php" ]] || { echo "FAIL [deploy sync]: stale managed plugin remains"; FAILED=1; }
[[ -e "$R/public/wp-content/mu-plugins/custom.php" ]] || { echo "FAIL [deploy sync]: unrelated MU plugin was removed"; FAILED=1; }
echo "ok   [deploy sync]"

run() {  # name, key's site ("" = original staging key), requested site, FAKE_CURL, FAKE_DEPLOY_FAIL, expected exit, expected message, expected RUNNER afterwards
  local name="$1" key="$2" site="$3" curl_mode="$4" deploy_fail="$5" want_exit="$6" want_msg="$7" want_runner="$8" out code R runner
  R="$T/www/$([[ "$site" == production ]] && echo pellanazarene || echo staging)"
  runner="$T/libexec/pn-$site-deploy"
  rm -f /t/rolled /t/git.log /t/curl.log /t/runuser.log /t/sudo.log "$T"/libexec/pn-*-deploy "$T"/libexec/pn-*-deploy.candidate
  cp "$T/libexec/known-good.orig" "$runner"; chmod +x "$runner"
  mkdir -p "$R/repo/wp-content/mu-plugins" "$R/public/wp-content/mu-plugins"
  printf 'source site\n' > "$R/repo/wp-content/mu-plugins/pn-site.php"
  printf 'installed site\n' > "$R/public/wp-content/mu-plugins/pn-site.php"
  printf 'stale security\n' > "$R/public/wp-content/mu-plugins/pn-security.php"
  printf 'unrelated\n' > "$R/public/wp-content/mu-plugins/custom.php"
  # The key's site is the forced command's argument in authorized_keys; the request is what CI sends.
  out="$(PATH=$T/bin:$PATH FAKE_CURL="$curl_mode" FAKE_DEPLOY_FAIL="$deploy_fail" SSH_ORIGINAL_COMMAND="$site $SHA" \
    bash "$T/wrapper.sh" ${key:+"$key"} 2>&1)"; code=$?
  if [[ "$code" != "$want_exit" ]]; then
    echo "FAIL [$name]: exit $code, wanted $want_exit"
    printf '%s\n' "$out"
    FAILED=1
  fi
  grep -qF "$want_msg" <<<"$out" || { echo "FAIL [$name]: output lacks: $want_msg"; FAILED=1; }
  if [[ "$want_runner" == "refused" ]]; then
    # Refused before anything happened: no git, no backup, no deploy, no request to either site.
    [[ ! -e /t/git.log && ! -e /t/curl.log && ! -e /t/runuser.log ]] || { echo "FAIL [$name]: something ran after the refusal"; FAILED=1; }
    grep -q 'known-good RUNNER ran' "$runner" || { echo "FAIL [$name]: runner was replaced"; FAILED=1; }
    [[ -e "$R/public/wp-content/mu-plugins/pn-security.php" ]] || { echo "FAIL [$name]: the site was touched"; FAILED=1; }
    echo "ok   [$name] exit=$code"
    return
  fi
  # After a healthy deploy the new deploy.sh becomes the known-good runner; after any rollback the old one stays.
  if [[ "$want_runner" == "promoted" ]]; then
    grep -q 'candidate deploy.sh ran' "$runner" || { echo "FAIL [$name]: new deploy.sh was not promoted"; FAILED=1; }
  else
    grep -q 'known-good RUNNER ran' "$runner" || { echo "FAIL [$name]: known-good runner was replaced"; FAILED=1; }
    [[ ! -e "$R/public/wp-content/mu-plugins/pn-security.php" ]] || { echo "FAIL [$name]: rollback left the removed security plugin active"; FAILED=1; }
    [[ -e "$R/public/wp-content/mu-plugins/custom.php" ]] || { echo "FAIL [$name]: rollback removed an unrelated MU plugin"; FAILED=1; }
  fi
  [[ ! -e "$runner.candidate" ]] || { echo "FAIL [$name]: candidate file left behind"; FAILED=1; }
  expected_user="pn-$site-deploy"
  if grep -qv "^$expected_user " /t/runuser.log; then
    echo "FAIL [$name]: a command ran as another site's identity"; FAILED=1
  fi
  grep -q "^$expected_user .*wp --path=$R/public db export" /t/runuser.log \
    || { echo "FAIL [$name]: database export did not run as the site's identity"; FAILED=1; }
  # Each site is checked at its own address; only staging sends the staging password.
  if [[ "$site" == production ]]; then
    if ! { grep -q 'https://pellanazarene.org/' /t/curl.log && ! grep -q 'staging' /t/curl.log && ! grep -q -- '--config' /t/curl.log; }; then
      echo "FAIL [$name]: production health check used the wrong address or the staging password"; FAILED=1
    fi
    ls "$T/backups/pn-production-db"/pre-*.sql.gz >/dev/null 2>&1 || { echo "FAIL [$name]: no production database backup"; FAILED=1; }
  else
    if ! { grep -q 'https://staging.pellanazarene.org/' /t/curl.log && grep -q -- '--config' /t/curl.log; }; then
      echo "FAIL [$name]: staging health check used the wrong address or no password"; FAILED=1
    fi
  fi
  backup_dir="$T/backups/pn-$site-db"
  [[ "$(stat -c '%U:%a' "$backup_dir")" == "root:700" ]] \
    || { echo "FAIL [$name]: backup directory is not root-owned and private"; FAILED=1; }
  echo "ok   [$name] exit=$code"
}
run "healthy deploy, candidate promoted"        ""         staging    ok     0 0 "Healthy. Deployed"                 promoted
run "broken site, rollback heals it"            ""         staging    heals  0 1 "Staging is healthy again"          kept
run "broken site, rollback cannot heal it"      ""         staging    broken 0 6 "STAGING IS STILL UNHEALTHY"        kept
run "deploy script fails, rollback heals it"    ""         staging    heals  3 3 "Staging is healthy again"          kept
run "staging key with its site named"           staging    staging    ok     0 0 "Healthy. Deployed"                 promoted
run "production deploy, candidate promoted"     production production ok     0 0 "Healthy. Deployed"                 promoted
run "production broken, rollback heals it"      production production heals  0 1 "Production is healthy again"       kept
run "production broken, rollback cannot heal"   production production broken 0 6 "PRODUCTION IS STILL UNHEALTHY"     kept
run "original staging key asks for production"  ""         production ok     0 2 "may only deploy to staging"        refused
run "staging key asks for production"           staging    production ok     0 2 "may only deploy to staging"        refused
run "production key asks for staging"           production staging    ok     0 2 "may only deploy to production"     refused
run "key line with an unknown site"             bogus      staging    ok     0 2 "misconfigured"                     refused
if [[ $FAILED == 0 ]]; then
  echo "All wrapper scenarios passed."
else
  echo "WRAPPER TESTS FAILED"
  exit 1
fi
