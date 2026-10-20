# Pella Nazarene Website

Custom code and configuration for [pellanazarene.org](https://pellanazarene.org), a self-hosted WordPress site.

This repository holds only what we wrote or configured. WordPress core, third-party plugins, uploaded media, and secrets are **not** committed; scripts install or restore them.

## Layout

```
wp-content/
  plugins/pn-sermons/        Sermons, series, speakers, scripture; YouTube import; companion-post drafts
  mu-plugins/pn-site.php     Site-wide behavior: admin cleanup, roles, hardening
  themes/pella-nazarene/     Child theme of Twenty Twenty-Five: theme.json, patterns, templates
plugins.txt                  Third-party plugins and pinned versions (installed by WP-CLI)
scripts/
  install-plugins.sh         Install/update the plugins in plugins.txt
deploy/
  deploy.sh                  Run on the server by GitHub Actions
  nginx.conf.example         Nginx server block with FastCGI page cache
  crontab.example            System cron for WP-Cron (sermon import, backups)
.github/workflows/
  ci.yml                     PHP lint on every push and pull request
  deploy.yml                 Deploy main to the server over SSH
docs/
  setup.md                   Building the server from scratch
  runbook.md                 Day-to-day operations and "if X breaks, do Y"
.wp-env.json                 Local development with @wordpress/env
```

## Local development

Requires Docker and Node.

```bash
npx @wordpress/env start      # http://localhost:8888  (admin / password)
npx @wordpress/env run cli wp plugin list
npx @wordpress/env stop
```

`.wp-env.json` mounts this repo's theme, plugin, and mu-plugin into the local site.

## Deploying

Push to `main`. GitHub Actions lints the PHP, then SSHes to the server and runs `deploy/deploy.sh`, which pulls the repo, syncs `wp-content/` code into the live site, installs plugins from `plugins.txt`, and flushes caches.

Required GitHub secrets: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_PATH`.

## Secrets

Kept in `wp-config.php` on the server only (never committed):

| Constant | Used by |
|---|---|
| `PN_YOUTUBE_CHANNEL_ID` | Sermon import (optional; can also be set in Settings → Sermons) |
| `PN_ANTHROPIC_API_KEY` | Companion-post drafts |
| `WPMS_*` | WP Mail SMTP |
