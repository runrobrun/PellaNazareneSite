# Fonts

Plus Jakarta Sans, the church's brand typeface (see `docs/PellaNazareneBrandingGuide.pdf`).

| File | What | Size |
|---|---|---|
| `plus-jakarta-sans-normal.woff2` | Variable font, weights 200-800. Regular (400) for body, Extra Bold (800) for headings | 27 KB |
| `plus-jakarta-sans-italic.woff2` | Variable italic, weights 200-800. Light Italic (300) for subheadings | 29 KB |

- **Source:** Google Fonts' build of the open-source font (https://github.com/tokotype/PlusJakartaSans), v12, **latin** subset only, downloaded 2026-10-23 from `fonts.gstatic.com`.
- **License:** SIL Open Font License 1.1 (`OFL.txt`), which allows self-hosting and embedding.
- **Why self-hosted:** no third-party request on page load, no tracking, and faster (one connection instead of three).
- **Checked:** both files are valid WOFF2 with a `wght` axis 200-800 and every character used in normal English text. If the church ever needs other alphabets, add the `latin-ext` file from the same source.
- `theme.json` declares them with `font-display: swap`, and `functions.php` preloads the regular file (it also covers the bold headings).

## Integrity

SHA-256 of the files as downloaded and checked on 2026-10-23 (`shasum -a 256 <file>`):

```
cd8db90cd950e26bc8761f65d323588bd5cd112d326d6d322bc7c8ea86771215  plus-jakarta-sans-normal.woff2
f7f3c763fa6749357a187418d736aab5b2acdd1d0a83a408631382bcfd80d141  plus-jakarta-sans-italic.woff2
995c7199cab65954f545996326755daee7b63cc6b42b06c13da1f9502ab08a99  OFL.txt
```
