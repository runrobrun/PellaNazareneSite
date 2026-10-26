#!/usr/bin/env python3
"""Checks the web photos in the theme against their manifest: real WebP, sensible size, no camera or location
metadata, every file listed, every entry described. Run through photos-check.sh (Docker, nothing to install)."""
import json
import os
import sys

from PIL import Image

DIR = "wp-content/themes/pella-nazarene/assets/images/photos"
MAX_FILE_KB = 250
MAX_TOTAL_KB = 1536
passed = failed = 0


def check(name, ok, detail=""):
    global passed, failed
    if ok:
        passed += 1
        print("  ok    " + name)
    else:
        failed += 1
        print("  FAIL  %s (%s)" % (name, detail))


manifest = json.load(open(os.path.join(DIR, "manifest.json"), encoding="utf-8"))["photos"]
slugs = [p["slug"] for p in manifest]
check("slugs are unique", len(slugs) == len(set(slugs)), "duplicate slug")
files = sorted(f for f in os.listdir(DIR) if f.endswith((".webp", ".jpg")))
check("every photo file is in the manifest", set(os.path.splitext(f)[0] for f in files) == set(slugs), "files %s vs manifest %s" % (files, slugs))
total = 0
for p in manifest:
    s = p["slug"]
    jpg = p.get("format") == "jpg"
    path = os.path.join(DIR, s + (".jpg" if jpg else ".webp"))
    if not os.path.isfile(path):
        check("%s: file exists" % s, False, path)
        continue
    kb = os.path.getsize(path) / 1024
    total += kb
    with open(path, "rb") as f:
        head = f.read(12)
    if jpg:
        check("%s: is a real JPEG file" % s, head[:3] == b"\xff\xd8\xff", "bad header")
    else:
        check("%s: is a real WebP file" % s, head[:4] == b"RIFF" and head[8:12] == b"WEBP", "bad header")
    im = Image.open(path)
    if "size" in p:
        check("%s: exactly %dx%d" % (s, p["size"][0], p["size"][1]), list(im.size) == list(p["size"]), "%dx%d" % im.size)
    else:
        check("%s: no more than %d px on the long side" % (s, p["max"]), max(im.size) <= p["max"], "%dx%d" % im.size)
    check("%s: under %d KB" % (s, MAX_FILE_KB), kb <= MAX_FILE_KB, "%.0f KB" % kb)
    meta = {k for k in im.info if k.lower() in ("exif", "xmp", "icc_profile")} | ({"exif"} if im.getexif() else set())
    check("%s: no camera, location or other embedded metadata" % s, not meta, "found %s" % sorted(meta))
    if "square" in p:
        check("%s: square" % s, im.width == im.height, "%dx%d" % im.size)
    alt = p.get("alt", "").strip()
    check("%s: has alt text of a real sentence" % s, len(alt) >= 12 and not alt.lower().startswith(("image of", "picture of", "photo of")), "'%s'" % alt)
    check("%s: says where it came from" % s, len(p.get("rights", "")) >= 20, "rights missing")
    check("%s: names its original" % s, bool(p.get("source")), "source missing")
check("all photos together are under %d KB" % MAX_TOTAL_KB, total <= MAX_TOTAL_KB, "%.0f KB" % total)
print("\nPassed: %d   Failed: %d" % (passed, failed))
sys.exit(1 if failed else 0)
