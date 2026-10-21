# Backlog

Work for the pellanazarene.org migration to the Linode server. Ordered roughly by dependency, not strictly by priority.

**Status:** `todo` · `doing` · `review` · `done` · `blocked`
**Size:** S (under half a day) · M (about a day) · L (several days)

## Applies to every story

Each story must meet the Definition of Done in `CLAUDE.md` before its PR is opened. Two gates are non-negotiable:

1. **Security check:** `/security-review` run on the branch, findings fixed or justified, WordPress security checklist confirmed.
2. **Performance:** no regression against the targets in `CLAUDE.md` (LCP < 2.5 s, CLS < 0.1, mobile score 90+).

Story IDs: `INF` infrastructure, `DEV` tooling, `MIG` content migration, `FE` front end, `FEAT` features, `SEC` security, `PERF` performance, `OPS` operations.

---

## Epic 1: Foundation

### INF-1 Provision the Linode server · M · doing
Done so far: Linode created (4 GB, Ubuntu 24.04, Chicago) with Cloud Firewall `pella-nazarene-fw` (inbound 22/80/443 only); `scripts/bootstrap-server.sh` run; `deploy` user with key login and passwordless sudo; ufw, fail2ban, unattended upgrades, Central time zone; MariaDB and Redis bound to localhost; production and staging databases and users created (passwords in `/root/pella-secrets/`, root-only).
Still open: hostname set (`pella-web`) is done; **lock SSH** (`bootstrap-server.sh deploy --lock-ssh`: no root login, no passwords) is deferred until the server is verified, but must be done before production launch; take a Linode snapshot first.
Build the server per `docs/setup.md`: Ubuntu 24.04, Nginx, PHP 8.3 FPM, MariaDB, Redis, WP-CLI, unattended upgrades, timezone.
- Server reproducible from `docs/setup.md` alone; fix the doc where it is wrong.
- Non-root deploy user, SSH key-only, root login disabled.
- Firewall (ufw) allows only 22, 80, 443; fail2ban or equivalent on SSH.
- Linode backups or snapshots enabled.

### INF-2 Staging site on the Linode server · M · doing · needs INF-1
Done: `https://staging.pellanazarene.org` is live (basic auth, noindex, Let's Encrypt, own DB/Redis/cache, email blocked via `pn-site.php`, deploys from `main`). Verified: lint clean, 401 without auth, HTTP redirects, bare IP closed. Still to do: production-to-staging refresh script; test email-block and noindex end to end; reboot check; enable automatic deploy to staging in DEV-3.
Separate staging environment on the same Linode, isolated from production.
- Own vhost (e.g. `staging.pellanazarene.org`), own database and DB user, own `wp-config.php` and salts, own file root.
- Protected by HTTP basic auth or Cloudflare Access; `noindex` header and robots disallow.
- Outbound email blocked or redirected to a sink; no production API keys or SMTP.
- Same stack versions as production (PHP, Nginx config, Redis with a distinct key prefix/DB).
- Script to refresh staging from production: DB copy with `wp search-replace` of the URL, uploads sync, and scrubbing of user emails and form entries.
- Documented in `docs/setup.md` and `docs/runbook.md`.

### INF-3 Domain, DNS, CDN, and TLS · S · todo · needs INF-1
**Decision needed:** the domain's DNS is hosted at DNS Made Easy, not Cloudflare. The docs and `deploy/nginx.conf.example` assume Cloudflare (origin cert, Full strict, real-IP). Either (a) move DNS to Cloudflare (free; gives CDN, DDoS protection, and caching), or (b) stay on DNS Made Easy and use Let's Encrypt on the server with no CDN. Then update `docs/setup.md`, `CLAUDE.md`, and the Nginx example to match.
- Cloudflare in front, origin certificate installed, SSL mode Full (strict).
- Nginx real-IP config for Cloudflare (`cloudflare-realip.conf`).
- `www` redirects to apex; HTTP redirects to HTTPS; HSTS enabled after verification.
- DNS TTL lowered ahead of cutover (see MIG-9).

### INF-4 Nginx, PHP-FPM, and page cache tuning · M · review · needs INF-1
Branch `story/INF-4-nginx-php-tuning`. Config in `deploy/nginx/` and `deploy/php/`, installed by `scripts/apply-server-tuning.sh`. Verified on staging 2026-10-20: services active, pool and OPcache settings loaded, page cache MISS then HIT, security headers present, Nginx version hidden, gzip on, site and wp-admin working. Brotli left to Cloudflare if we adopt it (INF-3); HSTS and CSP deferred (see `docs/setup.md`).
Take `deploy/nginx.conf.example` to a tested config.
- FastCGI cache bypasses logged-in users, POSTs, admin, REST, and cart-style cookies; cache hit/miss header for debugging.
- Gzip/Brotli, long-lived `Cache-Control` on static assets, HTTP/2.
- PHP-FPM pool sized for the server's RAM; OPcache tuned.
- Security headers: `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`/CSP frame-ancestors, `Permissions-Policy`.
- PHP execution blocked in `uploads/`; `xmlrpc.php` blocked.

### DEV-1 Local development environment · S · todo
Add the `.wp-env.json` the README describes (theme, plugin, mu-plugin mounted; plugins from `plugins.txt`).
- `npx @wordpress/env start` yields a working site with this repo's code active.
- README instructions verified from a clean checkout.

### DEV-2 CI pipeline · M · done
Branch `story/DEV-2-ci`. Workflow, `phpcs.xml.dist`, `composer.json`/`composer.lock`; existing code brought to the WordPress standard (160 auto-fixes, the rest by hand). Still to do after merge: turn on branch protection on `main` (GitHub settings, requires the three CI checks) and a plugin-vulnerability check (SEC-2).
Add `.github/workflows/ci.yml`.
- PHP lint (`php -l`) and PHPCS with WordPress coding standards on every push and PR.
- ShellCheck on `scripts/` and `deploy/`.
- Secret scanning (gitleaks or GitHub secret scanning) and dependency/plugin vulnerability check against `plugins.txt` (e.g. WPScan or Patchstack data).
- Branch protection on `main`: PR required, CI must pass.

### DEV-3 Deploy pipeline · M · done for staging (production added at cutover, MIG-9) · needs INF-1, INF-2, DEV-2
Staging half done and proven on 2026-10-20/21: first automatic deploy, a deliberately broken commit caught by the health check and rolled back automatically, and recovery. The wrapper is covered by `deploy/tests/ci-deploy-harness.sh`, which runs in CI. Built as: `deploy.yml`, forced-command wrapper `deploy/ci-deploy.sh`, `scripts/setup-ci-deploy.sh` (see `docs/setup.md` section 5). Includes database backup, SHA-pinned deploy, health check, automatic rollback. Production job with required approval is added at cutover (MIG-9).
Add `.github/workflows/deploy.yml`.
- Merge to `main` deploys to staging automatically; production deploy requires approval via the `production` GitHub environment.
- Uses `deploy/deploy.sh`; add pre-deploy DB backup and a rollback step.
- Secrets scoped per environment; deploy key read-only.
- Health check after deploy (HTTP 200 on home, a sermon page, `wp-login`); failed check fails the job.

---

## Epic 2: Content migration

Source: the current site is WordPress on the Ministry Designs theme (uploads under `/mt-content/uploads/`). Its sitemap lists 237 URLs, but only about 30 are real content. The rest are empty taxonomy archives and Lorem ipsum template filler. Full detail is in `docs/Pella Nazarene Website Content Inventory.pdf` and `docs/pellanazarene-content-inventory.csv` (one row per URL with an action of migrate, rebuild, recreate, or drop). The inventory estimates a one-weekend migration done by hand; no crawler is needed.

### MIG-1 Inventory the current site · done
Inventory delivered (PDF and CSV in `docs/`). The old "Calendar" page is just the Planning Center calendar, so nothing to migrate.

### MIG-2 URL map and redirects · S · todo
Implement the redirects from the inventory as 301s in Nginx (config kept in `deploy/`), and test every URL in the CSV.
- `/events-2/` to `/events/`; `/sermons-t/` to `/sermons/`; `/sermons-t/<slug>/` to `/sermons/<slug>/`.
- `/about-us/`, `/new-here/`, `/kids/`, `/giving/`, `/contact/`, `/resources/`, `/small-groups/` keep their slugs.
- `/news/*` redirects to `/blog/` (the new blog; old posts are not carried over).
- About 200 empty taxonomy archives (`u_topic`, `u_scripture`, `u_group-details`, event and group tags/categories) return 410 or redirect to `/sermons/`.
- Redirect test script runs against staging and production.

### MIG-3 Migrate the 7 pages · M · todo · needs INF-2, FE-2
Home, New Here, About Us, Kids, Giving, Contact, Resources (about 3,100 words). Move by hand into block patterns.
- Home: replace the hero slider that repeats one slide six times with a single hero; drop the FAQ block that duplicates New Here; featured sermon comes from the sermon plugin, not hard-coded.
- Giving: keep the Church Center link `pella-nazarene.churchcenter.com/giving`; add the missing text-to-give link or remove the mention.
- Contact: rebuild the form in Fluent Forms (see FEAT-3).
- Resources: re-check the 11 outbound links (nazarene.org, usacanadaregion.org, ncm.org).
- Staff review and sign-off on each page.

### MIG-4 Fix known content problems · S · todo · needs MIG-3
Needs input from the church staff.
- Office hours: Contact says 8:30-5 and footer says 8:00-5, both labeled Eastern Time. Confirmed: Central time; office hours are Monday 1-4 PM, Wednesday 1-4 PM, Friday 9 AM-12 PM. Use these everywhere (Contact page, footer, Google/schema markup) from one source, `parts/service-info.html`, which already has them.
- Phone link `tel:641628-1818` to `tel:+16416281818`; email address as a `mailto:` link.
- Every "Plan a Visit" button, map link, and Kids pre-register button currently goes to `#`; point each to a real destination.
- New Here's "Connect" card says "Link to be added"; wire it to the Connect Card form.
- Rob's bio and the "Sinful Nature" belief sentence are truncated; get the full text from staff.
- Typos: "Giving Onine", "activites", "Wedensday".
- Alt text on all images.

### MIG-5 Media and branding assets · M · todo · needs MIG-3
Bring over only the ~35 images needed, from `pellanazarene.org/mt-content/uploads/`.
- Keep: staff headshots (toddoweb, cindyoweb, robhweb, floydwweb, floydpreach), congregation photos, kids ministry photos, communion/preaching photos, and the welcome video (see list in the PDF).
- Confirm with staff before reusing likely stock photos (kids-1..4, family-2, photography.church41..., 2025/10 prayer/baptism/youth/etc., numbered About Us images).
- Re-compress and convert to WebP/AVIF at sensible sizes; welcome video re-encoded and served via a poster/click-to-play, not autoplaying.
- Logo: the old site renders it as inline SVG; get the original from the designer or the old admin Design tab. Need primary (full text) and symbol-only versions in navy, white, and black, as SVG. Optimize the SVGs and keep them in the theme.
- Record the licensing status of each stock photo kept.

### MIG-6 Sermons: import from YouTube instead of the old site · M · todo · needs INF-2
The old site has only 4 sermons; the YouTube channel (@pellanazarene) is the real archive. Do not migrate sermon posts from the old site.
- Backfill with the YouTube Data API `playlistItems.list` on the uploads playlist (the RSS feed returns only the latest 15). Idempotent; no duplicates on re-run.
- Create speaker terms: Todd Olson, Cindy Olson, Rob Hammann, Floyd Ware (link each to a WP user where applicable).
- Create series "Holiness in the House: A Study in Titus" and attach its sermons.
- Preserve the one real description (Pentecost Promised, Proclaimed, and Practiced, 2026-05-26: Acts 1-3, topics Community/Holiness/Holy Spirit). The other three (God's Messenger, Vision Sunday 1 and 2) are title and video only.
- The featured home sermon ("Holiness in the House", Pastor Cindy Olson) is not in the old archive; verify it imports.
- API key stored as a `wp-config.php` constant, restricted by API and referrer/IP.

### MIG-7 Rebuild events and small groups · M · todo
- Events: the calendar is managed in Planning Center, which is the source of truth. Confirm these 5 recurring events exist there with correct Central times: Sunday School Sun 9:30-10:15; Connections SS Class Sun 9:30-10:15; Lighthouse Student Ministry Sun 5:30-7:00 PM; Midweek Prayer Tue 10:30-11:30 AM and Wed 7:00-8:00 PM (fix "Wedensday"); Coffee Time Tue 9:30-10:30 AM at Windmill Cafe. Fix the directions links. The website displays them via FEAT-2.
- Drop all 6 event filler pages (Kids, Family, Youth, Worship Night, Baptism Sunday, Movie Night).
- Small groups: the old page shows 0 groups and all 6 group pages are filler (including demo names Paul & Olivia Brunson and Will & Gloria Jackson). Nothing to migrate; decide with staff whether to build real groups here or link out to Planning Center Groups (see FEAT-4).

### MIG-8 Drop template filler · S · todo
Do not migrate: the 4 old blog posts (dated 2021-01-27, one mentions "Omega Church"; the new blog starts empty, see FEAT-7), the News index, the 6 event pages, the 6 group pages, and all empty taxonomy archives. Verify nothing from the filler (demo names, Lorem ipsum, "Omega Church") appears on staging before launch with a grep of the database.

### MIG-9 Production cutover plan and execution · M · todo · needs everything above
- Written cutover checklist: content freeze, DNS switch, cache purge, smoke tests, rollback plan.
- Old site kept intact for a defined period after cutover.
- Search Console: sitemap submitted, 404s monitored for two weeks.

---

## Epic 3: Site build

### FE-1 Brand and design system in `theme.json` · M · todo
Implement `docs/PellaNazareneBrandingGuide.pdf` in `theme.json`. The current palette there is placeholder (`#2f5d8c` etc.) and must be replaced.
- Colors: primary navy `#2D3D54`, white `#FFFFFF`, black `#000000`. Any tints needed for backgrounds are derived from the navy; no new hues without staff approval. Contrast meets WCAG AA (white on navy passes comfortably).
- Typeface: Plus Jakarta Sans, self-hosted (open license, `font-display: swap`). Extra Bold for headings, Light Italic for subheadings, Regular for body. Only those three styles are loaded, or one variable font file if smaller. Heading and body sizes must contrast clearly.
- Logo: primary (full text with symbol) wherever possible; symbol-only as the secondary (favicon, small spaces). Minimum width 100 px, keep aspect ratio and clear space around it; black or white one-color versions allowed on suitable backgrounds. Need original logo files from the designer (see MIG-5).
- Button, link, and spacing styles defined here rather than in CSS overrides.

### FE-2 Core templates and navigation · M · todo
Home, page, post, 404, search, header and footer parts (service times, address, contact in `parts/service-info.html`), responsive menu.

### FE-3 Staff and ministry pages · S · todo
Use `patterns/staff-member.php`; pages for pastors, ministries, and "I'm new" visitor information.

### FE-4 Accessibility pass · M · todo
Keyboard navigation, focus states, landmarks, alt text, form labels, reduced motion. WCAG 2.2 AA on key pages.

### FEAT-1 Sermons: finish and polish · L · todo
Sermon content comes from the YouTube import (MIG-6), not the old site.
Finish `pn-sermons`: archive and single templates, series/speaker/scripture browsing, search and filters, YouTube auto-import via cron.
- Click-to-load video facade (see Performance rules).
- Companion-post drafts (Anthropic API) behind a setting, off by default, drafts only, never auto-published.

### FEAT-2 Events calendar · M · todo · pairs with MIG-7
Show Planning Center Calendar events on the site without duplicating data entry. Evaluate (1) Planning Center's embed/public feed, (2) importing its iCal feed into a lightweight WP list, and (3) keeping The Events Calendar plugin. Prefer the lightest option that meets performance targets; if The Events Calendar is not needed, remove `the-events-calendar` from `plugins.txt`. Embeds/feeds are cached and load on demand. Times display in Central.

### FEAT-3 Forms · M · todo
Fluent Forms: Contact, Connect Card, Plan a Visit, Prayer Request.
- Spam protection (honeypot plus Cloudflare Turnstile), rate limiting, server-side validation.
- Email via WP Mail SMTP with SPF/DKIM/DMARC set up; delivery tested.
- Prayer request data handled as sensitive: limited access, retention period decided and documented.

### FEAT-4 Planning Center / Church Center integration · S · todo
Giving stays linked out to Church Center. Check whether the Planning Center subscription includes Groups, Registrations, or Calendar; if so, use them for small groups, kids pre-registration, and event signups and only link or embed them here. Embeds load on demand, not on every page.

### FEAT-5 SEO and sharing · S · todo
Sitemap, meta titles and descriptions, Open Graph images, `Church` schema.org markup with address, service times, and the office hours (Mon 1-4, Wed 1-4, Fri 9-12 Central).

### FEAT-7 Blog · S · todo
Set up a blog at `/blog/` starting empty (old posts are not migrated).
- Blog index and single-post templates in the theme, matching the design system; categories only if staff want them.
- Pastors (Author) can draft and Editor publishes; RSS feed works.
- Linked from the main nav; `/news/*` redirects here (MIG-2).
- Add a first real post before launch so the page is not empty.

### FEAT-8 Google Analytics on production · S · todo
Include the Google Analytics 4 tag, measurement ID **`G-3E45KJPG3G`** (a public identifier that appears in page source; not a secret), on the **production** site only.
- Loaded only when `wp_get_environment_type() === 'production'`, so staging, local, and CI never send data. Test on staging by temporarily forcing the condition, and confirm in GA (Realtime) that production traffic shows up after cutover.
- Not loaded for logged-in staff (pastors and the office would skew the numbers) or in the admin, previews, or the block editor.
- Performance: `async` script, loaded after the page's critical content, with no layout shift; the Lighthouse budget in PERF-1 must still pass with the tag on.
- Privacy: decide with the church whether a cookie notice or consent banner is wanted, and write the decision here. Update the site's privacy statement to mention analytics. IP anonymization is on by default in GA4.
- Security headers: when the Content-Security-Policy is added (INF-4 follow-up), allow `googletagmanager.com` and `google-analytics.com`.
- Implemented in `pn-site.php` (or a small mu-plugin) so it travels with the code. The ID is kept in one constant, not scattered through templates.
- Done when: the tag appears in production page source once, not on staging, and GA Realtime shows a test visit.

### FEAT-6 Staff roles and editing experience · S · todo
Verify the `pn-site.php` role setup: pastors as Author, office admin as Editor. Short editor guide in `docs/runbook.md`.

---

## Epic 4: Security

### SEC-1 WordPress hardening · M · todo · needs INF-1
- `DISALLOW_FILE_EDIT`, salts rotated, table prefix reviewed, debug off, `wp-config.php` outside web root or denied.
- Login protection: limit attempts, 2FA required for administrators and editors.
- REST user enumeration and author archives disabled for anonymous visitors.
- Review and extend `pn-site.php`.

### SEC-2 Automated updates and vulnerability monitoring · S · todo
CI deploys never touch third-party plugins (see `docs/setup.md` section 5), so this story also owns the plugin path: pin versions in `plugins.txt`, a documented manual or scheduled `install-plugins.sh` run, and a staging check before production.
- Core minor/security auto-updates on; plugin update policy documented in `plugins.txt`.
- Weekly check of installed plugin versions against a vulnerability database; alert goes to Rob.

### SEC-3 Security audit of existing custom code · M · todo
Run `/security-review` and a manual audit over `pn-sermons`, `pn-site.php`, and the theme: escaping, nonces, capability checks, the YouTube import and Anthropic API call paths, settings storage.

### SEC-4 Pre-launch security scan · S · todo · needs MIG-3
Scan staging (e.g. WPScan, `nikto`/ZAP baseline, SSL Labs, securityheaders.com). Fix or document every high and medium finding before cutover.

---

## Epic 5: Performance

### PERF-1 Performance baseline and budget · S · todo · needs INF-2
- Lighthouse/PageSpeed results recorded for home, a sermon, events, and a content page (mobile and desktop).
- Budget written into `docs/performance.md`: LCP < 2.5 s, CLS < 0.1, INP < 200 ms, total page weight under 1 MB on home.

### PERF-2 Caching verified end to end · M · todo · needs INF-4
Confirm FastCGI cache, Redis object cache, browser caching, and Cloudflare caching (including cache rules for static assets and HTML) behave as intended; document purge steps.

### PERF-3 Image and asset pipeline · M · todo
WebP/AVIF generation on upload, responsive sizes, lazy-loading rules, minimal CSS/JS, plugin assets dequeued where unused.

### PERF-4 Performance regression check in CI · M · todo · needs DEV-2, INF-2
Lighthouse CI against staging on PRs, failing when the budget in PERF-1 is exceeded.

---

## Epic 6: Operations

### OPS-1 Backups with off-server copy and a restore test · M · todo
Nightly DB backup exists in `deploy/crontab.example`; add uploads backup and off-server storage (encrypted). Perform a full restore on staging and record the time it took.

### OPS-2 Monitoring and alerts · S · todo
Uptime monitor on home and `wp-login`, disk/RAM alerts on the Linode, SSL expiry, failed cron/import alerts, email to Rob.

### OPS-3 Write the runbook · M · todo
Complete `docs/runbook.md`: where everything lives (without passwords), routine tasks, failure playbooks (down, forms not sending, sermons not importing, restore), and contacts.

### OPS-4 Handoff and training · S · todo
Walk staff through posting a sermon, editing a page, adding an event, and reading form submissions. Record a short video or write a one-page guide.

---

## Suggested order

1. INF-1 → INF-2 (staging) → DEV-1, DEV-2, DEV-3
2. INF-3, INF-4, SEC-1, SEC-3
3. MIG-2 through MIG-8 alongside FE and FEAT work
4. PERF-1 through PERF-4, SEC-4, OPS-1 through OPS-3
5. MIG-9 cutover, then OPS-4
