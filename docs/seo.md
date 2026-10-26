# Search and sharing

How the site shows up in Google and in link previews (Facebook, text messages, email). The code is `wp-content/mu-plugins/pn-seo.php`.

## Page titles

Every page title ends with the site name: **Kids | Pella Nazarene**. The home page is **Pella Church of the Nazarene | Pella, Iowa**. The site name comes from Settings → General → Site Title, so set it to `Pella Nazarene` on the live site.

## Descriptions (the gray text under a Google result)

Each page has its own short description (about 150 characters). Staff edit it in the page editor: **Page settings → Excerpt**. If a page has no excerpt, the first lines of its text are used. `scripts/create-pages.sh` fills in a description for any page that has none and never touches one staff have written. The home page, the sermons list and the blog list have fixed descriptions in `pn-seo.php`.

Sermons use their excerpt (the importer sets one), blog posts use their excerpt or their first lines.

## Link previews (Facebook, texts, email)

When someone shares a page, the preview shows the page title, its description and a picture:

| Page | Picture |
|---|---|
| Most pages | The church photo `og-default.jpg` (1200×630, made by `scripts/build-photos.sh`) |
| A sermon | The sermon video's own YouTube picture |
| A page or post with a featured image | That image |

To see a preview before sharing for real, paste the address into Facebook's Sharing Debugger (`developers.facebook.com/tools/debug`). After changing a page, press **Scrape Again** there, because Facebook remembers the old preview.

## Google's data about the church

The home page carries a hidden block of data (schema.org "Church") with the church's name, address, phone, email, office hours, weekly services (worship 10:30 AM, small groups 9:30 AM, Central time) and social pages. Google can use it for the box beside search results and for Maps. The facts are in one place, `pn_church_info()` in `pn-seo.php`.

**The office hours there must match the Service info block shown on the site.** The page tests compare the two and fail if they differ, so if the hours change, change both (`parts/service-info.html` and `pn_church_info()`).

## Sitemap and robots.txt

WordPress builds the sitemap itself at `/wp-sitemap.xml`: pages, blog posts, sermons, and sermon series, speakers and scriptures. People (authors) are deliberately left out. Drafts are never listed. `/robots.txt` points search engines at the sitemap and keeps them out of the admin.

**Staging is hidden from search engines** on purpose (`noindex` header and tag). The live site is not.

## At launch

1. In **Google Search Console**, add the live site, verify it (a DNS record at the DNS host is easiest), and submit `https://pellanazarene.org/wp-sitemap.xml`.
2. Check the live home page source: one `application/ld+json` block, and a `description` and `og:image` tag.
3. Share the home page in a text message to yourself and look at the preview.
4. Set Google Business Profile (the Maps listing) to the same address, phone and hours.
5. Confirm Settings → Reading → "Discourage search engines" is **off** on the live site.

## Testing

`deploy/tests/pages-check.sh` checks every page has a title, a description (50 to 160 characters) and sharing tags, that no two pages share a title or description, that the sharing picture is a real 1200×630 JPEG, that sermons and posts share correctly, that Google's data is valid and its hours match the Contact page, and that the sitemap and robots.txt are right. Each rule is proven with a deliberate break.
