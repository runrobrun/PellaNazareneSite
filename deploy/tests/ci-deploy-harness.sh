#!/usr/bin/env bash
# Exercises deploy/ci-deploy.sh with stubbed git/curl/wp/sudo inside a throwaway container,
# covering: healthy deploy, broken site healed by rollback, broken site NOT healed (exit 6),
# and a failing deploy script. Nothing touches a real server.
#
# Run from the repo root:
#   docker run --rm -v "$PWD":/work:ro ubuntu:24.04 bash /work/deploy/tests/ci-deploy-harness.sh
set -u
T=/t; mkdir -p $T/root/repo $T/root/public $T/bin $T/libexec $T/backups $T/cache
printf 'password: stub\n' > $T/secrets.txt
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
exec "$@"
EOF
cat > $T/bin/wp <<'EOF'
#!/usr/bin/env bash
echo "-- SQL dump --"
EOF
cat > $T/bin/curl <<'EOF'
#!/usr/bin/env bash
# FAKE_CURL: ok = always healthy | broken = always 500 | heals = 500 until the rollback runner has run
case "$FAKE_CURL" in
  ok) printf '<html>fine</html>\n200';;
  broken) printf '<html>boom</html>\n500';;
  heals) if [[ -f /t/rolled ]]; then printf '<html>fine</html>\n200'; else printf '<html>boom</html>\n500'; fi;;
esac
EOF
printf '#!/usr/bin/env bash\ntouch /t/rolled\necho "[known-good RUNNER ran]"\n' > $T/libexec/pn-staging-deploy.orig
chmod +x $T/bin/*
# --- a copy of the wrapper pointed at the sandbox --------------------------
sed -e 's|^ROOT=.*|ROOT="/t/root"|' -e 's|^CACHE_DIR=.*|CACHE_DIR="/t/cache"|' -e 's|^BACKUPS=.*|BACKUPS="/t/backups"|' \
    -e 's|^SECRETS_FILE=.*|SECRETS_FILE="/t/secrets.txt"|' -e 's|^RUNNER=.*|RUNNER="/t/libexec/pn-staging-deploy"|' \
    -e 's|^CANDIDATE=.*|CANDIDATE="/t/libexec/pn-staging-deploy.candidate"|' -e 's|sleep 4|sleep 0|' \
    /work/deploy/ci-deploy.sh > $T/wrapper.sh
SHA=$(printf 'a%.0s' {1..40})
FAILED=0
run() {  # name, FAKE_CURL, FAKE_DEPLOY_FAIL, expected exit code, expected message, expected RUNNER afterwards
  local name="$1" curl_mode="$2" deploy_fail="$3" want_exit="$4" want_msg="$5" want_runner="$6" out code
  rm -f /t/rolled /t/git.log "$T/libexec/pn-staging-deploy.candidate"
  cp "$T/libexec/pn-staging-deploy.orig" "$T/libexec/pn-staging-deploy"; chmod +x "$T/libexec/pn-staging-deploy"
  out="$(PATH=$T/bin:$PATH FAKE_CURL="$curl_mode" FAKE_DEPLOY_FAIL="$deploy_fail" SSH_ORIGINAL_COMMAND="staging $SHA" bash "$T/wrapper.sh" 2>&1)"; code=$?
  [[ "$code" == "$want_exit" ]] || { echo "FAIL [$name]: exit $code, wanted $want_exit"; FAILED=1; }
  grep -qF "$want_msg" <<<"$out" || { echo "FAIL [$name]: output lacks: $want_msg"; FAILED=1; }
  # After a healthy deploy the new deploy.sh becomes the known-good runner; after any rollback the old one stays.
  if [[ "$want_runner" == "promoted" ]]; then
    grep -q 'candidate deploy.sh ran' "$T/libexec/pn-staging-deploy" || { echo "FAIL [$name]: new deploy.sh was not promoted"; FAILED=1; }
  else
    grep -q 'known-good RUNNER ran' "$T/libexec/pn-staging-deploy" || { echo "FAIL [$name]: known-good runner was replaced"; FAILED=1; }
  fi
  [[ ! -e "$T/libexec/pn-staging-deploy.candidate" ]] || { echo "FAIL [$name]: candidate file left behind"; FAILED=1; }
  echo "ok   [$name] exit=$code"
}
run "healthy deploy, candidate promoted"        ok     0 0 "Healthy. Deployed"                   promoted
run "broken site, rollback heals it"            heals  0 1 "Staging is healthy again"            kept
run "broken site, rollback cannot heal it"      broken 0 6 "STAGING IS STILL UNHEALTHY"          kept
run "deploy script fails, rollback heals it"    heals  3 3 "Staging is healthy again"            kept
if [[ $FAILED == 0 ]]; then
  echo "All wrapper scenarios passed."
else
  echo "WRAPPER TESTS FAILED"
  exit 1
fi
