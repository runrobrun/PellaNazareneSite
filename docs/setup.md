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
define( 'PN_ANTHROPIC_API_KEY', '…' );   // only when companion posts are turned on
```

## 4. Nginx, cron, Cloudflare

- Copy `deploy/nginx.conf.example` to `/etc/nginx/sites-available/`, adjust, enable, `nginx -t && systemctl reload nginx`.
- Create a Cloudflare origin certificate; set SSL mode to **Full (strict)**.
- Install `deploy/crontab.example` for the `www-data` user.
- `mkdir -p /var/backups/pellanazarene && chown www-data /var/backups/pellanazarene`

## 5. GitHub deploys

1. Create a deploy user on the server with write access to `/var/www/pellanazarene` and an SSH key.
2. Add that key as a read-only **deploy key** on the GitHub repo so the server can `git fetch`.
3. Add repo secrets: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_PATH` (`/var/www/pellanazarene`).
4. Create a `production` environment in the repo settings (optionally require approval).

## 6. Site configuration

- **Settings → Sermons:** confirm the channel ID, click **Check YouTube now**.
- **Sermons → Speakers:** create the four pastors and link each to their WordPress user.
- **Users:** pastors as **Author**, office admin as **Editor**.
- **WP Mail SMTP:** send through the church Gmail account.
- **Fluent Forms:** Contact, Connect Card, Plan a Visit, Prayer Request.

## Backfilling older sermons

The RSS feed only returns the latest 15 videos. To import the full archive, use the YouTube Data API `playlistItems.list` on the channel's uploads playlist (the channel ID with `UC` replaced by `UU`). Not built yet.
