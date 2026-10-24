# Design system

How the brand guide (`docs/PellaNazareneBrandingGuide.pdf`) is built into the theme. Everything lives in `wp-content/themes/pella-nazarene/theme.json` (plus the fonts in `assets/fonts/`); don't hard-code colors or fonts anywhere else.

## Colors

Three brand colors (navy, white, black) and tints of the navy. No other hues. Editors get exactly these nine swatches; the custom color picker, custom gradients, and WordPress's built-in palettes are turned off.

| Name in the editor | Slug | Hex | Use |
|---|---|---|---|
| **Navy** | `accent-3` | `#2D3D54` | Headings, links, buttons, focus rings. The brand color |
| Navy dark | `primary-dark` | `#263447` | Button and link hover (navy mixed with 15% black) |
| White | `base` | `#FFFFFF` | Page background, text on navy |
| Black | `contrast` | `#000000` | Body text |
| Muted text | `accent-4` | `#576476` | Secondary text (navy at 80%) |
| Navy tint 20% | `accent-1` | `#D5D8DD` | Borders, strong tinted sections |
| Navy tint 8% | `accent-2` | `#EEEFF1` | Callout and section backgrounds |
| Navy tint 4% | `accent-5` | `#F7F7F8` | Faint backgrounds |
| Border | `accent-6` | 20% of the text color | Hairlines |

The slugs come from the parent theme (Twenty Twenty-Five) so its patterns and templates stay readable; only the values are ours. So "Navy" is `accent-3`, not a slug called `primary`.

**Contrast (WCAG 2.2 AA needs 4.5:1 for text, 3:1 for interface elements).** Every pairing the theme uses is checked from the real `theme.json` by `deploy/tests/brand-contrast.php`: black on white 21:1, navy on white 11:1, white on navy 11:1, muted text on white 6.0:1, muted on the 8% tint 5.2:1. Change a color to something unreadable and CI fails.

## Typography

Plus Jakarta Sans only, self-hosted (two variable WOFF2 files, 56 KB total; see `assets/fonts/README.md`), `font-display: swap`, the regular file preloaded.

| Role | Style | Where |
|---|---|---|
| Headings | Extra Bold (800), navy, tight line height | `h1` to `h6` |
| **Subheading** | **Light Italic (300)**, navy, large | Block style "Subheading" on a paragraph or heading (sidebar, Styles) |
| Body | Regular (400), black, 18 px, line height 1.6 | everything else |

Heading sizes are fluid (they shrink on phones): `h1` 32 to 52 px, `h2` 26 to 36 px, `h3` 20 to 24 px. Staff pick from five preset sizes; arbitrary sizes are off. Headings get extra space above them (except as the first block in a group).

## Components

- **Links:** navy, underlined; darker navy on hover; a 3 px navy ring on keyboard focus.
- **Buttons:** navy with white Extra Bold text, 8 px corners, at least 44 px tall (a comfortable touch target); dark navy on hover; focus ring. The **Outline** style is a navy border and navy text.
- **Quote:** large text with a navy rule on the left.
- **Callout / section background:** use the 8% tint (`accent-2`) with black or navy text.

## Header, footer, and templates

- **Header** (`patterns/header.php`, used by `parts/header.html`): the primary logo (220 px, shrinking to 150 px on phones; explicit width and height so nothing jumps while it loads), the main menu (**New Here, About Us, Sermons, Events, Kids, Giving, Contact**) and a **Plan a Visit** button that links to New Here. On phones the menu collapses to a hamburger with a full-screen panel; the current page is underlined.
- **Footer** (`patterns/footer.php`, used by `parts/footer.html`): navy, with the white logo, the mission line, **Facebook, Instagram, and YouTube** icons, a "Visit us" column (the `service-info` part: service times, address, tap-to-call phone, email, office hours) and an "Explore" menu (Plan a Visit, Resources, Blog, Contact), then the copyright line with the current year.
- **404 page**: a friendly message with Plan a visit, Watch a sermon, and Contact us buttons and a search box.
- Menu links are built from the site's own address, so staging links to staging and production to production. **Until the pages exist (MIG-3), menu items other than Sermons lead to the 404 page.**
- **Edit these in the repo, not in the Site Editor.** Saving a change in the Site Editor stores a copy in the database that then overrides the theme's file and stops future updates from showing up.
- Skip-to-content link, `header`/`nav`/`main`/`footer` landmarks (on every page type, including the 404), and two labelled menus (Primary, Footer) come with it.
- **Keyboard focus rings:** a 3 px ring on every link and button, **navy on the white header and mobile menu, white on the navy footer**. A white ring on the white header would be invisible, which is exactly the mistake a code review caught; `a11y-check.sh` now tabs through each page and fails if any stop lacks a visible ring at 3:1 contrast or better.

## Logo and icons

Web versions live in `wp-content/themes/pella-nazarene/assets/images/` as **SVG** (crisp at any size, 1 to 14 KB each). They are built from the designer's PNG masters in `docs/branding/source/` by `scripts/build-brand-assets.sh`, which refuses to produce a shape that strays from its master: 99.9% of every outline must be within 1.5 px and no point more than 3 px away (measured: within 1.0 px for 99.9%, worst point 2 px, at 1000 to 3000 px canvases). Use them as `get_theme_file_uri( 'assets/images/<file>' )`, always with `width`/`height` attributes and meaningful `alt` text.

| File | Use |
|---|---|
| `logo-primary.svg` | **The logo**: symbol + "pella Nazarene Church", navy. Header, footer on white, documents. Use this whenever there is room |
| `logo-primary-white.svg` | Same in white, for navy backgrounds and photos |
| `logo-primary-black.svg` | Same in pure black: one-color uses (printing, forms, documents, anywhere navy isn't possible). Prefer navy on the site |
| `logo-symbol.svg` / `logo-symbol-white.svg` | Symbol only. Tight spaces, social avatars, the favicon source |
| `logo-lighthouse.svg` / `logo-lighthouse-black.svg` | **Lighthouse Student Ministry** (youth), stacked, navy or black. The youth ministry page and youth materials only, never in place of the church logo |
| `icon-love-people.svg` / `-white.svg` | Mission icon: the split heart ("love people where they are") |
| `icon-grow-together.svg` / `-white.svg` | Mission icon: the sprout ("grow together in Jesus") |
| `icon.svg`, `favicon.ico`, `apple-touch-icon.png` | Browser tab, bookmarks, phone home screen. Printed by `functions.php` unless an administrator sets a Site Icon in WordPress |

The mission icons also come as a white glyph on a navy rounded square (`docs/branding/source/tile-*.png`); on the site build that tile in CSS: navy `#2D3D54` square, white icon SVG on top.

**Rules from the brand guide:** keep the aspect ratio; never recolor beyond navy, white, or black (the supplied files are exactly `#2D3D54`, `#FFFFFF`, and `#000000`); never smaller than **100 px wide** on screen; leave clear space around it. The guide shows a safe area but the PDF text doesn't give a measurement; until someone confirms it from the guide's "Logo Safe Area" page, use half the logo's height on every side.

**Check them:** `deploy/tests/brand-assets-check.sh` verifies the masters are exact brand colors, every shipped SVG is plain artwork (no scripts, event handlers, links, or external references, since an SVG opened directly in a browser can run code), each still matches its master, and the favicon set is valid.

## What is deliberately switched off

- **Whole-site style variations.** The parent theme ships 23 (Evening, Noon, Midnight, other font pairings). Offering them would let an administrator repaint the site in one click, so the Site Editor's "browse styles" list is empty (`functions.php`).
- **Custom colors, custom gradients, arbitrary font sizes, WordPress's default palettes.** They'd allow off-brand choices, and the default palettes add CSS to every page.

## Not done yet

- **Page templates beyond the 404** (home page layout, About, New Here, Kids...) are built with the content (MIG-3). Blog posts and the blog index have their own templates (FEAT-7); other single pages use the page template. Its footer links (Blog, Shop, Patterns...) are placeholders.
- **Parent theme's block styles** ("Display", "Subtitle", "Annotation", "Pill shaped", "Style 1 to 5") are still in the editor. They pick up the brand colors, but "Subtitle" overlaps with our "Subheading". Review in FE-2.
- **Font fallback metrics.** When the brand font swaps in over the fallback there can be a small layout shift. Measure in PERF-1 and, if needed, add size-adjusted fallback faces (PERF-3).

## Checking it

```bash
deploy/tests/a11y-check.sh                        # axe-core in a real browser (WCAG 2.2 AA + best practice) AND a keyboard walk that requires a visible 3:1 focus ring on every stop: desktop, phone, mobile menu open, 404, search
deploy/tests/theme-check.sh                       # real WordPress + the parent theme + ours: 104 checks (colors, fonts, preload, no dangling variables, locked-down options, component styles)
docker run --rm -v "$PWD":/repo:ro php:8.3-cli php /repo/deploy/tests/brand-contrast.php /repo/wp-content/themes/pella-nazarene/theme.json
deploy/tests/brand-assets-check.sh                # logos and icons: exact colors, safe SVGs, match the masters, favicon set
scripts/build-brand-assets.sh                     # rebuild the SVGs and favicons if the masters ever change
```

`deploy/tests/fixtures/brand-check-page.html` is a page that uses every brand element; the theme check renders it, and it's handy for looking at the theme by eye.
