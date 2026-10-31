#!/usr/bin/env python3
"""Check a price build-up against the quote spec before filing it.

    python3 costing_check.py costing.json spec.json

Same maths as the hub (public.qc_totals): line cost = (men x days, or qty) x rate, +20% where "vat": true (rate is ex-VAT
and the VAT can't be reclaimed). Fails if any option/extra price differs from the spec, if a line has no source, or a
labour line has no men/days. Prints cost, profit and margin per option so they can go in the note to Lewis.
"""
import json, sys

def line_cost(l):
    q = (float(l['men']) * float(l['days'])) if l.get('men') not in (None, '') and l.get('days') not in (None, '') else float(l.get('qty') or 0)
    return round(q * float(l.get('rate') or 0) * (1.2 if l.get('vat') else 1), 2)

def main(cpath, spath):
    c, s = json.load(open(cpath)), json.load(open(spath))
    probs, out = [], []
    sp = sorted((round(float(o['total']), 2), bool(o.get('optional'))) for o in s['options'])
    cp = sorted((round(float(o['price']), 2), bool(o.get('optional'))) for o in c['options'])
    if sp != cp:
        probs.append(f'Build-up prices {cp} != quote prices {sp}')
    for o in c['options']:
        if not o.get('lines'):
            probs.append(f"{o['name']}: no cost lines")
        for l in o.get('lines', []):
            if l.get('source') not in ('checked', 'est', 'given'):
                probs.append(f"{o['name']} / {l.get('description')}: source must be checked, est or given")
            if l.get('kind') == 'labour' and (not l.get('men') or not l.get('days')):
                probs.append(f"{o['name']} / {l.get('description')}: labour needs men and days")
            if l.get('kind') not in ('materials', 'labour', 'plant', 'waste', 'other'):
                probs.append(f"{o['name']} / {l.get('description')}: kind must be materials, labour, plant, waste or other")
        cost = round(sum(line_cost(l) for l in o.get('lines', [])), 2)
        price = float(o['price'])
        out.append({'option': o['name'], 'cost': cost, 'price': price, 'profit': round(price - cost, 2),
                    'margin_pct': round((price / cost - 1) * 100, 1) if cost else None,
                    'est_lines': sum(1 for l in o.get('lines', []) if l.get('source') == 'est')})
    print(json.dumps(out, indent=1))
    if probs:
        raise SystemExit('CHECK FAILED:\n- ' + '\n- '.join(probs))

if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    main(sys.argv[1], sys.argv[2])
