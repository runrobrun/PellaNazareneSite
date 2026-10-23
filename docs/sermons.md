# Sermons: importing from YouTube

The sermons on the site come from the YouTube channel (@pellanazarene), not from the old website. Two things bring them in:

- **New sermons, automatically.** Once a day the site reads the channel's RSS feed (the latest 15 videos) and adds new ones as drafts. This needs no key.
- **The whole history, once, by command.** `wp pn-sermons backfill` reads every upload through the YouTube Data API. This needs an API key.

Every imported sermon is a **draft** for a person to look over and publish, except the four that have hand-written details from the old site (see "Overrides"), which are published.

## One-time setup: the API key

The key only has to exist while you run the backfill, but it is fine to leave in place.

1. In [Google Cloud Console](https://console.cloud.google.com/), create a project (for example "Pella Nazarene website"). Any Google account works; it does not need to be the YouTube channel's owner.
2. **APIs & Services → Library → YouTube Data API v3 → Enable.**
3. **APIs & Services → Credentials → Create credentials → API key.**
4. Open the key and **restrict it**:
   - *API restrictions*: YouTube Data API v3 only.
   - *Application restrictions*: IP addresses, and add the server's address (`172.237.145.140` for the Linode). A copied key then does nothing from anywhere else.
5. Put it in `wp-config.php` on the server (never in the repo, never in chat or an email):

   ```php
   define( 'PN_YOUTUBE_API_KEY', '…' );
   define( 'PN_YOUTUBE_CHANNEL_ID', 'UCzv2v5YupU-E2blu_0BAhAg' );
   ```

   Edit the file in an editor on the server rather than typing the key into a command, so it does not end up in shell history.
6. `wp pn-sermons status` confirms the key and channel are set. It never prints the key.

The free quota (10,000 units a day) is far more than needed: reading 300 videos costs about 10 units.

## Running the backfill

Always look first. A dry run reads YouTube and prints what *would* happen; it changes nothing.

```bash
cd /var/www/pellanazarene.org/public     # or the staging site
wp pn-sermons backfill --dry-run --filter="Sunday Morning Service|Sunday AM Worship"
```

The table lists every video with what the importer decided: the service date, speaker, series, title and scripture it found, and, for the ones it skips, why (too short, not public, a live stream, title does not match). Adjust until the list is right, then run it for real without `--dry-run`.

| Option | What it does |
|---|---|
| `--filter="a\|b"` | Only titles containing one of these. Use it to keep out announcements and clips. |
| `--exclude="a\|b"` | Skip titles containing one of these. |
| `--min-minutes=20` | Skip anything shorter (default 20). Removes Shorts and clips. |
| `--since=2024-01-01`, `--until=…` | Limit by service date. |
| `--limit=10` | Stop after creating this many. Good for a first trial. |
| `--status=draft\|pending\|publish` | Status of new sermons (default `draft`). |
| `--skip-thumbnails` | Do not download a picture for each sermon (quicker). |
| `--format=csv\|json` | Report format. |

Start on **staging** with `--limit=10`, check the drafts, then run the rest, then do production.

It is safe to run again. A video already on the site is never imported twice, even if its sermon is in the trash, and a sermon someone has edited is never overwritten.

## Reviewing the drafts

Under **Sermons** in the admin, open each draft and check the title, speaker, series, scripture and date, then publish. If the importer was unsure about something it says so in the **Import note** field. Sermons with no details in the YouTube description keep the YouTube title; add the speaker and scripture by hand.

To remove a sermon, put it in the **trash**; do not empty the trash. A trashed sermon is remembered, so the next import does not bring it back.

## How the importer reads a video

The descriptions are typed by hand, so the parser is forgiving and conservative. It looks at the first lines of the description for pieces separated by ` - `, `–`, `|` or `:`, and recognises:

- **Speaker**: Todd Olson, Cindy Olson, Rob Hammann, Floyd Ware (with or without "Pastor" or "Associate Pastor"), or any "Pastor/Rev./Dr. First Last".
- **Scripture**: any Bible book name or abbreviation followed by chapter and verses, such as `John 14:1-4`.
- **Series**: the known series in `wp-content/plugins/pn-sermons/data/sermon-rules.json`. With two unknown pieces it treats the first as the series and the second as the message, and leaves a note.
- **Service date**: from the video title ("Sunday Morning Service May 24, 2026"), otherwise the day it was published in Central time. The post is dated the service day, not the upload day.

When it cannot tell, it keeps the YouTube title and leaves the details empty rather than guess, and records why in the import note. The original YouTube title and description are always kept in private fields.

### Rules and overrides

Two small files control the exceptions; change them in a pull request:

- `data/sermon-rules.json`: the speakers (with the spellings they appear under), the series (with a match phrase), and words to ignore. "Holiness in the House: A Study in Titus" collects every video whose description names "Holiness in the House".
- `data/sermon-overrides.json`: hand-set details for particular videos, matched by YouTube ID or service date: the Pentecost sermon (with its real description), God's Messenger, and Vision Sunday 1 and 2. Overrides also record each sermon's **old address**, so a link to the old site goes to the right new sermon (MIG-2).

If a Vision Sunday video is not found on the channel, those two overrides simply do not apply, and their old addresses fall back to the Sermons page. Check `docs/redirects.md` before cutover.

## Troubleshooting

| Message | What to do |
|---|---|
| "No YouTube API key is set" | Add `PN_YOUTUBE_API_KEY` to `wp-config.php`. |
| "YouTube does not accept the API key" | The key was copied wrongly or deleted in Google Cloud. |
| "The API key is restricted to other addresses" | Add this server's IP to the key's restrictions. |
| "The daily YouTube quota is used up" | Wait until midnight Pacific and run it again; nothing is lost. |
| "Could not reach YouTube" | A network problem; run it again. |

The key is removed from every message the importer prints or logs. If it ever leaks, delete it in Google Cloud and create a new one.

## Testing

`deploy/tests/sermon-parser-test.php` tests the parser on real descriptions. `deploy/tests/sermon-import-check.sh` runs the whole backfill against real WordPress and a fake YouTube (pagination, Shorts, live and private videos, duplicates, errors, idempotence, redirects, and that the key never appears in output or the database). Both run in CI.
