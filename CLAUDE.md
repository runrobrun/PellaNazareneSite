# CLAUDE.md

Instructions for Claude Code working in this repo. Read `README.md` for layout, `docs/setup.md` for server build, and `docs/backlog.md` for the work queue.

## What this is

Custom code and config for pellanazarene.org, a self-hosted WordPress site being migrated onto a Linode server (Ubuntu 24.04, Nginx, PHP 8.3 FPM, MariaDB, Redis, Cloudflare in front). Only our own code lives here: WordPress core, third-party plugins, uploads, and secrets are **not** committed.

- `wp-content/plugins/pn-sermons/` custom sermon plugin
- `wp-content/mu-plugins/pn-site.php` site-wide behavior and hardening
- `wp-content/themes/pella-nazarene/` block child theme of Twenty Twenty-Five
- `plugins.txt` third-party plugins (installed by `scripts/install-plugins.sh`)
- `deploy/` server-side deploy script, Nginx and cron examples

## How to work

1. Pick a story from `docs/backlog.md` (or the one the user names). Work one story per branch: `story/<ID>-short-name`. Never commit directly to `main`.
2. Make the smallest change that satisfies the acceptance criteria. Match the surrounding code's style and comment density.
3. Run the **Definition of Done** below before opening a PR. Do not open a PR with any box unchecked; say which are unchecked and why.
4. Open a PR against `main` and update the story's status in `docs/backlog.md` in the same PR.
5. Commit and push only when the user asks. Ask before anything outward-facing or hard to reverse (production deploys, DNS, Cloudflare, server changes, deleting data).

## Definition of Done (every story)

- [ ] Acceptance criteria met.
- [ ] **Security check passed**: run `/security-review` on the branch and fix or explicitly justify every finding. Also confirm the checklist below. This is mandatory before any PR.
- [ ] **Performance check passed**: no new render-blocking assets, no uncached heavy queries, images sized and lazy-loaded; see Performance rules.
- [ ] Checks pass locally (see README "Checks") and CI is green: PHP syntax, WordPress coding standards (PHPCS), ShellCheck, secret scan.
- [ ] Verified on local (`wp-env`) and, once it exists, on staging.
- [ ] Docs updated (`README.md`, `docs/setup.md`, `docs/runbook.md`) if setup, ops, or secrets changed.
- [ ] Backlog status updated.

## Security rules

Every PHP change must hold to the WordPress standards:

- **Escape on output** (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`), **sanitize on input** (`sanitize_text_field`, `absint`, etc.), **validate** before use.
- **Nonces** on every form and state-changing request; **capability checks** (`current_user_can`) on every admin action and REST/AJAX handler. REST routes need a real `permission_callback`, never `__return_true` for writes.
- **`$wpdb->prepare()`** for any SQL with variable input.
- Every PHP file starts with `defined( 'ABSPATH' ) || exit;`.
- No secrets, keys, passwords, or IPs in the repo. Secrets live in server `wp-config.php` constants (see README). Never print them in logs or output.
- Outbound requests use `wp_remote_*` with timeouts and validated URLs. Check third-party API responses before trusting them.
- Third-party plugins are pinned or reviewed; do not add one without a reason recorded in `plugins.txt`.
- Server config changes keep: Cloudflare Full (strict) TLS, `DISALLOW_FILE_EDIT`, no `xmlrpc.php`, no directory listing, uploads not executable, SSH key-only login, firewall allowing only 22/80/443.

## Performance rules

The site must load fast, especially on phones on church-parking-lot cellular.

- Targets (mobile, Lighthouse/PageSpeed): LCP under 2.5 s, CLS under 0.1, INP under 200 ms, performance score 90 or higher on home, a sermon page, and the events page.
- Keep the Nginx FastCGI page cache working for anonymous visitors; do not add cookies or query strings that bypass it.
- Redis object cache stays enabled. Avoid uncached `WP_Query` with `posts_per_page => -1` or meta queries on hot pages.
- Load scripts and styles only on pages that need them; defer or async non-critical JS. No jQuery dependencies in new code.
- Images: WebP/AVIF, correct `srcset`/`sizes`, explicit width and height, `loading="lazy"` below the fold, hero image not lazy and preloaded.
- Sermon video: use a click-to-load facade, never autoloading YouTube iframes.
- Fonts: self-host, `font-display: swap`, minimal weights. No third-party tracking or font CDNs without approval.
- Before and after numbers go in the PR description when a change touches front-end output.

## Environments

| Env | Where | Deploy |
|---|---|---|
| Local | `@wordpress/env` (see README) | manual |
| Staging | Linode, separate vhost/DB (see backlog) | auto on merge to `main` once built |
| Production | Linode, `pellanazarene.org` | manual approval via GitHub `production` environment |

Never point local or staging at production's database, API keys, or SMTP. Staging must be `noindex` and block outbound email.

Third-party tracking (Google Analytics, measurement ID `G-3E45KJPG3G`, see FEAT-8) loads on **production only**: never on staging, local, or for logged-in staff.

## Conventions

- PHP 8.3, WordPress coding standards, tabs for indentation. Prefix everything with `pn_` / `PN_`.
- Block theme: edit `theme.json`, templates, parts, and patterns rather than adding PHP/CSS overrides.
- Shell scripts: `set -euo pipefail`, idempotent, safe to re-run.
- Docs are Markdown in `docs/`. Keep them accurate; stale docs are bugs.

## Migration source

The old site is WordPress on the Ministry Designs theme (uploads under `/mt-content/uploads/`). Only about 30 of its 237 URLs are real content; the rest is Lorem ipsum filler and empty archives. Before touching content, read `docs/Pella Nazarene Website Content Inventory.pdf` and `docs/pellanazarene-content-inventory.csv`.

- Migrate by hand: 7 pages, 5 recurring events, about 35 images. Do not migrate sermons from the old site; import them from the YouTube channel (@pellanazarene).
- Never carry over filler: Lorem ipsum, "Omega Church", or the demo names Paul & Olivia Brunson and Will & Gloria Jackson.
- Timezone is **Central** (America/Chicago). The old site wrongly says Eastern; never copy that. Office hours are Mon 1-4 PM, Wed 1-4 PM, Fri 9 AM-12 PM Central; the old site's 8:00/8:30-5 hours are wrong.
- Events are managed in Planning Center, which is the source of truth for the calendar. The blog starts fresh; old posts are not migrated.
- Old URLs: every address on the old site has a defined outcome in `deploy/redirects/expected.csv` (explained in `docs/redirects.md`; check a live site with `scripts/check-redirects.sh`). Never use `--allow-fallback` at production cutover.
- Giving stays on Church Center (`pella-nazarene.churchcenter.com/giving`); the old site's buttons that go to `#` must get real targets or be removed.

## Brand

Follow `docs/PellaNazareneBrandingGuide.pdf`. Mission: "to love people where they are and grow together in Jesus." Voice: neighborly, warm, personal, encouraging, small-town church family; avoid corporate or churchy jargon.

- Colors: navy `#2D3D54` (slug `accent-3`), white, black, and tints of the navy. Don't introduce other colors. Details and contrast numbers: `docs/design-system.md`.
- Type: Plus Jakarta Sans only (Extra Bold headings, Light Italic subheadings via the "Subheading" block style, Regular body), self-hosted in the theme's `assets/fonts/`.
- Logo: use the primary full-text version (`assets/images/logo-primary.svg`, white variant on navy) when possible; symbol-only in tight spaces; the Lighthouse Student Ministry logo is for youth pages only. Never stretch or recolor beyond navy, white, or black; keep clear space and at least 100 px wide. Masters are in `docs/branding/source/`; rebuild web assets with `scripts/build-brand-assets.sh`, never hand-edit them.
- Brand values are defined in `theme.json`; don't hard-code colors or fonts elsewhere. Custom colors and arbitrary sizes are disabled for editors on purpose. `deploy/tests/theme-check.sh` verifies the brand; CI runs it.

## Known gaps

`README.md` references `.github/workflows/deploy.yml` and `.wp-env.json`, which do not exist yet (see DEV-1 and DEV-3 in the backlog). Don't assume they work. CI (`ci.yml`) exists.
