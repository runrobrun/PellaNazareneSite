# Google Analytics

The live site counts visits with **Google Analytics 4** (measurement ID `G-3E45KJPG3G`, a public identifier that is visible in the page source; it is not a secret). The code is `wp-content/mu-plugins/pn-analytics.php`.

## When the tag is on

| Situation | Tag? |
|---|---|
| The live site, an ordinary visitor | **Yes**, once, at the very end of the page |
| Staging, local copies, tests (`WP_ENVIRONMENT_TYPE` is `staging`, `local` or `development`) | No |
| Anyone signed in (pastors, office staff), the admin, previews, the feed, the login page | No |
| A visitor whose browser sends **Global Privacy Control** or **Do Not Track** | No: the page never contacts Google |

The live server must have `WP_ENVIRONMENT_TYPE` set to `production` (or not set at all, which means production). Staging is set to `staging` in its `wp-config.php`.

## How it stays fast

The tag is printed after the page content (in the footer), and a few lines of JavaScript add Google's script with `async`. Nothing waits for it, and it adds nothing the visitor can see move.

## The cookie decision

Decided with the church (October 2026): **no cookie banner**, plus an honest **Privacy page** (`/privacy/`, linked from every page's footer) that says what the site collects, that Google Analytics sets cookies, that staff are not counted, and how to opt out (Global Privacy Control or Do Not Track, or Google's opt-out add-on). Google Analytics 4 does not keep full IP addresses. This is a practical choice for a small US church, not legal advice; if the site starts to get many visitors from Europe, switch to a consent banner (analytics only after someone accepts).

## Checking it works (at launch)

1. Open the live site in a private window and look at the page source: the text `googletagmanager.com/gtag/js` should appear **once**.
2. In Google Analytics, open **Reports → Realtime** and visit a couple of pages; your visit should show within a minute.
3. Open the same site signed in as staff: the tag should **not** be there.
4. Open staging: the tag should **not** be there.

## Changing the ID

Edit the one line in `pn-analytics.php`, or set `PN_GA_MEASUREMENT_ID` in `wp-config.php` (the tests do this with a made-up ID so they can never record a visit in the real account).

## Later: security headers

When a Content-Security-Policy is added (the Nginx headers follow-up), allow `googletagmanager.com` and `google-analytics.com`.

## Testing

`deploy/tests/pages-check.sh` checks the tag is on once on the live site and absent on staging, local, development, for signed-in staff, in the admin, on the login page and in the feed. The accessibility run (`deploy/tests/a11y-check.sh`) opens the site in a real browser and checks an ordinary visitor gets the tag and a visitor with Global Privacy Control or Do Not Track never contacts Google (every request to Google is blocked in the test, and the test site uses a made-up ID).
