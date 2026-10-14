#!/usr/bin/env python3
"""Validate page files: python3 check_pages.py data/areas/corby.txt [more files]"""
import re, sys

REQ = ['title', 'desc', 'h1', 'lead', 'answer', 'img', 'img_alt']
AREA_KEYS = ['hub', 'scrap-car-collection', 'mot-failure', 'non-runner', 'damaged-cars', 'scrap-van']
IMGS = set(l.split()[1] for l in open('/home/claude/mk-driveways-site/BRIEF.md') if l.startswith('- scrap-') or l.startswith('- damaged-') or l.startswith('- non-runner-') or l.startswith('- two-scrap'))
IMGS = {l.split(' ')[1] for l in open('/home/claude/mk-driveways-site/BRIEF.md') if re.match(r'^- [a-z0-9-]+ — ', l)} | IMGS
BANNED = ['we pay cash', 'paid in cash', 'cash on collection', 'unparalleled', 'seamless', 'top-notch', 'look no further', 'hassle-free', '!', '—', '£']
LINKS = re.compile(r'\]\((/[^)]*)\)')
SV = ['scrap-car-collection', 'mot-failure', 'non-runner', 'damaged-cars', 'scrap-van']
AREAS = ['corby', 'kettering', 'weldon', 'priors-hall-park', 'great-oakley', 'stanion', 'brigstock', 'gretton', 'cottingham', 'middleton', 'rockingham', 'geddington', 'rothwell', 'desborough', 'deenethorpe', 'wilbarston', 'stoke-albany', 'east-carlton', 'rushton', 'great-easton', 'caldecott', 'harringworth', 'bulwick', 'grafton-underwood', 'lowick', 'barton-seagrave']
GUIDES = ['how-to-scrap-a-car', 'certificate-of-destruction', 'scrap-car-without-logbook', 'scrap-or-repair', 'how-scrap-car-prices-work']
VALID = {'/', '/get-a-price', '/services', '/areas', '/costs', '/about', '/contact', '/guides', '/privacy'} | {f'/{s}' for s in SV} | {f'/costs/{s}' for s in SV} | {f'/areas/{a}' for a in AREAS} | {f'/{s}/{a}' for s in SV for a in AREAS} | {f'/guides/{g}' for g in GUIDES}

bad = 0
for path in sys.argv[1:]:
    txt = open(path).read()
    parts = re.split(r'^===\s+(\S+)\s*$', txt, flags=re.M)
    keys = parts[1::2]
    if '/areas/' in path and keys != AREA_KEYS:
        print(f'{path}: keys {keys} != {AREA_KEYS}'); bad += 1
    for k, body in zip(parts[1::2], parts[2::2]):
        sec = re.split(r'^---\s*$', body, maxsplit=2, flags=re.M)
        if len(sec) < 2: print(f'{path} [{k}]: missing --- separators'); bad += 1; continue
        head = dict(re.findall(r'^([a-z0-9_]+):\s*(.*)$', sec[0], flags=re.M))
        for r in REQ:
            if not head.get(r): print(f'{path} [{k}]: missing {r}'); bad += 1
        if head.get('img') and head['img'] not in IMGS: print(f'{path} [{k}]: unknown img {head["img"]}'); bad += 1
        words = len(re.findall(r"[A-Za-z0-9']+", sec[1]))
        faqs = re.findall(r'^Q:\s*(.+)\nA:\s*(.+)$', sec[2] if len(sec) > 2 else '', flags=re.M)
        full = sec[1] + (sec[2] if len(sec) > 2 else '') + head.get('answer', '') + head.get('lead', '') + head.get('desc', '')
        hits = [b for b in BANNED if b.lower() in full.lower()]
        links = LINKS.findall(sec[1])
        badl = [l for l in links if l.rstrip('/') not in VALID and l != '/']
        if re.search(r'^# ', sec[1], flags=re.M): print(f'{path} [{k}]: has H1 in body'); bad += 1
        print(f'{path} [{k}]: {words} words, {len(faqs)} FAQs, {len(links)} links' + (f', BANNED {hits}' if hits else '') + (f', BAD LINKS {badl}' if badl else '') + ('' if '/get-a-price' in links else ', NO /get-a-price LINK'))
        if hits or badl or len(faqs) < 4 or '/get-a-price' not in links: bad += 1
print('PROBLEMS:', bad)
