#!/usr/bin/env bash
# The setup scripts must run in the OLD bash that ships with a Mac (3.2) as well as the server's, which once failed
# with "replace[@]: unbound variable" when no --replace was given. Runs create-pages.sh and create-forms.sh under bash 3.2
# in Docker with a stand-in `wp` that just prints what it was asked to do.
# Run from the repo root:  deploy/tests/script-args-check.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
docker run --rm -v "$PWD":/repo:ro bash:3.2 bash -c '
set -u
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ok    $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
echo "bash $BASH_VERSION"
mkdir -p /stub; printf "#!/bin/sh\necho \"wp \$*\"\n" > /stub/wp; chmod +x /stub/wp; PATH=/stub:$PATH
for s in create-pages create-forms; do
	out="$(bash /repo/scripts/$s.sh /srv/site 2>&1)"; rc=$?
	[ "$rc" = 0 ] && ok "$s: runs with no options" || bad "$s: runs with no options ($out)"
	echo "$out" | grep -q "eval-file /repo/scripts/$s.php\$" && ok "$s: asks WordPress to run the right file, with nothing extra" || bad "$s: wrong command ($out)"
	out="$(bash /repo/scripts/$s.sh /srv/site --replace one two 2>&1)"; rc=$?
	[ "$rc" = 0 ] && echo "$out" | grep -q "eval-file /repo/scripts/$s.php one two\$" && ok "$s: --replace passes its names on" || bad "$s: --replace ($out)"
	out="$(bash /repo/scripts/$s.sh /srv/site --replace 2>&1)"; rc=$?
	[ "$rc" != 0 ] && ok "$s: --replace with no name is refused" || bad "$s: --replace with no name was accepted"
	out="$(bash /repo/scripts/$s.sh /srv/site --bogus 2>&1)"; rc=$?
	[ "$rc" != 0 ] && ok "$s: an unknown option is refused" || bad "$s: an unknown option was accepted"
done
echo; echo "Passed: $PASS   Failed: $FAIL"
[ "$FAIL" = 0 ]'
