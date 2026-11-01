// ===== Report builder content: every section we have used, per report type =====
// Real = taken from a real Dimensure report. Example = made up to show the layout (no real report of this type yet). Idea = new suggestion.

const IMG = (src, cap) => ({ img: src, cap });

// ---------- small drawing helpers (SVG / tables) ----------
const H = {
  tbl(head, rows, opts = {}) {
    return `<table>${head ? `<tr>${head.map(h => `<th>${h}</th>`).join('')}</tr>` : ''}${rows.map(r => `<tr>${r.map((c, i) => `<td${opts.num && opts.num.includes(i) ? ' class="n"' : ''}>${c}</td>`).join('')}</tr>`).join('')}</table>`;
  },
  res(t) { const c = /pass|yes|within|closed/i.test(t) ? 'ok' : /flag|partial|risk/i.test(t) ? 'fl' : /fail|no\b|more than|open|not built/i.test(t) ? 'bd' : ''; return `<span class="rs ${c}">${t}</span>`; },
  chip(p) { const m = { H: ['High', 'h'], M: ['Medium', 'm'], L: ['Low', 'l'] }[p]; return `<span class="pc ${m[1]}">${m[0]}</span>`; },
  tolBars(rows) { // rows: [label, within, high, low]
    const w = 560, rh = 26;
    return `<svg viewBox="0 0 ${w} ${rows.length * rh + 34}" width="100%" role="img" aria-label="Share of area within tolerance by road">
    ${rows.map((r, i) => { const y = i * rh + 4, x0 = 80, W = 440; const a = W * r[1] / 100, b = W * r[2] / 100, c = W * r[3] / 100;
      return `<text x="0" y="${y + 15}" class="svl">${r[0]}</text><rect x="${x0}" y="${y}" width="${a}" height="18" fill="#3fa86b"/><rect x="${x0 + a}" y="${y}" width="${b}" height="18" fill="#d9822b"/><rect x="${x0 + a + b}" y="${y}" width="${c}" height="18" fill="#2f6db5"/><text x="${x0 + W + 6}" y="${y + 14}" class="svs">${r[1]}%</text>`; }).join('')}
    <line x1="${80 + 440 * 0.9}" x2="${80 + 440 * 0.9}" y1="0" y2="${rows.length * rh}" stroke="#0e1e3a" stroke-dasharray="3 3"/><text x="${80 + 440 * 0.9 - 4}" y="${rows.length * rh + 12}" class="svs" text-anchor="end">90% = pass</text>
    <rect x="80" y="${rows.length * rh + 20}" width="10" height="10" fill="#3fa86b"/><text x="94" y="${rows.length * rh + 29}" class="svs">within +10 / −30 mm</text><rect x="230" y="${rows.length * rh + 20}" width="10" height="10" fill="#d9822b"/><text x="244" y="${rows.length * rh + 29}" class="svs">high</text><rect x="290" y="${rows.length * rh + 20}" width="10" height="10" fill="#2f6db5"/><text x="304" y="${rows.length * rh + 29}" class="svs">low</text></svg>`;
  },
  diffBars(pts, tolHi, tolLo, label) { // pts: [ch, diffmm]
    const w = 560, h = 170, x0 = 40, y0 = 85, sc = 2.2, bw = (w - x0 - 10) / pts.length;
    return `<svg viewBox="0 0 ${w} ${h + 20}" width="100%" role="img" aria-label="${label}">
    <rect x="${x0}" y="${y0 - tolHi * sc}" width="${w - x0 - 10}" height="${(tolHi - tolLo) * sc}" fill="#e7f5ec"/>
    <line x1="${x0}" x2="${w - 10}" y1="${y0}" y2="${y0}" stroke="#0e1e3a"/>
    <text x="${x0 - 4}" y="${y0 - tolHi * sc + 4}" class="svs" text-anchor="end">+${tolHi}</text><text x="${x0 - 4}" y="${y0 - tolLo * sc + 4}" class="svs" text-anchor="end">${tolLo}</text><text x="${x0 - 4}" y="${y0 + 4}" class="svs" text-anchor="end">0</text>
    ${pts.map((p, i) => { const v = p[1], x = x0 + i * bw + 2, hh = Math.abs(v) * sc, out = v > tolHi || v < tolLo; return `<rect x="${x}" y="${v >= 0 ? y0 - hh : y0}" width="${bw - 4}" height="${hh}" fill="${out ? '#c2412d' : '#3fa86b'}"/><text x="${x + (bw - 4) / 2}" y="${h + 4}" class="svs" text-anchor="middle">${p[0]}</text>`; }).join('')}
    <text x="${x0}" y="${h + 18}" class="svs">${label}</text></svg>`;
  },
  hist(bins) { // [label, pct, inTol]
    const w = 560, h = 150, x0 = 10, bw = (w - 20) / bins.length, mx = Math.max(...bins.map(b => b[1]));
    return `<svg viewBox="0 0 ${w} ${h + 30}" width="100%" role="img" aria-label="Spread of levels against design">${bins.map((b, i) => { const hh = (h - 20) * b[1] / mx, x = x0 + i * bw; return `<rect x="${x + 2}" y="${h - hh}" width="${bw - 4}" height="${hh}" fill="${b[2] ? '#3fa86b' : '#d9822b'}"/><text x="${x + bw / 2}" y="${h - hh - 4}" class="svs" text-anchor="middle">${b[1]}%</text><text x="${x + bw / 2}" y="${h + 14}" class="svs" text-anchor="middle">${b[0]}</text>`; }).join('')}<text x="10" y="${h + 28}" class="svs">mm against design (green = within tolerance)</text></svg>`;
  },
  xsection() {
    return `<svg viewBox="0 0 560 190" width="100%" role="img" aria-label="Road cross-section with crossfall">
    <polygon points="40,70 520,58 520,66 40,78" fill="#3b3f47"/><polygon points="20,62 40,62 40,78 20,78" fill="#9aa3b2"/><polygon points="520,52 540,52 540,66 520,66" fill="#9aa3b2"/>
    <polygon points="40,78 520,66 520,130 40,130" fill="#d8cdb5"/><text x="280" y="105" class="svl" text-anchor="middle">Type 1 sub-base and capping</text>
    <text x="280" y="48" class="svl" text-anchor="middle">One-way crossfall 1 in 40 (2.5%)</text>
    <path d="M80,40 L480,30" stroke="#0e1e3a" marker-end="url(#ar)" fill="none"/><defs><marker id="ar" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="#0e1e3a"/></marker></defs>
    <line x1="40" x2="40" y1="140" y2="160" stroke="#0e1e3a"/><line x1="520" x2="520" y1="140" y2="160" stroke="#0e1e3a"/><line x1="40" x2="520" y1="152" y2="152" stroke="#0e1e3a"/><text x="280" y="170" class="svl" text-anchor="middle">5.5 m carriageway: 69 mm channel to channel</text>
    <circle cx="44" cy="74" r="7" fill="none" stroke="#c2412d" stroke-width="2"/><text x="56" y="92" class="svs" fill="#c2412d">gully on the low side</text></svg>`;
  },
  roadGrade() { // Mulberry Road 2 (real figures)
    return `<svg viewBox="0 0 560 200" width="100%" role="img" aria-label="Road 2 vertical profile">
    <rect x="235" y="20" width="110" height="150" fill="#fbe6e2"/><text x="290" y="34" class="svs" text-anchor="middle" fill="#c2412d">about 14.8 m steeper than 7%</text>
    <polyline points="20,160 120,155 190,140 270,95 330,70 400,58 540,52" fill="none" stroke="#0e1e3a" stroke-width="3"/>
    <text x="60" y="178" class="svs">2.5% · 10.4 m</text><text x="150" y="130" class="svs">16.5 m sag</text><text x="282" y="110" class="svl" fill="#c2412d">9.0% · 4.8 m</text><text x="350" y="88" class="svs">15.9 m hog</text><text x="440" y="74" class="svs">2.5% · 10.2 m</text>
    <text x="20" y="196" class="svs">Road 1 junction</text><text x="540" y="196" class="svs" text-anchor="end">Road 3 junction · limits: 8% street, 7% footway</text></svg>`;
  },
  basin() { // Mulberry Basin 1 (real figures)
    return `<svg viewBox="0 0 560 210" width="100%" role="img" aria-label="Basin 1 section">
    <polygon points="20,40 120,40 200,150 300,150 380,40 450,40 450,200 20,200" fill="#d8cdb5"/>
    <polygon points="136,62 364,62 300,150 200,150" fill="#9cc3e6"/>
    <line x1="20" x2="450" y1="40" y2="40" stroke="#0e1e3a" stroke-dasharray="4 3"/><text x="456" y="44" class="svs">Top of bank 56.60</text>
    <line x1="120" x2="380" y1="62" y2="62" stroke="#2f6db5"/><text x="456" y="66" class="svs" fill="#2f6db5">TWL 56.30</text>
    <text x="250" y="166" class="svs" text-anchor="middle">Floor 55.00 · dead level, no low-flow channel</text>
    <text x="456" y="148" class="svs">Outlet IL 54.82</text><text x="24" y="34" class="svs">Freeboard 300 mm</text>
    <text x="250" y="100" class="svl" text-anchor="middle">1.30 m of water (guide max 1.2 m)</text>
    <text x="250" y="190" class="svs" text-anchor="middle">Flow control set for 1.7 m head · head available 56.30 − 54.82 = 1.48 m</text></svg>`;
  },
  zone45() {
    return `<svg viewBox="0 0 560 220" width="100%" role="img" aria-label="Foundation and drain zone of influence">
    <rect x="0" y="40" width="560" height="180" fill="#efe6d2"/><line x1="0" x2="560" y1="40" y2="40" stroke="#0e1e3a"/>
    <rect x="70" y="40" width="70" height="110" fill="#b7bcc6"/><text x="105" y="100" class="svs" text-anchor="middle">Trench fill</text><text x="105" y="165" class="svs" text-anchor="middle">Founded 1.10 m</text>
    <line x1="140" y1="150" x2="320" y2="220" stroke="#c2412d" stroke-width="2" stroke-dasharray="6 4"/><text x="250" y="182" class="svs" fill="#c2412d">45° line from the base of the foundation</text>
    <circle cx="260" cy="205" r="9" fill="#2f6db5"/><line x1="260" x2="260" y1="40" y2="196" stroke="#2f6db5" stroke-dasharray="3 3"/><text x="272" y="120" class="svs" fill="#2f6db5">Storm drain, invert 1.85 m</text>
    <text x="10" y="28" class="svl">Drain below the 45° line → foundation deepened or drain moved</text></svg>`;
  },
  plotGrid(cells) { // [label, status]
    const col = { ok: '#3fa86b', fl: '#e0a21b', bd: '#c2412d', na: '#c9d0db' };
    return `<div class="pg">${cells.map(c => `<div style="background:${col[c[1]]}">${c[0]}</div>`).join('')}</div><p class="cap">Green clear · amber query · red finding · grey not checked</p>`;
  },
  donut(c, p, o) {
    const t = c + p + o, r = 60, C = 2 * Math.PI * r; let off = 0;
    const seg = (v, col) => { const l = C * v / t; const s = `<circle r="${r}" cx="90" cy="90" fill="none" stroke="${col}" stroke-width="26" stroke-dasharray="${l} ${C - l}" stroke-dashoffset="${-off}" transform="rotate(-90 90 90)"/>`; off += l; return s; };
    return `<svg viewBox="0 0 420 180" width="100%" role="img" aria-label="Close-out status">${seg(c, '#3fa86b')}${seg(p, '#e0a21b')}${seg(o, '#c2412d')}<text x="90" y="96" text-anchor="middle" class="svb">${t}</text>
    <rect x="200" y="50" width="12" height="12" fill="#3fa86b"/><text x="218" y="60" class="svl">Closed ${c}</text><rect x="200" y="80" width="12" height="12" fill="#e0a21b"/><text x="218" y="90" class="svl">Partially closed ${p}</text><rect x="200" y="110" width="12" height="12" fill="#c2412d"/><text x="218" y="120" class="svl">Still open ${o}</text></svg>`;
  },
  heatStrip(seed, label) {
    let s = seed; const rnd = () => (s = (s * 9301 + 49297) % 233280) / 233280;
    const cols = ['#2f6db5', '#9cc3e6', '#cfe8cf', '#f4a261', '#c0392b'];
    let cells = ''; for (let r = 0; r < 4; r++) for (let c = 0; c < 40; c++) { const v = Math.min(4, Math.max(0, Math.round(2 + Math.sin((c + seed) / 5) * 1.3 + (rnd() - 0.5) * 1.2))); cells += `<rect x="${20 + c * 13}" y="${20 + r * 13}" width="13" height="13" fill="${cols[v]}"/>`; }
    return `<svg viewBox="0 0 560 120" width="100%" role="img" aria-label="${label}">${cells}<text x="20" y="90" class="svs">ch 0</text><text x="540" y="90" class="svs" text-anchor="end">ch 200</text>
    ${['>15 mm thin', '5–15 thin', 'within ±5', '5–15 thick', '>15 thick'].map((t, i) => `<rect x="${20 + i * 105}" y="100" width="10" height="10" fill="${cols[i]}"/><text x="${34 + i * 105}" y="109" class="svs">${t}</text>`).join('')}</svg>`;
  },
  catchment() {
    const b = (x, y, w, t) => `<rect x="${x}" y="${y}" width="${w}" height="34" rx="5" fill="#fff" stroke="#0e1e3a"/><text x="${x + w / 2}" y="${y + 21}" class="svs" text-anchor="middle">${t}</text>`;
    const a = (x1, y1, x2, y2) => `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="#2f6db5" stroke-width="2" marker-end="url(#ab)"/>`;
    return `<svg viewBox="0 0 560 200" width="100%" role="img" aria-label="Where the water goes"><defs><marker id="ab" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="#2f6db5"/></marker></defs>
    ${b(10, 20, 120, 'Roofs and drives')}${b(10, 80, 120, 'Adoptable roads')}${b(10, 140, 120, 'Road 1 entrance')}
    ${b(200, 50, 130, 'S104 storm sewer')}${b(200, 140, 130, 'Highway drain S20–S21')}
    ${b(400, 20, 140, 'Basin 1 → ditch')}${b(400, 80, 140, 'Basin 2 → ditch')}${b(400, 140, 140, 'ExSw1 (owner?)')}
    ${a(130, 37, 200, 62)}${a(130, 97, 200, 72)}${a(130, 157, 200, 157)}${a(330, 62, 400, 40)}${a(330, 72, 400, 95)}${a(330, 157, 400, 157)}
    <text x="470" y="194" class="svs" text-anchor="middle" fill="#c2412d">no storage on this route</text></svg>`;
  },
  roadSchematicLowPoints() {
    return `<svg viewBox="0 0 560 230" width="100%" role="img" aria-label="Low points on the road network">
    <path d="M30,190 L200,190 L200,40 M200,120 L420,120 L420,30 M420,120 L530,190" stroke="#3b3f47" stroke-width="18" fill="none" stroke-linejoin="round"/>
    ${[[60, 190, 'ok'], [140, 190, 'ok'], [200, 70, 'ok'], [270, 120, 'bd'], [350, 120, 'ok'], [420, 50, 'fl'], [480, 152, 'bd'], [200, 160, 'ok']].map(p => `<circle cx="${p[0]}" cy="${p[1]}" r="8" fill="${p[2] === 'ok' ? '#3fa86b' : p[2] === 'fl' ? '#e0a21b' : '#c2412d'}" stroke="#fff" stroke-width="2"/>`).join('')}
    <text x="10" y="222" class="svs">● gully at the low point &nbsp; ● low point, gully set back &nbsp; ● low point, no gully</text></svg>`;
  },
  countBars(rows) { // [area, H, M, L]
    const mx = Math.max(...rows.map(r => r[1] + r[2] + r[3])), W = 400, rh = 28;
    return `<svg viewBox="0 0 560 ${rows.length * rh + 30}" width="100%" role="img" aria-label="Findings by area">${rows.map((r, i) => { const y = i * rh + 4; let x = 110; const seg = (v, c) => { const w = W * v / mx; const s = v ? `<rect x="${x}" y="${y}" width="${w}" height="20" fill="${c}"/><text x="${x + w / 2}" y="${y + 14}" class="svs" text-anchor="middle" fill="#fff">${v}</text>` : ''; x += w; return s; };
      return `<text x="0" y="${y + 15}" class="svl">${r[0]}</text>${seg(r[1], '#c2412d')}${seg(r[2], '#d9822b')}${seg(r[3], '#3b6ea8')}`; }).join('')}
    <rect x="110" y="${rows.length * rh + 12}" width="10" height="10" fill="#c2412d"/><text x="124" y="${rows.length * rh + 21}" class="svs">High</text><rect x="170" y="${rows.length * rh + 12}" width="10" height="10" fill="#d9822b"/><text x="184" y="${rows.length * rh + 21}" class="svs">Medium</text><rect x="250" y="${rows.length * rh + 12}" width="10" height="10" fill="#3b6ea8"/><text x="264" y="${rows.length * rh + 21}" class="svs">Low</text></svg>`;
  },
  pipeProfile() {
    return `<svg viewBox="0 0 560 190" width="100%" role="img" aria-label="Pipe run long section">
    <polyline points="20,40 540,30" stroke="#6b7280" stroke-width="2" fill="none"/><text x="24" y="30" class="svs">Finished road level</text>
    <polyline points="20,120 200,135 380,150 540,163" stroke="#2f6db5" stroke-width="3" fill="none"/><polyline points="20,124 200,141 380,149 540,169" stroke="#c2412d" stroke-width="2" stroke-dasharray="5 3" fill="none"/>
    ${[20, 200, 380, 540].map((x, i) => `<rect x="${x - 6}" y="${[30, 33, 36, 30][i]}" width="12" height="${[94, 108, 120, 139][i]}" fill="none" stroke="#0e1e3a"/><text x="${x}" y="182" class="svs" text-anchor="middle">S${i + 1}</text>`).join('')}
    <text x="300" y="110" class="svs" fill="#2f6db5">design invert</text><text x="300" y="178" class="svs" fill="#c2412d">as-built invert (example)</text></svg>`;
  },
};

// ---------- the report types ----------
const TYPES = [];
const T = o => TYPES.push(o);

// shared bits
const QC_TOL = 'Sub-base +10 / −30 mm, binder ±6 mm, kerbs ±10 mm (MCHW Series 700 / 1100).';

T({ id: 'qc-rf', grp: 'As-built checks', name: 'Road Formation Quality Check', code: 'QC-RF', sample: ['real', 'Real: QC-RF-013-01, Whitehouse YZ'],
  about: 'Stone and kerbs scanned before surfacing and checked against the engineer\'s levels.',
  title: 'Road Formation Quality Check', ref: 'QC-RF-013-01', project: 'Whitehouse Farm Phases Y & Z – Roads 1, 3, 4, 5 and 7', info: 'Scans of 28 and 29 Oct · MJA 3D strings · 7562-402/403 T6 · 7562-601 rev 5',
  glance: '9 findings: 4 medium, 5 low. 2,259 m² of Type 1 checked; 59.5% within +10 / −30 mm. Roads 3 and 4 sit a steady 10–20 mm high in stone and kerbs; the Road 5 entrance is laid without its 100 mm platform raise.',
  counts: [0, 4, 5], exposure: '£8,000 – £50,000',
  top: ['M1 – confirm whether the Road 5 entrance platform is still required before surfacing.', 'M2 – confirm the level Roads 3 and 4 were set out to.', 'M3 – confirm how the Road 7 junction was trimmed.'],
  qs: ['Is the 100 mm platform at the Road 5 entrance still required? (M1)', 'Is the finished road to follow the kerbs as laid or the drawn levels? (M2)', 'Are any high or low areas sacrificial, or are layers still to come?'],
  F: [['M1', 'M', 'Road 5 entrance laid without the 100 mm platform raise', 'Road 5 ch 0–11', 3], ['M2', 'M', 'Roads 3 and 4 set 10–20 mm high', 'Roads 3 and 4', 3], ['M3', 'M', 'Road 7 level carried through the Road 1 table', 'Road 1 / Road 7', 4], ['M4', 'M', 'No plate seen at 5 gullies; 3 items off position', 'Roads 1 and 3', 5], ['L1', 'L', 'Road 7 stone 21 mm high', 'Road 7 ch 5–24', 6]],
  limits: 'Road 1 east of ch 155 and north-east of ch 347.5, Road 2/2A and 6/6A not checked (outside the scan or a different layer). Checked at least 250 mm in from the kerb face. Levels to about ±5 mm.',
  next: ['Answer the questions on page 1', 'Re-scan after any trimming or raising', 'Road Surfacing Quality Check after binder'],
  w: { t: 'M1 · Road 5 entrance laid without the 100 mm platform raise', p: 'M', rows: [['Drawing', 'MJA 3D strings (+100 mm platform), Kerbing Plan 7562-402 T6, Details 7562-200 T4'], ['What the scan shows', 'Stone averages 97 mm below the target over 73.5 m², and sits within a few mm of the road level without the raise.'], ['Why it matters', 'The detail carries the full build-up over the table. Sub-base tolerance +10 / −30 mm (MCHW Series 700, Table 7/1).'], ['Possible reason', 'The raise may be formed later, dropped, or set out from the road strings.'], ['Query to confirm', 'Is the platform still required, and are stone and kerbs to be raised before surfacing?'], ['Cost if missed', 'Medium (£2,000–£10,000)']] },
  passed: [['Road 1 sub-base', '82.2% within +10 / −30 mm'], ['Kerb line positions', 'Within about ±50 mm of the drawing'], ['Gullies and manholes found', '34 of 42 plates within 300 mm']],
  dwgs: [['7562-402/403', 'Kerbing Layout', 'T6'], ['7562-601', 'Areas for Adoption', '5'], ['7562-200', 'Construction Details', 'T4'], ['MJA 3D strings', 'Road strings', '–']],
  qc: true,
  spec: [
    { id: 'rf_heat', g: 'fe', t: 'Whole-site heat map over the drawing', d: 'Sub-base against target in colour bands, kerb tops as squares, every finding labelled.', ...IMG('rf13-p2.jpg') },
    { id: 'rf_area', g: 'fe', t: 'Area sheets with finding labels', d: 'Closer views of each part of the site, labels arrowed to each issue.', ...IMG('rf13-p8.jpg') },
    { id: 'rf_road', g: 'fe', t: 'Per-road chainage sheet (kerb readings on the layout)', d: 'Faded kerbing layout, top-of-kerb dots every 0.5 m, readings against design at intervals.', ...IMG('rf13-p11.jpg') },
    { id: 'rf_kerbstrip', g: 'fe', t: 'Kerb line chainage strip', d: 'One road at a time, kerb and Type 1 differences written along the kerb line.', ...IMG('rf12-p2.jpg') },
    { id: 'rf_roadplan', g: 'fe', t: 'Per-road level plan (Type 1 and kerbs)', d: 'Heat map for one road with kerb results along each side.', ...IMG('rf12-p6.jpg') },
    { id: 'rf_byroad', g: 'brief', t: 'Sub-base by road table', d: 'Area, % within, % high, % low, median and result per road.', html: () => H.tbl(['Road', 'Ch', 'Area m²', 'Within', 'High >10', 'Low >30', 'Median', 'Result'], [['Road 1', '156–347', '944.3', '82.2%', '12.1%', '5.7%', '−5', H.res('Flag')], ['Road 3', '3–78', '475.6', '43.2%', '45.0%', '11.8%', '+8', H.res('Flag')], ['Road 4', '0–81', '546.8', '45.1%', '53.3%', '1.6%', '+11', H.res('Flag')], ['Road 5', '0–12', '96.4', '17.9%', '4.0%', '78.2%', '−90', H.res('Flag')], ['Road 7', '0–37', '196.2', '49.7%', '43.8%', '6.5%', '+7', H.res('Flag')]], { num: [2, 3, 4, 5, 6] }) + '<p class="cap">Pass = at least 90% of the area within +10 / −30 mm.</p>' },
    { id: 'rf_tolbars', g: 'brief', idea: 1, t: 'Tolerance bar per road (chart)', d: 'The same table as a bar chart, readable at a glance on page 1. Real figures.', html: () => H.tolBars([['Road 1', 82.2, 12.1, 5.7], ['Road 3', 43.2, 45.0, 11.8], ['Road 4', 45.1, 53.3, 1.6], ['Road 5', 17.9, 4.0, 78.2], ['Road 7', 49.7, 43.8, 6.5]]) },
    { id: 'rf_hist', g: 'fe', idea: 1, ex: 1, t: 'Spread of levels (histogram)', d: 'How much of the stone is how far off, in bands.', html: () => H.hist([['<−60', 4, 0], ['−60 to −30', 3, 0], ['−30 to 0', 31, 1], ['0 to +10', 28, 1], ['+10 to +25', 24, 0], ['>+25', 10, 0]]) },
    { id: 'rf_profile', g: 'fe', idea: 1, t: 'Centreline difference along a road (chart)', d: 'As-built minus target every 5 m, tolerance band shaded. Real Road 1 centreline values.', html: () => H.diffBars([[160, -5], [165, -4], [170, -8], [175, -7], [180, -1], [185, -1], [190, 4]], 10, -30, 'Road 1 centreline, mm against target, by chainage') },
    { id: 'rf_levels', g: 'app', t: '5 m level tables', d: 'Target and built level each side and on the centreline every 5 m.', html: () => H.tbl(['Ch', 'Left target', 'Left built', 'Diff', 'CL target', 'CL built', 'Diff'], [['160', '103.126', '103.146', '<b class="hi">+20</b>', '103.180', '103.175', '−5'], ['165', '103.181', '103.182', '+2', '103.233', '103.229', '−4'], ['170', '103.235', '103.243', '+8', '103.289', '103.281', '−8'], ['175', '103.289', '103.293', '+4', '103.342', '103.335', '−7'], ['180', '103.344', '103.349', '+5', '103.396', '103.395', '−1']], { num: [1, 2, 3, 4, 5, 6] }) + '<p class="cap">Road 1, m AOD, mm. Red more than 10 mm high, blue more than 30 mm low.</p>' },
    { id: 'rf_xfall', g: 'app', t: 'Crossfall check table', d: 'Design against as-built crossfall every 5 m each side.', html: () => H.tbl(['Road', 'Ch', 'Side', 'Design', 'Built', 'Diff', 'Result'], [['Road 4', '0–5', 'Left', '2.3%', '3.0%', '+0.7', H.res('Pass')], ['Road 4', '0–5', 'Right', '1.3%', '1.8%', '+0.4', H.res('Pass')], ['Road 4', '5–10', 'Left', '2.5%', '3.4%', '+1.0', H.res('Flag')], ['Road 4', '5–10', 'Right', '2.4%', '2.3%', '−0.1', H.res('Pass')]]) },
    { id: 'rf_mh', g: 'fe', t: 'Manhole and gully position plot', d: 'Drawn positions against plates found in the scan.', ...IMG('rf13-p18.jpg') },
    { id: 'rf_mhtbl', g: 'app', t: 'Manhole and gully table (300 mm test)', d: 'Each item, plate size and offset from the drawing.', html: () => H.tbl(['Ref', 'Item', 'Road', 'Ch', 'Plate m', 'Offset mm', 'Result'], [['D3', 'Storm manhole', 'Road 1', '160.2', '1.00 × 0.45', '279', H.res('within 300 mm')], ['D4', 'Gully', 'Road 1', '168.1', '0.75 × 0.60', '136', H.res('within 300 mm')], ['D7', 'Gully', 'Road 1', '187.2', '–', '–', H.res('no plate seen')], ['D41', 'Storm manhole', 'Road 3', '16.8', '0.75 × 0.65', '562', H.res('more than 300 mm')]]) },
    { id: 'rf_vol', g: 'fe', idea: 1, ex: 1, t: 'Stone over / under volume', d: 'Extra or missing stone by road in m³ and tonnes, net of what was laid extra.', html: () => H.tbl(['Road', 'High m³', 'Low m³', 'Net', 'Tonnes (2.1 t/m³)'], [['Road 3', '7.1', '0.9', '+6.2', '13.0'], ['Road 4', '8.4', '0.2', '+8.2', '17.2'], ['Road 5', '0.1', '7.0', '−6.9', '−14.5']], { num: [1, 2, 3, 4] }) + '<p class="cap">Example figures.</p>' },
    { id: 'rf_flowlink', g: 'fe', idea: 1, t: 'Link to the flow model', d: 'Where a flow model exists, a box with its link and a QR code.', html: () => `<div class="qr"><div class="qrc"></div><div><b>Surface water flow model</b><p>See where rain would stand if the roads are surfaced to the kerbs as built. Ref DA-FLOW-7562-YZ-AB-01.</p><p class="cap">Scan the code or open it in the portal.</p></div></div>` },
  ],
});

T({ id: 'qc-rs', grp: 'As-built checks', name: 'Road Surfacing Quality Check', code: 'QC-RS', sample: ['ex', 'Example content – no real report yet'],
  about: 'Binder and surface course scanned and checked for level and thickness against the formation scan and design.',
  title: 'Road Surfacing Quality Check', ref: 'QC-RS-0XX-01', project: 'Example site – Roads 1 and 3', info: 'Formation scan · binder scan · 3D strings · construction details',
  glance: '6 findings: 1 high, 2 medium, 3 low. Binder within ±6 mm over 81% of the area. Road 3 binder is 12–18 mm thin over 40 m where the stone was left high.',
  counts: [1, 2, 3], exposure: '£6,000 – £70,000',
  top: ['H1 – confirm the thin binder on Road 3 ch 20–60 before surface course.', 'M1 – check ironwork set 15 mm proud on Road 1.', 'M2 – confirm kerb upstand at the crossings.'],
  qs: ['Is surface course to be laid at extra thickness on Road 3, or the binder replaced? (H1)', 'Were covers set to binder or to finished level? (M1)', 'Is any regulating layer still to come?'],
  F: [['H1', 'H', 'Binder 12–18 mm thin over 40 m', 'Road 3 ch 20–60', 3], ['M1', 'M', 'Ironwork 15 mm proud of binder', 'Road 1', 4], ['M2', 'M', 'Kerb upstand under 100 mm at crossings', 'Road 1 / Road 3', 4], ['L1', 'L', 'Binder high on the crown', 'Road 1 ch 80–95', 5]],
  limits: 'Thickness only where both the formation and binder scans cover the road. Ironwork read from the scan to about ±5 mm.',
  next: ['Confirm the fix for H1 before surface course', 'Final surface scan for ponding', 'Flow model on the as-built surface'],
  w: { t: 'H1 · Road 3 binder 12–18 mm thin over 40 m', p: 'H', rows: [['Drawing', 'Construction details: 60 mm AC20 binder'], ['What the scan shows', 'Binder 42–48 mm thick at ch 20–60, where the formation scan showed stone 15–20 mm high.'], ['Why it matters', 'Binder tolerance ±6 mm; thin layers reduce pavement life.'], ['Possible reason', 'Binder laid to finished levels over high stone.'], ['Query to confirm', 'Is extra surface course thickness acceptable, or is the binder to be replaced?'], ['Cost if missed', 'High (£10,000–£50,000)']] },
  passed: [['Road 1 binder level', '89% within ±6 mm'], ['Crossfall', 'All 5 m sections within 0.5% of design']], dwgs: [['Construction details', 'Pavement build-up', 'T4'], ['3D strings', 'Road strings', '–']], qc: true,
  spec: [
    { id: 'rs_thick', g: 'fe', ex: 1, t: 'Layer thickness heat map', d: 'Binder thickness from formation scan to binder scan, along each road.', html: () => H.heatStrip(3, 'Binder thickness against 60 mm') },
    { id: 'rs_level', g: 'fe', ex: 1, t: 'Finished level against design heat map', d: 'Binder or surface level against the design level, same colour bands.', html: () => H.heatStrip(11, 'Binder level against design') },
    { id: 'rs_tbl', g: 'brief', ex: 1, t: 'Thickness by road table', d: 'Average, minimum and % within tolerance per road and layer.', html: () => H.tbl(['Road', 'Layer', 'Design mm', 'Average', 'Min', 'Within ±6', 'Result'], [['Road 1', 'Binder', '60', '61', '52', '89%', H.res('Pass')], ['Road 3', 'Binder', '60', '51', '42', '58%', H.res('Fail')]], { num: [2, 3, 4, 5] }) },
    { id: 'rs_tonnage', g: 'fe', ex: 1, idea: 1, t: 'Tonnage over / under laid, with £', d: 'Material laid against design quantity, converted to tonnes and cost.', html: () => H.tbl(['Road', 'Layer', 'Design t', 'Laid t', 'Diff t', '£ at £95/t'], [['Road 1', 'Binder', '74.2', '75.6', '+1.4', '+£133'], ['Road 3', 'Binder', '58.0', '49.3', '−8.7', '−£827']], { num: [2, 3, 4, 5] }) },
    { id: 'rs_upstand', g: 'app', ex: 1, t: 'Kerb upstand after surfacing', d: 'Upstand every 5 m against 100 / 125 mm, and at crossings 0–6 / 25 mm.', html: () => H.tbl(['Road', 'Ch', 'Kerb', 'Design', 'Built', 'Result'], [['Road 1', '40', 'HB2', '125', '128', H.res('Pass')], ['Road 1', '62', 'Crossing', '25', '41', H.res('Flag')]]) },
    { id: 'rs_iron', g: 'app', ex: 1, t: 'Ironwork level check', d: 'Every cover and grate against the surface around it.', html: () => H.tbl(['Ref', 'Item', 'Road', 'Proud / low mm', 'Result'], [['G4', 'Gully grate', 'Road 1', '+15', H.res('Fail')], ['S7', 'Manhole cover', 'Road 1', '+3', H.res('Pass')]]) },
    { id: 'rs_profile', g: 'fe', idea: 1, ex: 1, t: 'Thickness along a road (chart)', d: 'Thickness against design every 5 m with the tolerance band.', html: () => H.diffBars([[0, 2], [5, 1], [10, -3], [20, -12], [30, -16], [40, -18], [50, -14], [60, -6]], 6, -6, 'Road 3 binder thickness, mm against 60 mm') },
    { id: 'rs_pond', g: 'fe', idea: 1, t: 'Ponding check on the surface', d: 'Low spots where water would stand on the surface as laid (from the flow model method).', ...IMG('rd-p3.jpg', 'Style example from a road levels check') },
  ],
});

T({ id: 'qc-pl', grp: 'As-built checks', name: 'Plot / Oversite Quality Check', code: 'QC-PL', sample: ['real', 'Real: QC-PL-014-P45-01 Plot 45, QC-PL-011 Plot 11'],
  about: 'Slab and brickwork at oversite scanned and checked against the house type drawing.',
  title: 'Plot / Oversite Quality Check', ref: 'QC-PL-014-P45-01', project: 'Whitehouse Parcels Y & Z – Plot 45, Chartwell (opposite hand)', info: 'Scan of 28 Oct · MK-2504-110 rev A · levels layout',
  glance: '4 findings: 2 medium, 2 low. Brickwork is set out within 10 mm of the drawing. The floor falls about 12 mm from rear to front-right; the stub stack and electric duct are not visible.',
  counts: [0, 2, 2], exposure: '£4,000 – £24,000',
  top: ['M1 – confirm the floor level tolerance before screed.', 'M2 – confirm the stub stack and electric duct are in place.', 'L1 – note the low front-right brickwork corner.'],
  qs: ['Were the stub stack and duct cut below the floor or left out? (M2)', 'Is a levelling screed planned? (M1)', 'Any temporary works or layers still to come?'],
  F: [['M1', 'M', 'Floor not flat or level to tolerance', 'Whole floor', 3], ['M2', 'M', 'Stub stack and incoming electric duct not visible', 'Front-left room', 4], ['L1', 'L', 'Top of brickwork lowest at front-right corner', 'Corner C3', 6]],
  limits: 'Floor read from 310 mm inside the external face. Scan not tied to datum; levels are relative to the floor median.',
  next: ['Answer the questions', 'Substructure check on the next plots', 'Re-scan after any remedial work'],
  w: { t: 'M2 · Stub stack and incoming electric duct not visible', p: 'M', rows: [['Drawing', 'Chartwell substructure layout, opposite handing, MK-2504-110 rev A'], ['What the scan shows', 'No pipe, duct, cap or opening at the drawn stub stack (B) or electric duct (G). Every other drawn position shows a pipe (5 of 7, within 26 mm).'], ['Why it matters', 'Services left out of a beam and block floor usually mean cutting through blocks later.'], ['Possible reason', 'Cut below the floor and covered, not needed on this plot, or brought in elsewhere.'], ['Query to confirm', 'Are the stub stack and duct in place on this plot?'], ['Cost if missed', 'Medium (£2,000–£10,000)']] },
  passed: [['Pipe and duct positions', '5 of 7 drawn positions show a pipe or duct, all within 26 mm'], ['Handing and internal layout', 'Built as the opposite-handing drawing']], dwgs: [['MK-2504-110', 'Chartwell substructure layout (opp. hand)', 'A']], qc: true,
  spec: [
    { id: 'pl_meas', g: 'fe', t: 'Measurement overlay (drawn vs built, outside face)', d: 'Every external dimension drawn / as-built, easy to read.', ...IMG('pl14-p2.jpg') },
    { id: 'pl_meas2', g: 'fe', t: 'Measurement overlay across a pair of plots', d: 'Semi-detached pair with every external and party-wall dimension.', ...IMG('pl11-p2.jpg') },
    { id: 'pl_floor', g: 'fe', t: 'Floor level heat map', d: 'Floor level about its median on a fine grid, bearing walls shown.', ...IMG('pl14-p3.jpg') },
    { id: 'pl_area', g: 'fe', t: 'Floor level by area and steps across walls', d: 'Median level of each room and the step over each internal wall.', html: () => H.tbl(['Area', 'Median mm about floor median'], [['Rear (behind the spine wall)', '+3.8'], ['Front-left room', '+0.8'], ['Front-middle (hall and stairs)', '−4.7'], ['Front-right room', '−8.7']], { num: [1] }) + H.tbl(['Step across wall', 'Step mm'], [['Spine wall, rear to front-left room', '−5.2'], ['Spine wall, rear to front-right room', '−7.6'], ['Cross wall, hall to front-right room', '−1.4']], { num: [1] }) },
    { id: 'pl_overlay', g: 'fe', t: 'Drawing and as-built overlay (pipes, ducts, water)', d: 'House type drawing over the scan; drawn pipe positions, blue water duct marked separately.', ...IMG('pl14-p5.jpg') },
    { id: 'pl_corners', g: 'fe', t: 'Outside brickwork corner levels', d: 'Top of brickwork at every external corner and along each wall.', html: () => H.tbl(['Corner', 'Position', 'mm about plot 12 average'], [['Plot 11 · C1', 'Kitchen-end wall at the step', '0'], ['Plot 11 · C2', 'Gable and kitchen-end wall', '<b class="lo">−72</b>'], ['Plot 11 · C3', 'Gable and far-end wall', '−4'], ['Plot 12 · C1', 'Gable and kitchen-end wall', '+4']], { num: [2] }) },
    { id: 'pl_dims', g: 'app', t: 'Dimension tables', d: 'Every external and internal dimension, drawing vs scan, pass or flag.', html: () => H.tbl(['Dimension', 'Drawing', 'Scan', 'Diff', 'Result'], [['Overall width, top wall line', '9,940', '9,950', '+10', H.res('Pass')], ['Plot 11 depth at gable', '9,890', '9,922', '+32', H.res('Flag')], ['Plot 12 depth at gable', '8,800', '8,816', '+16', H.res('Flag')], ['Party wall thickness (top)', '300', '299', '−1', H.res('Pass')]], { num: [1, 2, 3] }) },
    { id: 'pl_flat', g: 'brief', idea: 1, ex: 1, t: 'Floor flatness summary box', d: 'One line for page 1: % of floor within ±5 mm and worst point.', html: () => `<div class="kpi"><div><b>71%</b><span>of floor within ±5 mm of median</span></div><div><b>−14 mm</b><span>lowest point (front-right)</span></div><div><b>+9 mm</b><span>highest point (rear)</span></div></div><p class="cap">Example figures.</p>` },
    { id: 'pl_dash', g: 'brief', idea: 1, ex: 1, t: 'Plot dashboard (for multi-plot scans)', d: 'One square per plot coloured by worst finding, linking to each plot report.', html: () => H.plotGrid([['44', 'ok'], ['45', 'fl'], ['46', 'ok'], ['47', 'bd'], ['48', 'ok'], ['49', 'na'], ['50', 'ok'], ['51', 'fl']]) },
  ],
});

T({ id: 'qc-sub', grp: 'As-built checks', name: 'Substructure & Internal Walls Check', code: 'QC-SUB', sample: ['real', 'Real: QC-SUB-016-P134-01 Plot 134 (approved)'],
  about: 'Walls scanned before the floor goes in: set-out, internal walls, bearing levels, void depth, drainage stubs.',
  title: 'Substructure & Internal Walls Check', ref: 'QC-SUB-016-P134-01', project: 'Whitehouse Parcels I, J & P – Plot 134, Kingston', info: 'Scan ijp 134-136 · MK-2403-101 rev B · levels layout 7331-005',
  glance: '5 findings: 1 medium, 4 low. Plot set out to within a few mm; bearing level consistent (96% of readings within ±10 mm). Void below the floor to confirm.',
  counts: [0, 1, 4], exposure: '£2,000 – £18,000',
  top: ['M1 – confirm the void depth below the floor.', 'L1 – review plan size and squareness on the overlay.', 'L2 – note the corner level differences.'],
  qs: ['Is the void depth acceptable where it is under 300 mm? (M1)', 'Is the plan size within the frame supplier\'s tolerance? (L1)', 'Any walls still to be built?'],
  F: [['M1', 'M', 'Void below the floor: depth to confirm', 'Whole plot', 8], ['L1', 'L', 'Plan size and squareness', 'Outside brickwork', 2], ['L2', 'L', 'Wall-top levels differ between corners', 'C4 and C6', 5], ['L3', 'L', 'Pipe or duct position off', 'Point C', 6]],
  limits: 'Scan covers walls and ground; void measured from bearing level on a 100 mm grid. Pipe positions within the floor kept clear.',
  next: ['Confirm M1 before beams are ordered', 'Plot / Oversite check after the floor'],
  w: { t: 'L2 · Wall-top levels differ between corners', p: 'L', rows: [['Drawing', 'MK-2403-101 rev B (Kingston substructure layout)'], ['What the scan shows', 'Outside brickwork corners C1 −2 and C6 +9 mm, 11 mm apart over 8,427 mm.'], ['Why it matters', 'Wall tops set the finished floor and the frame bearing.'], ['Possible reason', 'Normal coursing variation.'], ['Query to confirm', 'Is the variation within the tolerance for the floor system?'], ['Cost if missed', 'Low (under £2,000)']] },
  passed: [['FFL against plots 135 and 136', 'Within 2 mm of the design difference'], ['Handing and layout', 'Built as the as-handed drawing']], dwgs: [['MK-2403-101', 'Kingston substructure layout', 'B'], ['7331-005', 'Levels Layout Sheet 2', '10']], qc: true,
  spec: [
    { id: 'sub_out', g: 'fe', t: 'Measurement overlay, outside brickwork', d: 'External dimensions drawn / built with finding tags.', ...IMG('sub-p2.jpg') },
    { id: 'sub_in', g: 'fe', t: 'Measurement overlay, inner leaf and internal walls', d: 'Room widths and depths drawn / built.', ...IMG('sub-p3.jpg') },
    { id: 'sub_rect', g: 'fe', t: 'Dimension rectification sheet', d: 'Suggested wall adjustments for the site team to consider.', ...IMG('sub-p4.jpg') },
    { id: 'sub_void', g: 'fe', t: 'Void depth heat map', d: 'Depth from bearing level to the ground; 300 mm line drawn.', ...IMG('sub-p5.jpg') },
    { id: 'sub_ffl', g: 'fe', t: 'FFL against the other plots in the scan', d: 'Design difference against built difference to neighbouring plots.', html: () => H.tbl(['Plot', 'Design FFL', 'Design diff', 'Built diff', 'Out by', 'Result'], [['Plot 135 (Hawthorn)', '108.350', '−200', '−202', '−2', H.res('Pass')], ['Plot 136 (Penhurst V2)', '108.350', '−200', '−201', '−1', H.res('Pass')]], { num: [1, 2, 3, 4] }) + '<p class="cap">Plot 134 design FFL 108.550.</p>' },
    { id: 'sub_bear', g: 'fe', t: 'Bearing level summary', d: 'Bearing level below the brickwork and how consistent it is.', html: () => H.tbl(['Level', 'Result'], [['Outside brickwork corners about plot average', 'C1 −2, C2 +1, C3 −4, C4 −10, C5 −7, C6 +9 mm'], ['Bearing level below outside brickwork', '372 mm (average)'], ['Bearing readings within ±10 mm', '96.1% of 180 readings']]) },
    { id: 'sub_pipes', g: 'fe', t: 'Drawing and as-built overlay (pipes and ducts)', d: 'House type drawing over the scan with stub positions.', ...IMG('sub-p6.jpg') },
    { id: 'sub_dims', g: 'app', t: 'Dimension tables', d: 'Every dimension, drawing vs scan, pass or flag (as the Plot QC).', html: () => H.tbl(['Dimension', 'Drawing', 'Scan', 'Diff', 'Result'], [['Overall width, top wall line', '9,940', '9,950', '+10', H.res('Pass')], ['Party wall thickness', '300', '299', '−1', H.res('Pass')]], { num: [1, 2, 3] }) + '<p class="cap">Layout example using Plot 11 figures.</p>' },
    { id: 'sub_dash', g: 'brief', idea: 1, ex: 1, t: 'Plot dashboard (for multi-plot scans)', d: 'One square per plot coloured by worst finding.', html: () => H.plotGrid([['134', 'fl'], ['135', 'ok'], ['136', 'ok']]) },
  ],
});

T({ id: 'qc-drn', grp: 'As-built checks', name: 'As-built Drainage Check', code: 'QC-DRN', sample: ['ex', 'Example content – uses the manhole/gully part of a road scan'],
  about: 'Scan or survey of the drainage against the layout: positions, cover and invert levels.',
  title: 'As-built Drainage Check', ref: 'QC-DRN-0XX-01', project: 'Example site – S104 network', info: 'GNSS / scan survey · S104 layout · long sections',
  glance: '5 findings: 1 high, 2 medium, 2 low. 38 of 42 chambers within 300 mm of the drawn position. S12 invert 150 mm high at the size change.',
  counts: [1, 2, 2], exposure: '£6,000 – £70,000',
  top: ['H1 – confirm the S12 outgoing invert.', 'M1 – check the gradient S2–S3 against self-cleansing.', 'M2 – confirm D41 position was moved on purpose.'],
  qs: ['Was S12 laid invert-to-invert rather than soffit-to-soffit? (H1)', 'Were any chambers moved on purpose? (M2)', 'Is CCTV planned before adoption?'],
  F: [['H1', 'H', 'S12 outgoing invert 150 mm high', 'S12', 3], ['M1', 'M', 'S2–S3 flatter than drawn', 'S2–S3', 3], ['M2', 'M', 'Storm manhole 562 mm off position', 'D41', 4], ['L1', 'L', 'Cover 12 mm proud', 'S7', 5]],
  limits: 'Inverts measured where the chamber was open. Positions from cover centres.', next: ['Answer the questions', 'CCTV survey', 'Re-check after any relay'],
  w: { t: 'H1 · S12 outgoing invert 150 mm high', p: 'H', rows: [['Drawing', 'S104 layout and long section'], ['What the survey shows', 'Outgoing 375 mm invert at the incoming 225 mm level.'], ['Why it matters', 'Soffit-to-soffit connection required at a size change (DCG).'], ['Possible reason', 'Laid invert to invert from the single label on the drawing.'], ['Query to confirm', 'Is the outgoing invert to be lowered to suit?'], ['Cost if missed', 'High']] },
  passed: [['Chamber positions', '38 of 42 within 300 mm'], ['Pipe gradients', '14 of 16 runs within 10% of design']], dwgs: [['S104 layout', 'Drainage layout', '–'], ['Long sections', 'Drainage long sections', '–']], qc: true,
  spec: [
    { id: 'dr_plot', g: 'fe', t: 'Chamber and gully position plot', d: 'Drawn positions against what was found.', ...IMG('rf13-p18.jpg', 'Real plot from a road formation scan') },
    { id: 'dr_pos', g: 'app', t: 'Position offset table', d: 'Each chamber, offset from the drawing, pass or fail.', html: () => H.tbl(['Ref', 'Item', 'Offset mm', 'Result'], [['D3', 'Storm manhole', '279', H.res('within 300 mm')], ['D41', 'Storm manhole', '562', H.res('more than 300 mm')]]) },
    { id: 'dr_inv', g: 'fe', ex: 1, t: 'Cover and invert level table', d: 'Design and as-built cover and invert at every chamber.', html: () => H.tbl(['MH', 'Cover design', 'Cover built', 'Invert design', 'Invert built', 'Result'], [['S11', '56.420', '56.415', '54.951', '54.948', H.res('Pass')], ['S12', '56.300', '56.311', '56.549', '56.699', H.res('Fail')]], { num: [1, 2, 3, 4] }) },
    { id: 'dr_grad', g: 'fe', ex: 1, t: 'As-built gradient check', d: 'Gradient between chambers recalculated from the levels.', html: () => H.tbl(['Run', 'Size', 'Design', 'Built', 'Result'], [['S1–S2', '375', '1 in 180', '1 in 176', H.res('Pass')], ['S2–S3', '375', '1 in 250', '1 in 310', H.res('Flag')]]) },
    { id: 'dr_profile', g: 'fe', ex: 1, idea: 1, t: 'Pipe run long section', d: 'Design against as-built invert along a run, chambers marked.', html: () => H.pipeProfile() },
    { id: 'dr_cover', g: 'app', ex: 1, idea: 1, t: 'Cover against finished level', d: 'Each cover against the surface around it (ready for surfacing).', html: () => H.tbl(['Ref', 'Proud / low mm', 'Result'], [['S7', '+12', H.res('Flag')], ['S8', '−2', H.res('Pass')]]) },
  ],
});

T({ id: 'da-rd', grp: 'Drawing checks', name: 'Road Levels & Channel Check', code: 'DA-RD', sample: ['real', 'Real: DA-RD-009-01, Wixams R5'],
  about: 'Every channel low point found from the engineer\'s strings and checked for a gully.',
  title: 'Road Levels & Channel Check', ref: 'DA-RD-009-01', project: 'Wixams R5 – adoptable roads N1 to N10', info: '3D strings · ENG-510 E · ENG-710 C · long sections',
  glance: '11 findings: 0 high, 6 medium, 5 low. 12 places hold water over 0.5 m² (8 to 53 mm deep), mostly where a gully is set back from a ramp, junction radius or turning-head corner.',
  counts: [0, 6, 5], exposure: '£12,000 – £70,000',
  top: ['M1 – Road N8 end: no gully on the junction radii (53 mm held).', 'M2 – Road N5 table: gullies 1 m back from the ramp toe.', 'M3 – Road N10 start at the existing ramp.'],
  qs: ['Are gullies intended at the low points in M1 to M6 and L1?', 'What are the existing levels at the Road N10 tie-in? (M3)', 'Can the gully spacing calculations be supplied? (L5)'],
  F: [['M1', 'M', 'Road N8 end: low point on the junction radii, no gully', 'N8 ch 119', 2], ['M2', 'M', 'Road N5 table entry: gullies 1 m back from ramp toe', 'N5', 3], ['M3', 'M', 'Road N10 start: water held at the existing ramp', 'N10 ch 0', 4], ['M4', 'M', 'Road N3 turning head corner: no gully', 'N3', 5], ['L1', 'L', 'Road N2A junction radius: low point, no gully', 'N2A', 8]],
  limits: 'Checked from the 3D strings and agreement plans only. Existing levels at tie-ins not supplied.', next: ['Designer responses', 'Revised drawings', 'Close-out review', 'Flow model (optional)'],
  w: { t: 'M1 · Road N8 end: low point on the junction radii with no gully', p: 'M', rows: [['Drawing reference', 'S104 Plan ENG-510 Rev E, S38 Plan ENG-710 Rev C, 3D STRINGS.DWG, Long Sections ENG-544 Rev D'], ['What the drawings show', 'Channel lowest on the radius at 32.111; nearest gully 4.4 m away.'], ['Why it matters', 'Water held at a low point with no gully stands after every storm.'], ['Possible reason', 'Gully symbol placed on the straight rather than at the radius low point.'], ['Query for the designer to confirm', 'Is a gully intended at the low point of each N8/N8A junction radius?'], ['Cost if missed', 'Medium (£2,000–£10,000)']] },
  passed: [['S104 against S38 gully positions', '122 gullies match within 10 mm (one difference, L4)'], ['Gullies on the low side', '121 of 122 within 0.6 m of a channel line'], ['Sag curves', 'Gullies at the low point on both channels at N1 ch 141.8, N3 ch 26.8, N10 ch 113']],
  dwgs: [['3D STRINGS.DWG', 'Centreline and channel strings', '–'], ['H7516-7517-ENG-510', 'Section 104 Agreement Plan', 'E'], ['H7516-7517-ENG-710', 'Section 38 Agreement Plan', 'C'], ['ENG-540 to 546', 'Long Sections', 'D']],
  spec: [
    { id: 'rd_fig1', g: 'fe', t: 'Figure on each finding: water held at a low point', d: 'Close-up plan: channel, gullies, water held and depth.', ...IMG('rd-p2.jpg') },
    { id: 'rd_fig2', g: 'fe', t: 'Figure: ramp toe and gully position', d: 'Raised table entry with water against the ramp toe.', ...IMG('rd-p3.jpg') },
    { id: 'rd_fig3', g: 'fe', t: 'Figure: turning head corner', d: 'Corner of a turning head with no gully.', ...IMG('rd-p5.jpg') },
    { id: 'rd_lowtbl', g: 'app', t: 'Low point table', d: 'Every low point, channel level, nearest gully, gully yes/no.', html: () => H.tbl(['Road', 'Ch', 'Channel level', 'Nearest gully m', 'Gully at low point'], [['Road N8', '7.8', '33.392', '0.3', H.res('Yes')], ['Road N8', '118.7', '32.111', '4.4', H.res('No')], ['Road N8A', '17.4', '32.111', '3.6', H.res('No')]], { num: [1, 2, 3] }) },
    { id: 'rd_plan', g: 'brief', idea: 1, ex: 1, t: 'Whole-site low point plan', d: 'Every low point on one plan: green has a gully, amber set back, red none.', html: () => H.roadSchematicLowPoints() },
    { id: 'rd_xs', g: 'fe', idea: 1, t: 'Crossfall section diagram', d: 'Explains the channel check: crossfall, channel offset and low side.', html: () => H.xsection() },
    { id: 'rd_method', g: 'app', t: 'How the check was made', d: 'Short method page (low points from the strings, gully positions, rain model).', html: () => `<p>1. Low points from the strings alone: every channel string resampled every 0.25 m and joined at the junctions.</p><p>2. Gully positions taken from the S104 and S38 plans and compared.</p><p>3. A rain model of the road surface run to see where water is left standing.</p>` },
    { id: 'rd_pred', g: 'app', t: 'Gully positions predicted from the strings (trial)', d: 'Independent gully layout from the strings for comparison.', html: () => `<p>Predicted 89 gullies against the 122 on the S104 plan. Of the 30 low points, 18 have a drawn gully within 1.5 m; the rest are the findings in this report.</p>` },
    { id: 'rd_flow', g: 'fe', idea: 1, t: 'Link to the flow model', d: 'Box with link and QR code to the 3D flow model of the same roads.', html: () => `<div class="qr"><div class="qrc"></div><div><b>Surface water flow model</b><p>Every gully on the drawing, with rain run over the road. Ref DA-FLOW-009-01.</p></div></div>` },
  ],
});

T({ id: 'da-inf', grp: 'Drawing checks', name: 'Roads & Sewers Design Audit', code: 'DA-INF', sample: ['real', 'Real: Mulberry trial, Braintree Road (findings text and figures)'],
  about: 'The full road, drainage and SuDS drawing check before construction.',
  title: 'Roads & Sewers Design Audit', ref: 'DA-INF-0XX-01', project: 'Land North of Braintree Road, Great Dunmow', info: 'E4283/740 N · 701 N · 390 Q · Essex DCM June 2026',
  glance: '31 findings: 6 high, 13 medium, 12 low. The two most serious affect adoption: the entrance highway drain relies on an unconfirmed connection, and the basins and outfalls sit at or beyond the red line. Road 2 rises at 9.0%.',
  counts: [6, 13, 12], exposure: 'from £120,000',
  top: ['H1 – confirm ownership of ExSw1 and the connection.', 'H2 – confirm land rights for the basin outfalls.', 'H3 – Road 2 at 9.0%: regrade or agree a departure.'],
  qs: ['Who owns ExSw1, and has a connection been agreed? (H1)', 'Are HW7, HW8 and the ditch on Mulberry land or in an easement? (H2)', 'Has ECC agreed a departure for the 9.0% on Road 2? (H3)'],
  F: [['H1', 'H', 'Highway drain outfalls into a manhole of unconfirmed ownership', 'Braintree Road', 8], ['H2', 'H', 'Basin 2, flow control and outfalls at or beyond the red line', 'West boundary', 9], ['H3', 'H', 'Road 2 rises at 9.0%, above Essex limits', 'Road 2', 10], ['W1', 'M', 'No gully in the last 14 m before Braintree Road', 'Road 1 ch 0–14', 5], ['M1', 'M', 'Basin 1 flow control head does not match the basin', 'Basin 1', 12]],
  limits: 'Three drawings checked. Long sections 2–4, drainage details, gully calcs, hydraulic model, S278 GA, plot levels and GI not supplied. Plan distances scaled at 1:500.',
  next: ['Designer responses to the questions', 'Missing sheets', 'Close-out review on revised drawings', 'As-built QC during construction'],
  w: { t: 'H3 · Road 2 gradient of 9.0% is above Essex limits', p: 'H', rows: [['Drawing reference', '740/N (Road 2); 701/N'], ['What the drawings show', '2.5% for 10.4 m, 16.5 m sag, 9.0% for 4.8 m, 15.9 m hog, 2.5% for 10.2 m; about 14.8 m steeper than 7%.'], ['Why it matters', 'Essex Design Guide max street gradient typically 8%; DCM clause 2.3 limits footways to 7% up to 30 m.'], ['Possible reason', '2.5% at each junction leaves little length to climb 2.9 m; a departure may be agreed.'], ['Query for the designer to confirm', 'Has a departure been agreed for the 9.0% length, with high-friction surfacing?'], ['Cost if missed', 'High, if found after plot levels are set']] },
  passed: [['Road 1 pipe gradients', 'All match their labels'], ['Junction approach gradients', '2.5% for the first 10.5 m'], ['Basin freeboard', '300 mm on both basins']],
  dwgs: [['E4283/740', 'Longitudinal Sections Sheet 1 of 4', 'N'], ['E4283/701', 'Highway Kerbing Plan', 'N'], ['E4283/390', 'Section 104 Plan', 'Q']],
  spec: [
    { id: 'inf_water', g: 'brief', t: 'Falls, crossfall and standing water table', d: 'Location, what happens to the water, result.', html: () => H.tbl(['Location', 'What happens to the water', 'Result'], [['Road 1 entrance, ch 0–14', 'No gully; runs onto Braintree Road', H.res('Fails (W1)')], ['Basin 1 and 2 floors', 'Dead level; no route to the outlet', H.res('Fails (W2)')], ['Road 1 crest, ch 97–107', 'Fall under 0.5%; gullies either side', H.res('Risk (W4)')], ['Road 1 ch 14–59', 'Gullies on the low side at 11–15 m', H.res('Passes')]]) },
    { id: 'inf_route', g: 'fe', t: '"Where the water goes" (raindrop to outfall)', d: 'Every catchment traced to its outfall as a simple diagram.', html: () => H.catchment() },
    { id: 'inf_grade', g: 'fe', idea: 1, t: 'Road vertical profile with limits', d: 'Gradient drawn to show where it breaks the limits. Real Road 2 figures.', html: () => H.roadGrade() },
    { id: 'inf_xs', g: 'fe', idea: 1, t: 'Crossfall section diagram', d: 'Crossfall, channel offset and gully side explained.', html: () => H.xsection() },
    { id: 'inf_basin', g: 'fe', idea: 1, t: 'SuDS basin section', d: 'Levels, water depth, freeboard and flow control head on one section. Real Basin 1 figures.', html: () => H.basin() },
    { id: 'inf_drift', g: 'fe', t: 'Plan against long section level table', d: 'Cover levels compared across drawings to show revision drift.', html: () => H.tbl(['Chamber', 'Plan 390/Q', 'Long section 740/N', 'Diff'], [['S21', '56.000', '55.991', '9 mm'], ['S1', '58.799', '58.797', '2 mm'], ['F2', '58.756', '58.754', '2 mm']], { num: [1, 2, 3] }) },
    { id: 'inf_extract', g: 'fe', idea: 1, t: 'Drawing extract on each finding', d: 'Cropped part of the drawing with the issue ringed.', ...IMG('rd-p2.jpg', 'Style example: figure from a road levels check') },
    { id: 'inf_low', g: 'app', t: 'Low priority items as one table', d: 'Issue, drawing, detail, query, instead of full write-ups.', html: () => H.tbl(['#', 'Issue', 'Drawing', 'Query'], [['L1', 'One invert shown at S12 where the pipe size changes', '390/Q', 'Show both inverts at S12?'], ['L3', 'Duplicate pipe numbers', '740/N, 390/Q', 'Separate prefix per network?']]) },
    { id: 'inf_qlist', g: 'brief', t: 'Numbered questions for the design team', d: 'Every question in one list, each linked to its finding.', html: () => `<ol><li>Who owns ExSw1, and has a connection been agreed? (H1, M5)</li><li>Are HW7, HW8 and the ditch on Mulberry land? (H2)</li><li>Has ECC agreed a departure for Road 2? (H3)</li><li>Which level does the 1.7 m Basin 1 design head run to? (M1)</li></ol>` },
    { id: 'inf_missing', g: 'brief', idea: 1, t: 'Missing information list', d: 'Sheets and data not supplied, and which checks they block.', html: () => H.tbl(['Not supplied', 'Checks it would allow'], [['Long sections 2–4', 'Roads 4–7 levels and channels'], ['Gully calculations', 'Spacing and interception on the 9% length'], ['Hydraulic model', 'Flow control and storage check']]) },
    { id: 'inf_area', g: 'brief', idea: 1, ex: 1, t: 'Findings by area chart', d: 'High, medium and low counts for roads, drainage, SuDS and drawing management.', html: () => H.countBars([['Roads', 2, 5, 4], ['Drainage', 2, 5, 4], ['SuDS', 1, 2, 1], ['Drawings', 1, 1, 3]]) },
  ],
});

T({ id: 'da-drn', grp: 'Drawing checks', name: 'Drainage Check', code: 'DA-DRN', sample: ['mix', 'Mix: real Mulberry drainage findings, example counts'],
  about: 'Drainage network, gullies, outfalls, SuDS, hydraulics and details only.',
  title: 'Drainage Check', ref: 'DA-DRN-0XX-01', project: 'Land North of Braintree Road (drainage only)', info: '390/Q · 740/N · DCG v2.3 · ECC SuDS Design Guide',
  glance: '14 findings: 2 high, 6 medium, 6 low. The entrance highway drain relies on an unconfirmed connection and Basin 1\'s flow control head does not match the basin.',
  counts: [2, 6, 6], exposure: 'from £60,000',
  top: ['H1 – confirm the ExSw1 connection.', 'H2 – confirm outfall land rights and consent.', 'M1 – reconcile the Basin 1 flow control head.'],
  qs: ['Who owns ExSw1? (H1)', 'Is ordinary watercourse consent in hand? (H2)', 'Which level does the 1.7 m head run to? (M1)'],
  F: [['H1', 'H', 'Highway drain outfall to a manhole of unconfirmed ownership', 'S21–ExSw1', 3], ['H2', 'H', 'Outfalls at or beyond the red line', 'HW7, HW8', 4], ['M1', 'M', 'Basin 1 flow control head does not match', 'Basin 1', 5], ['M6', 'M', 'Concrete protection without extents; S20 shallow', 'S1, S7, S20', 6], ['M8', 'M', 'Manhole S9 type "Unknown"', 'S9', 7]],
  limits: 'Drainage details, calculations and hydraulic model not supplied.', next: ['Designer responses', 'Missing details', 'Close-out', 'As-built drainage check'],
  w: { t: 'M1 · Basin 1 flow control head does not match the basin', p: 'M', rows: [['Drawing reference', '390/Q'], ['What the drawing shows', '3.8 l/s unit, design head 1.7 m; outlet IL 54.820; TWL 56.30; top of bank 56.60.'], ['Why it matters', 'Head available is 1.48 m. At 1.7 m the freeboard drops to 80 mm.'], ['Possible reason', 'Outlet invert changed at Rev P without resizing the unit.'], ['Query for the designer to confirm', 'Which level does the 1.7 m head run to?'], ['Cost if missed', 'Medium']] },
  passed: [['Road 1 pipe gradients', 'All match their labels (1 in 180, 1 in 20, 1 in 150)'], ['Soffit-to-soffit connections', 'Met at S6 and S5'], ['Basins drain fully', 'Outlets at basin invert']], dwgs: [['E4283/390', 'Section 104 Plan', 'Q'], ['E4283/740', 'Longitudinal Sections', 'N']],
  spec: [
    { id: 'drn_grad', g: 'fe', t: 'Pipe gradient recalculation table', d: 'Every run recalculated from the inverts against its label.', html: () => H.tbl(['Run', 'Size', 'From inverts', 'Label', 'Result'], [['S20–S21', '225', '1 in 20', '1 in 20', H.res('Pass')], ['S21–ExSw1', '225', '1 in 30.5', '1 in 30', H.res('Pass')], ['S12–HW1', '375', '1 in 16.6 if laid from 56.699', '1 in 18', H.res('Flag')]]) },
    { id: 'drn_cover', g: 'fe', t: 'Cover to soffit and protection table', d: 'Cover at each chamber and where protection is needed.', html: () => H.tbl(['Location', 'Cover level', 'Soffit', 'Cover to soffit', 'Protection shown'], [['S1 (375)', '58.797', '57.647', '1.150 m', 'Label only'], ['S7 (450)', '56.850', '55.678', '1.172 m', 'Label only'], ['S20 (225)', '57.769', '56.574', '1.195 m', H.res('No')]], { num: [1, 2, 3] }) },
    { id: 'drn_mh', g: 'fe', t: 'Manhole size and type check', d: 'Diameter against pipe size (DCG Table B1) and type by depth.', html: () => H.tbl(['MH', 'Largest pipe', 'Shown', 'Required', 'Type', 'Result'], [['S9', '225', '1200', '1200', 'Unknown', H.res('Flag')], ['S12', '375', '1350', '1350', 'B', H.res('Pass')]]) },
    { id: 'drn_basin', g: 'fe', t: 'SuDS basin section', d: 'Real Basin 1 levels, water depth, freeboard and head.', html: () => H.basin() },
    { id: 'drn_fc', g: 'fe', t: 'Flow control head check', d: 'Head from the drawing against the unit\'s design head.', html: () => H.tbl(['Item', 'Value'], [['Flow control', '3.8 l/s, design head 1.7 m'], ['Outlet invert S13', '54.820'], ['Top water level', '56.30'], ['Head available', '1.48 m']]) },
    { id: 'drn_out', g: 'brief', t: 'Outfalls and agreements table', d: 'Every outfall, owner, consent and adoption status.', html: () => H.tbl(['Outfall', 'Receives', 'Owner / consent', 'Status'], [['ExSw1', 'Highway drain S21', 'Unconfirmed', H.res('Open')], ['HW7 → ditch', 'Basin 2', 's23 consent?', H.res('Open')], ['HW8 → ditch', 'Basin 1', 's23 consent?', H.res('Open')]]) },
    { id: 'drn_route', g: 'fe', idea: 1, t: 'Catchment and outfall diagram', d: 'Each catchment, the network it uses and where it leaves site.', html: () => H.catchment() },
    { id: 'drn_vel', g: 'app', t: 'Self-cleansing velocity table', d: 'Runs close to 1.0 m/s full bore.', html: () => H.tbl(['Run', 'Size', 'Gradient', 'Full-bore velocity'], [['S2–S3', '375', '1 in 250', '1.09 m/s'], ['into S17', '450', '1 in 338', '1.06 m/s']], { num: [3] }) },
    { id: 'drn_lowpts', g: 'fe', t: 'Gullies at channel low points', d: 'Only where road levels affect drainage.', ...IMG('rd-p3.jpg', 'Style example from a road levels check') },
  ],
});

const PLOTS_EX = [['1', 'ok'], ['2', 'ok'], ['3', 'fl'], ['4', 'ok'], ['5', 'bd'], ['6', 'ok'], ['7', 'ok'], ['8', 'fl'], ['9', 'ok'], ['10', 'ok'], ['11', 'na'], ['12', 'ok']];
T({ id: 'da-plt', grp: 'Drawing checks', name: 'Groundworks Design Audit', code: 'DA-PLT', sample: ['ex', 'Example content – no real report yet'],
  about: 'Foundation, plot and substructure drawings: FFLs, foundation depths, DPC, details carried onto the plans.',
  title: 'Groundworks Design Audit', ref: 'DA-PLT-0XX-01', project: 'Example site – plots 1 to 12', info: 'Foundation layout · plot levels · foundation details · GI report',
  glance: '9 findings: 1 high, 4 medium, 4 low. Compressible void former shown on the foundation detail and key but not marked on the foundation plan for plots 3, 5 and 8.',
  counts: [1, 4, 4], exposure: '£15,000 – £90,000',
  top: ['H1 – confirm void former extents on plots 3, 5 and 8.', 'M1 – plot 5 foundation shallower than 1.0 m below finished ground.', 'M2 – garage FFLs missing on plots 2 and 9.'],
  qs: ['Is the void former required on plots 3, 5 and 8? (H1)', 'Is plot 5 to be deepened? (M1)', 'What are the garage FFLs? (M2)'],
  F: [['H1', 'H', 'Void former on detail and key, not on the plan', 'Plots 3, 5, 8', 3], ['M1', 'M', 'Foundation 0.85 m below finished ground', 'Plot 5', 4], ['M2', 'M', 'Garage FFL missing', 'Plots 2, 9', 4], ['L1', 'L', 'Title block scale 1:100, drawing at 1:200', 'Sheet 2', 6]],
  limits: 'GI report and tree survey checked for foundation depth only.', next: ['Designer responses', 'Revised foundation plan', 'Plot / Oversite QC on site'],
  w: { t: 'H1 · Void former on the detail and key, not on the plan', p: 'H', rows: [['Drawing reference', 'Foundation details sheet 1; foundation layout'], ['What the drawings show', 'Detail F3 and the key show a compressible void former; the plan does not mark it on any plot.'], ['Why it matters', 'In shrinkable clay near trees the void former protects against heave (NHBC 4.2).'], ['Possible reason', 'Plan hatch missed when the detail was added.'], ['Query for the designer to confirm', 'Which plots need the void former, and to what extent?'], ['Cost if missed', 'High']] },
  passed: [['FFL against road levels', 'All plots at least 150 mm above the adjacent road'], ['DPC', 'At least 150 mm above finished ground on all plots']], dwgs: [['Foundation layout', 'Foundation plan', 'Example'], ['Foundation details', 'Details sheet 1', 'Example']],
  spec: [
    { id: 'plt_dash', g: 'brief', idea: 1, t: 'Plot dashboard', d: 'One square per plot coloured by worst finding.', html: () => H.plotGrid(PLOTS_EX) },
    { id: 'plt_ffl', g: 'fe', t: 'FFL schedule check', d: 'FFL per plot and garage against road and ground levels.', html: () => H.tbl(['Plot', 'House FFL', 'Garage FFL', 'Adj. road', 'Above road', 'Result'], [['1', '58.450', '58.300', '58.210', '240 mm', H.res('Pass')], ['2', '58.600', '–', '58.380', '220 mm', H.res('Flag')], ['5', '59.050', '58.900', '58.950', '100 mm', H.res('Fail')]], { num: [1, 2, 3] }) },
    { id: 'plt_depth', g: 'fe', t: 'Foundation depth against finished ground', d: 'Depth below finished ground and GI / tree requirement per plot.', html: () => H.tbl(['Plot', 'Founded', 'Finished ground', 'Depth', 'Required', 'Result'], [['3', '57.200', '58.300', '1.10 m', '1.00 m', H.res('Pass')], ['5', '58.050', '58.900', '0.85 m', '1.00 m', H.res('Fail')]], { num: [1, 2, 3, 4] }) },
    { id: 'plt_detail', g: 'fe', t: 'Detail-to-plan implementation table', d: 'Each detail and spec item checked onto the plans both ways.', html: () => H.tbl(['Item on detail / spec', 'Shown on plan?', 'Plots'], [['Compressible void former', H.res('No'), '3, 5, 8'], ['Heave precautions to garage', H.res('Yes'), '2, 9'], ['Radon membrane', H.res('Yes'), 'All']]) },
    { id: 'plt_zone', g: 'fe', idea: 1, t: 'Foundation and drain section', d: '45° line from the foundation base against nearby drains.', html: () => H.zone45() },
    { id: 'plt_cover', g: 'app', idea: 1, t: 'Plot coverage map', d: 'Which plots and house types were covered by the drawings supplied.', html: () => H.plotGrid(PLOTS_EX.map(p => [p[0], p[0] === '11' ? 'na' : 'ok'])) },
  ],
});

T({ id: 'da-fd', grp: 'Drawing checks', name: 'Foundations vs Drainage Check', code: 'DA-FD', sample: ['ex', 'Example content – no real report yet'],
  about: 'Every drain near a foundation checked against the 45° bearing line and the 10 m search radius.',
  title: 'Foundations vs Drainage Check', ref: 'DA-FD-0XX-01', project: 'Example site – plots 1 to 12', info: 'Foundation layout · drainage layout rev 18 · drainage details',
  glance: '6 findings: 2 high, 2 medium, 2 low. Storm drains on plots 5 and 8 sit below the 45° line from the trench fill; foundations were coordinated to drainage rev 16, not rev 18.',
  counts: [2, 2, 2], exposure: '£20,000 – £100,000',
  top: ['H1 – plot 5 storm drain below the 45° line.', 'H2 – plot 8 foul drain below the 45° line.', 'M1 – foundations coordinated to an older drainage revision.'],
  qs: ['Are plots 5 and 8 to be deepened or the drains moved? (H1, H2)', 'Has the foundation design been checked against drainage rev 18? (M1)', 'Are any chambers within 1 m of walls intended? (M2)'],
  F: [['H1', 'H', 'Storm drain below the 45° line', 'Plot 5', 3], ['H2', 'H', 'Foul drain below the 45° line', 'Plot 8', 3], ['M1', 'M', 'Foundations coordinated to drainage rev 16', 'All', 4], ['M2', 'M', 'Chamber 0.6 m from the wall', 'Plot 3', 4]],
  limits: 'Drains within 10 m of each foundation checked. ADH H1 allowance applied.', next: ['Designer responses', 'Revised foundation depths', 'Substructure check on site'],
  w: { t: 'H1 · Plot 5 storm drain below the 45° line', p: 'H', rows: [['Drawing reference', 'Foundation layout; drainage layout rev 18'], ['What the drawings show', 'Trench fill founded at 1.10 m; storm drain invert 1.85 m, 1.2 m from the wall.'], ['Why it matters', 'Drain trench below the 45° line undermines the foundation (Approved Document H1, 2.25).'], ['Possible reason', 'Drainage moved at rev 18 after the foundations were set.'], ['Query for the designer to confirm', 'Is plot 5 to be deepened or the drain relocated?'], ['Cost if missed', 'High']] },
  passed: [['Drains crossing under foundations', 'Lintels / sleeves shown on all 4'], ['Soakaways', 'All at least 5 m from buildings']], dwgs: [['Foundation layout', 'Foundation plan', 'Example'], ['Drainage layout', 'Drainage sheet 1', '18']],
  spec: [
    { id: 'fd_section', g: 'fe', t: '45° zone section at each clash', d: 'Section through the foundation and drain with the 45° line.', html: () => H.zone45() },
    { id: 'fd_tbl', g: 'fe', t: 'Clash table by plot', d: 'Every drain within the zone: depth, distance, required foundation depth.', html: () => H.tbl(['Plot', 'Drain', 'Invert depth', 'Distance from wall', 'Founded', 'Needed', 'Result'], [['5', 'Storm 150', '1.85 m', '1.2 m', '1.10 m', '1.85 m', H.res('Fail')], ['8', 'Foul 100', '1.60 m', '0.9 m', '1.00 m', '1.60 m', H.res('Fail')], ['3', 'Storm 150', '1.10 m', '2.4 m', '1.10 m', '–', H.res('Pass')]], { num: [2, 3, 4, 5] }) },
    { id: 'fd_dash', g: 'brief', t: 'Traffic light per plot', d: 'Each plot coloured by worst clash.', html: () => H.plotGrid(PLOTS_EX) },
    { id: 'fd_rev', g: 'brief', idea: 1, t: 'Revision coordination check', d: 'Which drainage revision the foundation design was checked against.', html: () => H.tbl(['Drawing', 'Revision used by foundation designer', 'Current', 'Result'], [['Drainage layout sheet 1', '16', '18', H.res('Flag')], ['Levels layout', '10', '10', H.res('Pass')]]) },
  ],
});

T({ id: 'da-pd', grp: 'Drawing checks', name: 'Plot Drawing Check', code: 'DA-PD', sample: ['ex', 'Example content – no real report yet'],
  about: 'Plot drawings only: FFL per plot including garages, foundation depth, DPC and tanking, title-block scale.',
  title: 'Plot Drawing Check', ref: 'DA-PD-0XX-01', project: 'Example site – plots 1 to 12', info: 'Plot levels drawings · house type drawings',
  glance: '5 findings: 0 high, 3 medium, 2 low. Garage FFLs missing on two plots; plot 5 DPC under 150 mm above finished ground at the rear.',
  counts: [0, 3, 2], exposure: '£6,000 – £34,000',
  top: ['M1 – supply garage FFLs on plots 2 and 9.', 'M2 – plot 5 rear DPC height.', 'M3 – plot 7 tanking where ground is above FFL.'],
  qs: ['What are the garage FFLs? (M1)', 'Is the rear ground on plot 5 to be lowered? (M2)', 'Is tanking needed on plot 7? (M3)'],
  F: [['M1', 'M', 'Garage FFL missing', 'Plots 2, 9', 2], ['M2', 'M', 'DPC 90 mm above finished ground at rear', 'Plot 5', 3], ['M3', 'M', 'Ground above FFL, no tanking shown', 'Plot 7', 3], ['L1', 'L', 'Title block scale wrong', 'Sheet 2', 4]],
  limits: 'Plot drawings only; road and drainage drawings not checked.', next: ['Designer responses', 'Groundworks Design Audit if the full package is issued'],
  w: { t: 'M2 · Plot 5 DPC 90 mm above finished ground at the rear', p: 'M', rows: [['Drawing reference', 'Plot levels sheet 1'], ['What the drawings show', 'FFL 59.050, rear finished ground 58.960.'], ['Why it matters', 'DPC at least 150 mm above finished ground (NHBC 5.1).'], ['Possible reason', 'Rear garden levels raised to suit the fence line.'], ['Query for the designer to confirm', 'Is the rear ground to be lowered, or a step and channel added?'], ['Cost if missed', 'Medium']] },
  passed: [['FFL shown for every house', '12 of 12'], ['Foundation depth note', 'On every sheet']], dwgs: [['Plot levels', 'Plot levels sheets 1–2', 'Example']],
  spec: [
    { id: 'pd_ffl', g: 'fe', t: 'FFL schedule (house and garage)', d: 'Every plot FFL and garage FFL against adjacent levels.', html: () => H.tbl(['Plot', 'House FFL', 'Garage FFL', 'Rear ground', 'DPC height', 'Result'], [['2', '58.600', '–', '58.420', '180 mm', H.res('Flag')], ['5', '59.050', '58.900', '58.960', '90 mm', H.res('Fail')], ['7', '59.300', '59.150', '59.380', '−80 mm', H.res('Fail')]], { num: [1, 2, 3, 4] }) },
    { id: 'pd_dash', g: 'brief', t: 'Plot dashboard', d: 'One square per plot coloured by worst finding.', html: () => H.plotGrid(PLOTS_EX) },
    { id: 'pd_tb', g: 'app', t: 'Title block and scale check', d: 'Scale, north point, revision and status on each sheet.', html: () => H.tbl(['Sheet', 'Scale stated', 'Scale measured', 'Status', 'Result'], [['Plot levels 1', '1:200', '1:200', 'Construction', H.res('Pass')], ['Plot levels 2', '1:100', '1:200', 'Construction', H.res('Flag')]]) },
  ],
});

T({ id: 'da-pkg', grp: 'Drawing checks', name: 'Combined Roads & Sewers and Groundworks Audit', code: 'DA-PKG', sample: ['mix', 'Mix: real Mulberry findings with example plot findings'],
  about: 'Every drawing, every check, findings grouped by area.',
  title: 'Combined Roads & Sewers and Groundworks Design Audit', ref: 'DA-PKG-0XX-01', project: 'Example full package', info: 'Full drawing package',
  glance: '40 findings across roads, drainage, SuDS, foundations and plots: 7 high, 17 medium, 16 low.',
  counts: [7, 17, 16], exposure: 'from £150,000',
  top: ['H1 – outfall connection ownership (drainage).', 'H3 – Road 2 at 9.0% (roads).', 'H7 – void former missing from the foundation plan (foundations).'],
  qs: ['Who owns ExSw1? (H1)', 'Has a departure been agreed for Road 2? (H3)', 'Which plots need the void former? (H7)'],
  F: [['H1', 'H', 'Highway drain outfall ownership unconfirmed', 'Drainage', 3], ['H3', 'H', 'Road 2 at 9.0%', 'Roads', 5], ['H7', 'H', 'Void former missing from the foundation plan', 'Foundations', 9], ['M1', 'M', 'Basin 1 flow control head', 'SuDS', 11]],
  limits: 'As each part report.', next: ['Designer responses', 'Close-out', 'As-built QC'],
  w: { t: 'H7 · Void former missing from the foundation plan', p: 'H', rows: [['Drawing reference', 'Foundation details sheet 1; foundation layout'], ['What the drawings show', 'Void former on detail F3 and key, not on the plan.'], ['Why it matters', 'Heave protection in shrinkable clay near trees.'], ['Possible reason', 'Plan hatch missed.'], ['Query for the designer to confirm', 'Which plots need it?'], ['Cost if missed', 'High']] },
  passed: [['Road 1 pipe gradients', 'All match'], ['DPC heights', 'All above 150 mm except plot 5']], dwgs: [['Full package', 'See register', '–']],
  spec: [
    { id: 'pkg_area', g: 'brief', ex: 1, t: 'Findings by area table', d: 'High / medium / low counts per area.', html: () => H.tbl(['Area', 'High', 'Medium', 'Low'], [['Roads and channels', '2', '5', '4'], ['Drainage', '2', '6', '6'], ['SuDS', '1', '3', '2'], ['Foundations vs drainage', '1', '2', '2'], ['Plots', '1', '1', '2']], { num: [1, 2, 3] }) },
    { id: 'pkg_dash', g: 'brief', t: 'Plot dashboard', d: 'Plots coloured by worst finding.', html: () => H.plotGrid(PLOTS_EX) },
    { id: 'pkg_water', g: 'fe', t: 'Falls and standing water table (roads part)', d: 'As the Roads & Sewers audit.', html: () => H.tbl(['Location', 'What happens to the water', 'Result'], [['Road 1 entrance', 'Runs onto Braintree Road', H.res('Fails (W1)')], ['Basin floors', 'Dead level', H.res('Fails (W2)')]]) },
    { id: 'pkg_route', g: 'fe', t: 'Where the water goes diagram', d: 'Catchments to outfalls.', html: () => H.catchment() },
    { id: 'pkg_basin', g: 'fe', t: 'SuDS basin section', d: 'Real Basin 1 figures.', html: () => H.basin() },
    { id: 'pkg_zone', g: 'fe', t: 'Foundation and drain section', d: '45° zone at each clash.', html: () => H.zone45() },
    { id: 'pkg_split', g: 'fe', idea: 1, t: 'One chapter per area', d: 'Findings Explained split into chapters (roads, drainage, SuDS, foundations, plots) with a tab on the page edge.', html: () => `<div class="tabsx"><span>Roads</span><span>Drainage</span><span>SuDS</span><span>Foundations</span><span>Plots</span></div><p>Each chapter has its own findings, figures and checks passed.</p>` },
  ],
});

T({ id: 'da-co', grp: 'Drawing checks', name: 'Design Audit Close-out', code: 'DA-CO', sample: ['real', 'Real: DA-CO-003-01 (demo project)'],
  about: 'Follow-up after the designer responds: every original finding marked Closed, Partially closed or Still open.',
  title: 'Design Audit Close-out', ref: 'DA-CO-003-01', project: 'Demo Site – Phase 1', info: '12 sheets (listed in Findings Explained)',
  glance: '22 items to confirm with the design team: 0 high, 6 medium and 16 low. Estimated cost if missed and found on site: from £12,000.',
  counts: [0, 6, 16], exposure: 'from £12,000',
  top: ['M1 – S37 and F28 inverts do not follow the sequence.', 'M2 – manhole schedule does not cover all chambers.', 'M3 – storm outfall headwalls east of the bus lane.'],
  qs: ['Which network does Manhole Schedule 700 cover? (M2, L3)', 'Where do S37 and F28 sit in the network? (M1, L13)', 'Who owns the land and watercourse at the outfall? (M3, L5)'],
  F: [['M1', 'M', 'S37 and F28 inverts do not follow the S36 and F27 sequence', '310, 002', 3], ['M2', 'M', 'Manhole schedule does not cover the chambers on the layouts', '700, 002, 003', 3], ['M3', 'M', 'Storm outfall headwalls and ditch beyond the site', '605', 4], ['L1', 'L', 'Lower-capacity outlet pipes at SW23 and SW8', '700', 7]],
  limits: 'No road long sections, 3D strings, channel levels or contours supplied, so channel and ponding checks were not possible.', next: ['Responses to open items', 'Revised sheets', 'Further close-out if needed'],
  w: { t: 'M1 · S37 and F28 inverts do not follow the S36 and F27 sequence', p: 'M', rows: [['Drawing', '7331-MJA-SW-XX-DR-C-310, 7331-MJA-SW-XX-DR-C-002'], ['What the drawing shows', 'Inverts out of sequence along the run.'], ['Why it matters', 'Backfall or a step at the chamber.'], ['Possible reasons', 'A separate schedule may exist for the S/F network.'], ['Query for the designer to confirm', 'Where do S37 and F28 sit in the network and which way does each link fall?'], ['Cost if missed', 'Medium']] },
  passed: [['Gully connection detail', '150 mm clay, concrete surround under 1.2 m cover'], ['Private drainage gradients', 'Given at every run']], dwgs: [['7331-MJA-SW-XX-DR-C-002', 'Drainage Layout Sheet 2', '18'], ['7331-MJA-SW-XX-DR-C-700', 'Manhole Schedules', 'T3']],
  spec: [
    { id: 'co_status', g: 'brief', t: 'Status of every original finding', d: 'Closed / Partially closed / Still open, with the designer\'s response.', html: () => H.tbl(['ID', 'Finding', 'Designer response', 'Status'], [['M1', 'Inverts out of sequence', 'Revised on rev 19', H.res('Closed')], ['M2', 'Schedule incomplete', 'Partly revised', H.res('Partially closed')], ['M3', 'Outfall beyond site', 'No response yet', H.res('Still open')]]) },
    { id: 'co_donut', g: 'brief', idea: 1, ex: 1, t: 'Close-out status chart', d: 'How many items are closed, partially closed and open.', html: () => H.donut(12, 6, 4) },
    { id: 'co_new', g: 'fe', t: 'New findings on the revised drawings', d: 'Anything new, marked New, kept separate from the original list.', html: () => H.tbl(['ID', 'Finding', 'Status'], [['N1', 'New gully on revised layout has no connection shown', '<span class="rs fl">New</span>']]) },
    { id: 'co_before', g: 'fe', idea: 1, t: 'Before and after drawing extracts', d: 'Old and revised drawing side by side for each closed item.', html: () => `<div class="ba"><div><b>Rev 18</b><div class="ph2"></div></div><div><b>Rev 19</b><div class="ph2"></div></div></div><p class="cap">Cropped from the two revisions with the change ringed.</p>` },
    { id: 'co_reg', g: 'app', t: 'Revision comparison register', d: 'Each sheet, revision at audit and at close-out, what changed.', html: () => H.tbl(['Sheet', 'At audit', 'Now', 'Changed'], [['C-002', '17', '18', 'Inverts S36–S38'], ['C-700', 'T2', 'T3', 'Schedule extended']]) },
    { id: 'co_water', g: 'fe', t: '"Where the water goes" narrative', d: 'Plain description of each catchment\'s route to its outfall.', html: () => `<p>Roofs discharge through rainwater pipes into private storm drains, some feeding permeable paving with diffuser units under drives and courts. No storage depths, volumes or outlets are given for these.</p>` },
  ],
});

T({ id: 'da-cus', grp: 'Custom & add-ons', name: 'Custom report', code: 'DA-CUS', sample: ['real', 'Real: DA-CUS-009-01 Road Strings vs Setting Out'],
  about: 'One-off reports. These are the parts that would stay fixed on every custom report; the middle changes per job.',
  title: 'Road Strings vs Setting Out Check', ref: 'DA-CUS-009-01', project: 'Wixams R5 – adoptable roads N1 to N10', info: 'XRP001 rev 0 · ENG-260 Rev A · ENG-540 to 546 · 3D STRINGS.DWG',
  glance: '7 findings: 1 high, 2 medium, 4 low. Road N4 setting-out levels are up to 235 mm above the long section and 3D strings.',
  counts: [1, 2, 4], exposure: '£14,000 – £70,000',
  top: ['H1 – confirm which levels govern on Road N4.', 'M1 – Road N1 south end: 17 m of the report not in the strings.', 'M2 – Road N8 entry ramp not in the report.'],
  qs: ['Which levels govern on Road N4? (H1)', 'Is the N1 south end in a later revision of the strings? (M1)', 'Can the chainage origin be stated on both documents? (M2)'],
  F: [['H1', 'H', 'Road N4 setting-out levels up to 235 mm above the strings', 'Road N4', 2], ['M1', 'M', 'Road N1: 17 m of the report not in the strings', 'N1 south end', 3], ['M2', 'M', 'Road N8 entry 95 mm above the strings; ramp not in the report', 'N8', 4], ['L1', 'L', 'Kerb layout supplied is for a different phase', '705 A', 6]],
  limits: 'Setting-out report rev 0 (2020) compared with current strings and long sections.', next: ['Designer responses', 'Re-issue of the setting-out report'],
  w: { t: 'H1 · Road N4 setting-out levels up to 235 mm above the strings', p: 'H', rows: [['Drawing reference', 'Setting Out report XRP001 rev 0, Appendix E; ENG-260 Rev A'], ['What the drawings show', 'Report levels on N4 rise above the long section and strings, up to 235 mm.'], ['Why it matters', 'Roads set out from the report would be built well above design.'], ['Possible reason', 'Report predates a level change on N4.'], ['Query for the designer to confirm', 'Which levels govern on Road N4?'], ['Cost if missed', 'High']] },
  passed: [['3D strings against long sections', '777 levels on 14 roads: 747 within 6 mm (726 within 2 mm)']], dwgs: [['XRP001', 'Adoptable Road Setting Out report', '0'], ['ENG-540 to 546', 'Long Sections', 'D']],
  spec: [
    { id: 'cus_long', g: 'fe', t: 'Long section comparison chart', d: 'Report levels against long section and strings along a road.', ...IMG('cus-p2.jpg') },
    { id: 'cus_plan', g: 'fe', t: 'Plan figure: points beyond the strings', d: 'Report points plotted on the layout against the strings.', ...IMG('cus-p3.jpg') },
    { id: 'cus_brief', g: 'app', idea: 1, t: '"Scope of this report" box', d: 'The one-off brief written out, so the reader knows what was and wasn\'t asked.', html: () => `<div class="box"><b>Scope of this report</b><p>Compare the adoptable road setting-out report and junction table with the current long sections and 3D strings for roads N1 to N10. Kerb lines and drainage not in scope.</p></div>` },
  ],
});

T({ id: 'da-flow', grp: 'Custom & add-ons', name: 'Surface Water Flow Model', code: 'DA-FLOW', sample: ['real', 'Real: DA-FLOW-7562-YZ-AB-01 kerbs as built'], noBrief: 1,
  about: 'Interactive 3D page of the roads showing where rain stands. Sections here are parts of the page, not PDF pages.',
  title: 'Surface Water Flow Model', ref: 'DA-FLOW-7562-YZ-AB-01', project: 'Whitehouse YZ – roads 1, 3, 4, 5, 7',
  spec: [
    { id: 'fl_view', g: 'fe', t: '3D view of the roads', d: 'True-scale model, every gully on the drawing.', ...IMG('flow-view.jpg') },
    { id: 'fl_full', g: 'fe', t: 'Whole page layout', d: 'Heading, model, controls, finding cards and method.', ...IMG('FLOW-YZ-1.jpg') },
    { id: 'fl_cards', g: 'fe', t: 'Finding cards (depth, area, litres, nearest gully)', d: 'One card per place holding water, with the query.', html: () => `<div class="fc"><b>1 · Road 5 ch 0 left</b> <span class="rs bd">23 mm deep</span><p>Area 2.3 m² · Held 16 L · Nearest gully 5.1 m</p><p>Query to confirm: is a gully needed at this low point?</p></div>` },
    { id: 'fl_ctrl', g: 'fe', t: 'Controls: rain, gullies at low points, drawings, labels, height', d: 'All the switches on the page.', html: () => `<div class="tabsx"><span>Rain off / light / heavy</span><span>As modelled / add gullies</span><span>Drawings under the model</span><span>Labels</span><span>Height ×4</span></div>` },
    { id: 'fl_kerbs', g: 'fe', t: 'Kerbs as built table', d: 'Median and range of kerbs against the drawing per road.', html: () => H.tbl(['Road', 'Area m²', 'Median mm', 'Range mm'], [['Road 1', '969', '−2', '−59 to +14'], ['Road 3', '506', '+21', '+11 to +44'], ['Road 5', '118', '+26', '−64 to +46']], { num: [1, 2] }) },
    { id: 'fl_method', g: 'fe', t: 'How the model was built, and its limits', d: 'Surface, lay-bys, junctions, ends, gullies, accuracy.', html: () => `<ul><li><b>Surface.</b> Drawn levels moved to the kerb tops as built.</li><li><b>Gullies.</b> A gully takes water within 300 mm of its grate.</li><li><b>Accuracy.</b> Kerb tops read to about ±5 mm.</li></ul>` },
    { id: 'fl_pdf', g: 'fe', idea: 1, t: 'PDF snapshot with the report', d: 'A one-page PDF of the model\'s findings to attach to emails.', ...IMG('FLOW-009-1.jpg', 'Page header as it would print') },
    { id: 'fl_copy', g: 'fe', t: 'Copyright footer', d: '© Dimensure, confidential, ref and issue at the foot of the page.', html: () => `<p class="cap">© 2026 Dimensure. Confidential. Ref DA-FLOW-7562-YZ-AB-01, issue 1.</p>` },
  ],
});

T({ id: 'sv', grp: 'Custom & add-ons', name: 'Stockpile Volumes (drone)', code: 'SV', sample: ['real', 'Real: SV-435-01 Whitehouse YZ (draft)'], noBrief: 1,
  about: 'Drone survey of stockpiles with volumes per heap.',
  title: 'Stockpile Volumes', ref: 'SV-435-01', project: 'Whitehouse YZ – aerial survey 31 October 2026',
  spec: [
    { id: 'sv_plan', g: 'fe', t: 'Drone plan with heaps outlined and coloured by height', d: 'Every heap numbered on the aerial surface.', ...IMG('sv-p1.jpg') },
    { id: 'sv_tiles', g: 'brief', t: 'Total volume and footprint', d: 'Headline figures.', html: () => `<div class="kpi"><div><b>7,230 m³</b><span>Total volume, 11 heaps</span></div><div><b>4,369 m²</b><span>Total heap footprint</span></div></div>` },
    { id: 'sv_tbl', g: 'brief', t: 'Volume table by heap', d: 'Footprint, max height and volume per heap.', html: () => H.tbl(['Heap', 'Footprint m²', 'Max height m', 'Volume m³'], [['Heap 1', '318', '2.5', '262'], ['Heap 5', '2,415', '5.1', '5,847'], ['Heap 10', '362', '1.3', '250'], ['Total (11 heaps)', '4,369', '–', '7,230']], { num: [1, 2, 3] }) },
    { id: 'sv_notes', g: 'fe', t: 'Method notes and accuracy', d: 'Base surface, numbering, resolution, sensitivity.', html: () => `<ol><li>Volumes from the surveyed surface down to a base fitted to the ground around each toe.</li><li>Surface model resolution 5 cm; ground sample distance 2.5 cm from 406 images.</li><li>Small heaps are the most sensitive.</li></ol>` },
    { id: 'sv_draft', g: 'brief', t: 'Draft / not tied to datum warning', d: 'Shown when flown without ground control.', html: () => `<div class="box warn">Draft · indicative volumes. Captured without ground control points, so not yet tied to OS grid or datum. Not for valuation or payment.</div>` },
    { id: 'sv_tonnes', g: 'fe', idea: 1, ex: 1, t: 'Tonnage at a stated density', d: 'Volume converted to tonnes for each material type.', html: () => H.tbl(['Heap', 'Material', 'Volume m³', 'Density t/m³', 'Tonnes'], [['Heap 5', 'Topsoil', '5,847', '1.6', '9,355']], { num: [2, 3, 4] }) + '<p class="cap">Example density.</p>' },
    { id: 'sv_change', g: 'fe', idea: 1, ex: 1, t: 'Change since the last survey', d: 'Heap-for-heap difference against the previous flight.', html: () => H.tbl(['Heap', 'Last survey m³', 'This survey m³', 'Change'], [['Heap 5', '6,210', '5,847', '−363'], ['Heap 1', '180', '262', '+82']], { num: [1, 2, 3] }) },
    { id: 'sv_details', g: 'fe', t: 'Survey details and copyright page', d: 'Capture, client, project, date, reference and copyright.', html: () => H.tbl(['Capture', 'Aerial photography, 31 October 2026'], [['Reference', 'SV-435-01'], ['Issue', 'Draft']]) },
  ],
});
