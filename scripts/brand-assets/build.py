#!/usr/bin/env python3
"""Build the web brand assets (SVG logos and icons, favicon set) from the designer's PNG masters.

Run it through scripts/build-brand-assets.sh (Docker), not directly. Input: /src (docs/branding/source).
Output: /out (the theme's assets/images). Optional contact sheet for a visual check: /prev/contact.png.

Every logo and icon is flat, single-color artwork, so tracing it gives an SVG that is crisp at any size
and far lighter than the PNG. The build FAILS if a traced shape strays from its master by more than
0.07% of its width at its worst point, so a bad trace can never ship.
"""
import gzip
import io
import re
import subprocess
import sys
import tempfile
from pathlib import Path

import numpy as np
import cairosvg
from PIL import Image
from scipy import ndimage as ndi

SRC, OUT, PREV = Path('/src'), Path('/out'), Path('/prev')
NAVY, WHITE = '#2D3D54', '#FFFFFF'
MAX_DEVIATION = 0.0007          # worst-case edge movement, as a share of the image width...
MIN_LIMIT_PX = 2.0              # ...but never below 2 px: the measurement moves in whole pixels (1, 1.41, 2...)
FAIL = []


def load_mask(path, width, crop=True):
    """Alpha mask (True = shape) of a master, tight-cropped and resized to `width` px."""
    im = Image.open(path).convert('RGBA')
    if crop:
        im = im.crop(im.getchannel('A').point(lambda v: 255 if v > 8 else 0).getbbox())
    h = round(im.height * width / im.width)
    return im.resize((width, h), Image.LANCZOS), width, h


def trace(mask):
    """Trace a boolean mask with potrace; returns (path data, transform, width, height)."""
    h, w = mask.shape
    with tempfile.TemporaryDirectory() as t:
        pbm, svg = Path(t, 'm.pbm'), Path(t, 'm.svg')
        Image.fromarray((~mask * 255).astype('uint8')).convert('1').save(pbm)
        subprocess.run(['potrace', '-s', '--tight', '-u', '10', '-t', '2', '-a', '1.0', '-O', '0.2', '-o', str(svg), str(pbm)], check=True)
        s = svg.read_text()
    d = ' '.join(re.findall(r'<path d="([^"]+)"', s))
    tf = re.search(r'<g transform="([^"]+)"', s).group(1)
    vb = [float(x) for x in re.search(r'viewBox="([^"]+)"', s).group(1).split()]
    return d, tf, vb[2], vb[3]


def svg_doc(layers, vb_w, vb_h, extra=''):
    body = ''.join(f'<g transform="{tf}" fill="{fill}" stroke="none"><path d="{d}"/></g>' for d, tf, fill in layers)
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {vb_w:g} {vb_h:g}">{extra}{body}</svg>'


def edge_deviation(a, b):
    """Largest distance (px) between the outlines of two masks."""
    def edge(m):
        return m ^ ndi.binary_erosion(m)
    ea, eb = edge(a), edge(b)
    da, db = ndi.distance_transform_edt(~ea), ndi.distance_transform_edt(~eb)
    return float(np.concatenate([da[eb], db[ea]]).max())


def render_alpha(svg, w, h):
    png = cairosvg.svg2png(bytestring=svg.encode(), output_width=w, output_height=h)
    return np.array(Image.open(io.BytesIO(png)).convert('RGBA').getchannel('A')) > 127


def check(name, svg, mask, w, h):
    dev = edge_deviation(mask, render_alpha(svg, w, h))
    limit = max(MIN_LIMIT_PX, MAX_DEVIATION * w)
    ok = dev <= limit
    if not ok:
        FAIL.append(name)
    print(f"  {'ok  ' if ok else 'FAIL'} {name:<34} worst edge move {dev:4.2f}px of {w}px wide (limit {limit:.1f}px)  svg {len(svg):>6} B, gzipped {len(gzip.compress(svg.encode())):>5} B")


def write(name, svg):
    (OUT / name).write_text(svg)


def mono_asset(stem, navy_master, white_master, width):
    """One-color artwork: trace the navy master; the white file reuses the same shape (checked against its master)."""
    im, w, h = load_mask(SRC / navy_master, width)
    mask = np.array(im.getchannel('A')) > 127
    d, tf, vw, vh = trace(mask)
    svg = svg_doc([(d, tf, NAVY)], vw, vh)
    check(f'{stem}.svg', svg, mask, w, h)
    write(f'{stem}.svg', svg)
    if white_master:
        # The white master can be a pixel off in size from the navy one (export rounding); same artwork.
        wim, _, _ = load_mask(SRC / white_master, width)
        wim = wim.resize((w, h), Image.LANCZOS)
        wmask = np.array(wim.getchannel('A')) > 127
        same = (mask & wmask).sum() / (mask | wmask).sum()
        if same < 0.995:
            FAIL.append(f'{stem}-white (white master differs from navy master: {same:.4f})')
        write(f'{stem}-white.svg', svg_doc([(d, tf, WHITE)], vw, vh))
        print(f"  ok   {stem + '-white.svg':<34} same shape as its white master ({same * 100:.2f}% overlap)")
    return svg, mask


def contact_sheet(files):
    sheet = Image.new('RGB', (1500, 1500), (255, 255, 255))
    x = y = 20
    row_h = 0
    for f, bg in files:
        png = cairosvg.svg2png(url=str(OUT / f), output_height=160)
        im = Image.open(io.BytesIO(png)).convert('RGBA')
        pad = 16
        tile = Image.new('RGBA', (im.width + 2 * pad, im.height + 2 * pad), bg)
        tile.alpha_composite(im, (pad, pad))
        if x + tile.width > 1480:
            x, y, row_h = 20, y + row_h + 20, 0
        sheet.paste(tile.convert('RGB'), (x, y))
        x += tile.width + 20
        row_h = max(row_h, tile.height)
    sheet.crop((0, 0, 1500, y + row_h + 20)).save(PREV / 'contact.png')


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    print('Logos')
    prim, prim_mask = mono_asset('logo-primary', 'logo-primary-navy.png', 'logo-primary-white.png', 3000)
    sym, sym_mask = mono_asset('logo-symbol', 'logo-symbol-navy.png', 'logo-symbol-white.png', 2000)
    mono_asset('logo-lighthouse', 'logo-lighthouse-navy.png', None, 1400)
    print('Mission icons')
    mono_asset('icon-love-people', 'icon-love-people-navy.png', 'icon-love-people-white.png', 1000)
    mono_asset('icon-grow-together', 'icon-grow-together-navy.png', 'icon-grow-together-white.png', 1000)

    print('Favicons (from the symbol)')
    # icon.svg: navy symbol, white in dark-mode browser chrome
    d, tf, vw, vh = trace(sym_mask)
    write('icon.svg', svg_doc([(d, tf, NAVY)], vw, vh, extra='<style>@media (prefers-color-scheme:dark){path{fill:#fff}}</style>'))
    sym_im, _, _ = load_mask(SRC / 'logo-symbol-navy.png', 1024)
    sq = max(sym_im.size)
    canvas = Image.new('RGBA', (sq, sq), (0, 0, 0, 0))
    canvas.alpha_composite(sym_im, ((sq - sym_im.width) // 2, (sq - sym_im.height) // 2))
    canvas.save(OUT / 'favicon.ico', sizes=[(16, 16), (32, 32), (48, 48)])
    # iOS home-screen icon: no transparency allowed, so navy symbol on white with room around it
    apple = Image.new('RGBA', (180, 180), (255, 255, 255, 255))
    inner = canvas.resize((132, 132), Image.LANCZOS)
    apple.alpha_composite(inner, (24, 24))
    apple.convert('RGB').save(OUT / 'apple-touch-icon.png', optimize=True)
    for f in ('icon.svg', 'favicon.ico', 'apple-touch-icon.png'):
        print(f"  ok   {f:<34} {(OUT / f).stat().st_size} B")

    if PREV.exists():
        contact_sheet([('logo-primary.svg', (255, 255, 255, 255)), ('logo-primary-white.svg', (45, 61, 84, 255)),
                       ('logo-symbol.svg', (255, 255, 255, 255)), ('logo-symbol-white.svg', (45, 61, 84, 255)),
                       ('logo-lighthouse.svg', (255, 255, 255, 255)), ('icon-love-people.svg', (255, 255, 255, 255)),
                       ('icon-love-people-white.svg', (45, 61, 84, 255)), ('icon-grow-together.svg', (255, 255, 255, 255)),
                       ('icon-grow-together-white.svg', (45, 61, 84, 255))])
    if FAIL:
        print('\nFAILED:', ', '.join(FAIL))
        sys.exit(1)
    print('\nAll assets built and within tolerance.')


if __name__ == '__main__':
    main()
