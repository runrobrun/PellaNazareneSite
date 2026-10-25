#!/usr/bin/env bash
# Creates the Contact and Prayer Request forms in Fluent Forms from deploy/forms/*.json. Safe to re-run: forms that
# already exist are left alone, so staff edits are never overwritten.
#
# Usage:
#   PN_CONTACT_TO=a@x.org PN_PRAYER_TO="b@x.org,c@x.org" create-forms.sh WP_PATH
#   create-forms.sh WP_PATH --replace contact     # also reset one form to the file's current version
# Who receives the email comes from PN_CONTACT_TO and PN_PRAYER_TO (not stored in the repo).
# Set PN_WP_USER=www-data (on the server) to run WordPress as the web user, like create-pages.sh.
set -euo pipefail

WP_PATH="${1:?Usage: $0 WP_PATH [--replace NAME...]}"
shift
replace=()
if [[ "${1:-}" == "--replace" ]]; then
	shift
	[[ $# -gt 0 ]] || { echo "--replace needs at least one form name." >&2; exit 1; }
	replace=("$@")
elif [[ $# -gt 0 ]]; then
	echo "Unknown option: $1" >&2; exit 1
fi

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
wp_cmd=(wp)
if [[ -n "${PN_WP_USER:-}" ]]; then
	wp_cmd=(sudo -n -u "$PN_WP_USER" env "WP_CLI_CACHE_DIR=/tmp/wpcli-$PN_WP_USER" "PN_CONTACT_TO=${PN_CONTACT_TO:-}" "PN_PRAYER_TO=${PN_PRAYER_TO:-}" wp)
fi
"${wp_cmd[@]}" --path="$WP_PATH" eval-file "$here/create-forms.php" ${replace[@]+"${replace[@]}"}   # (this form also works in the older bash on a Mac)
