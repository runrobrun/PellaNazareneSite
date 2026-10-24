#!/usr/bin/env python3
"""Builds the web photos from the originals listed in the theme's photos manifest.

Each photo becomes <slug>.webp next to the manifest: orientation fixed, scaled down (never up) to its
"max" long edge, optional square crop ("square" is how far from the top to start, 0 to 1), and no metadata
(no camera details, no GPS location). Run through scripts/build-photos.sh (Docker; nothing to install).
"""
import json
import os
import sys

from PIL import Image, ImageOps

MANIFEST = "wp-content/themes/pella-nazarene/assets/images/photos/manifest.json"
OUT = os.path.dirname(MANIFEST)
QUALITY = 78

with open(MANIFEST, encoding="utf-8") as f:
    photos = json.load(f)["photos"]

missing = [p["source"] for p in photos if not os.path.isfile(p["source"])]
if missing:
    sys.exit("Originals not found (they are not in git; see docs/photos.md):\n  " + "\n  ".join(missing))

for p in photos:
    im = ImageOps.exif_transpose(Image.open(p["source"]))
    im = im.convert("RGB")
    if "square" in p:
        side = min(im.size)
        top = int((im.height - side) * float(p["square"]))
        left = (im.width - side) // 2
        im = im.crop((left, top, left + side, top + side))
    im.thumbnail((p["max"], p["max"]), Image.LANCZOS)
    dest = os.path.join(OUT, p["slug"] + ".webp")
    im.save(dest, "WEBP", quality=QUALITY, method=6)   # no exif= argument: metadata is not carried over
    print("%-20s %4dx%-4d %4d KB" % (p["slug"], im.width, im.height, os.path.getsize(dest) // 1024))
