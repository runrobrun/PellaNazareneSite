# Cutover: moving pellanazarene.org to the new site

The plan for switching the live address from the old Ministry Designs site to the new site on the Linode server (MIG-9). Work through it top to bottom and tick each box. Nothing here is done automatically.

The short version: build the live site on the Linode **beside** the old one, copy the reviewed content over from staging, test it privately, then change two DNS records. The old site stays untouched and paid for, so going back is the same two DNS records changed back.

## Fill in before you start

Write the answers here (no passwords; say where the password lives instead).

| What | Answer |
|---|---|
| Where the old site is hosted, and where its login is kept | _TBD_ |
| DNS host and where its login is kept | Moving from DNS Made Easy to Cloudflare (decision 1: (a)); steps in `docs/cloudflare-dns-move.md`; nameservers moved 2026-11-07 |
| Current DNS records for `pellanazarene.org` and `www` (type, value, TTL), copied before anything changes | _TBD_ |
| Linode server IP (IPv4, and IPv6 if used) | _TBD_ |
| Cutover date and time | **Friday 2026-11-20, morning** (decided 2026-11-17; see "When") |
| Who runs it / who on staff decides go or no-go | Rob Hammann, both (Rob is the church office and its tech support) |
| Old site kept until | Cutover date + 60 days |

## Decisions to make first

1. **DNS and certificates (INF-3).** **Decided 2026-11-07: (a) Cloudflare**; the DNS move is `docs/cloudflare-dns-move.md`. The choice was: either (a) move DNS to Cloudflare and put the site behind it, or (b) stay on DNS Made Easy and use a Let's Encrypt certificate on the server, no CDN. The steps below say where the two differ. If (a), move DNS to Cloudflare **at least a week before** cutover, as its own step: copy every record across, leave the proxy **off** (grey cloud) so the old site keeps working exactly as before, then change the nameservers at the registrar.
2. **How content reaches the live site.** Recommended: **copy the staging database** (step 4), because staging holds everything staff have reviewed: page edits, published sermons, photos, blog post, and each person's two-factor setup. The alternative, building the live site fresh with the scripts and redoing every staff edit by hand, risks losing work. Either way, staging edits stop at the content freeze.
3. **When.** **Decided 2026-11-17: Friday 2026-11-20, morning.** Pella Nazarene is a very small church and Rob is both the office and the tech support, so the usual "Tuesday to Thursday with the office reachable" rule reduces to "a weekday morning Rob is free". Never Saturday night or Sunday, when people look up service times. Schedule: build the live site (step 2) Wednesday 11-18; test it privately and freeze and copy content (steps 3 to 5) Thursday 11-19; switch (step 6) Friday 11-20 morning, leaving the weekend to watch it before Sunday.

## 1. Go or no-go (one week before)

Every **must** box ticked, or the date moves. **Should** boxes can slip past launch if written down here with a date.

**Must**
- [x] Staff have signed off every page (MIG-3), 2026-11-16, including the parking and kids-age wording. Micah Hammann's email is added to About Us when he has one; it does not hold up launch.
- [x] Staff have confirmed the five weekly event times (MIG-7), 2026-11-16.
- [x] Floyd Ware's bio approved as it stands (MIG-4), 2026-11-16.
- [x] At least one real blog post is published, so `/blog/` is not empty (FEAT-7), 2026-11-16.
- [x] Sermons reviewed and published on staging (the full "I AM" series), 2026-11-16. Staff decided not to migrate the four old-site sermons (Pentecost, God's Messenger, Vision Sunday 1 and 2): their old addresses go to the Sermons list, and `deploy/redirects/expected.csv` expects exactly that. (If they are ever imported, update those four rows to `/sermons/*`.)
- [x] `scripts/check-filler.sh` is clean on staging, all three parts (database, pages, code) (`docs/filler-check.md`), 2026-11-17: 127 columns, 22 pages and the code. WordPress's starter posts ("Hello world!", "Sample Page", the "Privacy Policy" draft) were deleted first.
- [x] `scripts/check-redirects.sh` passes on staging **without** `--allow-fallback` (`docs/redirects.md`), 2026-11-17: all 51 rows passed. Re-run 2026-11-19 with `--pages-must-exist` as well: all 51 passed.
- [x] Real email addresses agreed for the Contact form and for prayer requests (`docs/forms.md`), 2026-11-16. They are passed to `create-forms.sh` on the server, never stored in the repo.
- [x] Pre-launch security scan done, every high and medium finding fixed or written down (SEC-4), 2026-11-17: `docs/security-scan.md`. No high or medium findings. Fluent Forms 6.2.17 installed on staging 2026-11-17 and a Contact test message recorded. SSL Labs A, security headers A; A+ for both once HSTS is on, a week after cutover ("Afterwards").
- [x] Server: a Linode snapshot taken (2026-11-16), SSH locked (2026-11-16: `sshd -T`, read by `scripts/security-check-server.sh`, shows the effective `permitrootlogin no`, `passwordauthentication no` and `kbdinteractiveauthentication no`), and Linode backups switched on (2026-11-17) (INF-1).
- [x] Decision 1 made (Cloudflare); DNS moved 2026-11-07 and settled by 2026-11-14.

**Should**
- [ ] Production deploys from GitHub set up but still switched off (`docs/setup.md` section 5: a separate production key, the `production` environment with required reviewers). Step 2 deploys by hand; automatic deploys are switched on after the switch (step 7).
- [ ] Off-server backups and one restore tried on staging (OPS-1).
- [ ] Uptime monitor on the home page and `wp-login.php` (OPS-2).
- [ ] Performance numbers recorded on staging (PERF-1).

## 2. Build the live site beside the old one (3 to 7 days before)

The old site keeps serving visitors the whole time. `scripts/setup-production.sh` does the build, the same way `setup-staging.sh` built staging; three things come first because they involve a key, a certificate and a password that only you should handle. All commands run on the server as `deploy`, from the staging checkout (`cd /var/www/staging/repo`), unless they say "on your Mac".

- [ ] **Production deploy account (SEC-5).** On your Mac, make a key used only for production deploys: `ssh-keygen -t ed25519 -N "" -C pella-ci-deploy-production -f ~/.ssh/pella_ci_deploy_production`. Show its public half with `cat ~/.ssh/pella_ci_deploy_production.pub` (one line starting `ssh-ed25519`; never share the file without `.pub`). On the server, paste that line between the quotes: `scripts/setup-ci-deploy.sh production "ssh-ed25519 AAAA... pella-ci-deploy-production"`. This creates `pn-production-deploy`, which owns the live site's code from the first minute; the GitHub side (the `production` environment and its secrets) is the "Should" item in step 1 and can follow.
- [ ] **Cloudflare origin certificate.** Cloudflare dashboard → `pellanazarene.org` → **SSL/TLS → Origin Server → Create Certificate**: let Cloudflare generate the key (RSA 2048), hostnames `pellanazarene.org` and `*.pellanazarene.org`, 15 years → Create. On the server, paste each part into its file and save: `sudo install -d -m 755 /etc/ssl/cloudflare`, then `sudo nano /etc/ssl/cloudflare/pellanazarene.pem` (the **Origin Certificate**) and `sudo nano /etc/ssl/cloudflare/pellanazarene.key` (the **Private Key**; Cloudflare shows it only once). Then `sudo chmod 600 /etc/ssl/cloudflare/pellanazarene.key`. Do **not** change the SSL/TLS mode yet; that happens at the switch (step 6).
- [ ] **Gmail app password** (needed before the switch, so the forms can email): create it for `pellanazarene@gmail.com` (`docs/forms.md`), then `sudo nano /root/pella-secrets/gmail-app-password`, paste the 16 characters, save, `sudo chmod 600 /root/pella-secrets/gmail-app-password`.
- [ ] **Build:** `scripts/setup-production.sh ADMIN_USER ADMIN_EMAIL` (your own username and email). It is safe to re-run; it stops and says what's missing if a step above isn't done. It creates the `pellanazarene` database and user, WordPress with unique salts, `wp-config.php` (`WP_ENVIRONMENT_TYPE` production, which turns email and Google Analytics on; `DISABLE_WP_CRON`, `DISALLOW_FILE_EDIT`, `FORCE_SSL_ADMIN`; the production Redis user and `pn_production_` prefix; the Gmail settings; the YouTube channel), the SEC-5 file ownership, the pinned plugins and our code, the Cloudflare real-IP snippet (from cloudflare.com/ips), the Nginx vhost (tested before it is enabled), WP-Cron and the nightly database backup as `pn-production-php`, and the nightly WordPress security updates.
- [ ] Check: `sudo -u pn-production-deploy -H wp --path=/var/www/pellanazarene/public plugin list --fields=name,status,version` shows Two Factor 0.17.0 and Fluent Forms 6.2.17 active and no The Events Calendar; `scripts/security-check-server.sh production` has no FAIL.
- [ ] Look at it privately: add a line to your Mac's hosts file (`sudo nano /etc/hosts`, then `172.237.145.140 pellanazarene.org`), browse `https://pellanazarene.org`, then remove the line. The browser warns about the certificate (an origin certificate is only trusted through Cloudflare); that is expected for this test only. It shows WordPress's starter content until step 4 copies staging over.

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

Then, on the live copy: re-add the hosts-file line from step 2 **before opening `wp-admin` in your browser**, and keep it in place through step 5. Public DNS still points to the old site.
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

- [ ] The browser checks from step 5 again, now on a phone over cellular too.
- [ ] Google Analytics: the four checks in `docs/analytics.md` (tag once in the source, Realtime shows your visit, absent when signed in, absent on staging).
- [ ] Search and sharing: the five launch steps in `docs/seo.md` (submit `https://pellanazarene.org/wp-sitemap.xml`, check the source, share a link to yourself, Google Business Profile, "Discourage search engines" off).
- [ ] Staging still asks for a password and still says `noindex`.
- [ ] Real visitor addresses reach the site through Cloudflare: load the home page from your phone (cellular), then on the server `sudo tail -3 /var/log/nginx/access.log` shows your phone's address (the one https://ifconfig.me shows on the phone), not one from the ranges at https://www.cloudflare.com/ips/. Otherwise the login throttle and the forms' spam limit would treat every visitor as one. (SEC-4, finding 3)
- [ ] The SSL Labs and security-headers checks from `docs/security-scan.md` section 3 on `pellanazarene.org`: SSL Labs A or better. HSTS is not on yet (it comes a week later, under "Afterwards"), so A, not A+, is expected now.
- [ ] Switch on deploys to the live site: in GitHub, Settings → Secrets and variables → Actions → **Variables**, add the repository variable `PRODUCTION_DEPLOYS` with the value `on`. From the next merge, each change goes to staging, then waits for an approval in the Actions tab before it reaches the live site.
- [ ] Tell staff the new site is live and that edits now happen at `pellanazarene.org/wp-admin`.

## If it goes wrong: rollback

Go back if the live site is down or badly broken and the cause is not found within **30 minutes**. A broken form or a wrong photo is not a reason to roll back: fix it on the live site (the phone number on every page covers the gap).

1. At the DNS host, put the `pellanazarene.org` and `www` records back to the old values written at the top (Cloudflare: also turn the proxy off). With the 300-second TTL, most visitors are back on the old site within 10 minutes.
2. Tell staff the old site is back and not to edit the new one.
3. Fix the new site privately with the hosts-file line, and run steps 5 and 6 again on a new date.

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
- [ ] Turn on HSTS once HTTPS has been fine for a week (`docs/setup.md`, Nginx section). Then re-test `pellanazarene.org` on SSL Labs and securityheaders.com: expect A+ on both, and record it in `docs/security-scan.md`.

**After 60 days**
- [ ] With staff agreement, cancel the old hosting. Keep the backup from step 6.
- [ ] Mark MIG-9 done in `docs/backlog.md`.
