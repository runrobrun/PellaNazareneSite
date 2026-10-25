# Forms: Contact and Prayer Request

Two forms live on the site, built with the **Fluent Forms** plugin (pinned in `plugins.txt`):

| Form | Page | Where it goes | Kept? |
|---|---|---|---|
| **Contact** | `/contact/` | Emailed to the church contact address | Saved in the admin for **12 months**, then deleted automatically |
| **Prayer Request** | `/prayer/` | Emailed to the pastors only | **Not kept.** Erased from the website the moment the email is sent |

The **Connect Card** and **Plan a Visit** are not on this site: those buttons open the church's Connect Card in Church Center (`pella-nazarene.churchcenter.com/people/forms/962834`), so visitor details go straight into Planning Center.

## Setting the forms up (once per server)

1. Make sure Fluent Forms is installed (`scripts/install-plugins.sh`; it is pinned to the version in `plugins.txt`).
2. Create the forms and say who gets the email (nothing about who is stored in the repo):
   ```bash
   cd /var/www/staging/repo
   PN_WP_USER=www-data \
   PN_CONTACT_TO="pellanazarene@gmail.com" \
   PN_PRAYER_TO="pastor1@example.org,pastor2@example.org" \
   scripts/create-forms.sh /var/www/staging/public
   ```
   Several addresses are separated by commas. Re-running is safe: forms that exist are left alone, so staff edits are kept. `--replace contact` (or `prayer`) resets one form to the file in `deploy/forms/`.
3. Create the pages: `scripts/create-pages.sh` (see `docs/setup.md`). Pages show a form with `[pn_form name="contact"]` or `[pn_form name="prayer"]`, so the same page text works on staging and the live site.

The script also deletes Fluent Forms' two sample forms and turns on its hidden spam trap (which is off until switched on).

**Staging never sends email** (by design), so on staging the forms accept messages but nothing is delivered; the test suite checks the emails. Do the real delivery test on the live site (see below).

## Email delivery (live site)

Email goes out through the **WP Mail SMTP** plugin using the church Gmail account. Put these in `wp-config.php` on the live server (never in the repo):

```php
define( 'WPMS_ON', true );
define( 'WPMS_MAILER', 'smtp' );
define( 'WPMS_MAIL_FROM', 'pellanazarene@gmail.com' );
define( 'WPMS_MAIL_FROM_FORCE', true );
define( 'WPMS_MAIL_FROM_NAME', 'Pella Nazarene website' );
define( 'WPMS_SMTP_HOST', 'smtp.gmail.com' );
define( 'WPMS_SMTP_PORT', 587 );
define( 'WPMS_SSL', 'tls' );
define( 'WPMS_SMTP_AUTH', true );
define( 'WPMS_SMTP_USER', 'pellanazarene@gmail.com' );
define( 'WPMS_SMTP_PASS', '…' );   // a Google "app password" (below), not the account password
```

- **App password:** in the Google account, turn on 2-Step Verification, then create an app password (Security → 2-Step Verification → App passwords). Use that 16-character code as `WPMS_SMTP_PASS`.
- **SPF, DKIM, DMARC:** because the "from" address is a Gmail address, Google signs and authenticates the mail itself, so **no DNS changes are needed**. (Only if you later send *from* an address at `pellanazarene.org` do those records need to be added at the DNS host.)
- **Replies:** each email's Reply-To is the visitor's address, so "Reply" answers the visitor.
- **Delivery test (do this once at launch):** on the live site, send a message through each form and confirm it arrives (check Spam the first time), then use **WP Mail SMTP → Tools → Email Test**.

## How spam is stopped

1. **Hidden trap field** that only bots fill in (Fluent Forms).
2. **Too fast:** a form sent less than 3 seconds after the page loaded is refused (a person takes longer than that to write something).
3. **Too many:** one internet connection may send 5 messages an hour per form. The visitor is told to try later or call (641) 628-1818. (Fluent Forms also blocks 5 in 30 seconds.)
4. Fields are checked on the server (a real email address, a message is required).

If spam ever gets through, Cloudflare Turnstile can be added on top (not needed now).

## Privacy

- **Prayer requests are never stored.** Fluent Forms emails the pastors, and straight after, our code erases the entry (the plugin's own "delete entry" setting only exists in its paid version). Tests check the entry, its field values and its log lines are all gone. Because the request lives only in the pastors' inboxes, it is covered by the inbox's own security: use a strong password and two-step sign-in on those accounts.
- **Contact messages** are kept 12 months (so nothing is lost if an email goes astray), then deleted by a daily job (`pn_forms_cleanup`). Change the period with the `pn_forms_keep_months` filter.
- Only administrators can see saved messages (the Fluent Forms entries screen).

## If the forms stop working

| Symptom | Likely cause |
|---|---|
| A visitor says "it didn't send" | They sent it faster than 3 seconds, or from a connection that already sent 5 this hour; the form tells them. |
| Messages arrive but nothing is emailed | The Gmail app password was changed or revoked; reset it and update `WPMS_SMTP_PASS`. WP Mail SMTP → Email Log shows the error. |
| Page shows no form | The form was deleted or never created: run `scripts/create-forms.sh`. |
| Emails land in Spam | Mark one as "Not spam"; Gmail learns. |

## Testing

`deploy/tests/forms-check.sh` runs the real plugin: it creates the forms from the repo files, sends real submissions and checks the spam rules, who receives each email, that prayer requests are not kept, and the 12-month cleanup (each rule is proven by a deliberate break). The accessibility run also fills in and sends both forms in a real browser. Both run in CI.
