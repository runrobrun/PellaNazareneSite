# Server setup

Building the production server from scratch. Target: Ubuntu 24.04 LTS on Linode, Nginx, PHP 8.3 FPM, MariaDB, Redis, Cloudflare in front.

## 1. Server

```bash
apt update && apt upgrade -y
apt install -y nginx mariadb-server redis-server unattended-upgrades acl \
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
sudo chown -R deploy:www-data public
sudo chown -R www-data:www-data public/wp-content/uploads
sudo install -m 755 -o root -g root repo/deploy/clear-cache.sh /usr/local/sbin/pn-clear-cache
repo/deploy/deploy.sh /var/www/pellanazarene
wp theme activate pella-nazarene --path=public
```

The first deploy needs `public` code owned by `deploy` so it can replace the existing WordPress files. Keep `wp-content/uploads` owned by `www-data`. The `deploy` account is the server administrator, not a CI identity.

Add to `public/wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
define( 'WP_AUTO_UPDATE_CORE', true );
define( 'DISALLOW_FILE_EDIT', true );
define( 'WP_REDIS_HOST', '127.0.0.1' );
define( 'WP_REDIS_USERNAME', 'pn-production' );
define( 'WP_REDIS_PASSWORD', '…' ); // from /root/pella-secrets/redis-production.pw
define( 'WP_REDIS_DATABASE', 0 );
define( 'WP_REDIS_PREFIX', 'pn_production_' );
define( 'WP_REDIS_SELECTIVE_FLUSH', true ); // its Redis user may not run FLUSHDB; needs Redis Object Cache 3.0.0 or newer for the username
define( 'PN_YOUTUBE_CHANNEL_ID', 'UC…' );
define( 'PN_YOUTUBE_API_KEY', '…' );      // only for the one-time sermon backfill; see docs/sermons.md
define( 'PN_ANTHROPIC_API_KEY', '…' );   // only when companion posts are turned on
```

## 4. Nginx, cron, Cloudflare

- Before enabling the production vhost, provision the `deploy` user with `scripts/bootstrap-server.sh deploy` (run as root), then verify `ssh deploy@SERVER` works and `sudo -n true` succeeds. From a repository checkout, run `scripts/apply-server-tuning.sh` as `deploy`; it creates the isolated PHP-FPM users and installs the snippets included by the vhost.
- Create a Cloudflare origin certificate; set SSL mode to **Full (strict)**.
- Install `deploy/crontab.example` for `pn-production-php`.
- `mkdir -p /var/backups/pellanazarene && chown pn-production-php /var/backups/pellanazarene`
- PHP-FPM uses separate `pn-staging-php` and `pn-production-php` users. After tuning creates them, grant production's pool access to its config and uploads:
  ```bash
  sudo chgrp pn-production-php /var/www/pellanazarene/public/wp-config.php
  sudo chmod 640 /var/www/pellanazarene/public/wp-config.php
  sudo find /var/www/pellanazarene/public/wp-content/uploads -type d \
    -exec setfacl -m 'u:pn-production-php:rwx,d:u:pn-production-php:rwx' {} +
  sudo find /var/www/pellanazarene/public/wp-content/uploads -type f \
    -exec setfacl -m 'u:pn-production-php:rw-' {} +
  ```
  Uploads remain owned by `www-data`; the ACL grants only production's PHP-FPM user access. Bootstrap also creates site-specific Redis ACL users and root-only password files; production's ACL restricts it to the `pn_production_*` key prefix. For Redis administration, use the `pn-admin` ACL credentials from `/root/pella-secrets/redis-admin.pw`.
- Then copy `deploy/nginx.conf.example` to `/etc/nginx/sites-available/`, adjust and enable it, and run `nginx -t && systemctl reload nginx`.

## 5. GitHub deploys

> **Existing server?** These steps build a new one. To move the running staging site to the separate deploy users, follow `docs/deploy-isolation-migration.md` instead.

Merging to `main` deploys to **staging** automatically after CI passes (`.github/workflows/deploy.yml`). The same commit then goes to **production** only after a person approves it in GitHub, and only once production deploys are switched on (below).

How it works: each environment SSHes with its own key and dedicated account (`pn-staging-deploy` or `pn-production-deploy`), not the administrative `deploy` account. Each key is forced to `sudo -n /usr/local/bin/pn-ci-deploy <its-site>` and that account's sudoers rule permits only this wrapper and its own fixed-path cache clearer. The root-owned wrapper (`deploy/ci-deploy.sh`) refuses commits not on `origin/main` or whose `deploy.sh` does not declare the supported runner contract. It runs Git, the stable site-specific deploy runner, and WP-CLI as the site's unprivileged account; staging's account cannot write production files or run production PHP. PHP-FPM also runs as separate site users, and only the matching pool can read each site's `wp-config.php`. The wrapper backs up that site's database (last five kept in `/var/backups/pn-<site>-db`), health-checks over localhost, rolls back to the previous commit if deployment or health checks fail, then re-checks and reports whether the site is healthy again (the run still ends red, with exit code 6 if it remains unhealthy). CI deployments change code only; they never install or update third-party plugins, so a code merge cannot change plugin versions and a rollback never has to undo them. To add or update plugins, edit `plugins.txt` in a PR, then after it merges run `sudo -u pn-staging-deploy scripts/install-plugins.sh /var/www/staging/public` on the server and check the site (plan: SEC-2). Direct staging deployments use the same lock. The database backup is not restored automatically; the path is printed.

One-time setup:

1. On your computer, make a key just for CI (no passphrase): `ssh-keygen -t ed25519 -N "" -C pella-ci-deploy -f ~/.ssh/pella_ci_deploy`
2. On the server, from the staging checkout: `scripts/setup-ci-deploy.sh staging "ssh-ed25519 AAAA... pella-ci-deploy"` (paste the line from `pella_ci_deploy.pub`). It creates `pn-staging-deploy`, restricts its sudo access to staging-only commands, gives it ownership of staging code (uploads stay owned by `www-data`), installs the wrapper and stable runner, and moves this key out of the administrative account if it was previously there.
3. In GitHub, create an environment named **staging** (Settings > Environments), set deployment branches to `main` only, and add these environment **variables** (not secrets; a hostname and username are not secret): `DEPLOY_HOST` (`staging.pellanazarene.org`) and `DEPLOY_USER` (`pn-staging-deploy`); and these environment **secrets**: `DEPLOY_SSH_KEY` (the private key file's contents) and `DEPLOY_KNOWN_HOSTS` (the output of `ssh-keyscan -t ed25519 staging.pellanazarene.org`, after checking its fingerprint matches the one the server printed). Then delete the private key from your computer; it lives only in GitHub.
4. To revoke CI access, delete the `pella-ci-deploy` line from `/home/pn-staging-deploy/.ssh/authorized_keys`.

**Production deploys.** Same wrapper, same steps (backup in `/var/backups/pn-production-db`, health check, rollback), with its own key and dedicated `pn-production-deploy` account. Its site-bound sudo rule and filesystem access are separate from staging's.

1. Make a second key, only for production: `ssh-keygen -t ed25519 -N "" -C pella-ci-deploy-production -f ~/.ssh/pella_ci_deploy_production`
2. On the server, from the production checkout (`/var/www/pellanazarene/repo`), with the read-only GitHub checkout key at `/home/deploy/.ssh/github_deploy`: `scripts/setup-ci-deploy.sh production "ssh-ed25519 AAAA... pella-ci-deploy-production"`. It creates `pn-production-deploy`, transfers production code ownership to it (uploads remain owned by `www-data`), and refuses a key already assigned to the production account for another command.
3. In GitHub, create an environment named **production**: **Required reviewers** (Rob, plus one other person if possible; this is the approval), deployment branches `main` only, and the same two variables and two secrets as staging, with the production key. Set `DEPLOY_USER` to `pn-production-deploy`. `DEPLOY_HOST` must be a name that reaches the server directly, not through the Cloudflare proxy (an orange-cloud name only passes web traffic, not SSH); use a DNS-only name such as `staging.pellanazarene.org` while it stays DNS-only, and make `DEPLOY_KNOWN_HOSTS` with `ssh-keyscan` against that same name.
4. Production deploys stay off until the repository variable `PRODUCTION_DEPLOYS` is `on` (Settings → Secrets and variables → Actions → Variables). Set it at cutover (`docs/cutover.md`, step 7). Until then the production job is skipped on every merge. To pause production deploys later, set it to anything else.
5. To approve a deploy: Actions → the Deploy run → **Review deployments** → production → Approve. A deploy left unapproved expires after 30 days; nothing reaches the live site without the click.
6. To revoke, delete the `pella-ci-deploy-production` line from `/home/pn-production-deploy/.ssh/authorized_keys`.

## Nginx and PHP-FPM tuning

Config for both sites lives in `deploy/nginx/` and `deploy/php/`; `scripts/apply-server-tuning.sh` installs it on the server (run as `deploy` from a repo checkout). It backs up every file it replaces and rolls back if the Nginx or PHP-FPM config test fails. It installs:

- `pn-performance.conf` (gzip types, hidden Nginx version, open-file cache), `pn-security-headers.conf`, and `pn-fastcgi-cache.conf` (page-cache rules with stale-while-revalidate).
- Separate PHP-FPM pools for staging and production (12 workers total), each running as its own user and socket, replacing the packaged `www.conf`; `99-pn.ini` (OPcache, upload limits, `expose_php` off). Shell-execution functions are disabled for web requests; if a plugin breaks, remove only the function it needs from `deploy/php/pn-pool.conf`.
- A 2 GB swap file if the server has none.

**Login protection and blocked files.** `pn-security.conf` throttles `/wp-login.php` to about 10 requests a minute per address (HTTP 429 beyond a short burst); this keys on the client IP, so when Cloudflare is added the real-IP config (INF-3) must be in place first. The vhost also refuses `wp-config.php`, `readme.html`, `license.txt`, `xmlrpc.php`, dotfiles, and any `.php` file under `wp-content/uploads/`. These deny rules must stay **above** the PHP handler in the vhost, because Nginx uses the first matching regex location; `deploy/tests/nginx-config.sh` checks this.

**Two-factor plugin.** CI deploys never install plugins, so after the first deploy that contains `pn-security.php`, install it by hand as the staging CI user (a pinned version; do not run `install-plugins.sh`, which upgrades every unpinned plugin): `sudo -u pn-staging-deploy wp plugin install two-factor --version=0.17.0 --activate --path=/var/www/staging/public`. Until it is active the must-use plugin fails open and shows administrators a red warning. WordPress settings applied at setup: `FORCE_SSL_ADMIN` on and open registration off (`users_can_register` 0). WordPress keeps the default `wp_` table prefix on purpose: renaming it on a live install is invasive and a different prefix adds almost no protection. Staff procedures are in `docs/runbook.md`.

HSTS and a Content-Security-Policy are deliberately not set yet; they are added after HTTPS is verified on production and the YouTube/Church Center embeds are checked. Verify the cache with `curl -sI <url> | grep -i x-cache` twice: the first request should say `MISS`, the second `HIT`.

## Staging site

Staging runs on the same Linode as production, fully separate: its own directory (`/var/www/staging`), database (`pellanazarene_staging`), `wp-config.php` and salts, Redis ACL user and key prefix, PHP-FPM user and socket, Nginx cache zone, and a Let's Encrypt certificate for `staging.pellanazarene.org`. It sits behind HTTP basic auth, sends `X-Robots-Tag: noindex`, and `WP_ENVIRONMENT_TYPE=staging` makes `pn-site.php` block all outgoing email and keeps Google Analytics off (`docs/analytics.md`).

```bash
# as the administrative deploy user, from a checkout of this repo
scp scripts/setup-staging.sh deploy@SERVER:/tmp/
ssh deploy@SERVER
bash /tmp/setup-staging.sh ADMIN_USER ADMIN_EMAIL   # 1st run prints a GitHub deploy key and stops
# add the key at GitHub > repo > Settings > Deploy keys (read-only), then run it again
```

Credentials are written to `/root/pella-secrets/` (root-only) and never printed: `staging-basic-auth.txt`, `staging-wp-admin.pw`, and the database password. The checkout's read-only GitHub deploy key lives at `~/.ssh/github_deploy`; `setup-ci-deploy.sh` copies it to the site's CI user so that only that user fetches into its code tree. Before CI is configured, code is owned by the administrative `deploy` account for setup; afterward it is owned by `pn-staging-deploy`. PHP-FPM runs as `pn-staging-php`, has access only to staging's config and uploads, and `www-data` remains the uploads owner. Update plugins and core with WP-CLI, not from the dashboard.

## 6. Site configuration

- **Create the pages:** `PN_WP_USER=pn-staging-php scripts/create-pages.sh /var/www/staging/public` (run as `deploy`; `PN_WP_USER` makes WordPress save photos as the staging PHP-FPM user) creates Home, New Here, About Us, Kids, Giving, Contact, Resources, Events, Small Groups, and Blog from the theme's page patterns and makes Home the front page. Safe to re-run: pages that exist are left alone, so staff edits are never overwritten. To reset one page to the pattern's current text: `scripts/create-pages.sh /var/www/staging/public --replace about-us`. It also loads the page photos into the media library (`docs/photos.md`; if one cannot be saved the script says why and creates nothing), sets pretty permalinks (`/%postname%/`) if WordPress is still on "Plain" (with Plain permalinks every menu link shows the home page), and clears WordPress's object cache; set `PN_NGINX_CACHE_DIR=/var/cache/nginx/pellanazarene-staging` to clear the Nginx page cache as well (production: `/var/cache/nginx/pellanazarene`), otherwise old pages can linger for up to an hour. CI deploys never create or change page content; run this by hand after the first deploy that contains the patterns. Staff edit pages in the normal WordPress editor afterwards.
- **Settings → Sermons:** confirm the channel ID, click **Check YouTube now**.
- **Sermons → Speakers:** create the four pastors and link each to their WordPress user.
- **Users:** pastors as **Author**, office admin as **Editor**. Authors write posts and sermons and submit them for review; they cannot publish. Editors publish.
- **WP Mail SMTP:** send through the church Gmail account (settings and the Google app password are in `docs/forms.md`).
- **Forms:** `PN_CONTACT_TO=… PN_PRAYER_TO=… PN_WP_USER=pn-staging-php scripts/create-forms.sh /var/www/staging/public` creates the Contact and Prayer Request forms (Fluent Forms, pinned); the Connect Card and Plan a Visit buttons link to the Church Center Connect Card. Setup, email delivery, spam rules and privacy: `docs/forms.md`.

## Backfilling older sermons

The RSS feed only returns the latest 15 videos. To import the full archive run `wp pn-sermons backfill --dry-run`, then without `--dry-run`. It uses the YouTube Data API and needs `PN_YOUTUBE_API_KEY`. Step-by-step instructions, including creating the key: `docs/sermons.md`.
