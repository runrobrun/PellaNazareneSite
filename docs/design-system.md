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

## What is deliberately switched off

- **Whole-site style variations.** The parent theme ships 23 (Evening, Noon, Midnight, other font pairings). Offering them would let an administrator repaint the site in one click, so the Site Editor's "browse styles" list is empty (`functions.php`).
- **Custom colors, custom gradients, arbitrary font sizes, WordPress's default palettes.** They'd allow off-brand choices, and the default palettes add CSS to every page.

## Not done yet

- **Logo.** We don't have the original logo files; see MIG-5. The header and footer (FE-2) will use the primary logo (navy, at least 100 px wide), with the symbol-only version as the favicon.
- **Header, footer, navigation, templates** are still the parent theme's (FE-2). Its footer links (Blog, Shop, Patterns...) are placeholders.
- **Parent theme's block styles** ("Display", "Subtitle", "Annotation", "Pill shaped", "Style 1 to 5") are still in the editor. They pick up the brand colors, but "Subtitle" overlaps with our "Subheading". Review in FE-2.
- **Font fallback metrics.** When the brand font swaps in over the fallback there can be a small layout shift. Measure in PERF-1 and, if needed, add size-adjusted fallback faces (PERF-3).

## Checking it

```bash
deploy/tests/theme-check.sh                       # real WordPress + the parent theme + ours: 44 checks (colors, fonts, preload, no dangling variables, locked-down options, component styles)
docker run --rm -v "$PWD":/repo:ro php:8.3-cli php /repo/deploy/tests/brand-contrast.php /repo/wp-content/themes/pella-nazarene/theme.json
```

`deploy/tests/fixtures/brand-check-page.html` is a page that uses every brand element; the theme check renders it, and it's handy for looking at the theme by eye.
