# Server setup

Building the production server from scratch. Target: Ubuntu 24.04 LTS on Linode, Nginx, PHP 8.3 FPM, MariaDB, Redis, Cloudflare in front.

## 1. Server

```bash
apt update && apt upgrade -y
apt install -y nginx mariadb-server redis-server unattended-upgrades \
  php8.3-fpm php8.3-mysql php8.3-curl php8.3-gd php8.3-imagick php8.3-intl \
  php8.3-mbstring php8.3-xml php8.3-zip php8.3-redis
dpkg-reconfigure -plow unattended-upgrades
timedatectl set-timezone America/Chicago
```

Install WP-CLI: https://wp-cli.org/#installing

## 2. Database

```sql
CREATE DATABASE pellanazarene CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pellanazarene'@'localhost' IDENTIFIED BY '…';
GRANT ALL ON pellanazarene.* TO 'pellanazarene'@'localhost';
```

## 3. WordPress and this repo

```bash
mkdir -p /var/www/pellanazarene && cd /var/www/pellanazarene
git clone git@github.com:OWNER/pella-nazarene-site.git repo
wp core download --path=public
wp config create --path=public --dbname=pellanazarene --dbuser=pellanazarene --dbpass='…'
wp core install --path=public --url=https://pellanazarene.org --title="Pella Nazarene" \
  --admin_user=… --admin_email=pellanazarene@gmail.com
wp theme install twentytwentyfive --path=public
repo/deploy/deploy.sh /var/www/pellanazarene
wp theme activate pella-nazarene --path=public
chown -R www-data:www-data public
```

Add to `public/wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
define( 'WP_AUTO_UPDATE_CORE', true );
define( 'DISALLOW_FILE_EDIT', true );
define( 'WP_REDIS_HOST', '127.0.0.1' );
define( 'PN_YOUTUBE_CHANNEL_ID', 'UC…' );
define( 'PN_YOUTUBE_API_KEY', '…' );      // only for the one-time sermon backfill; see docs/sermons.md
define( 'PN_ANTHROPIC_API_KEY', '…' );   // only when companion posts are turned on
```

## 4. Nginx, cron, Cloudflare

- Before enabling the production vhost, provision the `deploy` user with `scripts/bootstrap-server.sh deploy` (run as root), then verify `ssh deploy@SERVER` works and `sudo -n true` succeeds. From a repository checkout, run `scripts/apply-server-tuning.sh` as `deploy` first; it installs the snippets included by the vhost. Then copy `deploy/nginx.conf.example` to `/etc/nginx/sites-available/`, adjust, enable, and run `nginx -t && systemctl reload nginx`.
- Create a Cloudflare origin certificate; set SSL mode to **Full (strict)**.
- Install `deploy/crontab.example` for the `www-data` user.
- `mkdir -p /var/backups/pellanazarene && chown www-data /var/backups/pellanazarene`

## 5. GitHub deploys

Merging to `main` deploys to **staging** automatically after CI passes (`.github/workflows/deploy.yml`). Production is added at cutover (MIG-9) with required approval.

How it works: the workflow SSHes to the server with a key made only for CI. In `~deploy/.ssh/authorized_keys` that key is locked with `restrict,command="/usr/local/bin/pn-ci-deploy"`, so it cannot open a shell or run anything else. The wrapper (`deploy/ci-deploy.sh`, root-owned) accepts only `staging <40-character sha>`, refuses any commit that is not on `origin/main` or whose `deploy.sh` does not declare the supported CI runner contract, backs up the staging database (last five kept in `/var/backups/pn-staging-db`), deploys that exact commit with the root-owned, stable `/usr/local/libexec/pn-staging-deploy` runner, health-checks the site over localhost, and rolls back to the previous commit if deployment or health checks fail, then re-checks the site and says whether staging is healthy again (the run still ends red, with exit code 6 if staging is still unhealthy after the rollback). CI deployments change code only; they never install or update third-party plugins, so a code merge cannot change plugin versions and a rollback never has to undo them. To add or update plugins, edit `plugins.txt` in a PR, then after it merges run `scripts/install-plugins.sh /var/www/staging/public` as `deploy` on the server and check the site (plan: SEC-2). The wrapper runs a root-owned copy of `deploy.sh` taken from the deployed commit; after a healthy deploy it becomes the known-good copy that rollbacks use. Direct staging deployments use the same lock. The database backup is not restored automatically; the path is printed.

One-time setup:

1. On your computer, make a key just for CI (no passphrase): `ssh-keygen -t ed25519 -N "" -C pella-ci-deploy -f ~/.ssh/pella_ci_deploy`
2. On the server, from the staging checkout: `scripts/setup-ci-deploy.sh "ssh-ed25519 AAAA... pella-ci-deploy"` (paste the line from `pella_ci_deploy.pub`). It installs the wrapper and stable runner, appends the key (existing keys are never touched), and prints the server's host-key fingerprint.
3. In GitHub, create an environment named **staging** (Settings > Environments), set deployment branches to `main` only, and add these environment **variables** (not secrets; a hostname and username are not secret, and masking them blanks the word "deploy" out of every log line): `DEPLOY_HOST` (`staging.pellanazarene.org`) and `DEPLOY_USER` (`deploy`); and these environment **secrets**: `DEPLOY_SSH_KEY` (the private key file's contents) and `DEPLOY_KNOWN_HOSTS` (the output of `ssh-keyscan -t ed25519 staging.pellanazarene.org`, after checking its fingerprint matches the one the server printed). Then delete the private key from your computer; it lives only in GitHub.
4. To revoke CI access, delete the `pella-ci-deploy` line from `~deploy/.ssh/authorized_keys`.

## Nginx and PHP-FPM tuning

Config for both sites lives in `deploy/nginx/` and `deploy/php/`; `scripts/apply-server-tuning.sh` installs it on the server (run as `deploy` from a repo checkout). It backs up every file it replaces and rolls back if the Nginx or PHP-FPM config test fails. It installs:

- `pn-performance.conf` (gzip types, hidden Nginx version, open-file cache), `pn-security-headers.conf`, and `pn-fastcgi-cache.conf` (page-cache rules with stale-while-revalidate).
- A PHP-FPM pool sized for the 4 GB server (12 workers max) that replaces the packaged `www.conf`, and `99-pn.ini` (OPcache, upload limits, `expose_php` off). Shell-execution functions are disabled for web requests; if a plugin breaks, remove only the function it needs from `deploy/php/pn-pool.conf`.
- A 2 GB swap file if the server has none.

**Login protection and blocked files.** `pn-security.conf` throttles `/wp-login.php` to about 10 requests a minute per address (HTTP 429 beyond a short burst); this keys on the client IP, so when Cloudflare is added the real-IP config (INF-3) must be in place first. The vhost also refuses `wp-config.php`, `readme.html`, `license.txt`, `xmlrpc.php`, dotfiles, and any `.php` file under `wp-content/uploads/`. These deny rules must stay **above** the PHP handler in the vhost, because Nginx uses the first matching regex location; `deploy/tests/nginx-config.sh` checks this.

**Two-factor plugin.** CI deploys never install plugins, so after the first deploy that contains `pn-security.php`, install it by hand (a pinned version; do not run `install-plugins.sh`, which upgrades every unpinned plugin): `wp plugin install two-factor --version=0.17.0 --activate --path=/var/www/staging/public`. Until it is active the must-use plugin fails open and shows administrators a red warning. WordPress settings applied at setup: `FORCE_SSL_ADMIN` on and open registration off (`users_can_register` 0). WordPress keeps the default `wp_` table prefix on purpose: renaming it on a live install is invasive and a different prefix adds almost no protection. Staff procedures are in `docs/runbook.md`.

HSTS and a Content-Security-Policy are deliberately not set yet; they are added after HTTPS is verified on production and the YouTube/Church Center embeds are checked. Verify the cache with `curl -sI <url> | grep -i x-cache` twice: the first request should say `MISS`, the second `HIT`.

## Staging site

Staging runs on the same Linode as production, fully separate: its own directory (`/var/www/staging`), database (`pellanazarene_staging`), `wp-config.php` and salts, Redis database and key prefix, Nginx cache zone, and a Let's Encrypt certificate for `staging.pellanazarene.org`. It sits behind HTTP basic auth, sends `X-Robots-Tag: noindex`, and `WP_ENVIRONMENT_TYPE=staging` makes `pn-site.php` block all outgoing email.

```bash
# as the deploy user, from a checkout of this repo
scp scripts/setup-staging.sh deploy@SERVER:/tmp/
ssh deploy@SERVER
bash /tmp/setup-staging.sh ADMIN_USER ADMIN_EMAIL   # 1st run prints a GitHub deploy key and stops
# add the key at GitHub > repo > Settings > Deploy keys (read-only), then run it again
```

Credentials are written to `/root/pella-secrets/` (root-only) and never printed: `staging-basic-auth.txt`, `staging-wp-admin.pw`, and the database password. Code is owned by `deploy` (so `deploy.sh` and WP-CLI can write it) and only `wp-content/uploads` is writable by `www-data`; update plugins and core with WP-CLI, not from the dashboard.

## 6. Site configuration

- **Create the pages:** `PN_WP_USER=www-data scripts/create-pages.sh /var/www/staging/public` (run as `deploy`; `PN_WP_USER` makes WordPress, which owns `wp-content/uploads`, save the photos) creates Home, New Here, About Us, Kids, Giving, Contact, Resources, Events, Small Groups, and Blog from the theme's page patterns and makes Home the front page. Safe to re-run: pages that exist are left alone, so staff edits are never overwritten. To reset one page to the pattern's current text: `scripts/create-pages.sh /var/www/staging/public --replace about-us`. It also loads the page photos into the media library (`docs/photos.md`; if one cannot be saved the script says why and creates nothing), sets pretty permalinks (`/%postname%/`) if WordPress is still on "Plain" (with Plain permalinks every menu link shows the home page), and clears WordPress's object cache; set `PN_NGINX_CACHE_DIR=/var/cache/nginx/pellanazarene-staging` to clear the Nginx page cache as well (production: `/var/cache/nginx/pellanazarene`), otherwise old pages can linger for up to an hour. CI deploys never create or change page content; run this by hand after the first deploy that contains the patterns. Staff edit pages in the normal WordPress editor afterwards.
- **Settings → Sermons:** confirm the channel ID, click **Check YouTube now**.
- **Sermons → Speakers:** create the four pastors and link each to their WordPress user.
- **Users:** pastors as **Author**, office admin as **Editor**. Authors write posts and sermons and submit them for review; they cannot publish. Editors publish.
- **WP Mail SMTP:** send through the church Gmail account.
- **Fluent Forms:** Contact, Connect Card, Plan a Visit, Prayer Request.

## Backfilling older sermons

The RSS feed only returns the latest 15 videos. To import the full archive run `wp pn-sermons backfill --dry-run`, then without `--dry-run`. It uses the YouTube Data API and needs `PN_YOUTUBE_API_KEY`. Step-by-step instructions, including creating the key: `docs/sermons.md`.
