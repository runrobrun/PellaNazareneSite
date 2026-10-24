# Photos on the site

Twelve photos are used on the pages. The web-sized files live in the theme (`wp-content/themes/pella-nazarene/assets/images/photos/`, about 750 KB in total); the originals (hundreds of MB) are **not** in git.

`manifest.json` in that folder is the list: for each photo, its original, its largest size, its alt text, and where it came from.

| Photo | Used on | Source |
|---|---|---|
| `home-hero` | Home, beside the opening text (loads first) | Church photo, Sunday service 2026-09-13 |
| `new-here-welcome`, `new-here-online` | New Here: "In person" and "Online" | Church photos, 2026-09-13 and 2026-10-18 |
| `about-community`, `about-worship` | About Us | Church photos (trivia night; Sunday service) |
| `staff-*` (four) | About Us, beside each bio | Staff headshots from the old website (2026-05) |
| `kids-welcome`, `kids-celebrate`, `kids-play` | Kids | Old website; **likely template stock photos, licence not confirmed** |

## Rights and permission

- **Church photos:** taken at services and events. The church posts a notice at the entrance that photos are taken (blanket permission). Photos that clearly show children need a parent's permission; none of the church photos used here show children.
- **Staff headshots:** from the old website, reused with the church's approval.
- **Kids photos:** from the old website's theme, not taken by the church. The church chose to reuse them for now. Their licence is not confirmed, so **replace them with our own kids photos (with parents' permission) when we have some.** To swap one, change the picture in the Kids page in the editor, or replace the original and rebuild (below).
- **Song lyrics:** no photo shows readable lyrics on the screens (the pictures were chosen for that). If a lyric slide ever shows in a photo, blur the screen first.

## How the photos get onto the pages

`scripts/create-pages.sh` loads each photo into the WordPress **media library** (once; photos already there are left alone) and the page patterns use them. WordPress then makes the smaller sizes, `srcset`, `sizes`, and `width`/`height` itself. Staff can change a photo or its alt text in the page editor like any other image.

Speed rules this follows: WebP; metadata (camera, GPS location) removed; the home photo is preloaded and loads first; every other photo is lazy-loaded; `sizes` matches how wide the photo really is on screen so phones don't download desktop sizes.

## Adding or replacing a photo

1. Put the original in `docs/Photos/` (your photos) or `docs/photos-source/` (anything else). Both folders are ignored by git.
2. Add an entry to `manifest.json`: `slug`, `source` (path to the original), `max` (longest side in pixels; 1400 is plenty for content, 1800 for the home photo, 640 for a headshot), `alt` (one sentence describing what a person sees), and `rights` (where it came from).
3. Run `scripts/build-photos.sh` (needs Docker) to make `<slug>.webp`.
4. Use it in a page pattern with `pn_photo_block( 'slug', 'full', '3/2' )` (variants: `full`, `hero`, `col`, `avatar`; the last argument is the crop), then run `scripts/create-pages.sh` for a new site, or insert it from the media library on a live one.
5. `deploy/tests/photos-check.sh` checks the files (real WebP, under 250 KB, no hidden metadata, alt text, rights).

## Alt text

Describe what the photo shows in one sentence, as if telling someone on the phone. No "image of". Staff photos use the person's name and role.
