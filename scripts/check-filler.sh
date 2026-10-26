#!/usr/bin/env bash
# Looks for leftovers from the old website (Lorem ipsum, demo names, wrong hours, sample WordPress posts...) listed
# in deploy/filler.txt. Changes nothing; exits 0 when everything is clean and 1 when it finds something.
#
# Usage:
#   check-filler.sh WP_PATH                         the whole database
#   check-filler.sh WP_PATH --url https://staging.pellanazarene.org --user church:PASSWORD
#                                                   ...and every published page as a visitor sees it
#   check-filler.sh WP_PATH --repo /var/www/staging/repo
#                                                   ...and the code (theme, plugins, scripts)
# Run it on staging before launch (MIG-8) and again right after cutover.
set -euo pipefail

WP_PATH="${1:?Usage: $0 WP_PATH [--url URL [--user USER:PASSWORD] [--insecure]] [--repo DIR]}"
shift
URL=""; USERPW=""; REPO=""; INSECURE=""
while [[ $# -gt 0 ]]; do
	case "$1" in
		--url)      URL="${2:?--url needs an address}"; shift 2 ;;
		--user)     USERPW="${2:?--user needs USER:PASSWORD}"; shift 2 ;;
		--repo)     REPO="${2:?--repo needs a folder}"; shift 2 ;;
		--insecure) INSECURE=1; shift ;;
		*) echo "Unknown option: $1" >&2; exit 2 ;;
	esac
done

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIST="${PN_FILLER_LIST:-$here/../deploy/filler.txt}"
[[ -r "$LIST" ]] || { echo "Cannot read the list of leftovers: $LIST" >&2; exit 2; }
found=0

# Lists of words for grep: one per line.
words="$(mktemp)"
# The words to look for in the database and on the pages (kinds "text" and "site"), and in the code (kind "text" only).
code_words="$(mktemp)"; code_files="$(mktemp)"; trap 'rm -f "$words" "$code_words" "$code_files"' EXIT
awk -F'|' '$1 ~ /^[[:space:]]*(text|site)[[:space:]]*$/ { gsub(/^[ \t]+|[ \t]+$/, "", $2); if ($2 != "") print $2 }' "$LIST" > "$words"
awk -F'|' '$1 ~ /^[[:space:]]*text[[:space:]]*$/ { gsub(/^[ \t]+|[ \t]+$/, "", $2); if ($2 != "") print $2 }' "$LIST" > "$code_words"
[[ -s "$words" ]] || { echo "The list has no words to look for." >&2; exit 2; }

echo "== 1. The database"
out="$(wp --path="$WP_PATH" eval-file "$here/check-filler.php" "$LIST")" || { echo "$out" >&2; exit 2; }
while IFS='|' read -r tag kind where id what context; do
	case "$tag" in
		FOUND) found=$((found+1)); printf '  FOUND  "%s" in %s (row %s): %s\n' "$what" "$where" "$id" "$context" ;;
		SCANNED) echo "  looked through $kind" ;;
	esac
done <<<"$out"

if [[ -n "$URL" ]]; then
	echo "== 2. Every published page, as a visitor sees it"
	curl_args=(-sS -m 40 -L)
	[[ -z "$INSECURE" ]] || curl_args+=(-k)
	[[ -z "$USERPW" ]] || curl_args+=(-u "$USERPW")
	curl_args+=(-f)
	if ! post_urls="$(wp --path="$WP_PATH" post list --post_type=any --post_status=publish --field=url)"; then
		echo "Could not enumerate published URLs with WP-CLI." >&2
		exit 2
	fi
	urls="$(printf '%s\n' "$post_urls" | awk '/^https?:\/\//')"
	urls="$(printf '%s\n%s/\n%s/feed/\n' "$urls" "${URL%/}" "${URL%/}" | sed '/^$/d' | sort -u)"
	pages=0
	while IFS= read -r page; do
		# Pages are listed with the site's own address; ask the address we were given instead.
		path="${page#*://*/}"; target="${URL%/}/$path"
		if ! body="$(curl "${curl_args[@]}" "$target" 2>/dev/null)"; then
			echo "  could not read $target" >&2
			found=$((found+1))
			continue
		fi
		pages=$((pages+1))
		# One word at a time, so every leftover on the page is reported (not just the first), as written on the page.
		while IFS= read -r word; do
			hit="$(grep -oiF -m1 -- "$word" <<<"$body" || true)"
			if [[ -n "$hit" ]]; then found=$((found+1)); printf '  FOUND  "%s" on %s\n' "$hit" "$target"; fi
		done <"$words"
	done <<<"$urls"
	echo "  looked at $pages pages"
fi

if [[ -n "$REPO" ]]; then
	echo "== 3. The code"
	if ! (cd "$REPO" && find wp-content/themes/pella-nazarene wp-content/plugins/pn-sermons wp-content/mu-plugins deploy/nginx deploy/forms deploy/redirects scripts -type f \
		! -name 'check-filler.*' ! -path '*/assets/*' ! -name '*.webp' ! -name '*.jpg' ! -name '*.png' ! -name '*.woff2' ! -name '*.ico' ! -name '*.svg' \
		-print0 >"$code_files"); then
		echo "Could not enumerate code files in: $REPO" >&2
		exit 2
	fi
	code_hits=0
	while IFS= read -r -d '' file; do
		if hits="$(grep -nioF -f "$code_words" -- "$REPO/${file#./}")"; then
			while IFS= read -r hit; do found=$((found+1)); code_hits=$((code_hits+1)); printf '  FOUND  %s\n' "$hit"; done <<<"$hits"
		else
			grep_status=$?
			if [[ $grep_status -ne 1 ]]; then
				echo "Could not scan code file: $REPO/${file#./}" >&2
				exit 2
			fi
		fi
	done <"$code_files"
	[[ $code_hits -gt 0 ]] || echo "  nothing in the code"
fi

echo
if [[ $found -eq 0 ]]; then
	echo "Clean: nothing from the old site's filler was found."
	exit 0
fi
echo "$found leftover(s) found. Fix them (or, if a word is fine on purpose, take its line out of deploy/filler.txt and say why in the pull request)."
exit 1
