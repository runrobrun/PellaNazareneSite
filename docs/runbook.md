# Runbook

> To be written once the server is live. Outline below.

## Where everything lives
- Server, domain registrar, Cloudflare, GitHub repo, Church Center, YouTube channel, Anthropic API key
- Who holds each login (point to the password manager, never list passwords here)

## How it's set up
- See `docs/setup.md`

## Staff accounts and two-factor sign-in

Everyone who can edit content (pastors, office staff, administrators) must use an **authenticator app** (Google Authenticator, Microsoft Authenticator, 1Password, and so on) when they sign in. Subscribers are not affected. There are no email codes.

**First login:** after entering the password the person lands on their Profile page. Scroll to **Two-Factor Options** and do **both** steps: (1) turn on the authenticator app, scan the QR code, and enter the 6-digit code to confirm; (2) **generate backup codes** and store them somewhere safe (a password manager). Nothing else in the admin opens until both are done. When the second step finishes, WordPress signs the person out once (this also ends any password-only session, such as one an attacker might have opened); they sign in again with their password and a code from the app.

**Lost or replaced phone:** sign in with a backup code (each works once). Then set up the new phone and generate fresh backup codes.

**No phone and no backup codes:** an administrator opens Users, Edit, Two-Factor Options, unchecks every method and saves. The person signs in with their password and sets 2FA up again. Or from the server:
```bash
wp --path=/var/www/pellanazarene/public two-factor disable <username> --yes
```

**An administrator is locked out:** SSH to the server (key login only) and run the command above for them. If the Two Factor plugin itself is broken, add `define( 'PN_REQUIRE_2FA', false );` to `wp-config.php` to stop *requiring* 2FA until it is fixed (people who already have it still get asked for a code); remove the line afterwards. If the plugin is inactive, or is a version the site does not recognize (it is pinned to 0.17.0; update it on purpose and test on staging first), the site shows a red warning to administrators and nobody is locked out.

**Someone leaves:** delete or demote their account (reassign their content), then run `wp config shuffle-salts` to sign everyone out everywhere. Never share a login; create one account per person.

**"429 Too Many Requests" on the login page:** the site allows about 10 login-page requests a minute per address. Wait a minute and try again.

## Routine tasks
- Add or remove a staff login
- Check that backups ran
- Renew the domain
- Edit page text in **Pages** in the admin (the Home page is titled "A church in Pella, Iowa"; also New Here, About Us, Kids, Giving, Contact, Resources, Events, Small Groups); nothing else is needed. Office hours, address and Sunday times on the Contact and New Here pages come from one shared block, **Appearance → Editor → Patterns → Template parts → Service info**
- Events: the regular weekly events are on the **Events** page (edit them there if a time changes); everything else, including one-time events and sign-ups, goes in Planning Center, and the page links to that calendar. Small groups are managed in Church Center Groups.
- Blog: a pastor writes under **Posts → Add New** and uses **Submit for Review**; the office admin (Editor) reads it and clicks **Publish**. The blog is at `/blog/` and its feed at `/feed/`. Pastors cannot publish on their own.
- Before launch, and again right after: `scripts/check-filler.sh` looks for leftovers from the old site (sample content, wrong hours); how to run it and fix what it finds: `docs/filler-check.md`
- Review new sermon drafts each week (Sermons in the admin) and publish them; how the import works and how to import the history: `docs/sermons.md`

## If something breaks
- Site is down
- Forms stopped sending email: `docs/forms.md` (the usual cause is the Gmail app password)
- Sermons stopped importing
- Restore from last night's backup

## Who to call
- Rob Hammann, then: _TBD_
