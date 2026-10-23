#!/usr/bin/env python3
"""Checks the brand assets that ship with the theme (run via brand-assets-check.sh in Docker).

 - the designer's masters are exactly brand navy #2D3D54 or white, on transparent backgrounds
 - every shipped SVG is plain artwork: well-formed, only the expected elements, no scripts, event handlers,
   links, or external references (an SVG opened directly in a browser CAN run code), only brand colors
 - every shipped SVG still matches its master (so a hand-edited or stale file is caught)
 - the favicon set is valid and the right sizes
"""
import collections
import importlib.util
import io
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

import numpy as np
from PIL import Image

ROOT = Path('/repo')
SRC = ROOT / 'docs/branding/source'
IMG = ROOT / 'wp-content/themes/pella-nazarene/assets/images'
spec = importlib.util.spec_from_file_location('build', ROOT / 'scripts/brand-assets/build.py')
build = importlib.util.module_from_spec(spec)
spec.loader.exec_module(build)

passed = failed = 0


def ok(msg):
    global passed
    passed += 1
    print(f'  ok    {msg}')


def bad(msg):
    global failed
    failed += 1
    print(f'  FAIL  {msg}')


def check(cond, msg):
    ok(msg) if cond else bad(msg)


print('== Masters: exact brand colors, transparent backgrounds')
for f in sorted(SRC.glob('*.png')):
    im = Image.open(f).convert('RGBA')
    px = np.array(im).reshape(-1, 4)
    solid = collections.Counter(map(tuple, px[px[:, 3] == 255][:, :3]))
    top = {'#%02X%02X%02X' % c for c, n in solid.items() if n > 0.02 * max(1, sum(solid.values()))}
    allowed = {'#2D3D54', '#FFFFFF'}
    check(top <= allowed and (px[:, 3] == 0).any(), f'{f.name}: colors {sorted(top)}, has transparency')

print('== Shipped SVGs are plain artwork')
ALLOWED_TAGS = {'svg', 'g', 'path', 'style'}
ALLOWED_FILLS = {'#2D3D54', '#FFFFFF'}
svgs = sorted(IMG.glob('*.svg'))
check(len(svgs) == 10, f'10 SVG files present ({len(svgs)})')
for f in svgs:
    text = f.read_text()
    try:
        tree = ET.fromstring(text)
    except ET.ParseError as e:
        bad(f'{f.name}: not well-formed XML ({e})')
        continue
    tags = {el.tag.split('}')[-1] for el in tree.iter()}
    attrs = {a.split('}')[-1].lower() for el in tree.iter() for a in el.attrib}
    fills = {el.attrib['fill'].upper() for el in tree.iter() if 'fill' in el.attrib}
    problems = []
    if tags - ALLOWED_TAGS:
        problems.append(f'unexpected elements {sorted(tags - ALLOWED_TAGS)}')
    if any(a.startswith('on') or a in ('href', 'src') for a in attrs):
        problems.append('event handler or link attribute')
    if re.search(r'(?i)<\?xml-stylesheet\b|<script|javascript:|data:|https?://(?!www\.w3\.org/2000/svg)|(?<!:)//[^/\s]+|@import|url\(', text):
        problems.append('script, external reference, or import')
    if 'style' in tags and f.name != 'icon.svg':
        problems.append('<style> outside icon.svg')
    if f.name == 'icon.svg' and not re.search(r'<style>@media \(prefers-color-scheme:dark\)\{path\{fill:#fff\}\}</style>', text):
        problems.append('icon.svg style is not the dark-mode rule')
    if not fills or not fills <= ALLOWED_FILLS:
        problems.append(f'fills {sorted(fills)}')
    if 'viewBox' not in tree.attrib:
        problems.append('no viewBox')
    if len(text) > 16000:
        problems.append(f'{len(text)} bytes is over the 16 KB budget')
    check(not problems, f'{f.name}: {len(text)} B, fills {sorted(fills)}' + (f' -- {"; ".join(problems)}' if problems else ''))

print('== Shipped SVGs still match their masters (worst edge movement)')
FIDELITY = {
    'logo-primary.svg': ('logo-primary-navy.png', 3000), 'logo-symbol.svg': ('logo-symbol-navy.png', 2000),
    'logo-lighthouse.svg': ('logo-lighthouse-navy.png', 1400), 'icon-love-people.svg': ('icon-love-people-navy.png', 1000),
    'icon-grow-together.svg': ('icon-grow-together-navy.png', 1000), 'icon.svg': ('logo-symbol-navy.png', 2000),
}
for name, (master, width) in FIDELITY.items():
    im, w, h = build.load_mask(SRC / master, width)
    mask = np.array(im.getchannel('A')) > 127
    svg = (IMG / name).read_text()
    if name == 'icon.svg':
        svg = re.sub(r'<style>.*?</style>', '', svg)
    dev = build.edge_deviation(mask, build.render_alpha(svg, w, h))
    limit = max(build.MIN_LIMIT_PX, build.MAX_DEVIATION * w)
    check(dev <= limit, f'{name}: {dev:.2f}px of {w}px wide (limit {limit:.1f}px)')
for navy, white in [('logo-primary.svg', 'logo-primary-white.svg'), ('logo-symbol.svg', 'logo-symbol-white.svg'),
                    ('icon-love-people.svg', 'icon-love-people-white.svg'), ('icon-grow-together.svg', 'icon-grow-together-white.svg')]:
    a, b = (IMG / navy).read_text(), (IMG / white).read_text()
    check(a.replace('#2D3D54', '#FFFFFF') == b, f'{white} is {navy} in white, nothing else changed')

print('== Favicon set')
ico = Image.open(IMG / 'favicon.ico')
check(ico.format == 'ICO' and {(16, 16), (32, 32), (48, 48)} <= set(ico.info.get('sizes', [])), f'favicon.ico is a valid ICO with sizes {sorted(ico.info.get("sizes", []))}')
ap = Image.open(IMG / 'apple-touch-icon.png')
check(ap.size == (180, 180) and ap.mode == 'RGB', f'apple-touch-icon.png is {ap.size[0]}x{ap.size[1]} {ap.mode} (iOS wants 180x180 with no transparency)')

print(f'\nPassed: {passed}   Failed: {failed}')
sys.exit(1 if failed else 0)
