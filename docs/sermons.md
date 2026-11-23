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
wp pn-sermons backfill --dry-run
```

The channel's titles do not follow one pattern ("Conquering Worry", "Love Your Enemies", "Sunday Jan 7th - Sunday AM Service"), so do **not** filter by title for the main import. The 20-minute minimum already drops clips and Shorts, and everything arrives as a draft for review. Use `--exclude` for the few things that should not be on the site (a memorial service, for example).

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

## Speaker photos

On the Sermons page each card shows the speaker's photo as a small round badge on the corner of the sermon artwork, so a page of one series (every card with the same graphic) still shows who preached each week. It is not shown on the sermon's own page, or on a speaker's own page, where every card would be the same face.

- A staff speaker gets their staff photo from the About page automatically: the speaker's slug (`todd-olson`) matches the photo (`staff-todd-olson` in `wp-content/themes/pella-nazarene/assets/images/photos/manifest.json`).
- For anyone else, or to use a different picture, go to **Sermons → Speakers**, edit the speaker, and use **Photo → Choose photo**. A square head-and-shoulders picture works best; it is shown at about 60 pixels. **Remove** goes back to the staff photo, or to no photo.
- A speaker with no photo (a guest) gets no badge; the card looks as it did before.

The photo field and its fallback are in the plugin (`includes/class-speakers.php`); the badge itself is the theme's (`inc/sermons.php`, styles in `style.css`). Changes show for visitors once the page cache expires.

## Weekly blog post drafts

Each week the site turns Sunday's sermon into a **draft** blog post for the pastor who preached it (FEAT-9). It is a standalone post on the sermon's theme, not a report of the sermon, and it ends with a link to watch the full message. Nothing is ever published automatically.

1. The daily import finds Sunday's video. If it recognizes the speaker, the draft is started straight away; otherwise it starts when staff set the speaker and publish the sermon. Only sermons from the last 14 days get a post, so the backfill command never drafts any, and a sermon only ever gets one.
2. The site reads the video's captions with **yt-dlp** and keeps them in the sermon's **Transcript** box (bottom of the sermon's edit screen; never shown on the website).
3. **Claude** writes the post from the transcript, following the writing instructions under **Settings → Sermons**.
4. **Pexels** supplies a free stock photo as the featured image, chosen from search words Claude suggests. The photographer is credited in the image caption. Without a Pexels key, or with no good match, the sermon's own picture is used.
5. The draft is saved under the preacher's account in the **Sermon Companion** category, and the preacher and the "Also notify" addresses get an email with a link to it and a list of names and verses to double-check (captions mishear them).

**When the captions can't be read**: YouTube sometimes blocks servers in data centers ("confirm you're not a bot"), and captions can take hours to appear after a live stream. The site tries again every 6 hours for about a day (a block is not retried). After that, the same people get one email asking them to copy the transcript from YouTube (under the video: **...more → Show transcript**), paste it into the sermon's Transcript box, and click Save. The draft follows a few minutes later.

### Setting it up

1. **yt-dlp**: on the server, as `deploy`, from a repo checkout: `scripts/install-yt-dlp.sh`. It installs pinned versions of yt-dlp and Deno (the JavaScript runtime yt-dlp needs for YouTube) and checks their checksums. yt-dlp runs only from cron through WP-CLI; the website itself (PHP-FPM) is not allowed to start programs.
2. **Claude key**: at [console.anthropic.com](https://console.anthropic.com/), create an account for the church, set a monthly spending limit (a post costs a few cents), and create an API key. Put it in `wp-config.php` as `PN_ANTHROPIC_API_KEY`.
3. **Pexels key** (free): at [pexels.com/api](https://www.pexels.com/api/), sign in and request a key. Put it in `wp-config.php` as `PN_PEXELS_API_KEY`.
4. **Link each preacher to their account**: **Sermons → Speakers**, edit the speaker, choose their **WordPress user**. Drafts are saved under that account and the email goes to that address. A speaker who isn't linked (a guest) gets nothing; the "Also notify" addresses still do.
5. **Switch it on**: **Settings → Sermons → Weekly blog post drafts**, tick **Draft blog posts**, and put the office address in **Also notify**.
6. **Check**: `sudo -u pn-production-php wp --path=/var/www/pellanazarene/public pn-sermons status` shows the keys (never their values) and whether yt-dlp was found. To try one sermon now: `... pn-sermons draft-post <sermon ID> --transcript-only` reads the captions and changes nothing; without `--transcript-only` it makes the draft and sends the email.

As for every key: in `wp-config.php` only, edited in an editor on the server (not typed into a command), never in the repo, chat or email. **Staging** gets its own keys or none, never production's, and its email is blocked anyway.

### Changing what it writes

The writing instructions are in **Settings → Sermons**. Edit them there; the format the site needs back is added separately, so editing them can't break it. A site still on the original instructions gets the current ones automatically, and once someone edits them, their version is kept.

### Troubleshooting

| What happened | What to do |
|---|---|
| No draft and no email | `pn-sermons status`: is it switched on, is the Claude key set? Was the sermon from the last 14 days, with a speaker? With `WP_DEBUG_LOG` on, the log says why (lines start `[pn-sermons]`). |
| Every week asks for a pasted transcript, "blocked" in the log | YouTube is blocking the server. Pasting works; or update yt-dlp (below), which often helps. |
| "yt-dlp NOT FOUND" | Run `scripts/install-yt-dlp.sh`. |
| yt-dlp stopped working | YouTube changes often break old versions. Pick a yt-dlp release at least two weeks old, update its version and checksum in `scripts/install-yt-dlp.sh` in a pull request, merge, and run the script again on the server. |
| The picture doesn't fit | Change it under **Featured image** in the post. Pexels photos are never reused, and every photo's Pexels page is stored on the image (`pn_pexels_url`). |
| Draft went to the wrong person | Fix the speaker's **WordPress user** under Sermons → Speakers, then change the post's author. |

## How the importer reads a video

The descriptions are typed by hand, so the parser is forgiving and conservative. It looks at the first lines of the description for pieces separated by ` - `, `–`, `|` or `:`, and recognises:

- **Speaker**: Todd Olson, Cindy Olson, Rob Hammann, Floyd Ware (with or without "Pastor" or "Associate Pastor"), or any "Pastor/Rev./Dr. First Last".
- **Scripture**: any Bible book name or abbreviation followed by chapter and verses, such as `John 14:1-4`.
- **Series**: the known series in `wp-content/plugins/pn-sermons/data/sermon-rules.json`. With two unknown pieces it treats the first as the series and the second as the message, and leaves a note.
- **Service date**: from the video title ("Sunday Morning Service May 24, 2026", "2020 08 02 …", "20200830 …"; a leading date is also removed from the sermon's title), otherwise the day it was published in Central time. The post is dated the service day, not the upload day.

When it cannot tell, it keeps the YouTube title and leaves the details empty rather than guess, and records why in the import note. The original YouTube title and description are always kept in private fields.

### Rules and overrides

Two small files control the exceptions; change them in a pull request:

- `data/sermon-rules.json`: the speakers (with the spellings they appear under), the series (with a match phrase), and words to ignore. "Holiness in the House: A Study in Titus" collects every video whose description names "Holiness in the House".
- `data/sermon-overrides.json`: hand-set details for particular videos, matched by YouTube ID or service date: the Pentecost sermon (with its real description), God's Messenger, and Vision Sunday 1 and 2. Overrides also record each sermon's **old address**, so a link to the old site goes to the right new sermon (MIG-2).

Staff decided on 2026-11-16 not to migrate these four old sermons; they were never imported on staging, and their old addresses go to the Sermons page (`deploy/redirects/expected.csv`). If they are ever imported, the overrides still give them their old addresses; then change those four rows back to `/sermons/*`. Check `docs/redirects.md` before cutover.

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

`deploy/tests/sermon-parser-test.php` tests the parser on real descriptions. `deploy/tests/sermon-import-check.sh` runs the whole backfill against real WordPress and a fake YouTube (pagination, Shorts, live and private videos, duplicates, errors, idempotence, redirects, and that the key never appears in output or the database). `deploy/tests/companion-check.sh` tests the weekly blog draft against real WordPress with a fake yt-dlp, Claude and Pexels (queuing, retries, a hanging yt-dlp, the "transcript needed" email, the photo rules, that drafts are never published, and that keys never appear). All three run in CI.
