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

## Checks

CI (`.github/workflows/ci.yml`) runs on every push and pull request: PHP syntax, WordPress coding standards (PHPCS, config in `phpcs.xml.dist`), ShellCheck on `scripts/` and `deploy/`, tests of the CI deploy wrapper (stubbed, no server), and a gitleaks secret scan. Run the same checks locally with Docker (nothing to install):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 install            # once; tooling comes from composer.lock
docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpcs   # coding standards (phpcbf auto-fixes)
docker run --rm -v "$PWD":/app:ro -w /app php:8.3-cli sh -c "find wp-content -name '*.php' -print0 | xargs -0 -n1 php -l"
docker run --rm -v "$PWD":/mnt:ro -w /mnt koalaman/shellcheck:stable scripts/*.sh deploy/*.sh
docker run --rm -v "$PWD":/repo -w /repo zricethezav/gitleaks:latest detect --source /repo --redact
docker run --rm -v "$PWD":/work:ro ubuntu:24.04 bash /work/deploy/tests/ci-deploy-harness.sh   # CI deploy wrapper: healthy / rollback heals / rollback cannot heal / deploy fails
```

Third-party GitHub Actions are pinned to full commit SHAs; update them deliberately.

## Deploying

Merge a pull request to `main`. After CI passes, `.github/workflows/deploy.yml` deploys that exact commit to **staging**: it backs up the database, runs `deploy/deploy.sh` (syncs `wp-content/` code, installs plugins from `plugins.txt`, flushes caches), health-checks the site, and rolls back automatically if the check fails. Production deploys are added at cutover. Setup and security model: `docs/setup.md` section 5.

`staging` environment variables: `DEPLOY_HOST`, `DEPLOY_USER`. Secrets: `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`.

## Secrets

Kept in `wp-config.php` on the server only (never committed):

| Constant | Used by |
|---|---|
| `PN_YOUTUBE_CHANNEL_ID` | Sermon import (optional; can also be set in Settings → Sermons) |
| `PN_ANTHROPIC_API_KEY` | Companion-post drafts |
| `WPMS_*` | WP Mail SMTP |
