# Checking for leftovers from the old site

The old website was built from a template, and it still had its sample content: Lorem ipsum text, made-up group leaders (Paul & Olivia Brunson, Will & Gloria Jackson), a blog post from "Omega Church" (the platform's old name), sample events and groups, and the wrong office hours. None of it was migrated. `scripts/check-filler.sh` is the safety net: it looks for any of it, and any of WordPress's own sample content, before launch.

## Run it

On the server, as `deploy`:

```bash
cd /var/www/staging/repo
scripts/check-filler.sh /var/www/staging/public \
  --url https://staging.pellanazarene.org --user STAGINGUSER:PASSWORD \
  --repo /var/www/staging/repo
```

It checks, in order:
1. **The database**: every text column of every table (page and post text, summaries, titles, settings, comments, menus, profiles, categories, custom fields, even the trash and drafts) for the words in `deploy/filler.txt`, and for sample posts, pages, events and groups with those exact titles.
2. **Every published page as a visitor sees it** (needs `--url`, and `--user` while staging has a password): what is in the code or a template as well as the database.
3. **The code** (needs `--repo`): the theme, the plugins we wrote and the scripts.

It changes nothing. It says `Clean` and exits 0, or lists each leftover (what, where, which row) and exits 1.

Run it on staging before launch (**do it again right after cutover**, on the live site: `--url https://pellanazarene.org`).

## What counts as a leftover

The list is `deploy/filler.txt`: one line per leftover, with the reason. Three kinds:
- `text`: must not appear anywhere (database, pages or code).
- `site`: must not appear in the database or on the pages, but a code comment may mention it (for example the redirect rules explain the old site's theme).
- `title`: no post, page, sermon, menu item or category may have exactly this title (WordPress's "Hello world!" and "Sample Page", the old filler events and groups, the old blog posts).

If a word ever needs to be allowed on purpose, take its line out of the list and explain why in the pull request. New leftovers you notice can be added the same way.

## Fixing what it finds

- A WordPress sample post or page ("Hello world!", "Sample Page", "Privacy Policy" draft): delete it in the admin, then empty the trash.
- Text in a page: edit the page (and look at Revisions if it keeps coming back).
- Text in a setting: Settings in the admin (the tagline is the usual one).
- A template part someone edited (the footer, the Service info block): Appearance → Editor → Patterns → Template parts → ⋯ → Clear customizations.

## Testing

`deploy/tests/filler-check.sh` runs the real script against real WordPress: a fresh WordPress is *not* clean (its sample content is found), real content with similar words is *not* a false alarm, leftovers planted in 17 different places are each found and then pass once removed, filler that is only in code (not the database) is caught by the page check, and the code check finds filler planted in a theme file. It also proves each check by breaking the scanner. It runs in CI.
