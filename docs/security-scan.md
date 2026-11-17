# Pre-launch security scan (SEC-4)

The record of the scan the cutover go/no-go list asks for: every high and medium finding is fixed or written down here. Re-run it (sections 2 and 3) after any big change and once a year.

| Part | Who | Status |
|---|---|---|
| 1. Review of our own code and server config | Claude, 2026-11-16 | Done: no high or medium findings; two low findings fixed |
| 2. Server check (`scripts/security-check-server.sh`) | Rob, on the server | Done 2026-11-16: 0 FAIL |
| 3. Outside scans of staging (WPScan, ZAP baseline, SSL Labs, security headers) | Rob, from a Mac with Docker | WPScan done 2026-11-16 (one low finding, fixed); ZAP, SSL Labs and headers to do |

## 1. Code and configuration review

**Scope:** everything in this repo that runs on the site or server: `wp-content/mu-plugins/` (site, security, SEO, forms), the `pn-sermons` plugin (YouTube import, companion drafts, settings, shortcodes, CLI), the theme's PHP (`functions.php`, `inc/`), the Nginx and PHP-FPM config in `deploy/`, and the deploy and update scripts. Checked against the security rules in `CLAUDE.md`.

**What holds up** (no change needed):
- **Input and output.** Settings are sanitized on save (`sanitize_text_field`, `sanitize_email`, `sanitize_textarea_field`) and escaped on output. Shortcode attributes are validated (YouTube IDs must match `^[\w-]{11}$`). The schema.org JSON is encoded with `JSON_HEX_TAG`, so it can't close its `<script>`. AI-drafted companion posts pass through `wp_kses_post` and are only ever saved as drafts for a pastor to review.
- **Admin actions.** "Check YouTube now" needs `manage_options` and a nonce. Linking a speaker to a user needs `manage_categories` and a nonce, and the value is `absint`ed. There are no custom REST routes or AJAX handlers.
- **SQL.** Every query with a variable uses `$wpdb->prepare()` (form clean-up, rate-limit locks, import lookups).
- **Redirects.** Every redirect uses `wp_safe_redirect`, so the site can't be used as an open redirect to other websites.
- **Secrets.** The YouTube and Anthropic keys come from `wp-config.php` constants and are never printed. Outbound calls use `wp_remote_*` with timeouts, and responses are checked before use.
- **Accounts.** Two-factor sign-in is required, including on the REST API. Login errors don't reveal whether a username exists. The users REST endpoints, author pages, author sitemaps and oEmbed author data are hidden from visitors. Application passwords are off.
- **Forms.** Spam is held back by a time check and a per-connection limit (5 an hour). Prayer requests are emailed and then erased, never stored. Contact messages are deleted after 12 months.
- **Server config.** `xmlrpc.php`, `wp-config.php`, `readme.html`, `license.txt`, dotfiles and PHP in uploads are all blocked before PHP runs. The Nginx and PHP versions are hidden, errors aren't displayed, and logins are rate-limited. Each site's PHP runs as its own user, which can't write the site's code (SEC-5). WordPress security releases install nightly (SEC-2).

**Findings**

| # | Severity | Finding | Outcome |
|---|---|---|---|
| 1 | Low | Nothing stopped Nginx from serving log files, database dumps or editor leftovers (`wp-content/debug.log`, `*.sql`, `*.sql.gz`, `*.bak`, `*.old`, `*.orig`, `*.swp`) if one ever ended up in the web folder. Turning on WordPress's debug log would have made `debug.log` public. | **Fixed:** both vhosts deny these, and the Nginx tests check five examples. |
| 2 | Low | No `Content-Security-Policy` header, which scanners report. | **Fixed (baseline):** `base-uri 'self'; object-src 'none'; frame-ancestors 'self'`, tested. Scripts are deliberately not restricted: Google Analytics, the video facade, Fluent Forms and the block editor need inline and third-party scripts, and a strict policy would have to be built and tested page by page. A scanner may still report "no script-src"; that is accepted. |
| 3 | Info (cutover) | The forms' rate limit and Nginx's login limit count visitors by IP address. Behind Cloudflare, every visitor would look like a Cloudflare server unless Nginx is told the real address. | **Already planned:** the cutover checklist (step 2) installs `cloudflare-realip.conf`. Its check now confirms the real address shows up in the access log. |
| 4 | Info (cutover) | No `Strict-Transport-Security` (HSTS) header yet. | **Already planned** for cutover with Cloudflare (INF-3). It is left off staging on purpose, so a mistake there can't lock browsers out. |

## Before scanning: put the new Nginx config on staging

A deploy only ships WordPress code, so this PR's Nginx changes (the blocked file types and the CSP header) reach the server by hand. On the server as `deploy`:

```bash
cd /var/www/staging/repo
# 1. The shared snippets, including the security headers. It backs up, tests and rolls back by itself.
scripts/apply-server-tuning.sh
# 2. The staging site file, rebuilt from the template (the old one is kept beside it).
sudo cp -a /etc/nginx/sites-available/staging.conf /etc/nginx/sites-available/staging.conf.before-sec-4
sed -e "s|@DOMAIN@|staging.pellanazarene.org|g" -e "s|@WEBROOT@|/var/www/staging/public|g" \
  deploy/nginx/staging.conf.template | sudo tee /etc/nginx/sites-available/staging.conf >/dev/null
sudo nginx -t && sudo systemctl reload nginx \
  || { sudo cp -a /etc/nginx/sites-available/staging.conf.before-sec-4 /etc/nginx/sites-available/staging.conf; sudo nginx -t && sudo systemctl reload nginx; echo "Rolled back"; }
# 3. Check: a CSP header, and the blocked file types refused (403), not passed to WordPress.
#    It asks for the staging gate password once and never puts it on a command line.
read -rsp 'Staging gate password: ' G; echo
auth() { printf 'user = "church:%s"\n' "$G"; }
curl -sI --config <(auth) https://staging.pellanazarene.org/ | grep -i content-security-policy
for f in /wp-content/debug.log /backup.sql /wp-config.php.bak; do
  echo "$f $(curl -s -o /dev/null -w '%{http_code}' --config <(auth) "https://staging.pellanazarene.org$f")"
done
unset G
```

Expect the `content-security-policy:` line and `403` three times. Production gets the same files when it is built (`docs/cutover.md` step 2).

## 2. Server check

On the server as `deploy`, from the site's checkout:

```bash
cd /var/www/staging/repo && scripts/security-check-server.sh staging
```

It changes nothing. It checks the firewall (including that incoming traffic is denied by default) and open ports, SSH settings, fail2ban, operating-system updates, WordPress core and plugin files against WordPress.org, wp-config settings and permissions, whether PHP can write core files, PHP files in uploads, the user list, the automatic core updates, and the secrets folder. Paste the output under "Results". Every **FAIL** must be fixed or written down before cutover; **WARN** lines are worth a look.

## 3. Outside scans of staging

From a Mac with Docker running, in one Terminal window. Staging sits behind the gate password (user `church`). Read it once, without typing it into a command; this is the zsh form, which is the Mac's default shell (in bash, `read -rsp 'Staging gate password: ' G`):

```zsh
read -rs "G?Staging gate password: "; echo
echo "password length: ${#G}"     # must be more than 0
```

Then, in the same window:

1. **WPScan** (known WordPress, plugin and theme vulnerabilities). A free API token from wpscan.com adds the vulnerability database; without one it only lists versions.
   ```bash
   docker run --rm wpscanteam/wpscan --update --url https://staging.pellanazarene.org \
     --http-auth "church:$G" --enumerate vp,vt,cb,dbe --random-user-agent \
     --api-token YOUR_FREE_TOKEN
   ```
2. **ZAP baseline** (a passive scan of the pages for common web problems; it doesn't attack anything). The single quotes around `Basic $AUTH` matter: ZAP splits this option again, and they keep the whole password header together:
   ```bash
   AUTH=$(printf 'church:%s' "$G" | base64)
   docker run --rm -t ghcr.io/zaproxy/zaproxy:stable zap-baseline.py \
     -t https://staging.pellanazarene.org -I \
     -z "-config replacer.full_list(0).description=gate -config replacer.full_list(0).enabled=true -config replacer.full_list(0).matchtype=REQ_HEADER -config replacer.full_list(0).matchstr=Authorization -config replacer.full_list(0).regex=false -config replacer.full_list(0).replacement='Basic $AUTH'"
   ```
3. **SSL Labs:** open https://www.ssllabs.com/ssltest/ and test `staging.pellanazarene.org`; aim for A or A+. Repeat for `pellanazarene.org` after cutover, where Cloudflare's certificate is the one tested.
4. **Security headers:** open https://securityheaders.com and test `https://staging.pellanazarene.org/`. It sees the gate's 401 page, which carries the same headers. Expect a missing HSTS (finding 4) and a CSP note (finding 2).

## Results

Paste each tool's summary here, then list each high or medium finding with its fix or the reason it is accepted.

| Tool | Date | Result | High/medium findings and outcome |
|---|---|---|---|
| Server check | 2026-11-16 | 32 ok, 4 warn, 0 FAIL. SSH already key-only; ufw default deny; core and plugin files match WordPress.org | No FAIL. Warnings: one OS security update (installs overnight) and three plugin updates (see WPScan). Also found The Events Calendar still installed on staging although MIG-7 removed it; **deleted 2026-11-16** |
| WPScan | 2026-11-16 | WordPress 7.1.4 (latest); no config backups or DB exports reachable; one plugin vulnerability | **Fluent Forms < 6.2.17, unauthenticated missing authorization** (Patchstack rates it low, "unlikely to be exploited"). **Fixed:** pinned to 6.2.17 (`plugins.txt`); the CI forms tests pass on it; install on staging after merge (see the PR). Info only, accepted: the WordPress version shows in the RSS feed's generator tag (public on WordPress.org anyway, and kept current nightly); `wp-cron.php` and the must-use plugin folder are reachable (normal for WordPress; cron also runs from the server's own cron); `X-Robots-Tag` appears twice on staging (harmless, staging only) |
| ZAP baseline | | | |
| SSL Labs | | | |
| Security headers | | | |
