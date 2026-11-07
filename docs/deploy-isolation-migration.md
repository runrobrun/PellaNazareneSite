# Moving the server to separate deploy users (SEC-5)

The code for this is already in `main`: each site gets its own unprivileged deploy user, its own PHP pool and its own Redis login, so code merged to `main` can no longer reach production through the staging deploy (`docs/setup.md` section 5). This page is the one-time change to the **existing** server, which still runs everything as the single `deploy` user. Production does not exist yet; it is built later with the new setup from the start (`docs/cutover.md`), so only staging moves now.

Do it in one sitting. Staging is **down for a few minutes** between steps 6 and 7, so start with a quiet half hour and don't merge pull requests while you work (a merge would start a deploy halfway through).

You need: SSH as `deploy` (on the hotspot if the network blocks SSH), access to the Linode Cloud Manager, and GitHub repository settings.

## 1. Before you start

- [ ] Fix PR merged: `main` contains the Redis fixes from the SEC-5 review (`+info` in the Redis users, `WP_REDIS_SELECTIVE_FLUSH`). On the server: `git -C /var/www/staging/repo log --oneline -5` shows it. Without them the object cache stays off and every deploy fails at `wp cache flush`.
- [ ] The last staging deploy is green (GitHub, Actions tab).
- [ ] Redis Object Cache is 3.0.0 or newer (older versions ignore the Redis username):
  ```bash
  wp --path=/var/www/staging/public plugin get redis-cache --field=version
  ```
  If it is older: `wp --path=/var/www/staging/public plugin update redis-cache`, then check the site.
- [ ] Find the staging CI key you will move (the public half; it stays the same key, so GitHub's secret does not change):
  ```bash
  grep 'pn-ci-deploy' ~/.ssh/authorized_keys | grep -o 'ssh-ed25519 .*'
  ```
  Copy that one line (it starts `ssh-ed25519` and ends with `pella-ci-deploy`).

## 2. Snapshot

- [ ] Linode Cloud Manager → the server → **Backups** → **Take a Manual Snapshot**, name it `before-sec-5`. (Backups must be switched on for the Linode; INF-1 asked for that.) This is the full way back.

## 3. Your own copies of what changes

```bash
B=~/before-sec-5 && mkdir -p "$B" && chmod 700 "$B"
wp --path=/var/www/staging/public db export "$B/staging.sql"
sudo cp -a /var/www/staging/public/wp-config.php /etc/nginx/sites-available/staging* /etc/php/8.3/fpm/pool.d \
  /etc/redis/redis.conf /etc/cron.d/pella-staging ~/.ssh/authorized_keys "$B"/
sudo chown -R deploy: "$B"
```

## 4. Redis users and the `acl` tool

```bash
sudo bash /var/www/staging/repo/scripts/bootstrap-server.sh deploy
```

No `--lock-ssh` here. It is safe to re-run: it also updates packages and re-applies the firewall and fail2ban settings. New: it installs `acl`, creates the Redis users `pn-staging`, `pn-production` and `pn-admin` (passwords in `/root/pella-secrets/redis-*.pw`), turns off Redis's open default user and restarts Redis.

From now until step 7, staging's object cache cannot log in to Redis. The site keeps working without the cache (a little slower), but WP-CLI commands report "Error establishing a Redis connection". That is expected; carry on.

## 5. The staging CI user

```bash
cd /var/www/staging/repo
scripts/setup-ci-deploy.sh staging "ssh-ed25519 AAAA... pella-ci-deploy"   # the line from step 1
```

It creates `pn-staging-deploy` (no password, no general sudo; only the staging deploy wrapper and staging cache clearer), moves the CI key from `deploy` to it, and hands it the staging code. **Staging is down from here until step 7 finishes** (the web server's PHP cannot read the site's settings until its new pool exists).

## 6. GitHub

- [ ] Settings → Environments → **staging** → Variables: change `DEPLOY_USER` from `deploy` to `pn-staging-deploy`. Leave `DEPLOY_HOST` and both secrets as they are.

## 7. Staging under its own PHP user

```bash
cd /var/www/staging/repo
scripts/setup-staging.sh <admin user> <admin email>   # the same two values as the first time; unused when WordPress is installed
```

It writes the Redis login into `wp-config.php`, sets file ownership (code owned by `pn-staging-deploy`, read by `pn-staging-php`; uploads writable by `pn-staging-php`), installs the two PHP pools (`apply-server-tuning.sh`), points the staging site at its own PHP socket, runs a deploy as `pn-staging-deploy`, and moves WP-Cron to `pn-staging-php`. Staging is back up when it finishes.

## 8. Check

Run each; all should pass.

```bash
# The site answers, as the right users
# (the login is read from the server's secrets file, as the deploy wrapper does; never typed or saved)
curl -s -o /dev/null -w '%{http_code}\n' https://staging.pellanazarene.org/ \
  --config <(sudo sed -n 's/^password: \(.*\)/user = "church:\1"/p' /root/pella-secrets/staging-basic-auth.txt)   # 200
ps -o user=,args= -C php-fpm8.3 | sort | uniq -c        # workers as pn-staging-php and pn-production-php, none as www-data

# The object cache is connected and can be emptied (what every deploy does)
sudo -u pn-staging-php wp --path=/var/www/staging/public redis status | grep -i status   # Connected
sudo -u pn-staging-deploy wp --path=/var/www/staging/public cache flush                  # Success

# The isolation holds: the staging Redis user can't run server-wide commands or read other keys
redis-cli --user pn-staging --pass "$(sudo cat /root/pella-secrets/redis-staging.pw)" --no-auth-warning flushall   # NOPERM
sudo -u pn-staging-deploy sudo -n true 2>&1 | head -1    # refused: no general sudo

# WP-Cron runs as the staging PHP user
sudo -u pn-staging-php wp --path=/var/www/staging/public cron event list --fields=hook,next_run_relative | head -5
```

Then in a browser:
- [ ] Sign in to `https://staging.pellanazarene.org/wp-admin/` (password and authenticator code).
- [ ] Media → Add New: upload a small picture, check it shows, then delete it (proves uploads are writable).
- [ ] Open a few pages and a sermon.
- [ ] GitHub → Actions → the latest **Deploy** run → **Re-run all jobs**. It must go green: that is the CI key logging in as `pn-staging-deploy`, the wrapper, the backup, the deploy, the cache clear and the health check, end to end.

## If something goes wrong

Undo only the piece that broke (copies from step 3 are in `~/before-sec-5`):

| Symptom | Undo |
|---|---|
| Staging shows 502 / "File not found" after step 7 | Restore the old pool and vhost, but keep the new code ownership and grant the restored `www-data` PHP pool access to the config: `sudo cp -a ~/before-sec-5/pool.d/. /etc/php/8.3/fpm/pool.d/ && sudo cp ~/before-sec-5/staging* /etc/nginx/sites-available/ && sudo setfacl -m u:www-data:r-- /var/www/staging/public/wp-config.php && sudo systemctl restart php8.3-fpm && sudo nginx -t && sudo systemctl reload nginx` |
| GitHub deploys fail with "Permission denied (publickey)" after reverting to the old setup | Restore the `before-sec-5` snapshot (Linode → Backups → the snapshot → Restore to this Linode). This restores the legacy deployment wrapper, ownership, and SSH key. Then set GitHub's staging `DEPLOY_USER` to `deploy`. Do not restore only the key or change the variable on the migrated server. |
| Object cache not connected | Check `WP_REDIS_USERNAME`, `WP_REDIS_PASSWORD` and `WP_REDIS_SELECTIVE_FLUSH` in `wp-config.php`, and that the plugin is 3.0.0 or newer. To switch the cache off meanwhile, run it as the CI code owner: `sudo -u pn-staging-deploy wp --path=/var/www/staging/public redis disable`. |
| Redis itself broken | Restore the pre-migration Redis and WordPress configurations together (the settings file keeps its new owner, so staging's own PHP user can still read it), then restart Redis: `sudo cp -a ~/before-sec-5/redis.conf /etc/redis/redis.conf && sudo chown root:redis /etc/redis/redis.conf && sudo cp -a ~/before-sec-5/wp-config.php /var/www/staging/public/wp-config.php && sudo chown pn-staging-deploy:pn-staging-php /var/www/staging/public/wp-config.php && sudo chmod 640 /var/www/staging/public/wp-config.php && sudo systemctl restart redis-server`. |
| Lost, or several things broken | Restore the `before-sec-5` snapshot (Linode → Backups → the snapshot → Restore to this Linode), then set GitHub's staging `DEPLOY_USER` to `deploy`. This returns the server and deployment configuration to the pre-migration setup; then take your time. |

## Afterwards

- [ ] Tell me it is done: I remove the fallback in `deploy/deploy.sh` that still clears the cache the old way (it is only for servers that have not moved), and mark SEC-5 done.
- [ ] From now on, run content scripts as the PHP user: `PN_WP_USER=pn-staging-php scripts/create-pages.sh ...` (not `www-data`), and WP-CLI as `sudo -u pn-staging-php wp ...` for anything that writes uploads.
- [ ] Keep `~/before-sec-5` and the snapshot for a week, then delete both (the SQL copy contains password hashes).
- [ ] At cutover, production is built with these users from the start (`docs/setup.md` sections 3 to 5 and `docs/cutover.md`).
