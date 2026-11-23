#!/usr/bin/env bash
# Installs (or updates) yt-dlp and Deno, the JavaScript runtime yt-dlp needs for YouTube, at pinned
# versions with checked SHA-256 sums. The sermon plugin uses yt-dlp to read a sermon's captions so it can
# draft the weekly blog post (FEAT-9, docs/sermons.md). Both sites on the server share the one copy.
#
# Run as the administrative deploy user from a repo checkout:  scripts/install-yt-dlp.sh
# Safe to re-run: a binary that already matches its checksum is left alone.
#
# To update (YouTube changes often break old yt-dlp versions): pick a release at least two weeks old,
# change the version and its sum below in a pull request (sums: SHA2-256SUMS on the yt-dlp release page,
# the .sha256sum file next to the Deno zip), merge, then run this again on the server.
set -euo pipefail

YTDLP_VERSION=2026.10.26
YTDLP_SHA256=767ba9dce7052aa196f8b12652f433d76de128ba168966fa84b6394d5e6c6795
DENO_VERSION=v2.9.8
DENO_SHA256=e890975bc2549807bbcc0b584ffafda72cc7c93349dd99c80483dd784f6c4609
BIN=/usr/local/bin

[[ $EUID -ne 0 ]] || { echo "Run as the administrative deploy user, not root." >&2; exit 1; }
sudo -n true || { echo "deploy needs passwordless sudo." >&2; exit 1; }
[[ "$(uname -m)" == "x86_64" ]] || { echo "These pinned builds are for x86_64; this is $(uname -m)." >&2; exit 1; }

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

matches() { [[ -f "$1" ]] && echo "$2  $1" | sha256sum --check --status; }

echo "==> yt-dlp $YTDLP_VERSION"
if matches "$BIN/yt-dlp" "$YTDLP_SHA256"; then
	echo "Already installed."
else
	curl -fsSL --proto '=https' --max-time 120 -o "$tmp/yt-dlp" \
		"https://github.com/yt-dlp/yt-dlp/releases/download/$YTDLP_VERSION/yt-dlp_linux"
	echo "$YTDLP_SHA256  $tmp/yt-dlp" | sha256sum --check --quiet || { echo "yt-dlp checksum mismatch; nothing installed." >&2; exit 1; }
	sudo install -m 755 -o root -g root "$tmp/yt-dlp" "$BIN/yt-dlp"
fi

echo "==> Deno $DENO_VERSION"
if [[ -x "$BIN/deno" ]] && [[ "$("$BIN/deno" --version 2>/dev/null | head -1)" == "deno ${DENO_VERSION#v} "* ]]; then
	echo "Already installed."
else
	curl -fsSL --proto '=https' --max-time 180 -o "$tmp/deno.zip" \
		"https://github.com/denoland/deno/releases/download/$DENO_VERSION/deno-x86_64-unknown-linux-gnu.zip"
	echo "$DENO_SHA256  $tmp/deno.zip" | sha256sum --check --quiet || { echo "Deno checksum mismatch; nothing installed." >&2; exit 1; }
	python3 -m zipfile -e "$tmp/deno.zip" "$tmp/deno-x"
	sudo install -m 755 -o root -g root "$tmp/deno-x/deno" "$BIN/deno"
fi

echo "==> Check"
"$BIN/yt-dlp" --version
"$BIN/deno" --version | head -1
echo "Next: as each site's PHP user, run 'wp pn-sermons status' to confirm the site finds yt-dlp (docs/sermons.md)."
