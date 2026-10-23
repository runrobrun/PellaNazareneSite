#!/usr/bin/env bash
# shellcheck disable=SC2015,SC2034  # "a && ok || FAIL" reporting is intended; the table's note column is read only to skip past it
# Check that every old Ministry Designs URL does what deploy/redirects/expected.csv says, on a live site.
#
# Usage:  scripts/check-redirects.sh BASE_URL [options]
#   --user USER:PASS     basic-auth login (staging)
#   --insecure           accept a self-signed certificate
#   --resolve H:P:IP     like curl --resolve (test a host before DNS points at it)
#   --allow-fallback     accept the fallback location for sermons/speakers/series that are not imported yet.
#                        Use on staging until the sermon import (MIG-6); NEVER at production cutover.
#   --pages-must-exist   the "stays" addresses (New Here, About Us...) must answer 200, not just "not redirected".
#                        Use at production cutover.
#   --layer NAME         only check rows answered by that layer: nginx, php, or page (default: all)
#   --table FILE         add another table (repeatable); the default is deploy/redirects/expected.csv
# Examples:
#   scripts/check-redirects.sh https://staging.pellanazarene.org --user church:PASSWORD --allow-fallback
#   scripts/check-redirects.sh https://pellanazarene.org --pages-must-exist
set -euo pipefail

BASE="${1:?Usage: $0 BASE_URL [--user U:P] [--insecure] [--resolve H:P:IP] [--allow-fallback] [--pages-must-exist] [--table FILE]}"
shift
HERE="$(cd "$(dirname "$0")/.." && pwd)"
CURL=(curl -s -o /dev/null --max-time 20 -w '%{http_code} %{redirect_url}')
ALLOW_FALLBACK=0; PAGES_MUST_EXIST=0; ONLY_LAYER=""; TABLES=()
while [[ $# -gt 0 ]]; do
	case "$1" in
		--user) CURL+=(-u "$2"); shift 2 ;;
		--insecure) CURL+=(-k); shift ;;
		--resolve) CURL+=(--resolve "$2"); shift 2 ;;
		--allow-fallback) ALLOW_FALLBACK=1; shift ;;
		--pages-must-exist) PAGES_MUST_EXIST=1; shift ;;
		--layer) ONLY_LAYER="$2"; shift 2 ;;
		--table) TABLES+=("$2"); shift 2 ;;
		*) echo "Unknown option: $1" >&2; exit 2 ;;
	esac
done
[[ ${#TABLES[@]} -gt 0 ]] || TABLES=("$HERE/deploy/redirects/expected.csv")
BASE="${BASE%/}"

pass=0; fail=0
report() { # ok|FAIL path message
	if [[ "$1" == ok ]]; then pass=$((pass+1)); printf '  ok    %-62s %s\n' "$2" "$3"; else fail=$((fail+1)); printf '  FAIL  %-62s %s\n' "$2" "$3"; fi
}
matches() { # actual expected  (expected may end in /* = anything below that prefix, but not the prefix itself)
	local actual="$1" expected="$2"
	if [[ "$expected" == */\* ]]; then
		local prefix="${expected%\*}"
		[[ "$actual" == "$prefix"* && "$actual" != "$prefix" ]]
	else
		[[ "$actual" == "$expected" ]]
	fi
}

for table in "${TABLES[@]}"; do
	[[ -f "$table" ]] || { echo "No such table: $table" >&2; exit 2; }
	echo "== $(basename "$table") against $BASE"
	while IFS=, read -r path status location fallback layer note || [[ -n "${path:-}" ]]; do
		[[ -z "${path:-}" || "$path" == \#* || "$path" == path ]] && continue
		[[ -n "$ONLY_LAYER" && "$layer" != "$ONLY_LAYER" ]] && continue
		out="$("${CURL[@]}" "$BASE$path" || true)"
		code="${out%% *}"; got="${out#* }"
		got="$(sed -E 's#^https?://[^/]+##' <<<"$got")"      # compare paths only, so any host works
		case "$status" in
			stays)
				if [[ "$PAGES_MUST_EXIST" == 1 ]]; then
					[[ "$code" == 200 ]] && report ok "$path" "answers 200" || report FAIL "$path" "must answer 200, got $code"
				else
					[[ "$code" != 301 && "$code" != 302 && "$code" != 308 && "$code" != 410 ]] && report ok "$path" "not redirected ($code)" || report FAIL "$path" "must not redirect or be gone, got $code ${got}"
				fi ;;
			301)
				if [[ "$code" == 301 ]] && matches "$got" "$location"; then report ok "$path" "301 -> $got"
				elif [[ "$code" == 301 && "$ALLOW_FALLBACK" == 1 && -n "$fallback" && "$got" == "$fallback" ]]; then report ok "$path" "301 -> $got (fallback: the new page does not exist yet)"
				else report FAIL "$path" "wanted 301 -> $location${fallback:+ (fallback $fallback)}, got $code ${got}"; fi ;;
			410)
				[[ "$code" == 410 ]] && report ok "$path" "410 gone" || report FAIL "$path" "wanted 410, got $code ${got}" ;;
			*) report FAIL "$path" "unknown status '$status' in the table" ;;
		esac
	done < "$table"
done
echo
echo "Passed: $pass   Failed: $fail"
[[ "$fail" == 0 ]]
