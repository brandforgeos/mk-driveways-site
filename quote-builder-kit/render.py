#!/usr/bin/env python3
"""Build Bro Hub quote builder — branded proposal renderer for Rutland Driveways (RD) and MK Driveways (MKD).

    python3 render.py spec.json out.pdf

spec.json (see example-spec.json) -> A4 dark-theme proposal PDF in the company's branding, rendered with headless
Chrome, with the hub data block (/BBHubQuote) embedded so the hub files the quote exactly. Before writing, every
figure is cross-checked (lines x unit price = option total, payment stages = 100%, stage amounts add up, every
total printed on the pages matches the data block). Any mismatch stops the render with a list of problems.
"""
import base64, html, json, math, os, re, subprocess, sys, tempfile
from datetime import date, datetime

KIT = os.path.dirname(os.path.abspath(__file__))
BRAND_DIR = os.path.join(KIT, '..', 'brand')
FONTS = os.path.join(BRAND_DIR, 'fonts')

BRANDS = {
    'RD': dict(name='Rutland Driveways & Landscaping Ltd', short='Rutland Driveways', phone='01572 344119',
               email='info@rutlanddriveways.co.uk', web='rutlanddriveways.co.uk', company_no='17360422',
               logo='rd-logo.png', hero='rd-hero.jpg', band='rd-hero-band.jpg',
               bg='#0C0C0C', panel='#161613', panel2='#1E1F1A', ink='#F4F4EF', body='#C9C9C1', muted='#8C8C84',
               line='rgba(255,255,255,.12)', accent='#72AA23', accent_ink='#0C0C0C',
               head_font="'Playfair Display'", head_weight=600, ref_prefix='RD'),
    'MKD': dict(name='Milton Keynes Driveways & Landscaping Ltd', short='MK Driveways & Landscaping', phone='01908 104408',
                email='info@miltonkeynesdriveways.co.uk', web='miltonkeynesdriveways.co.uk', company_no='17432451',
                logo='mkd-logo.png', hero='mkd-hero.jpg', band='mkd-hero-band.jpg',
                bg='#16181B', panel='#1D2024', panel2='#24282D', ink='#EDE9E3', body='#CFC9C0', muted='#8E8A84',
                line='rgba(237,233,227,.12)', accent='#C08A46', accent_ink='#16181B',
                head_font="'Poppins'", head_weight=500, ref_prefix='MKD'),
}

# distinct, consistent colours for build-up layers (need not match reality — readability first)
LAYER_COLOURS = {
    'resin': '#C8A76A', 'block': '#B5533C', 'paving': '#B5533C', 'porcelain': '#3A3F47', 'slab': '#9C9486', 'setts': '#7D746A',
    'tarmac': '#2B2B2B', 'macadam': '#2B2B2B', 'binder': '#3A3A3A', 'surface': '#454545', 'mac': '#4A4A4A',
    'sand': '#E3C77A', 'grit': '#E3C77A', 'mortar': '#BDB6A8', 'bed': '#BDB6A8', 'type 1': '#8E8A7E', 'type 3': '#6F7A6A',
    'sub-base': '#8E8A7E', 'stone': '#8E8A7E', 'gravel': '#A39B8B', 'concrete': '#A8A8A0', 'slab ': '#A8A8A0',
    'membrane': '#2F6FEB', 'geotextile': '#2F6FEB', 'dpm': '#2F6FEB', 'insulation': '#E6D34F', 'timber': '#9B6A3C',
    'sleeper': '#7A5230', 'soil': '#5A4632', 'formation': '#5A4632', 'sub-grade': '#5A4632',
}
FALLBACK = ['#6B8E5A', '#8A6B9E', '#5A8E8A', '#9E8A5A', '#5A6B8E']


def gbp(n, pence=None):
    n = round(float(n) + 0.0, 2)
    if pence is None:
        pence = abs(n - round(n)) > 0.004
    return '£' + (f'{n:,.2f}' if pence else f'{round(n):,}')


def e(s):
    return html.escape(str(s if s is not None else ''))


def font_face():
    out = []
    for f, fam, w in [('PlayfairDisplay-SemiBold.ttf', 'Playfair Display', 600), ('Poppins-Light.ttf', 'Poppins', 300),
                      ('Poppins-Regular.ttf', 'Poppins', 400), ('Poppins-Medium.ttf', 'Poppins', 500), ('Poppins-SemiBold.ttf', 'Poppins', 600)]:
        p = os.path.join(FONTS, f)
        if os.path.exists(p):
            out.append(f"@font-face{{font-family:'{fam}';font-weight:{w};src:url('file://{p}') format('truetype')}}")
    return '\n'.join(out)


def asset(name):
    p = os.path.join(BRAND_DIR, name)
    if not os.path.exists(p):
        raise SystemExit(f'Brand asset missing: {p}')
    return 'file://' + os.path.abspath(p)


def layer_colour(name, i):
    n = name.lower()
    for k, v in LAYER_COLOURS.items():
        if k in n:
            return v
    return FALLBACK[i % len(FALLBACK)]


def buildup_svg(b, B):
    """Cross-section drawn to relative scale (min height so thin layers stay readable)."""
    layers = b['layers']
    W, x0, lw, lab_x = 640, 20, 300, 340
    hs = [max(16, min(70, l.get('mm', 50) * 0.55)) for l in layers]
    H = sum(hs) + 40
    parts, y = [], 20
    for i, (l, h) in enumerate(zip(layers, hs)):
        c = layer_colour(l['name'], i)
        parts.append(f'<rect x="{x0}" y="{y}" width="{lw}" height="{h}" fill="{c}" stroke="rgba(0,0,0,.35)" stroke-width="1"/>')
        if 'sub-grade' in l['name'].lower() or 'formation' in l['name'].lower():
            for k in range(0, lw, 14):
                parts.append(f'<line x1="{x0+k}" y1="{y+h}" x2="{x0+k+10}" y2="{y+4}" stroke="rgba(0,0,0,.35)" stroke-width="1"/>')
        cy = y + h / 2
        parts.append(f'<line x1="{x0+lw}" y1="{cy}" x2="{lab_x-8}" y2="{cy}" stroke="{B["muted"]}" stroke-width="1"/>')
        mm = f"{l['mm']}mm " if l.get('mm') else ''
        parts.append(f'<text x="{lab_x}" y="{cy+5}" font-family="Poppins" font-size="15" fill="{B["ink"]}"><tspan font-weight="600">{e(mm)}</tspan>{e(l["name"])}</text>')
        y += h
    return f'<svg viewBox="0 0 {W} {H}" width="100%" xmlns="http://www.w3.org/2000/svg">{"".join(parts)}</svg>'


def check(spec):
    """Every figure cross-checked. Returns list of problems (empty = good)."""
    probs = []
    opts = spec['options']
    if not [o for o in opts if not o.get('optional')]:
        probs.append('No main option (every option is marked optional).')
    for o in opts:
        s = round(sum(round(float(l['qty']) * float(l['unit_price']), 2) for l in o['lines']), 2)
        if abs(s - float(o['total'])) > 0.004:
            probs.append(f"{o['name']}: lines add to {gbp(s, True)} but total is {gbp(o['total'], True)}")
        for l in o['lines']:
            if float(l['unit_price']) < 0 or float(l['qty']) <= 0:
                probs.append(f"{o['name']}: bad line {l}")
    st = spec.get('payment_stages') or []
    if abs(sum(float(x['pct']) for x in st) - 100) > 0.001:
        probs.append(f'Payment stages add to {sum(float(x["pct"]) for x in st)}%, not 100%')
    for k in ('ref', 'revision', 'valid_until'):
        if not spec.get(k):
            probs.append(f'Missing {k}')
    if not re.match(r'^(RD|MKD)-\d{4}-\d{3,4}$', spec.get('ref', '')):
        probs.append(f"Ref {spec.get('ref')} is not a hub job number")
    if not spec['ref'].startswith(spec['brand'] + '-'):
        probs.append(f"Ref {spec['ref']} doesn't match brand {spec['brand']}")
    c = spec.get('customer', {})
    if not (c.get('email') or c.get('phone')):
        probs.append('Customer has no email or mobile')
    if not (spec['job'].get('site_address') or c.get('address')):
        probs.append('No site address')
    ba = spec.get('before_after') or []
    if len(ba) > 4:
        probs.append(f'{len(ba)} before/after pairs — four at most')
    for i, v in enumerate(ba, 1):
        if not (v.get('title') or '').strip():
            probs.append(f'Before/after {i} has no title')
        shown = (v.get('heading') or v.get('title') or '').strip()
        if len(shown) > 70:
            probs.append(f'Before/after {i} heading is {len(shown)} characters — keep it to 70 or fewer so the after photo and the illustration note stay on the page (set a short "heading")')
        if v.get('placement') not in (None, 'after_brief', 'after_price'):
            probs.append(f'Before/after {i}: placement must be after_brief or after_price')
        for k in ('before', 'after'):
            if not v.get(k) or not os.path.exists(v[k]):
                probs.append(f'Before/after {i}: {k} image not found ({v.get(k)})')
    txt = json.dumps({k: v for k, v in spec.items() if k != 'before_after'}).lower()
    for bad in ('sort code', 'account number', 'iban'):
        if bad in txt:
            probs.append(f'Bank detail wording found ("{bad}") — bank details never go on quotes')
    if re.search(r'\bvat\b', txt) and spec.get('vat_registered') is not True:
        probs.append('VAT is mentioned but the company is not VAT registered — prices are all-inclusive, no VAT wording')
    return probs


def hub_block(spec):
    c, j = spec['customer'], spec['job']
    return {
        'customer': {k: c.get(k, '') for k in ('first_name', 'last_name', 'email', 'phone', 'address', 'town', 'postcode')},
        'job': {'ref': spec['ref'], 'title': j.get('title', ''), 'site_address': j.get('site_address', ''), 'site_postcode': j.get('site_postcode', '')},
        'quote': {
            'revision': spec['revision'], 'title': spec.get('title') or j.get('title', ''), 'valid_until': spec['valid_until'],
            'prices_inclusive': True, 'payment_stages': spec['payment_stages'],
            'options': [{'name': o['name'], 'optional': bool(o.get('optional')), 'total': round(float(o['total']), 2),
                         'lines': [{'description': l['description'], 'qty': l['qty'], 'unit': l.get('unit', 'item'),
                                    'unit_price': round(float(l['unit_price']), 2), 'notes': l.get('notes', '')} for l in o['lines']]}
                        for o in spec['options']],
        },
    }


def note(b):
    return f'<p class="small">{e(b["note"])}</p>' if b.get('note') else ''


def lnote(l):
    return f'<div class="small">{e(l["notes"])}</div>' if l.get('notes') else ''


def fmt_date(s):
    return datetime.strptime(s, '%Y-%m-%d').strftime('%-d %B %Y')


def site_line(spec):
    """Site address for the cover: street, town, postcode — each only once, even if the site address already includes them."""
    c, j = spec['customer'], spec['job']
    out = []
    for part in [j.get('site_address') or c.get('address'), c.get('town'), j.get('site_postcode') or c.get('postcode')]:
        for bit in [b.strip() for b in str(part or '').split(',') if b.strip()]:
            if bit.lower().replace(' ', '') not in [o.lower().replace(' ', '') for o in out]:
                out.append(bit)
    return ', '.join(out)


def build_html(spec):
    B = BRANDS[spec['brand']]
    c, j = spec['customer'], spec['job']
    who = ' '.join(x for x in [c.get('first_name'), c.get('last_name')] if x) or 'The Homeowner'
    site = site_line(spec)
    refrev = f"{spec['ref']} · Rev {spec['revision']}"
    issued = fmt_date(spec.get('date') or date.today().isoformat())
    mains = [o for o in spec['options'] if not o.get('optional')]
    extras = [o for o in spec['options'] if o.get('optional')]
    pages = []
    n_pages = [0]

    def page(inner, cls=''):
        n_pages[0] += 1
        pages.append(f'<section class="page {cls}">{inner}<footer><span>{e(B["name"])} · Company no. {B["company_no"]}</span><span>{e(refrev)}</span><span class="pn">{{PN}}</span></footer></section>')

    head = lambda kicker, title: f'<div class="kicker">{e(kicker)}</div><h1>{e(title)}</h1><div class="rule"></div>'

    # 1 cover
    price_from = min(float(o['total']) for o in mains)
    page(f'''<div class="hero" style="background-image:linear-gradient(180deg,rgba(0,0,0,.15) 0%,rgba(0,0,0,.05) 40%,{B["bg"]} 100%),url('{asset(B["hero"])}')"></div>
      <div class="cover">
        <img class="logo" src="{asset(B["logo"])}">
        <div class="kicker">Proposal &amp; Specification</div>
        <h1 class="big">{e(spec.get("title") or j.get("title"))}</h1>
        <div class="rule"></div>
        <table class="meta"><tr><td>Prepared for</td><td>{e(who)}</td></tr><tr><td>Site</td><td>{e(site)}</td></tr>
          <tr><td>Reference</td><td>{e(refrev)}</td></tr><tr><td>Issued</td><td>{e(issued)}</td></tr><tr><td>Valid until</td><td>{e(fmt_date(spec["valid_until"]))}</td></tr></table>
        {f'<div class="callout">{e(spec["cover_callout"])}</div>' if spec.get("cover_callout") else ''}
      </div>''', 'coverpg')

    # 2 project
    scope = ''.join(f'<div class="panel"><h3>{e(s["heading"])}</h3><ul>{"".join(f"<li>{e(i)}</li>" for i in s["items"])}</ul></div>' for s in spec.get('scope', []))
    page(f'''{head("Your Project", "The Brief")}
      <p class="lead">{e(spec.get("intro", ""))}</p>
      {scope}
      {f'<div class="panel"><h3>Your selected finishes</h3><p>{e(spec["finishes"])}</p></div>' if spec.get("finishes") else ''}''')

    # 2b before & after (director-approved visuals only — they come from photos.py's visuals/ folder).
    # Each goes after the Brief, or after the Price page with "placement": "after_price" (use that for optional extras).
    def ba_page(v):
        try:
            from PIL import Image
            with Image.open(v['after']) as im:
                portrait = im.height > im.width
        except Exception:
            portrait = False
        pair = (f'<div class="ba {"side" if portrait else ""}"><figure><img src="file://{os.path.abspath(v["before"])}"><figcaption>Before</figcaption></figure>'
                f'<figure><img src="file://{os.path.abspath(v["after"])}"><figcaption>After</figcaption></figure></div>')
        page(f'''{head("Your Home, Finished", v.get("heading") or v["title"])}{pair}
          <p class="small">Image for illustration purposes only. The after image was created from a photograph of your property to show the design; the colours, materials, levels and details of the work are as set out in this proposal.</p>''')

    for v in spec.get('before_after') or []:
        if v.get('placement') != 'after_price':
            ba_page(v)

    # 3 specification
    stages = spec.get('spec_stages', [])
    if stages:
        rows = ''.join(f'<div class="stage"><div class="num">{i+1:02d}</div><div><h3>{e(s["title"])}</h3><p>{e(s["text"])}</p></div></div>' for i, s in enumerate(stages))
        page(f'{head("How We Build It", "Specification")}<div class="stages">{rows}</div>')

    # 4 construction detail
    bus = spec.get('buildups', [])
    if bus:
        blocks = ''.join(f'<div class="panel diag"><h3>{e(b["name"])}</h3>{buildup_svg(b, B)}{note(b)}</div>' for b in bus)
        page(f'{head("Construction Detail", "Build-up")}{blocks}<p class="small">Diagrams are for illustration purposes only and not to scale. Colours identify each layer and are consistent across this document.</p>')

    # 5 investment
    def opt_block(o, extra=False):
        lines = ''.join(f'<tr><td>{e(l["description"])}{lnote(l)}</td><td class="r">{gbp(float(l["qty"])*float(l["unit_price"]))}</td></tr>' for l in o['lines']) if len(o['lines']) > 1 else ''
        return f'''<div class="opt {'extra' if extra else ''}"><div class="oh"><div><h3>{e(o["name"])}</h3>{f'<p class="small">{e(o["summary"])}</p>' if o.get("summary") else ''}{f'<p class="small">Programme: {e(o["duration"])}</p>' if o.get("duration") else ''}</div>
          <div class="price">{'+' if extra else ''}{gbp(o["total"])}</div></div>{f'<table class="lines">{lines}</table>' if lines else ''}</div>'''
    stage_rows = ''.join(f'<tr><td>{e(st["label"])}</td><td class="r">{float(st["pct"]):g}%</td><td class="r">{" / ".join(gbp(float(o["total"]) * float(st["pct"]) / 100, True) for o in mains)}</td></tr>' for st in spec['payment_stages'])
    page(f'''{head("Your Investment", "Price")}
      {''.join(opt_block(o) for o in mains)}
      {('<h2>Optional extras</h2>' + ''.join(opt_block(o, True) for o in extras)) if extras else ''}
      <div class="panel"><h3>Payment terms</h3><table class="lines"><tr><th>Stage</th><th class="r">%</th><th class="r">{' / '.join(e(o["name"]) for o in mains) if len(mains) > 1 else 'Amount'}</th></tr>{stage_rows}</table>
      <p class="small">Prices are fixed and all-inclusive. The deposit is requested once a start date has been agreed with you; payment details are sent with the deposit request.</p></div>''')

    for v in spec.get('before_after') or []:
        if v.get('placement') == 'after_price':
            ba_page(v)

    # 6 included / terms
    inc = ''.join(f'<li>{e(x)}</li>' for x in spec.get('included', []))
    exc = ''.join(f'<li>{e(x)}</li>' for x in spec.get('not_included', []))
    g = spec.get('guarantee', {})
    yrs = g.get('years', 5)
    vuba = '<p>Resin bound surfacing is installed as a Vuba system and carries Vuba’s 25-year manufacturer’s product guarantee under Vuba’s published terms, once the installation is registered with Vuba. This is in addition to our own workmanship guarantee and is provided by the manufacturer.</p>' if g.get('vuba') else ''
    terms = [
        ('Consents and permissions', 'You are responsible for obtaining and confirming any planning permission, Tree Preservation Order or conservation area consent, listed building consent, dropped kerb (s.184) licence or other permit the works need. If consents are missing or incorrect we may stop the works, and any delay or change is charged as a variation.'),
        ('Unforeseen conditions', 'Our price allows for the ground conditions we could reasonably expect from our survey. Unrecorded services, or ground that proves unsuitable at formation, are dealt with as a variation agreed with you before any extra work is done.'),
        ('Variations', 'Any change you ask for, or that becomes necessary, is priced and agreed in writing before the work is carried out.'),
        ('Weather', 'Some stages cannot be carried out in heavy rain or frost. Where weather affects the programme we will agree revised dates with you.'),
        ('Images', 'All images, visuals and diagrams in this proposal are for illustration purposes only. The work is carried out as described in the written specification and price.'),
        ('Validity', f'This proposal is valid until {fmt_date(spec["valid_until"])}. Material prices may need reviewing after that date.'),
    ] + [(t['title'], t['text']) for t in spec.get('terms_extra', [])]
    page(f'''{head("The Detail", "What's Included")}
      <div class="two"><div class="panel"><h3>Included</h3><ul class="tick">{inc}</ul></div><div class="panel"><h3>Not included</h3><ul>{exc}</ul></div></div>
      <div class="panel"><h3>Our guarantee</h3><p>Our workmanship is guaranteed for {yrs} years from completion. The guarantee is provided directly by {e(B["name"])} and is confirmed with a numbered certificate on completion.</p>{vuba}</div>
      <div class="terms">{''.join(f'<div><h4>{e(a)}</h4><p>{e(b)}</p></div>' for a, b in terms)}</div>''')

    # 7 acceptance
    opt_ticks = ''.join(f'<div class="tickrow"><div class="box"></div><span>{e(o["name"])} — {gbp(o["total"])}</span></div>' for o in mains)
    ext_ticks = ''.join(f'<div class="tickrow"><div class="box"></div><span>{e(o["name"])} — +{gbp(o["total"])}</span></div>' for o in extras)
    choices = ''.join(f'<div class="field"><label>{e(ch)}</label><div class="fill"></div></div>' for ch in spec.get('choices', []))
    page(f'''{head("Go Ahead", "Acceptance")}
      <p>You can accept this proposal online from the link we sent you, or sign below and return it to us.</p>
      <div class="panel"><h3>{'Choose your option' if len(mains) > 1 else 'Proposal'}</h3>{opt_ticks}{('<h3 style="margin-top:14px">Optional extras</h3>' + ext_ticks) if extras else ''}</div>
      {f'<div class="panel">{choices}</div>' if choices else ''}
      <div class="panel"><div class="field"><label>Signature</label><div class="fill tall"></div></div>
        <div class="two"><div class="field"><label>Print name</label><div class="fill"></div></div><div class="field"><label>Date</label><div class="fill"></div></div></div></div>
      <div class="contact"><b>{e(B["short"])}</b> · {e(B["phone"])} · {e(B["email"])} · {e(B["web"])}</div>''')

    total = len(pages)
    body = ''.join(p.replace('{PN}', f'{i+1} / {total}') for i, p in enumerate(pages))
    hf = B['head_font']
    css = f'''{font_face()}
    @page{{size:A4;margin:0}}
    *{{box-sizing:border-box}}
    html,body{{margin:0;background:{B["bg"]};-webkit-print-color-adjust:exact;print-color-adjust:exact}}
    body{{font-family:Poppins,Arial,sans-serif;font-weight:300;color:{B["body"]};font-size:10.2pt;line-height:1.5}}
    .page{{width:210mm;height:297mm;padding:20mm 17mm 22mm;position:relative;overflow:hidden;page-break-after:always;background:{B["bg"]}}}
    .page:last-child{{page-break-after:auto}}
    footer{{position:absolute;left:17mm;width:176mm;bottom:12mm;border-top:1px solid {B["line"]};padding-top:3mm;display:flex;justify-content:space-between;font-size:7.5pt;color:{B["muted"]}}}
    h1{{font-family:{hf},serif;font-weight:{B["head_weight"]};color:{B["ink"]};font-size:26pt;line-height:1.15;margin:0}}
    h1.big{{font-size:34pt}}
    h2{{font-family:{hf},serif;font-weight:{B["head_weight"]};color:{B["ink"]};font-size:15pt;margin:16px 0 6px}}
    h3{{font-weight:600;color:{B["ink"]};font-size:10.5pt;margin:0 0 6px;letter-spacing:.01em}}
    h4{{font-weight:600;color:{B["ink"]};font-size:9pt;margin:0 0 2px}}
    p{{margin:0 0 8px}}
    .kicker{{color:{B["accent"]};font-weight:500;letter-spacing:.22em;text-transform:uppercase;font-size:8.5pt;margin-bottom:6px}}
    .rule{{width:56px;height:3px;background:{B["accent"]};margin:12px 0 16px}}
    .lead{{font-size:11.5pt;color:{B["ink"]};font-weight:300}}
    .small{{font-size:8.6pt;color:{B["muted"]}}}
    .panel{{background:{B["panel"]};border:1px solid {B["line"]};border-radius:10px;padding:12px 14px;margin:10px 0}}
    ul{{margin:0;padding-left:18px}} li{{margin:2px 0}}
    ul.tick{{list-style:none;padding-left:0}} ul.tick li:before{{content:"■";color:{B["accent"]};font-size:7pt;margin-right:8px;vertical-align:2px}}
    .two{{display:flex;gap:10px}} .two>div{{flex:1}}
    .coverpg{{padding:0}}
    .hero{{position:absolute;left:0;top:0;width:100%;height:58%;background-size:cover;background-position:center}}
    .cover{{position:absolute;left:17mm;right:17mm;bottom:30mm}}
    .cover .logo{{height:20mm;margin-bottom:10mm}}
    table.meta{{border-collapse:collapse;font-size:10pt}} table.meta td{{padding:3px 18px 3px 0;color:{B["ink"]}}} table.meta td:first-child{{color:{B["muted"]};width:30mm}}
    .callout{{margin-top:8mm;border-left:3px solid {B["accent"]};padding:8px 12px;background:{B["panel"]};color:{B["ink"]}}}
    .stages{{display:flex;flex-direction:column;gap:7px}}
    .stage{{display:flex;gap:12px;background:{B["panel"]};border:1px solid {B["line"]};border-radius:10px;padding:9px 12px}}
    .stage .num{{font-family:{hf},serif;color:{B["accent"]};font-size:15pt;min-width:26px}}
    .stage p{{margin:0}}
    .diag svg{{display:block;margin:6px 0}}
    .opt{{background:{B["panel"]};border:1px solid {B["line"]};border-left:3px solid {B["accent"]};border-radius:10px;padding:12px 14px;margin:10px 0}}
    .opt.extra{{border-left-color:{B["muted"]}}}
    .oh{{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}}
    .price{{font-family:{hf},serif;font-weight:{B["head_weight"]};color:{B["accent"]};font-size:20pt;white-space:nowrap}}
    .opt.extra .price{{font-size:14pt;color:{B["ink"]}}}
    table.lines{{width:100%;border-collapse:collapse;margin-top:8px;font-size:9pt}}
    table.lines td,table.lines th{{border-top:1px solid {B["line"]};padding:5px 0;vertical-align:top;text-align:left;font-weight:300}}
    table.lines th{{color:{B["muted"]};font-weight:500;font-size:8pt;text-transform:uppercase;letter-spacing:.08em}}
    .r{{text-align:right !important;white-space:nowrap;padding-left:12px !important}}
    .terms{{display:flex;flex-wrap:wrap;gap:8px 14px;margin-top:6px}} .terms>div{{width:calc(50% - 7px)}} .terms p{{font-size:8.4pt;margin:0}}
    .tickrow{{display:flex;align-items:center;gap:10px;margin:7px 0;color:{B["ink"]}}}
    .box{{width:16px;height:16px;background:#fff;border-radius:3px;flex:none}}
    .field label{{display:block;font-size:8.5pt;color:{B["muted"]};margin:8px 0 4px}}
    .fill{{background:#fff;border-radius:4px;height:28px}} .fill.tall{{height:52px}}
    .ba{{display:flex;flex-direction:column;gap:5mm;margin:2mm 0 4mm}} .ba figure{{margin:0;position:relative}}
    .ba img{{display:block;width:100%;height:96mm;object-fit:cover;border-radius:8px;border:1px solid {B["line"]}}}
    .ba.side{{flex-direction:row}} .ba.side figure{{flex:1}} .ba.side img{{height:150mm}}
    .ba figcaption{{position:absolute;left:4mm;top:4mm;background:rgba(0,0,0,.62);color:#fff;font-weight:500;font-size:8pt;letter-spacing:.18em;text-transform:uppercase;padding:2px 8px;border-radius:4px}}
    .ba figure:last-child figcaption{{background:{B["accent"]};color:{B["accent_ink"]}}}
    .contact{{position:absolute;left:17mm;right:17mm;bottom:28mm;text-align:center;color:{B["muted"]};font-size:9pt}}
    .contact b{{color:{B["ink"]};font-weight:500}}'''
    return f'<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><style>{css}</style></head><body>{body}</body></html>', total


def render(spec, out_pdf):
    probs = check(spec)
    if probs:
        raise SystemExit('CHECK FAILED:\n- ' + '\n- '.join(probs))
    page_html, pages = build_html(spec)
    with tempfile.TemporaryDirectory() as d:
        hp = os.path.join(d, 'q.html')
        open(hp, 'w').write(page_html)
        raw = os.path.join(d, 'raw.pdf')
        chrome = next(c for c in ('google-chrome', 'chromium', 'chromium-browser') if subprocess.run(['which', c], capture_output=True).returncode == 0)
        subprocess.run([chrome, '--headless=new', '--no-sandbox', '--disable-gpu', '--allow-file-access-from-files', '--no-pdf-header-footer',
                        f'--print-to-pdf={raw}', '--virtual-time-budget=8000', 'file://' + hp], check=True, capture_output=True, timeout=180)
        from pypdf import PdfReader, PdfWriter
        r = PdfReader(raw)
        if len(r.pages) != pages:
            raise SystemExit(f'CHECK FAILED: rendered {len(r.pages)} pages, expected {pages} — something overflowed a page. Shorten that page.')
        w = PdfWriter(clone_from=r)
        block = hub_block(spec)
        w.add_metadata({'/Title': f"{spec['ref']} Rev {spec['revision']} — {spec.get('title') or spec['job'].get('title')}",
                        '/Author': BRANDS[spec['brand']]['name'],
                        '/BBHubQuote': base64.b64encode(json.dumps(block, ensure_ascii=False).encode()).decode()})
        w.write(out_pdf)
    # read back: data block decodes, sums match, and every price is printed on the pages exactly
    r = PdfReader(out_pdf)
    blk = json.loads(base64.b64decode(r.metadata['/BBHubQuote']))
    text = '\n'.join(p.extract_text() or '' for p in r.pages)
    missing = []
    for o in blk['quote']['options']:
        s = round(sum(round(l['qty'] * l['unit_price'], 2) for l in o['lines']), 2)
        assert abs(s - o['total']) < 0.005, (o['name'], s, o['total'])
        if gbp(o['total']) not in text:
            missing.append(f"{o['name']} {gbp(o['total'])}")
    if spec['ref'] not in text:
        missing.append(spec['ref'])
    if missing:
        raise SystemExit('CHECK FAILED: not found printed in the PDF: ' + ', '.join(missing))
    return {'pages': len(r.pages), 'bytes': os.path.getsize(out_pdf), 'options': [(o['name'], o['total']) for o in blk['quote']['options']]}


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    spec = json.load(open(sys.argv[1]))
    result = render(spec, sys.argv[2])
    # the exact data block embedded in the PDF, for public.qb_finish
    open(re.sub(r'\.pdf$', '', sys.argv[2]) + '.block.json', 'w').write(json.dumps(hub_block(spec), ensure_ascii=False))
    print(json.dumps(result))
