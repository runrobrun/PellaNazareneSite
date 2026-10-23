#!/usr/bin/env python3
"""Every URL in the old-site inventory must have a defined outcome in deploy/redirects/expected.csv, and the
table must agree with the inventory's own decisions. Needs only Python 3. Run from the repo root:
    python3 deploy/tests/redirects-coverage.py
"""
import csv
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
passed = failed = 0


def check(cond, msg):
    global passed, failed
    if cond:
        passed += 1
        print(f'  ok    {msg}')
    else:
        failed += 1
        print(f'  FAIL  {msg}')


def read_table(path):
    lines = [l for l in path.read_text().splitlines() if l.strip() and not l.startswith('#')]
    return list(csv.DictReader(lines))


table = read_table(ROOT / 'deploy/redirects/expected.csv')
by_path = {r['path']: r for r in table}
inventory = list(csv.DictReader((ROOT / 'docs/pellanazarene-content-inventory.csv').open()))

print('== The table itself is well formed')
check(len(by_path) == len(table), f'no path is listed twice ({len(table)} rows)')
check(all(r['status'] in ('301', '410', 'stays') for r in table), 'every status is 301, 410, or stays')
check(all(r['location'].startswith('/') for r in table if r['status'] == '301'), 'every 301 has a location')
check(all(r['location'] == '' for r in table if r['status'] != '301'), 'only 301 rows have a location')
check(all(r['fallback'] == '' or r['fallback'].startswith('/') for r in table), 'fallbacks are paths')
check(all(r['path'].startswith('/') for r in table), 'every path starts with /')
check(all(r['layer'] in ('nginx', 'php', 'page') for r in table), 'every row names its layer (nginx, php, or page)')
check(all(r['layer'] == 'page' for r in table if r['status'] == 'stays'), 'stays rows are pages')
check(all(r['layer'] == 'nginx' for r in table if r['status'] == '410'), '410 rows are answered by Nginx')
check(all(r['layer'] == 'php' for r in table if r['fallback']), 'rows that depend on what exists are answered by WordPress')

print('== Every inventory URL has an outcome, and it agrees with the inventory\'s decision')
for row in inventory:
    url = row['url'].strip()
    if url.startswith('(not in sitemap)'):
        print(f'  skip  {url} (not a URL on the old site: check it in the old admin)')
        continue
    action = row['action'].strip()
    for part in [p.strip() for p in url.split(' + ')]:
        part = re.sub(r'\s*\(\d+\)\s*$', '', part)            # "(72)" counts
        path = re.sub(r'^https?://[^/]+', '', part)
        if path.endswith('/*'):
            prefix = path[:-1]
            hits = [r for r in table if r['path'].startswith(prefix) and r['status'] in ('301', '410')]
            check(bool(hits), f'{path:<46} covered by {len(hits)} sample row(s)')
            if action.startswith('drop'):
                check(all(r['status'] == '410' for r in hits), f'{path:<46} dropped, so every sample is 410')
            continue
        entry = by_path.get(path)
        check(entry is not None, f'{path:<46} has a row')
        if entry is None:
            continue
        if action.startswith('drop'):
            check(entry['status'] in ('301', '410'), f'{path:<46} dropped, so it must redirect or be gone (is {entry["status"]})')
        elif row['type'] in ('page', 'listing') and action in ('migrate', 'rebuild') and not path.startswith(('/events-2', '/sermons-t', '/news')):
            check(entry['status'] == 'stays', f'{path:<46} is kept, so it stays (is {entry["status"]})')
        elif row['type'] == 'sermon':
            check(entry['status'] == '301' and entry['location'].startswith('/sermons/'), f'{path:<46} sermon goes under /sermons/')

print(f'\nPassed: {passed}   Failed: {failed}')
sys.exit(1 if failed else 0)
