# Moving DNS to Cloudflare (INF-3, step one)

This moves the **DNS** for `pellanazarene.org` from DNS Made Easy to Cloudflare, and nothing else. Every record is copied across exactly as it is, with Cloudflare's proxy **off** (grey cloud, "DNS only"), so the old website, church email, staging and everything else keep working as they do today. Putting the new site behind Cloudflare (orange cloud, origin certificate, Full (strict)) happens later, at cutover (`docs/cutover.md` step 6a).

Do it at least a week before cutover, so it has settled and any missed record has shown up. Nobody should notice anything. The way back, at any point, is to put the old nameservers back at the registrar: the DNS Made Easy zone stays untouched until well after cutover.

You need:
- the **DNS Made Easy** login;
- the login for the **registrar**, the company the domain is registered with. This may not be DNS Made Easy; the WHOIS lookup in step 1 shows it;
- an email address **the church controls** for the Cloudflare account, plus a second person to add as an admin.

Allow about an hour for steps 1 to 6. Then there is a wait of a few hours (sometimes up to a day) before step 7.

## 1. Find the registrar and current nameservers

On your Mac's Terminal (not the server):

```bash
whois pellanazarene.org | grep -iE 'registrar:|name server|dnssec|expir'
dig +short NS pellanazarene.org
```

- [ ] Write down the **registrar**, the **expiry date** and the **current nameservers**. DNS Made Easy is now part of DigiCert, so they show as `ns10`–`ns15.digicertdns.com/.net`. Checked 2026-11-07: registrar Domain.com (Network Solutions), expires 2027-11-02, DNSSEC unsigned. Keep them with the church's account notes, not in this repo. They are the way back.
- [ ] If the expiry is within the next three months, renew first. A domain that lapses mid-move takes the website and email down.
- [ ] Note whether **DNSSEC** shows as signed (see step 5).

## 2. Save a full copy of today's records

- [ ] DNS Made Easy → **Managed DNS** → `pellanazarene.org`. Export the zone if the page offers it (a BIND/zone file). Otherwise take screenshots of every record type: A, AAAA, CNAME, MX, TXT, SRV, CAA, and any others listed. Keep this copy outside the repo (no IPs in git).
- [ ] Look over the list and write down what each record is for. Usually:
  - `pellanazarene.org` and `www`: the old website.
  - `staging`: our Linode.
  - **MX** and the **TXT** records starting `v=spf1`, plus `_dmarc`, DKIM (`...._domainkey`) and `autodiscover`: church email. **These matter most.** If one is missed, email can quietly stop arriving or start landing in spam.
  - Other **TXT** records such as `google-site-verification=` and `MS=`: proofs of ownership for Google, Microsoft and others. They need copying too.
  - Anything for Planning Center / Church Center, YouTube, or the old host's control panel.

**What the zone held on 2026-11-07** (exported from DNS Made Easy; values kept out of the repo). There are only four records:

| Name | Type | Points to | What it's for | In Cloudflare |
|---|---|---|---|---|
| `pellanazarene.org` | A | the old host | old website | A, same address, **DNS only** |
| `www` | CNAME | (blank) | old website; a blank value in DNS Made Easy means "the domain itself" | CNAME to `pellanazarene.org`, **DNS only** |
| `staging` | A | the Linode | staging | A, same address, **DNS only** |
| `pellanazarene.org` | TXT | `google-site-verification=…` | proves to Google (Search Console) that we own the domain | TXT, value copied exactly, quotes included |

There are **no MX, SPF, DKIM or DMARC records**: nobody receives email at `@pellanazarene.org`. Church email is Gmail, and the website sends through the church Gmail account (`docs/forms.md`), so the email checks in step 7 don't apply. The DNS Made Easy export is JSON, which Cloudflare can't import, so add these four by hand in step 3 and delete anything else the scan adds.

## 3. Create the Cloudflare account and add the site

- [ ] Sign up at dash.cloudflare.com with the church email. Turn on **two-factor** right away (My Profile → Authentication).
- [ ] **Add a domain** → `pellanazarene.org` → **Free** plan. Cloudflare scans the current DNS and imports what it finds.
- [ ] If you have a zone file from step 2, also use **DNS → Records → Import and Export → Import**. It fills in records the scan can't guess, such as DKIM keys and oddly named TXT records. Then delete any duplicates.
- [ ] Add the second admin: **Manage Account → Members → Invite**, role **Administrator**.

## 4. Check every record against your copy (the important step)

In **DNS → Records**, go down your list from step 2 line by line:

- [ ] Every record is present with the **same type, name and value**. MX records also keep their **priority**.
- [ ] Every A, AAAA and CNAME record shows **Proxy status: DNS only** (grey cloud). The scan turns the website records **orange** by default, so click each one and switch it off. MX and TXT records have no cloud.
- [ ] `staging` is **DNS only**. Its Let's Encrypt certificate renews by the certificate authority checking the server directly; with the proxy on, renewal and the basic-auth login would behave differently. Staging stays grey for good.
- [ ] TTL can stay **Auto**. Cloudflare's Auto is 5 minutes, which is also what the cutover needs (`docs/cutover.md` step 3).
- [ ] Remove anything Cloudflare added that was **not** in your copy.

Leave every other Cloudflare setting alone for now (SSL/TLS, caching, "Always Use HTTPS", Bot Fight Mode). With every record grey they have no effect, and they get set at cutover.

## 5. DNSSEC (only if step 1 showed it signed)

DNSSEC signed by DNS Made Easy has to come off **before** the nameserver change, or the domain can stop resolving for some visitors.

- [ ] At the registrar, remove the **DS record** (it may be called "DNSSEC: off"). Then wait **two days** before step 6.
- [ ] After step 7, turn DNSSEC on in Cloudflare (**DNS → Settings → DNSSEC**) and add the DS record it gives you at the registrar.

If DNSSEC was not signed, skip this step. You can still turn it on through Cloudflare later.

## 6. Change the nameservers at the registrar

Pick a weekday, not Saturday evening or Sunday.

- [ ] Cloudflare shows two nameservers for the site (like `anna.ns.cloudflare.com`, `bob.ns.cloudflare.com`). Copy them exactly.
- [ ] At the registrar, replace the DNS Made Easy nameservers with **only** those two. Don't mix old and new.
- [ ] **Leave the zone at DNS Made Easy exactly as it is.** Don't delete records or cancel the account. It is the way back, and it keeps answering anyone whose computer still remembers the old nameservers.
- [ ] Back in Cloudflare, click **Check nameservers now**.

## 7. Check it worked (a few hours later)

Cloudflare emails when the site is **Active**. Then, on your Mac:

```bash
dig +short NS pellanazarene.org                       # the two Cloudflare names
for t in A AAAA MX TXT; do echo "== $t"; dig +short pellanazarene.org $t @1.1.1.1; done
dig +short www.pellanazarene.org @1.1.1.1
dig +short staging.pellanazarene.org @1.1.1.1         # the Linode address
```

- [ ] The answers match your copy from step 2. Website records show the **old host's address**, not a Cloudflare one (grey cloud).
- [ ] The old site loads at `https://pellanazarene.org` and `https://www.pellanazarene.org`.
- [ ] `https://staging.pellanazarene.org` asks for the basic-auth login and then loads.
- [ ] **Email:** only if MX records were added since 2026-11-07 (there were none then). In that case, send a message from an outside address **to** a church address and one back out. Both arrive and neither lands in spam.
- [ ] On the server, the staging certificate can still renew:
  ```bash
  sudo certbot renew --dry-run
  ```
- [ ] Give it a full week before relying on it for cutover. Watch for anything that stops working (giving links, Church Center emails, Google or Microsoft sign-ins).

## If something breaks

- **A single record is missing or wrong:** add or fix it in Cloudflare **DNS → Records**. The change is live within minutes.
- **Lots is wrong, or email stops:** at the registrar, put the old DNS Made Easy nameservers from step 1 back. The DNS Made Easy zone was never changed, so everything returns to how it was as computers pick up the change, usually within a few hours.

## Afterwards

- [ ] `docs/cutover.md`: the DNS host row says Cloudflare and where its login is kept. Decision 1 is (a). The "DNS already moved and settled" box is ticked.
- [ ] `docs/runbook.md` access list: the Cloudflare account owner and second admin.
- [ ] Keep DNS Made Easy paid and untouched until 60 days after cutover, the same as the old site. Then cancel it.
