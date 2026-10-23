# Brand asset masters

The files exactly as supplied by the church's designer, renamed. **Do not edit or recolor them.** The web versions in `wp-content/themes/pella-nazarene/assets/images/` are built from these by `scripts/build-brand-assets.sh`.

| File | What | Original name | Size |
|---|---|---|---|
| `logo-primary-navy.png` | Primary logo: symbol + "pella Nazarene Church" | `pella-01.png` | 7023 x 2550 |
| `logo-primary-white.png` | Same, white (for navy or photo backgrounds) | `pella-03 (1).png` | 7023 x 2550 |
| `logo-primary-black.png` | Primary logo in pure black (one-color uses) | `pella-05-black.png` | 7023 x 2550 |
| `logo-symbol-navy.png` | Symbol only (cross in a circle) | `pella-02.png` | 2570 x 2550 |
| `logo-symbol-white.png` | Symbol only, white | `pella-04.png` | 2570 x 2550 |
| `logo-lighthouse-navy.png` | Lighthouse Student Ministry (youth), stacked | `lighthouse_logo_1.png` | 1009 x 596 |
| `logo-lighthouse-black.png` | Lighthouse Student Ministry in pure black | `lighthouse-logo-black.png` | 1009 x 597 |
| `icon-love-people-navy.png` / `-white.png` | Mission icon "love people" (split heart) | `love-people-iso@3x.png` | about 918 x 844 |
| `icon-grow-together-navy.png` / `-white.png` | Mission icon "grow together" (sprout) | `grow-together-iso@3x.png` | about 914 x 913 |
| `tile-love-people.png` | Heart as a white glyph on a navy rounded square | `love-others.png` | 418 x 418 |
| `tile-grow-together.png` | Sprout as a white glyph on a navy rounded square | `grow-together.png` | 418 x 418 |

The tiles are kept as a reference for the intended look. On the site a tile is built in CSS: the white icon SVG on a navy (`#2D3D54`) rounded square.

All are transparent PNGs in exactly the brand navy `#2D3D54`, pure white `#FFFFFF`, or pure black `#000000`.

Note: the black Lighthouse logo has its two text lines about 2 and 4 px closer together than the navy one (about 0.5% of its height); the symbol itself is identical. The build traces each from its own master. If the designer re-exports them with matching spacing, nothing needs to change here. The main logos carry about 2% of built-in blank margin; the web versions are cropped tight and the brand's clear space is applied in CSS.

Ask the designer for **official SVG files** when convenient. They would replace the traced SVGs in the theme directly (same file names).
