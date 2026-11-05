# Cutover: moving pellanazarene.org to the new site

The plan for switching the live address from the old Ministry Designs site to the new site on the Linode server (MIG-9). Work through it top to bottom and tick each box. Nothing here is done automatically.

The short version: build the live site on the Linode **beside** the old one, copy the reviewed content over from staging, test it privately, then change two DNS records. The old site stays untouched and paid for, so going back is the same two DNS records changed back.

## Fill in before you start

Write the answers here (no passwords; say where the password lives instead).

| What | Answer |
|---|---|
| Where the old site is hosted, and where its login is kept | _TBD_ |
| DNS host and where its login is kept | DNS Made Easy (or Cloudflare, see decision 1) |
| Current DNS records for `pellanazarene.org` and `www` (type, value, TTL), copied before anything changes | _TBD_ |
| Linode server IP (IPv4, and IPv6 if used) | _TBD_ |
| Cutover date and time | _TBD_ (see "When") |
| Who runs it / who on staff decides go or no-go | Rob Hammann / _TBD_ |
| Old site kept until | Cutover date + 60 days |

## Decisions to make first

1. **DNS and certificates (INF-3).** Either (a) move DNS to Cloudflare and put the site behind it, or (b) stay on DNS Made Easy and use a Let's Encrypt certificate on the server, no CDN. The steps below say where the two differ. If (a), move DNS to Cloudflare **at least a week before** cutover, as its own step: copy every record across, leave the proxy **off** (grey cloud) so the old site keeps working exactly as before, then change the nameservers at the registrar.
2. **How content reaches the live site.** Recommended: **copy the staging database** (step 4), because staging holds everything staff have reviewed: page edits, published sermons, photos, blog post, and each person's two-factor setup. The alternative, building the live site fresh with the scripts and redoing every staff edit by hand, risks losing work. Either way, staging edits stop at the content freeze.
3. **When.** A weekday morning, Tuesday to Thursday, with someone from the office reachable. Not Saturday night or Sunday, when people look up service times.

## 1. Go or no-go (one week before)

Every **must** box ticked, or the date moves. **Should** boxes can slip past launch if written down here with a date.

**Must**
- [ ] Staff have signed off every page (MIG-3), including the open questions: parking and kids-age wording, the New Here welcome video, the staff emails on About Us.
- [ ] Staff have confirmed the five weekly event times (MIG-7).
- [ ] Floyd Ware's full bio is in, or the cut-off sentence is removed (MIG-4).
- [ ] At least one real blog post is published, so `/blog/` is not empty (FEAT-7).
- [ ] Sermons reviewed and published on staging; all four old-site sermons (Pentecost, God's Messenger, Vision Sunday 1 and 2) have published targets, with `pn_legacy_slug` set where needed so their old URLs reach the individual sermon pages (`docs/sermons.md`, "Overrides"; `docs/redirects.md`).
- [ ] `scripts/check-filler.sh` is clean on staging, all three parts (database, pages, code) (`docs/filler-check.md`).
- [ ] `scripts/check-redirects.sh` passes on staging **without** `--allow-fallback` (`docs/redirects.md`).
- [ ] Real email addresses agreed for the Contact form and for prayer requests (`docs/forms.md`).
- [ ] Pre-launch security scan done, every high and medium finding fixed or written down (SEC-4).
- [ ] Server: a Linode snapshot taken, then SSH locked (`scripts/bootstrap-server.sh deploy --lock-ssh`), and Linode backups switched on (INF-1).
- [ ] Decision 1 made, and if Cloudflare, DNS already moved and settled.

**Should**
- [ ] Production deploy job in GitHub with required approval (DEV-3). Until it exists, deploy by hand (step 2).
- [ ] Off-server backups and one restore tried on staging (OPS-1).
- [ ] Uptime monitor on the home page and `wp-login.php` (OPS-2).
- [ ] Performance numbers recorded on staging (PERF-1).

## 2. Build the live site beside the old one (3 to 7 days before)

The old site keeps serving visitors the whole time. Follow `docs/setup.md`; the live site differs from staging as below.

- [ ] Database and user `pellanazarene` (setup.md section 2; the password is already in `/root/pella-secrets/`).
- [ ] WordPress in `/var/www/pellanazarene/public`, this repo in `/var/www/pellanazarene/repo`, Twenty Twenty-Five installed (setup.md section 3).
- [ ] `wp-config.php`: unique salts; `WP_ENVIRONMENT_TYPE` set to `production` (this is what turns email and Google Analytics on); `DISABLE_WP_CRON`, `DISALLOW_FILE_EDIT`, `FORCE_SSL_ADMIN`; Redis on a **different database number and key prefix** from staging; the `WPMS_*` Gmail settings (`docs/forms.md`); the YouTube constants (`docs/sermons.md`).
- [ ] Code deployed: `deploy/deploy.sh /var/www/pellanazarene /var/cache/nginx/pellanazarene <commit>` as `deploy`, with the commit currently on staging.
- [ ] Plugins at the pinned versions: `scripts/install-plugins.sh /var/www/pellanazarene/public` on a fresh install is fine. Check `wp plugin list`: Two Factor 0.17.0 and Fluent Forms 6.2.16 active, and The Events Calendar **not** installed.
- [ ] Nginx vhost from `deploy/nginx.conf.example` (it already includes the redirect snippet), `scripts/apply-server-tuning.sh` run, `sudo nginx -t` passes. Two changes the example still needs: (1) it has no HTTPS server block for `www`, so add one (`listen 443 ssl http2; listen [::]:443 ssl http2; server_name www.pellanazarene.org;` with the same certificate, and `return 301 https://pellanazarene.org$request_uri;`), otherwise `https://www.pellanazarene.org` may hit another vhost or have its TLS handshake rejected; (2) with Let's Encrypt (decision 1b), point `ssl_certificate` at `/etc/letsencrypt/live/pellanazarene.org/` instead of the Cloudflare paths. Do not reload Nginx with a vhost whose certificate files do not exist yet.
- [ ] Certificate:
  - (a) Cloudflare: create the origin certificate, install it at the paths in the vhost, write the real-IP config (`/etc/nginx/snippets/cloudflare-realip.conf`, not in the repo yet: `set_real_ip_from` for each range at cloudflare.com/ips, and `real_ip_header CF-Connecting-IP;`) and uncomment its include in the vhost. The login throttle needs real IPs, so this comes before launch.
  - (b) Let's Encrypt: it cannot check the server until DNS points here. To avoid a few minutes of browser warnings at the switch, get a temporary certificate now with a DNS check (`sudo certbot certonly --manual --preferred-challenges dns -d pellanazarene.org -d www.pellanazarene.org`, then add the TXT record it asks for at DNS Made Easy). It will not renew itself, so it is replaced in step 6.
- [ ] Cron: `deploy/crontab.example` installed for `www-data`; `/var/backups/pellanazarene` created.
- [ ] Look at it privately: add a line to your computer's hosts file (`<Linode IP> pellanazarene.org`), browse it, then remove the line.

## 3. Lower the DNS time-to-live (at least 48 hours before cutover)

- [ ] At the DNS host, set the TTL of the `pellanazarene.org` and `www` address records (A, and AAAA if present) to 300 seconds. This makes the switch, and a rollback, take minutes instead of hours.
- [ ] Before continuing, wait at least the previous TTL recorded at the top of this document. Start this step early enough to finish the wait before switching in step 6.
- [ ] Leave every other record alone: **MX, TXT (SPF, Google verification), CNAME** and anything else stay exactly as they are, or church email may stop.
- [ ] Optional now: add the live site to **Google Search Console** as a domain property and verify it with the TXT record it gives (this does not affect the old site).

## 4. Content freeze and copy from staging (the day before)

From here on, **nobody edits staging**. Tell staff; any change they need now is made on the live site after the switch.

On the server, as `deploy`:

```bash
sudo install -d -o deploy -m 700 /var/backups/pn-cutover
# keep a copy of both databases as they are now
wp --path=/var/www/staging/public db export /var/backups/pn-cutover/staging.sql
wp --path=/var/www/pellanazarene/public db export /var/backups/pn-cutover/live-before.sql

# copy the content and the media
wp --path=/var/www/pellanazarene/public db import /var/backups/pn-cutover/staging.sql
sudo rsync -a /var/www/staging/public/wp-content/uploads/ /var/www/pellanazarene/public/wp-content/uploads/
sudo chown -R www-data:www-data /var/www/pellanazarene/public/wp-content/uploads

# point it at the real address and open it to search engines
cd /var/www/pellanazarene/public
wp search-replace 'https://staging.pellanazarene.org' 'https://pellanazarene.org' --all-tables --precise --skip-columns=guid
wp search-replace 'staging.pellanazarene.org' 'pellanazarene.org' --all-tables --precise --skip-columns=guid
wp option update blog_public 1
wp option update blogname 'Pella Nazarene'
wp cache flush
sudo find /var/cache/nginx/pellanazarene -type f -delete
```

Then, on the live copy: re-add the hosts-file line from step 2 **before opening `wp-admin` in your browser**, and keep it in place through step 4. Public DNS still points to the old site.
- [ ] **Forms** (Fluent Forms → each form → Settings → Email Notifications): set the real Contact and prayer recipients, and delete any test messages under Entries.
- [ ] **Users:** remove test accounts; check pastors are Author and the office admin is Editor. Everyone keeps their staging password and two-factor setup.
- [ ] **Settings → Sermons:** channel ID present; **Check YouTube now** works.
- [ ] `scripts/check-filler.sh /var/www/pellanazarene/public --repo /var/www/pellanazarene/repo` is clean (database and code; the page part runs after the switch).
- [ ] No staging references remain outside preserved GUIDs: run `wp db search 'staging.pellanazarene' --all-tables` and confirm any remaining matches are only in `guid` columns, intentionally excluded from search-replace above.

## 5. Test it privately (the day before)

The live site answers to its real name before DNS points at it, so test it by forcing the address. Replace `IP` with the Linode's address.

```bash
# every old address does what docs/redirects.md says, strictly
scripts/check-redirects.sh https://pellanazarene.org --resolve pellanazarene.org:443:IP --pages-must-exist
# add --insecure only with the Cloudflare origin certificate, which browsers and curl do not trust directly
```

With the hosts-file line from step 2 in place, in a browser:
- [ ] Every menu page opens with its photos: Home, New Here, About Us, Kids, Giving, Contact, Resources, Events, Small Groups, Blog, plus Prayer and Privacy.
- [ ] Sermons list, one sermon, and its video plays only after a click.
- [ ] Giving opens Church Center giving; Connect Card and Plan a Visit open the Church Center Connect Card.
- [ ] A made-up address shows the friendly 404.
- [ ] Send one message through Contact and one through Prayer Request; both arrive (check Spam the first time). Then **WP Mail SMTP → Tools → Email Test**. Delete the test Contact entry.
- [ ] Sign in to `wp-admin` with password and authenticator code.
- [ ] On a phone (same hosts trick is harder there, so this can wait until step 6).

Then remove the hosts-file line.

## 6. Switch (cutover morning)

- [ ] Take a Linode snapshot.
- [ ] Download a full backup of the old site from its host (files and database), and keep it with the church's records.
- [ ] Write the old A/AAAA values in the table at the top (they are the rollback).
- [ ] Change the records:
  - (a) Cloudflare: set the `pellanazarene.org` A record to the Linode IPv4 address and update any existing AAAA record to the Linode IPv6 address (or remove it if IPv6 is not used); do the same for `www`. Turn the proxy **on** (orange cloud) for the web records. SSL/TLS mode **Full (strict)**.
  - (b) DNS Made Easy: set the `pellanazarene.org` A record to the Linode IPv4 address and update any existing AAAA record to the Linode IPv6 address (or remove it if IPv6 is not used); do the same for `www`, or replace its address records with a CNAME to `pellanazarene.org`.
- [ ] Wait 5 to 10 minutes, then check from outside: `dig +short pellanazarene.org` shows the new address (Cloudflare: one of its addresses), and the home page loads.
- [ ] (b) only: replace the temporary certificate with one that renews itself: `sudo certbot certonly --webroot -w /var/www/pellanazarene/public -d pellanazarene.org -d www.pellanazarene.org --force-renewal`, `sudo nginx -t && sudo systemctl reload nginx`, and `sudo certbot renew --dry-run` succeeds.

## 7. Check the live site (first hour)

```bash
scripts/check-redirects.sh https://pellanazarene.org --pages-must-exist     # never --allow-fallback
scripts/check-filler.sh /var/www/pellanazarene/public --url https://pellanazarene.org --repo /var/www/pellanazarene/repo   # on the server
curl -sI http://pellanazarene.org/      | head -3   # 301 to https
curl -sI https://www.pellanazarene.org/ | head -3   # 301 to https://pellanazarene.org/
curl -sI https://pellanazarene.org/ | grep -i -E 'x-cache|x-robots'   # run twice: MISS then HIT; no X-Robots-Tag line
```

- [ ] The browser checks from step 4 again, now on a phone over cellular too.
- [ ] Google Analytics: the four checks in `docs/analytics.md` (tag once in the source, Realtime shows your visit, absent when signed in, absent on staging).
- [ ] Search and sharing: the five launch steps in `docs/seo.md` (submit `https://pellanazarene.org/wp-sitemap.xml`, check the source, share a link to yourself, Google Business Profile, "Discourage search engines" off).
- [ ] Staging still asks for a password and still says `noindex`.
- [ ] Tell staff the new site is live and that edits now happen at `pellanazarene.org/wp-admin`.

## If it goes wrong: rollback

Go back if the live site is down or badly broken and the cause is not found within **30 minutes**. A broken form or a wrong photo is not a reason to roll back: fix it on the live site (the phone number on every page covers the gap).

1. At the DNS host, put the `pellanazarene.org` and `www` records back to the old values written at the top (Cloudflare: also turn the proxy off). With the 300-second TTL, most visitors are back on the old site within 10 minutes.
2. Tell staff the old site is back and not to edit the new one.
3. Fix the new site privately with the hosts-file line, and run steps 4 and 6 again on a new date.

If edits were made on the new site before rolling back, they are kept there; nothing on the old site needs undoing, because nothing on it was changed.

## Afterwards

**Next day**
- [ ] Raise the DNS TTL back to an hour or more.
- [ ] Refresh staging from the live site (INF-2), so staging matches what is live again.
- [ ] Confirm last night's database backup is in `/var/backups/pellanazarene` and the sermon check ran (Sermons in the admin).

**For two weeks**
- [ ] Search Console → **Pages**: look at "Not found (404)" every few days. An old address with real visitors gets a row in `deploy/redirects/expected.csv` and a rule (`docs/redirects.md`, "Changing the rules").
- [ ] The server's own list of missing pages, most requested first:
  ```bash
  sudo awk '$9 == 404 {print $7}' /var/log/nginx/access.log | sort | uniq -c | sort -rn | head -20
  ```
- [ ] Turn on HSTS once HTTPS has been fine for a week (`docs/setup.md`, Nginx section).

**After 60 days**
- [ ] With staff agreement, cancel the old hosting. Keep the backup from step 6.
- [ ] Mark MIG-9 done in `docs/backlog.md`.
