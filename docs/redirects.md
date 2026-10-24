# Old-site redirects

Every address on the old Ministry Designs site that anyone might have bookmarked, linked to, or that Google has indexed, and what happens to it now. **The single source of truth is `deploy/redirects/expected.csv`.**

## What happens to each old address

| Old address | Now | How |
|---|---|---|
| `/new-here/`, `/about-us/`, `/kids/`, `/giving/`, `/contact/`, `/resources/`, `/small-groups/` | **Same address** (nothing to redirect) | The pages are rebuilt under the same names (MIG-3) |
| `/events-2/` and everything under it | 301 to `/events/` | Nginx |
| `/news/` and everything under it | 301 to `/blog/` (old posts were template filler and are not carried over) | Nginx |
| `/small-groups/<a group>/` (the 6 filler groups) | 301 to `/small-groups/` | Nginx |
| `/sermons-t/` | 301 to `/sermons/` | Nginx |
| `/sermons-t/<sermon>/` | 301 to the sermon's new page, **or to `/sermons/` if it does not exist** | WordPress (it has to look) |
| `/sermons-t/u_speaker/<name>/` | 301 to `/sermons/speaker/<name>/`, or `/sermons/` | WordPress |
| `/sermons-t/category/<series>/` | 301 to `/sermons/series/<series>/`, or `/sermons/` | WordPress |
| ~200 empty archives: sermon topics and scripture books, event categories and tags, small-group tags, categories and "group details" | **410 Gone** (they never held anything; 410 makes search engines drop them faster than a 404) | Nginx |

Visitors are never sent from one missing page to another: if a sermon's new page doesn't exist, they land on the sermon list.

**Two layers on purpose.** Nginx (`deploy/nginx/pn-redirects.conf` and `pn-redirects-server.conf`) answers everything that doesn't depend on the database, without starting PHP. WordPress (`pn_legacy_url_action()` in `wp-content/mu-plugins/pn-site.php`) answers the ones that do, and repeats the fixed rules as a safety net for local development, where Nginx isn't in front. The test stack proves both layers: it stops PHP and requires the Nginx rows to still work, so a gap in the Nginx rules can't hide behind the PHP copy.

## Checking a real site

```bash
# Staging, before the sermons are imported (MIG-6): accept "goes to /sermons/" for sermons not imported yet
scripts/check-redirects.sh https://staging.pellanazarene.org --user church:PASSWORD --allow-fallback

# Production, at and after cutover: strict. Every sermon, speaker, and series must reach its real page,
# and the kept pages (New Here, About Us...) must answer 200
scripts/check-redirects.sh https://pellanazarene.org --pages-must-exist
```

Never use `--allow-fallback` at cutover: it would pass while old sermon links still land on the list.

## Before cutover (MIG-9), these must be true

1. **The targets exist:** `/events/` and `/small-groups/` (MIG-7, built), the seven kept pages (MIG-3), and `/blog/` (FEAT-7, still to build).
2. **The sermon import (MIG-6) sets two things the redirects rely on:**
   - speakers with the slugs `todd-olson`, `cindy-olson`, `rob-hammann`, `floyd-ware`, and a series with the slug `holiness-in-the-house-a-study-in-titus`;
   - for any sermon whose new address differs from its old one, a post meta value `pn_legacy_slug` holding the old slug (for example `gods-messenger-malachi-31-4`). The redirect finds the sermon by its new slug first, then by `pn_legacy_slug`.
3. `scripts/check-redirects.sh https://pellanazarene.org --pages-must-exist` passes with no flags.

## Changing the rules

1. Edit `deploy/redirects/expected.csv` (and `deploy/tests/redirects-extra.csv` for edge cases only the test stack can set up). Columns are explained at the top of the file.
2. Change the matching rule: Nginx (`deploy/nginx/pn-redirects.conf`) for fixed answers, WordPress (`pn_legacy_url_action()`) for ones that depend on what exists.
3. `python3 deploy/tests/redirects-coverage.py` (every URL in the old-site inventory has a row, and the table agrees with the inventory's own decisions) and `deploy/tests/redirects-check.sh` (the stack test). CI runs both.
4. After deploying, run `scripts/apply-server-tuning.sh` to install the Nginx maps and snippet. For an existing installation, back up each active site HTTPS vhost and add `include snippets/pn-redirects-server.conf;` inside its server block before the location blocks if it is not already present, as shown in the updated vhost templates. The tuning script does not update active vhosts. Run `sudo nginx -t && sudo systemctl reload nginx`, then run `scripts/check-redirects.sh` against the live site.

Not covered: links into the old media folder (`/mt-content/uploads/...`); they now return 404. Only worth a rule if something external is found linking to them.
