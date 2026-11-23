#!/bin/sh
# Test stand-in for yt-dlp (deploy/tests/companion-check.sh). Records its arguments, one per line, in
# /tmp/ytdlp-args.log. /tmp/ytdlp-mode picks what it does: ok (default) writes captions; blocked, none, slow (hangs).
printf '%s\n' "$@" > /tmp/ytdlp-args.log
env > /tmp/ytdlp-env.log
mode="$(cat /tmp/ytdlp-mode 2>/dev/null || echo ok)"
dir=""; prev=""; url=""
for a in "$@"; do
	[ "$prev" = "--paths" ] && dir="$a"
	prev="$a"; url="$a"
done
id="${url##*v=}"
case "$mode" in
	blocked) echo "ERROR: [youtube] $id: Sign in to confirm you're not a bot. Use --cookies-from-browser" >&2; exit 1 ;;
	none)    echo "[info] There are no subtitles for the requested languages"; exit 0 ;;
	slow)    sleep 600 ;;
	*)       cp /repo/deploy/tests/fixtures/captions.json3 "$dir/$id.en-orig.json3" ;;
esac
