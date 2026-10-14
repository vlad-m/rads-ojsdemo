#!/usr/bin/env node
// Build article PDFs and issue cover PNGs from seed/content/journal.json + generator/bodies.json.
// Usage: node build_assets.mjs [--only <slug>] [--locale <locale>] [--no-covers] [--no-pdfs] [--web-only]
// One PDF per article and language (journal.json "pdf": {locale: path}) and one cover per issue and language.
// Requires the global playwright module and Chromium in $PLAYWRIGHT_BROWSERS_PATH.
import { createRequire } from 'module';
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const require = createRequire(import.meta.url);
const globalRoot = execSync('npm root -g').toString().trim();
const { chromium } = require(path.join(globalRoot, 'playwright'));

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SEED = path.dirname(HERE);
const JSON_PATH = path.join(SEED, 'content', 'journal.json');
const ASSETS = path.join(SEED, 'assets');
const FONT_DIR = path.resolve(SEED, '..', 'plugins', 'themes', 'academicJournal', 'fonts');
const FIRST_PAGE = 5; // journal pagination within each issue starts after the front matter

const args = process.argv.slice(2);
const only = args.includes('--only') ? args[args.indexOf('--only') + 1] : null;
const onlyLocale = args.includes('--locale') ? args[args.indexOf('--locale') + 1] : null;
const doCovers = !args.includes('--no-covers') && !only && !args.includes('--web-only');
const doPdfs = !args.includes('--no-pdfs');
const webOnly = args.includes('--web-only'); // only the HTML and JATS XML editions

const data = JSON.parse(fs.readFileSync(JSON_PATH, 'utf8'));
const bodies = JSON.parse(fs.readFileSync(path.join(HERE, 'bodies.json'), 'utf8')); // {slug: {locale: body}}
const LABELS = JSON.parse(fs.readFileSync(path.join(HERE, 'labels.json'), 'utf8')); // {locale: {key: text}}
const J = data.journal;

// ---------------------------------------------------------------- helpers
const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const C = { navy: '#00507A', burgundy: '#0072A6', gold: '#F7901E', slate: '#5B7A91', ink: '#1d1d1f', muted: '#6b6f7a', rule: '#d9d4c7' };

let FONT_CSS = null;
function fontFaces() {
  if (FONT_CSS) return FONT_CSS;
  // fonts are inlined as data: URIs (pages are loaded via setContent, where file:// URLs are blocked)
  const f = (file) => `data:font/woff2;base64,${fs.readFileSync(path.join(FONT_DIR, file)).toString('base64')}`;
  const RANGES = {
    latin: 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
    'latin-ext': 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF',
    cyrillic: 'U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116',
    'cyrillic-ext': 'U+0460-052F, U+1C80-1C8A, U+20B4, U+2DE0-2DFF, U+A640-A69F, U+FE2E-FE2F',
    greek: 'U+0370-0377, U+037A-037F, U+0384-038A, U+038C, U+038E-03A1, U+03A3-03FF',
  };
  const out = [];
  const face = (family, file, w, st) => {
    for (const [sub, rng] of Object.entries(RANGES)) {
      const name = file.replace('%s', sub) + '.woff2';
      if (fs.existsSync(path.join(FONT_DIR, name))) out.push(`@font-face{font-family:'${family}';font-style:${st};font-weight:${w};src:url('${f(name)}') format('woff2');unicode-range:${rng};}`);
    }
  };
  for (const [w, st] of [[400, 'normal'], [400, 'italic'], [600, 'normal'], [700, 'normal']]) face('Source Serif 4', `source-serif-4-%s-${w}-${st}`, w, st);
  for (const w of [400, 500, 600]) face('InterW', `inter-%s-${w}-normal`, w, 'normal');
  for (const w of [500, 600, 700]) face('BrandW', `montserrat-%s-${w}-normal`, w, 'normal');
  FONT_CSS = out.join('\n');
  return FONT_CSS;
}

const L = Object.fromEntries(Object.entries(LABELS).map(([loc, t]) => [loc, {
  ...t,
  vol: (i) => t.vol.replace('{volume}', i.volume).replace('{number}', i.number).replace('{year}', i.year),
  coverVol: (i) => t.coverVol.replace('{volume}', i.volume).replace('{number}', i.number).replace('{year}', i.year),
  sections: { ART: t.ART, REV: t.REV, SHC: t.SHC },
}]));
const bcp47 = (loc) => loc.replace('_', '-');
const DOT_DECIMAL = new Set(['en', 'zh_Hans', 'mn']);

function fmtDate(iso, loc) {
  const [y, m, d] = iso.split('-').map(Number);
  if (loc === 'ru') return `${String(d).padStart(2, '0')}.${String(m).padStart(2, '0')}.${y}`;
  if (loc === 'en') {
    const mon = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][m - 1];
    return `${d} ${mon} ${y}`;
  }
  return new Intl.DateTimeFormat(bcp47(loc), { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(Date.UTC(y, m - 1, d));
}
const num = (v, loc) => (DOT_DECIMAL.has(loc) ? String(v) : String(v).replace('.', ','));
const dashPages = (p) => String(p || '').replace('-', '–');
const initials = (a, loc) => `${a.familyName[loc]} ${a.givenName[loc][0]}.`;

// ---------------------------------------------------------------- charts (inline SVG)
function chartSVG(fig, loc) {
  const W = 520, H = 230, padL = 46, padR = 12, padT = 14, padB = fig.kind === 'hbar' ? 26 : 44;
  const pal = [C.navy, C.burgundy, C.gold, C.slate];
  const all = fig.series.flatMap((s) => s.values);
  const maxV = Math.max(...all);
  const step = niceStep(maxV / 4);
  const top = Math.ceil(maxV / step) * step;
  let svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" width="${W * 0.86}" height="${H * 0.86}" font-family="InterW, Inter, sans-serif" font-size="9.5">`;
  if (fig.kind === 'hbar') {
    const lab = 150, plotW = W - lab - padR - 30, n = fig.x.length, bandH = (H - padT - padB) / n;
    for (let t = 0; t <= top + 1e-9; t += step) {
      const x = lab + (t / top) * plotW;
      svg += `<line x1="${x}" y1="${padT}" x2="${x}" y2="${H - padB}" stroke="#e4e1d8" stroke-width="0.7"/><text x="${x}" y="${H - padB + 13}" text-anchor="middle" fill="${C.muted}">${num(+t.toFixed(2), loc)}</text>`;
    }
    fig.x.forEach((c, i) => {
      const v = fig.series[0].values[i], y = padT + i * bandH + bandH * 0.18, h = bandH * 0.64, w = (v / top) * plotW;
      svg += `<text x="${lab - 8}" y="${y + h / 2 + 3.5}" text-anchor="end" fill="${C.ink}">${esc(c)}</text><rect x="${lab}" y="${y}" width="${w}" height="${h}" fill="${i === 0 ? C.burgundy : C.navy}" rx="1"/><text x="${lab + w + 5}" y="${y + h / 2 + 3.5}" fill="${C.ink}" font-weight="600">${num(v, loc)}</text>`;
    });
    if (fig.ylabel) svg += `<text x="${lab + plotW / 2}" y="${H - 2}" text-anchor="middle" fill="${C.muted}">${esc(fig.ylabel)}</text>`;
    return svg + '</svg>';
  }
  const plotW = W - padL - padR, plotH = H - padT - padB, n = fig.x.length;
  const y = (v) => padT + plotH - (v / top) * plotH;
  for (let t = 0; t <= top + 1e-9; t += step) {
    svg += `<line x1="${padL}" y1="${y(t)}" x2="${W - padR}" y2="${y(t)}" stroke="#e4e1d8" stroke-width="0.7"/><text x="${padL - 6}" y="${y(t) + 3.5}" text-anchor="end" fill="${C.muted}">${num(+t.toFixed(2), loc)}</text>`;
  }
  svg += `<line x1="${padL}" y1="${padT + plotH}" x2="${W - padR}" y2="${padT + plotH}" stroke="${C.ink}" stroke-width="0.8"/>`;
  const band = plotW / n;
  const rot = fig.x.some((c) => c.length * 5.4 > band - 6);
  fig.x.forEach((c, i) => {
    const cx = padL + band * (i + 0.5);
    svg += rot ? `<text transform="translate(${cx},${padT + plotH + 12}) rotate(-25)" text-anchor="end" fill="${C.ink}">${esc(c)}</text>` : `<text x="${cx}" y="${padT + plotH + 14}" text-anchor="middle" fill="${C.ink}">${esc(c)}</text>`;
  });
  if (fig.kind === 'bar') {
    const k = fig.series.length, bw = (band * 0.7) / k;
    fig.series.forEach((s, si) => s.values.forEach((v, i) => {
      const x = padL + band * i + band * 0.15 + si * bw;
      svg += `<rect x="${x}" y="${y(v)}" width="${bw - 1.5}" height="${padT + plotH - y(v)}" fill="${pal[si]}" rx="1"/>`;
      if (k === 1 || n <= 5) svg += `<text x="${x + (bw - 1.5) / 2}" y="${y(v) - 3}" text-anchor="middle" fill="${C.ink}" font-size="8.5">${num(v, loc)}</text>`;
    }));
  } else {
    fig.series.forEach((s, si) => {
      const pts = s.values.map((v, i) => [padL + band * (i + 0.5), y(v)]);
      svg += `<polyline points="${pts.map((p) => p.join(',')).join(' ')}" fill="none" stroke="${pal[si]}" stroke-width="1.8"/>`;
      pts.forEach(([px, py]) => { svg += `<circle cx="${px}" cy="${py}" r="2.6" fill="#fff" stroke="${pal[si]}" stroke-width="1.5"/>`; });
    });
  }
  if (fig.ylabel) svg += `<text transform="translate(11,${padT + plotH / 2}) rotate(-90)" text-anchor="middle" fill="${C.muted}">${esc(fig.ylabel)}</text>`;
  let legend = '';
  if (fig.series.length > 1) legend = `<div class="legend">${fig.series.map((s, i) => `<span><i style="background:${pal[i]}"></i>${esc(s.name)}</span>`).join('')}</div>`;
  return svg + '</svg>' + legend;
}
function niceStep(raw) {
  const p = Math.pow(10, Math.floor(Math.log10(raw)));
  const r = raw / p;
  return (r <= 1 ? 1 : r <= 2 ? 2 : r <= 2.5 ? 2.5 : r <= 5 ? 5 : 10) * p;
}

// ---------------------------------------------------------------- article HTML
function articleHTML(a, ctx, loc = a.locale) {
  // the second abstract: English, or Russian in the English edition
  const alt = loc === 'en' ? 'ru' : 'en', T = L[loc];
  const b = bodies[a.slug][loc];
  const issue = ctx.issue;
  const published = !!issue;
  const pages = dashPages(a.pages);
  const first = published && a.pages ? parseInt(a.pages, 10) : 1;

  // affiliations
  const affs = [];
  const authorAff = a.authors.map((au) => {
    const key = au.affiliation[loc];
    let i = affs.findIndex((x) => x.key === key);
    if (i < 0) { affs.push({ key, country: au.country }); i = affs.length - 1; }
    return i + 1;
  });
  const single = affs.length === 1;
  const authorsLine = (lc) => a.authors.map((au, i) => `<span class="au">${esc(au.givenName[lc])} ${esc(au.familyName[lc])}${single ? '' : `<sup>${authorAff[i]}</sup>`}${au.primaryContact ? '<sup class="corr">✉</sup>' : ''}</span>`).join(', ');
  const affList = (lc) => affs.map((af, i) => {
    const au = a.authors[authorAff.indexOf(i + 1)];
    return `<div>${single ? '' : `<sup>${i + 1}</sup>`}${esc(au.affiliation[lc])}, ${countryName(af.country, lc)}</div>`;
  }).join('');
  const corr = a.authors.find((x) => x.primaryContact) || a.authors[0];

  const runRight = published ? `${T.vol(issue)} · ${T.pp} ${pages}` : T.manuscript;
  const runLeft = esc(J.name[loc]);
  const cite = published
    ? (loc === 'ru'
      ? `${a.authors.map((x) => initials(x, 'ru')).join(', ')} ${esc(a.title.ru)} // ${esc(J.abbreviation.ru)} ${issue.year}. Т. ${issue.volume}, № ${issue.number}. С. ${pages}.`
      : `${a.authors.map((x) => initials(x, loc)).join(', ')} ${esc(a.title[loc])}. <i>${esc(J.abbreviation[loc])}</i> ${issue.year};${issue.volume}(${issue.number}):${pages}.`)
    : '';

  const dates = published
    ? `<span><b>${T.received}:</b> ${fmtDate(a.dateSubmitted, loc)}</span><span><b>${T.accepted}:</b> ${fmtDate(a.dateAccepted, loc)}</span><span><b>${T.published}:</b> ${fmtDate(issue.datePublished, loc)}</span>`
    : `<span><b>${T.submitted}:</b> ${fmtDate(a.dateSubmitted, loc)}</span>`;

  const absBlock = (lc, withTitle) => `
    <section class="abs ${lc === loc ? 'primary' : 'secondary'}" lang="${lc}">
      ${withTitle ? `<div class="alt-title">${esc(a.title[lc])}${a.subtitle ? `. ${esc(a.subtitle[lc])}` : ''}</div><div class="alt-authors">${authorsLine(lc)}</div><div class="alt-affs">${affList(lc)}</div>` : ''}
      <h4>${L[lc].abstract}</h4>
      <div class="abs-text">${a.abstract[lc]}</div>
      <p class="kw"><b>${L[lc].keywords}:</b> ${a.keywords[lc].map(esc).join('; ')}</p>
    </section>`;

  const paras = (arr) => arr.map((p) => `<p>${esc(p)}</p>`).join('');
  // numeric columns (most cells look like numbers) are right-aligned
  const isNum = (c) => /^[\s\d.,%−+<>≤–—-]*\d[\s\d.,%−+<>≤–—-]*(п\. п\.|ч|мин|min|h)?$|^—$/.test(c);
  const numCol = b.table ? b.table.head.map((_, j) => j > 0 && b.table.rows.filter((r) => isNum(r[j])).length >= b.table.rows.length / 2) : [];
  const al = (j) => (numCol[j] ? ' class="num"' : '');
  const tbl = b.table ? `<figure class="tbl"><figcaption>${esc(b.table.caption)}</figcaption><table><thead><tr>${b.table.head.map((h, j) => `<th${al(j)}>${esc(h)}</th>`).join('')}</tr></thead><tbody>${b.table.rows.map((r) => `<tr>${r.map((c, j) => `<td${al(j)}>${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></figure>` : '';
  const fig = b.figure ? `<figure class="fig">${chartSVG(b.figure, loc)}<figcaption>${esc(b.figure.caption)}</figcaption></figure>` : '';
  const res = b.results || [];
  const resultsHTML = `${res[0] ? `<p>${esc(res[0])}</p>` : ''}${fig}${paras(res.slice(1))}${tbl}`;

  const css = `
${fontFaces()}
@page { size: A4; margin: 21mm 18mm 19mm 18mm;
  @top-left { content: "${runLeft}"; font: 500 7.6pt InterW, sans-serif; color: ${C.navy}; letter-spacing: .02em; vertical-align: bottom; padding-bottom: 2.5mm; border-bottom: .6pt solid ${C.navy}; }
  @top-right { content: "${runRight}"; font: 400 7.6pt InterW, sans-serif; color: ${C.burgundy}; vertical-align: bottom; padding-bottom: 2.5mm; border-bottom: .6pt solid ${C.navy}; }
  @bottom-left { content: "${T.licence}"; font: 400 7pt InterW, sans-serif; color: ${C.muted}; vertical-align: top; padding-top: 2.5mm; border-top: .4pt solid ${C.rule}; }
  @bottom-right { content: counter(page); font: 600 8pt InterW, sans-serif; color: ${C.navy}; vertical-align: top; padding-top: 2.5mm; border-top: .4pt solid ${C.rule}; }
}
@page :first { @top-left { content: none; border: none; } @top-right { content: none; border: none; } }
html { counter-reset: page ${first - 1}; }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Source Serif 4', 'WenQuanYi Zen Hei', serif; font-size: 9.3pt; line-height: 1.4; color: ${C.ink}; -webkit-print-color-adjust: exact; }
.mast { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2.2pt solid ${C.navy}; padding-bottom: 2.4mm; margin-bottom: 1mm; }
.mast .jn { font: 600 12.5pt BrandW, sans-serif; color: ${C.navy}; letter-spacing: .01em; }
.mast .jn small { display: block; font: 500 7pt InterW, sans-serif; letter-spacing: .14em; text-transform: uppercase; color: ${C.gold}; margin-bottom: 1mm; }
.mast .meta { text-align: right; font: 400 7.6pt/1.5 InterW, sans-serif; color: ${C.navy}; }
.mast .meta b { color: ${C.burgundy}; font-weight: 600; }
.subrule { border-top: .6pt solid ${C.gold}; margin-bottom: 6mm; }
.kind { font: 600 7.6pt InterW, sans-serif; letter-spacing: .16em; text-transform: uppercase; color: ${C.burgundy}; margin-bottom: 2.5mm; }
.kind.ms { color: #fff; background: ${C.burgundy}; display: inline-block; padding: 1mm 2.5mm; border-radius: 1mm; margin-left: 2mm; letter-spacing: .1em; }
h1 { font: 600 16pt/1.22 BrandW, sans-serif; color: ${C.navy}; margin: 0 0 1.5mm; }
h2.subtitle { font: 400 italic 12pt/1.25 'Source Serif 4', serif; color: ${C.navy}; margin: 0 0 3mm; }
.authors { font: 600 10.5pt 'Source Serif 4', serif; margin: 3.5mm 0 1.5mm; }
.authors sup { font: 500 6.5pt InterW, sans-serif; color: ${C.burgundy}; margin-left: .3mm; }
.affs { font: 400 8pt/1.45 InterW, sans-serif; color: ${C.muted}; }
.affs sup { color: ${C.burgundy}; margin-right: .8mm; }
.corrl { font: 400 8pt InterW, sans-serif; color: ${C.muted}; margin-top: 1.2mm; }
.corrl a { color: ${C.navy}; text-decoration: none; }
.dates { display: flex; gap: 6mm; flex-wrap: wrap; font: 400 7.8pt InterW, sans-serif; color: ${C.ink}; margin: 3.5mm 0 4mm; padding: 1.8mm 0; border-top: .5pt solid ${C.rule}; border-bottom: .5pt solid ${C.rule}; }
.dates b { color: ${C.navy}; font-weight: 600; }
.abs { padding: 3mm 4mm 2mm; margin-bottom: 3.5mm; -webkit-box-decoration-break: clone; box-decoration-break: clone; }
.abs.primary { background: #f4f1ea; border-left: 2.2pt solid ${C.navy}; }
.abs.secondary { border: .5pt solid ${C.rule}; border-left: 2.2pt solid ${C.gold}; }
.abs h4 { font: 600 7.6pt InterW, sans-serif; letter-spacing: .14em; text-transform: uppercase; color: ${C.navy}; margin: 0 0 1.2mm; }
.abs .abs-text p { margin: 0 0 1.5mm; text-align: justify; font-size: 8.6pt; line-height: 1.38; }
.abs .kw { margin: 0 0 1mm; font-size: 8.5pt; }
.abs .kw b { font: 600 7.8pt InterW, sans-serif; color: ${C.navy}; }
.alt-title { font: 600 10.5pt/1.25 'Source Serif 4', serif; color: ${C.navy}; margin-bottom: 1mm; }
.alt-authors { font: 400 italic 9pt 'Source Serif 4', serif; }
.alt-authors sup { font: 500 6pt InterW, sans-serif; color: ${C.burgundy}; }
.alt-affs { font: 400 7.4pt InterW, sans-serif; color: ${C.muted}; margin: .8mm 0 2mm; }
.alt-affs sup { color: ${C.burgundy}; margin-right: .6mm; }
.cite { font: 400 7.8pt/1.45 InterW, sans-serif; color: ${C.muted}; margin: 0 0 5mm; }
.cite b { color: ${C.navy}; font-weight: 600; }
.note { font: 400 8pt/1.45 InterW, sans-serif; color: ${C.burgundy}; border: .6pt dashed ${C.burgundy}; padding: 2mm 3mm; margin: 0 0 4mm; }
.body h3 { font: 600 8.6pt InterW, sans-serif; letter-spacing: .12em; text-transform: uppercase; color: ${C.navy}; margin: 5mm 0 2mm; padding-bottom: 1mm; border-bottom: .5pt solid ${C.rule}; break-after: avoid; }
.body h3 .n { color: ${C.burgundy}; margin-right: 2mm; }
.body p { margin: 0 0 2mm; text-align: justify; text-indent: 5mm; hyphens: auto; orphans: 3; widows: 3; }
figure { margin: 4mm 0 4.5mm; break-inside: avoid; }
figure.fig { text-align: center; padding: 3mm 0 0; border-top: .5pt solid ${C.rule}; border-bottom: .5pt solid ${C.rule}; }
figcaption { font: 400 7.9pt/1.4 InterW, sans-serif; color: ${C.ink}; text-align: left; margin: 2mm 0; }
figure.fig figcaption { text-align: center; }
.legend { font: 400 7.8pt InterW, sans-serif; display: flex; justify-content: center; gap: 5mm; margin-top: 1mm; }
.legend i { display: inline-block; width: 3mm; height: 3mm; border-radius: .5mm; margin-right: 1.3mm; vertical-align: -0.4mm; }
table { width: 100%; border-collapse: collapse; font: 400 8pt/1.35 InterW, sans-serif; }
thead th { text-align: left; font-weight: 600; color: #fff; background: ${C.navy}; padding: 1.6mm 2mm; }
tbody td { padding: 1.4mm 2mm; border-bottom: .5pt solid ${C.rule}; }
tbody tr:nth-child(even) td { background: #f7f5f0; }
td.num, th.num { text-align: right; white-space: nowrap; }
ol.refs { margin: 0; padding-left: 6mm; font-size: 8.1pt; line-height: 1.36; }
ol.refs li { margin-bottom: 1.2mm; padding-left: 1mm; }
ol.refs li::marker { color: ${C.burgundy}; font: 600 7.8pt InterW, sans-serif; }
.endmark { text-align: center; color: ${C.gold}; margin-top: 4mm; font-size: 9pt; letter-spacing: .5em; }
`;

  const issn = `ISSN ${J.onlineIssn} (${T.online})`;
  const mastMeta = published
    ? `${T.vol(issue)}<br><b>${T.pp} ${pages}</b> · ${issn}`
    : `${issn}<br><b>${T.manuscript}</b>`;
  const sec = (n, key, html) => `<h3><span class="n">${n}</span>${T[key]}</h3>${html}`;

  return `<!doctype html><html lang="${bcp47(loc)}"><head><meta charset="utf-8"><title>${esc(a.title[loc])}</title><style>${css}</style></head><body>
<header class="mast"><div class="jn"><small>${esc(T.publisher)}</small>${esc(J.name[loc])}</div><div class="meta">${mastMeta}</div></header>
<div class="subrule"></div>
<div class="kind">${T.sections[a.section]}${published ? '' : `<span class="kind ms">${T.manuscript}</span>`}</div>
<h1>${esc(a.title[loc])}</h1>
${a.subtitle ? `<h2 class="subtitle">${esc(a.subtitle[loc])}</h2>` : ''}
<div class="authors">${authorsLine(loc)}</div>
<div class="affs">${affList(loc)}</div>
<div class="corrl">✉ ${T.corr}: ${esc(corr.givenName[loc])} ${esc(corr.familyName[loc])}, <a>${esc(corr.email)}</a></div>
<div class="dates">${dates}</div>
${absBlock(loc, false)}
${absBlock(alt, true)}
${published ? `<p class="cite"><b>${T.cite}:</b> ${cite}${a.fundingAgency ? `<br><b>${T.funding}:</b> ${esc(a.fundingAgency[loc])}` : ''}</p>` : `<div class="note">${T.manuscriptNote}</div>`}
<main class="body">
${sec(1, 'intro', paras(b.intro))}
${sec(2, 'methods', paras(b.methods))}
${sec(3, 'results', resultsHTML)}
${sec(4, 'discussion', paras(b.discussion))}
${sec(5, 'conclusion', paras(b.conclusion))}
<h3>${T.refs}</h3>
<ol class="refs">${a.citations.map((c) => `<li>${esc(c)}</li>`).join('')}</ol>
<div class="endmark">◆ ◆ ◆</div>
</main>
</body></html>`;
}

// ---------------------------------------------------------------- HTML and JATS XML editions
// Shared pieces: authors with numbered affiliations, citation line, body sections.
function articleParts(a, ctx, loc) {
  const T = L[loc], b = bodies[a.slug][loc], issue = ctx.issue;
  const affs = [];
  const authorAff = a.authors.map((au) => {
    const key = au.affiliation[loc];
    let i = affs.findIndex((x) => x.key === key);
    if (i < 0) { affs.push({ key, country: au.country }); i = affs.length - 1; }
    return i + 1;
  });
  const pages = dashPages(a.pages);
  const cite = loc === 'ru'
    ? `${a.authors.map((x) => initials(x, 'ru')).join(', ')} ${a.title.ru} // ${J.abbreviation.ru} ${issue.year}. Т. ${issue.volume}, № ${issue.number}. С. ${pages}.`
    : `${a.authors.map((x) => initials(x, loc)).join(', ')} ${a.title[loc]}. ${J.abbreviation[loc]} ${issue.year};${issue.volume}(${issue.number}):${pages}.`;
  const sections = [['intro', b.intro], ['methods', b.methods], ['results', b.results || []], ['discussion', b.discussion], ['conclusion', b.conclusion]];
  return { T, b, issue, affs, authorAff, pages, cite, sections, alt: loc === 'en' ? 'ru' : 'en' };
}

function articleWebHTML(a, ctx, loc) {
  const { T, b, issue, affs, authorAff, cite, sections, alt } = articleParts(a, ctx, loc);
  const single = affs.length === 1;
  const authors = a.authors.map((au, i) => `<span class="au">${esc(au.givenName[loc])} ${esc(au.familyName[loc])}${single ? '' : `<sup>${authorAff[i]}</sup>`}</span>`).join(', ');
  const affList = affs.map((af, i) => `<li>${single ? '' : `<sup>${i + 1}</sup> `}${esc(af.key)}, ${esc(countryName(af.country, loc))}</li>`).join('');
  const corr = a.authors.find((x) => x.primaryContact) || a.authors[0];
  const paras = (arr) => arr.map((t) => `<p>${esc(t)}</p>`).join('');
  const fig = b.figure ? `<figure class="fig" id="fig1">${chartSVG(b.figure, loc)}<figcaption>${esc(b.figure.caption)}</figcaption></figure>` : '';
  const tbl = b.table ? `<figure class="tbl" id="tab1"><figcaption>${esc(b.table.caption)}</figcaption><div class="scroll"><table><thead><tr>${b.table.head.map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${b.table.rows.map((r) => `<tr>${r.map((c) => `<td>${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div></figure>` : '';
  const body = sections.map(([key, arr], n) => {
    const inner = key === 'results' ? `${arr[0] ? `<p>${esc(arr[0])}</p>` : ''}${fig}${paras(arr.slice(1))}${tbl}` : paras(arr);
    return `<section id="${key}"><h2><span class="n">${n + 1}.</span> ${esc(T[key])}</h2>${inner}</section>`;
  }).join('\n');
  const toc = sections.map(([key], n) => `<li><a href="#${key}">${n + 1}. ${esc(T[key])}</a></li>`).join('') + `<li><a href="#refs">${esc(T.refs)}</a></li>`;
  return `<!doctype html>
<html lang="${bcp47(loc)}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(a.title[loc])}</title>
<style>
:root { --primary: ${C.burgundy}; --dark: ${C.navy}; --accent: ${C.gold}; --ink: ${C.ink}; --muted: ${C.muted}; --line: #e5e1d8; }
* { box-sizing: border-box; }
body { margin: 0; background: #fbfaf7; color: var(--ink); font: 17px/1.65 "Source Serif 4", "PT Serif", Georgia, "Noto Serif", "Noto Serif CJK SC", serif; }
.wrap { max-width: 860px; margin: 0 auto; padding: 32px 24px 64px; }
.mast { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; padding-bottom: 10px; border-bottom: 3px solid var(--dark); font: 500 13px/1.4 Inter, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: var(--dark); }
.mast b { color: var(--accent); letter-spacing: .1em; text-transform: uppercase; font-size: 11px; }
.kind { margin: 28px 0 8px; font: 600 12px Inter, Arial, sans-serif; letter-spacing: .14em; text-transform: uppercase; color: var(--primary); }
h1 { margin: 0 0 6px; font: 600 32px/1.2 Montserrat, Inter, Arial, sans-serif; color: var(--dark); }
.subtitle { margin: 0 0 12px; font-style: italic; font-size: 20px; color: var(--dark); }
.authors { margin: 18px 0 4px; font-weight: 600; font-size: 18px; }
.authors sup, .affs sup { color: var(--primary); font: 600 11px Inter, Arial, sans-serif; }
.affs { margin: 0; padding: 0; list-style: none; font: 14px/1.5 Inter, Arial, sans-serif; color: var(--muted); }
.corr { font: 14px Inter, Arial, sans-serif; color: var(--muted); margin-top: 4px; }
.dates { display: flex; flex-wrap: wrap; gap: 6px 22px; margin: 18px 0; padding: 10px 0; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); font: 14px Inter, Arial, sans-serif; }
.dates b { color: var(--dark); }
.abs { margin: 0 0 18px; padding: 16px 20px; background: #f1f5f8; border-left: 4px solid var(--primary); border-radius: 4px; }
.abs.alt { background: #fff; border: 1px solid var(--line); border-left: 4px solid var(--accent); }
.abs h3, nav h3 { margin: 0 0 6px; font: 600 12px Inter, Arial, sans-serif; letter-spacing: .14em; text-transform: uppercase; color: var(--dark); }
.abs .t { font-weight: 600; color: var(--dark); margin-bottom: 6px; }
.abs p { margin: 0 0 8px; }
.kw { font-size: 15px; }
.kw b { font: 600 13px Inter, Arial, sans-serif; color: var(--dark); }
.cite { font: 14px/1.5 Inter, Arial, sans-serif; color: var(--muted); }
.cite b { color: var(--dark); }
nav { margin: 24px 0; padding: 14px 20px; background: #fff; border: 1px solid var(--line); border-top: 3px solid var(--accent); border-radius: 4px; }
nav ol { margin: 0; padding-left: 0; list-style: none; columns: 2; font: 15px/1.8 Inter, Arial, sans-serif; }
a { color: var(--primary); }
h2 { margin: 36px 0 12px; padding-bottom: 6px; border-bottom: 1px solid var(--line); font: 600 22px/1.3 Montserrat, Inter, Arial, sans-serif; color: var(--dark); }
h2 .n { color: var(--accent); }
section p { margin: 0 0 14px; text-align: justify; hyphens: auto; }
figure { margin: 24px 0; }
.fig { padding: 16px; background: #fff; border: 1px solid var(--line); border-radius: 4px; text-align: center; }
.fig svg { width: 100%; max-width: 640px; height: auto; }
.legend { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px 20px; margin-top: 6px; font: 13px Inter, Arial, sans-serif; }
.legend i { display: inline-block; width: 12px; height: 12px; border-radius: 2px; margin-right: 6px; vertical-align: -1px; }
figcaption { font: 14px/1.5 Inter, Arial, sans-serif; text-align: left; margin: 8px 0; }
.scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font: 14px/1.45 Inter, Arial, sans-serif; background: #fff; }
th { background: var(--dark); color: #fff; text-align: left; padding: 8px 10px; }
td { padding: 7px 10px; border-bottom: 1px solid var(--line); }
tbody tr:nth-child(even) td { background: #f7f5f0; }
ol.refs { padding-left: 24px; font-size: 15px; }
ol.refs li { margin-bottom: 6px; }
.licence { margin-top: 40px; padding-top: 12px; border-top: 1px solid var(--line); font: 13px Inter, Arial, sans-serif; color: var(--muted); }
@media (max-width: 600px) { body { font-size: 16px; } h1 { font-size: 25px; } nav ol { columns: 1; } .wrap { padding: 20px 16px 48px; } }
</style>
</head>
<body>
<article class="wrap">
<header class="mast"><span><b>${esc(T.publisher)}</b><br>${esc(J.name[loc])}</span><span>${esc(T.vol(issue))} · ${esc(T.pp)} ${esc(dashPages(a.pages))}<br>ISSN ${esc(J.onlineIssn)} (${esc(T.online)})</span></header>
<div class="kind">${esc(T.sections[a.section])}</div>
<h1>${esc(a.title[loc])}</h1>
${a.subtitle ? `<p class="subtitle">${esc(a.subtitle[loc])}</p>` : ''}
<p class="authors">${authors}</p>
<ul class="affs">${affList}</ul>
<p class="corr">✉ ${esc(T.corr)}: ${esc(corr.givenName[loc])} ${esc(corr.familyName[loc])}, <a href="mailto:${esc(corr.email)}">${esc(corr.email)}</a></p>
<div class="dates"><span><b>${esc(T.received)}:</b> ${fmtDate(a.dateSubmitted, loc)}</span><span><b>${esc(T.accepted)}:</b> ${fmtDate(a.dateAccepted, loc)}</span><span><b>${esc(T.published)}:</b> ${fmtDate(issue.datePublished, loc)}</span></div>
<section class="abs" lang="${bcp47(loc)}"><h3>${esc(T.abstract)}</h3>${a.abstract[loc]}<p class="kw"><b>${esc(T.keywords)}:</b> ${a.keywords[loc].map(esc).join('; ')}</p></section>
<section class="abs alt" lang="${bcp47(alt)}"><h3>${esc(L[alt].abstract)}</h3><div class="t">${esc(a.title[alt])}</div>${a.abstract[alt]}<p class="kw"><b>${esc(L[alt].keywords)}:</b> ${a.keywords[alt].map(esc).join('; ')}</p></section>
<p class="cite"><b>${esc(T.cite)}:</b> ${esc(cite)}${a.fundingAgency ? `<br><b>${esc(T.funding)}:</b> ${esc(a.fundingAgency[loc])}` : ''}</p>
<nav><ol>${toc}</ol></nav>
${body}
<section id="refs"><h2>${esc(T.refs)}</h2><ol class="refs">${a.citations.map((c) => `<li>${esc(c)}</li>`).join('')}</ol></section>
<p class="licence">${esc(T.licence)} · <a href="https://creativecommons.org/licenses/by/4.0/">CC BY 4.0</a></p>
</article>
</body>
</html>
`;
}

// JATS 1.3 (Journal Publishing tag set)
function articleJATS(a, ctx, loc) {
  const { T, b, issue, affs, authorAff, sections, alt } = articleParts(a, ctx, loc);
  const x = esc;
  const lang = (l) => bcp47(l).split('-')[0];
  const absParas = (html) => (html.match(/<p>([\s\S]*?)<\/p>/g) || [html]).map((p) => `<p>${x(p.replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"'))}</p>`).join('');
  const [p0, p1] = String(a.pages || '').split('-');
  const date = (type, iso) => { const [y, m, d] = iso.split('-'); return `<date date-type="${type}" iso-8601-date="${iso}"><day>${d}</day><month>${m}</month><year>${y}</year></date>`; };
  const [py, pm, pd] = issue.datePublished.split('-');
  const secType = { intro: 'intro', methods: 'materials|methods', results: 'results', discussion: 'discussion', conclusion: 'conclusions' };
  const fig = b.figure ? `<fig id="f1" position="float"><caption><p>${x(b.figure.caption)}</p></caption></fig>` : '';
  const tbl = b.table ? `<table-wrap id="t1" position="float"><caption><p>${x(b.table.caption)}</p></caption><table><thead><tr>${b.table.head.map((h) => `<th>${x(h)}</th>`).join('')}</tr></thead><tbody>${b.table.rows.map((r) => `<tr>${r.map((c) => `<td>${x(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></table-wrap>` : '';
  const body = sections.map(([key, arr]) => {
    const ps = arr.map((t) => `<p>${x(t)}</p>`);
    const inner = key === 'results' ? [ps[0] || '', fig, ...ps.slice(1), tbl].join('\n') : ps.join('\n');
    return `<sec id="sec-${key}" sec-type="${secType[key]}"><title>${x(T[key])}</title>\n${inner}\n</sec>`;
  }).join('\n');
  const contribs = a.authors.map((au, i) => `<contrib contrib-type="author"${au.primaryContact ? ' corresp="yes"' : ''}><name name-style="western"><surname>${x(au.familyName[loc])}</surname><given-names>${x(au.givenName[loc])}</given-names></name>${loc !== 'en' ? `<name-alternatives><name name-style="western" xml:lang="en"><surname>${x(au.familyName.en)}</surname><given-names>${x(au.givenName.en)}</given-names></name></name-alternatives>` : ''}<xref ref-type="aff" rid="aff${authorAff[i]}"/>${au.primaryContact ? `<email>${x(au.email)}</email>` : ''}</contrib>`).join('\n');
  const affXml = affs.map((af, i) => `<aff id="aff${i + 1}"><institution>${x(af.key)}</institution>, <country country="${af.country}">${x(countryName(af.country, loc))}</country></aff>`).join('\n');
  return `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE article PUBLIC "-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.3 20210610//EN" "https://jats.nlm.nih.gov/publishing/1.3/JATS-journalpublishing1-3.dtd">
<article xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:mml="http://www.w3.org/1998/Math/MathML" article-type="${a.section === 'REV' ? 'review-article' : a.section === 'SHC' ? 'brief-report' : 'research-article'}" dtd-version="1.3" xml:lang="${lang(loc)}">
<front>
<journal-meta>
<journal-id journal-id-type="publisher-id">${x(J.acronym.en)}</journal-id>
<journal-title-group><journal-title>${x(J.name[loc])}</journal-title>${loc !== 'en' ? `<trans-title-group xml:lang="en"><trans-title>${x(J.name.en)}</trans-title></trans-title-group>` : ''}<abbrev-journal-title>${x(J.abbreviation[loc])}</abbrev-journal-title></journal-title-group>
<issn publication-format="electronic">${x(J.onlineIssn)}</issn>
<publisher><publisher-name>${x(T.publisher)}</publisher-name></publisher>
</journal-meta>
<article-meta>
<article-categories><subj-group subj-group-type="heading"><subject>${x(T.sections[a.section])}</subject></subj-group></article-categories>
<title-group>
<article-title>${x(a.title[loc])}</article-title>${a.subtitle ? `\n<subtitle>${x(a.subtitle[loc])}</subtitle>` : ''}
<trans-title-group xml:lang="${lang(alt)}"><trans-title>${x(a.title[alt])}</trans-title>${a.subtitle ? `<trans-subtitle>${x(a.subtitle[alt])}</trans-subtitle>` : ''}</trans-title-group>
</title-group>
<contrib-group>
${contribs}
</contrib-group>
${affXml}
<pub-date publication-format="electronic" date-type="pub" iso-8601-date="${issue.datePublished}"><day>${pd}</day><month>${pm}</month><year>${py}</year></pub-date>
<volume>${issue.volume}</volume>
<issue>${x(issue.number)}</issue>
${p0 ? `<fpage>${p0}</fpage><lpage>${p1 || p0}</lpage>` : ''}
<history>${date('received', a.dateSubmitted)}${date('accepted', a.dateAccepted)}</history>
<permissions><copyright-statement>© ${py} ${x(a.authors.map((au) => `${au.givenName[loc]} ${au.familyName[loc]}`).join(', '))}</copyright-statement><copyright-year>${py}</copyright-year><license license-type="open-access" xlink:href="https://creativecommons.org/licenses/by/4.0/"><license-p>${x(T.licence)}</license-p></license></permissions>
<abstract xml:lang="${lang(loc)}">${absParas(a.abstract[loc])}</abstract>
<trans-abstract xml:lang="${lang(alt)}">${absParas(a.abstract[alt])}</trans-abstract>
<kwd-group xml:lang="${lang(loc)}" kwd-group-type="author">${a.keywords[loc].map((k) => `<kwd>${x(k)}</kwd>`).join('')}</kwd-group>
<kwd-group xml:lang="${lang(alt)}" kwd-group-type="author">${a.keywords[alt].map((k) => `<kwd>${x(k)}</kwd>`).join('')}</kwd-group>
${a.fundingAgency ? `<funding-group><award-group><funding-source>${x(a.fundingAgency[loc])}</funding-source></award-group></funding-group>` : ''}
</article-meta>
</front>
<body>
${body}
</body>
<back>
<ref-list><title>${x(T.refs)}</title>
${a.citations.map((c, i) => `<ref id="r${i + 1}"><mixed-citation>${x(c)}</mixed-citation></ref>`).join('\n')}
</ref-list>
</back>
</article>
`;
}

function countryName(code, lc) {
  try {
    return new Intl.DisplayNames([bcp47(lc), 'en'], { type: 'region' }).of(code) || code;
  } catch {
    return code;
  }
}

const pageCount = (buf) => (buf.toString('latin1').match(/\/Type\s*\/Page(?![s\w])/g) || []).length;

async function renderPdf(page, html, out) {
  await page.setContent(html, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  const buf = await page.pdf({ format: 'A4', printBackground: true, preferCSSPageSize: true });
  if (out) fs.writeFileSync(out, buf);
  return pageCount(buf);
}

// ---------------------------------------------------------------- covers
function coverHTML(issue, idx, loc) {
  const T = L[loc];
  const accents = ['#F7901E', '#5FC3EE', '#FFB25B'];
  const acc = accents[idx % accents.length];
  const motif = [motifContours, motifLattice, motifNetwork][idx % 3](acc);
  return `<!doctype html><html lang="${bcp47(loc)}"><head><meta charset="utf-8"><style>${fontFaces()}
html,body{margin:0;width:600px;height:848px;overflow:hidden}
body{background:linear-gradient(160deg,${C.burgundy} 0%,${C.navy} 100%);color:#fff;font-family:'Source Serif 4','WenQuanYi Zen Hei',serif;position:relative}
.frame{position:absolute;inset:22px;border:1px solid ${C.gold}}
.frame2{position:absolute;inset:28px;border:.5px solid rgba(255,255,255,.35)}
.top{position:absolute;top:58px;left:60px;right:60px;display:flex;justify-content:space-between;font:500 10.5px InterW,sans-serif;letter-spacing:.2em;color:${C.gold};text-transform:uppercase}
.name{position:absolute;top:104px;left:60px;right:60px;font-family:BrandW,sans-serif;font-weight:600;font-size:36px;line-height:1.12;letter-spacing:.005em}
.rule{position:absolute;top:262px;left:60px;width:96px;height:2px;background:${acc}}
.vol{position:absolute;top:282px;left:60px;font:500 15px InterW,sans-serif;letter-spacing:.16em;color:${C.gold};text-transform:uppercase}
.motif{position:absolute;top:322px;left:29px;right:29px;height:262px;overflow:hidden}.motif svg{margin-left:-29px;display:block;width:600px;height:262px}
.theme{position:absolute;left:60px;right:60px;bottom:128px;font-style:italic;font-size:25px;line-height:1.25;color:#fff}
.theme small{display:block;font:500 9.5px InterW,sans-serif;font-style:normal;letter-spacing:.24em;color:${acc};margin-bottom:10px;text-transform:uppercase}
.pub{position:absolute;left:60px;right:60px;bottom:58px;border-top:1px solid ${C.gold};padding-top:14px;display:flex;justify-content:space-between;font:400 11px InterW,sans-serif;color:rgba(255,255,255,.85);letter-spacing:.04em}
</style></head><body>
<div class="frame"></div><div class="frame2"></div>
<div class="top"><span>ISSN ${J.onlineIssn}</span><span>${esc(J.acronym[loc])} · ${esc(T.openAccess)}</span></div>
<div class="name">${esc((J.coverName || J.name)[loc])}</div>
<div class="rule"></div>
<div class="vol">${esc(T.coverVol(issue))}</div>
<div class="motif">${motif}</div>
<div class="theme"><small>${esc(T.coverTheme)}</small>${esc(issue.title[loc])}</div>
<div class="pub"><span>${esc(T.publisher)}</span><span>${esc(J.abbreviation[loc])}</span></div>
</body></html>`;
}

function rng(seed) { let s = seed; return () => { s = (s * 16807) % 2147483647; return (s - 1) / 2147483646; }; }

function motifContours(acc) { // topographic contour lines
  let p = '';
  for (let k = 0; k < 14; k++) {
    const r = 22 + k * 15;
    let d = '';
    for (let t = 0; t <= 64; t++) {
      const a = (t / 64) * Math.PI * 2;
      const rr = r * (1 + 0.12 * Math.sin(3 * a + k * 0.35) + 0.06 * Math.cos(5 * a - k * 0.2));
      d += `${t ? 'L' : 'M'}${(400 + rr * 1.25 * Math.cos(a)).toFixed(1)},${(165 + rr * 0.8 * Math.sin(a)).toFixed(1)}`;
    }
    p += `<path d="${d}Z" fill="none" stroke="${k % 4 === 0 ? acc : C.gold}" stroke-opacity="${k % 4 === 0 ? 0.85 : 0.32}" stroke-width="${k % 4 === 0 ? 1.2 : 0.7}"/>`;
  }
  return `<svg viewBox="0 0 600 330" preserveAspectRatio="xMidYMid slice">${p}<circle cx="400" cy="165" r="3" fill="${acc}"/></svg>`;
}
function motifLattice(acc) { // hexagonal crystal lattice fading to the left
  let p = '';
  const s = 26, h = s * Math.sqrt(3);
  for (let row = -1; row < 9; row++) for (let col = 0; col < 16; col++) {
    const cx = col * s * 1.5 + 60, cy = row * h + (col % 2 ? h / 2 : 0) + 10;
    const op = Math.max(0, Math.min(1, (cx - 120) / 420));
    if (op <= 0.03) continue;
    let d = '';
    for (let i = 0; i < 6; i++) { const a = (Math.PI / 3) * i; d += `${i ? 'L' : 'M'}${(cx + s * Math.cos(a)).toFixed(1)},${(cy + s * Math.sin(a)).toFixed(1)}`; }
    const hl = (row * 7 + col * 3) % 11 === 0;
    p += `<path d="${d}Z" fill="${hl ? acc : 'none'}" fill-opacity="${hl ? 0.28 * op : 0}" stroke="${hl ? acc : C.gold}" stroke-opacity="${(hl ? 0.9 : 0.38) * op}" stroke-width="${hl ? 1.1 : 0.7}"/>`;
    if (hl) p += `<circle cx="${cx}" cy="${cy}" r="2.4" fill="${acc}" fill-opacity="${op}"/>`;
  }
  return `<svg viewBox="0 0 600 330" preserveAspectRatio="xMidYMid slice">${p}</svg>`;
}
function motifNetwork(acc) { // network of nodes over sine waves
  const r = rng(7);
  const nodes = Array.from({ length: 34 }, () => [140 + r() * 420, 20 + r() * 290]);
  let p = '';
  for (let w = 0; w < 6; w++) {
    let d = '';
    for (let x = 0; x <= 600; x += 10) d += `${x ? 'L' : 'M'}${x},${(250 + w * 11 + 14 * Math.sin(x / 55 + w * 0.6)).toFixed(1)}`;
    p += `<path d="${d}" fill="none" stroke="${C.gold}" stroke-opacity="${0.18 + w * 0.05}" stroke-width="0.8"/>`;
  }
  nodes.forEach((a, i) => nodes.forEach((b, j) => {
    if (j <= i) return;
    const dd = Math.hypot(a[0] - b[0], a[1] - b[1]);
    if (dd < 88) p += `<line x1="${a[0].toFixed(1)}" y1="${a[1].toFixed(1)}" x2="${b[0].toFixed(1)}" y2="${b[1].toFixed(1)}" stroke="${C.gold}" stroke-opacity="${(0.55 - dd / 200).toFixed(2)}" stroke-width="0.7"/>`;
  }));
  nodes.forEach((a, i) => { p += `<circle cx="${a[0].toFixed(1)}" cy="${a[1].toFixed(1)}" r="${i % 5 === 0 ? 4 : 2.2}" fill="${i % 5 === 0 ? acc : '#E8DCC4'}" fill-opacity="${i % 5 === 0 ? 0.95 : 0.7}"/>`; });
  return `<svg viewBox="0 0 600 330" preserveAspectRatio="xMidYMid slice">${p}</svg>`;
}

// ---------------------------------------------------------------- main
const browser = await chromium.launch();
const page = await browser.newPage();
fs.mkdirSync(path.join(ASSETS, 'pdf'), { recursive: true });
fs.mkdirSync(path.join(ASSETS, 'covers'), { recursive: true });

const wanted = new Set();
if (doPdfs) {
  // pass 1: measure page counts (in the article's own language) and assign
  // sequential page ranges per issue; the other editions reuse the range
  let changed = false;
  if (!only && !webOnly) {
    for (const issue of data.issues) {
      let p = FIRST_PAGE;
      for (const a of issue.articles) {
        const n = await renderPdf(page, articleHTML({ ...a, pages: `${p}-${p + 9}` }, { issue }), null);
        const range = `${p}-${p + n - 1}`;
        if (a.pages !== range) { a.pages = range; changed = true; }
        p += n;
      }
    }
    if (changed) {
      fs.writeFileSync(JSON_PATH, JSON.stringify(data, null, 2) + '\n');
      console.log('updated page ranges in journal.json');
    }
  }
  // pass 2: final render, every language
  const jobs = [
    ...data.issues.flatMap((issue) => issue.articles.map((a) => [a, { issue }])),
    ...data.submissions.map((a) => [a, { issue: null }]),
  ].filter(([a]) => !only || a.slug === only);
  for (const [a, ctx] of jobs) {
    for (const [loc, rel] of Object.entries(a.pdf)) {
      wanted.add(path.join(ASSETS, rel));
      if ((onlyLocale && loc !== onlyLocale) || webOnly) continue;
      const out = path.join(ASSETS, rel);
      const n = await renderPdf(page, articleHTML(a, ctx, loc), out);
      const exp = a.pages ? a.pages.split('-').reduce((x, y) => y - x + 1) : null;
      const note = ctx.issue && exp !== n ? `  (${exp} pp in the original)` : '';
      console.log(`${rel}  ${n} pp  ${(fs.statSync(out).size / 1024).toFixed(0)} KB${note}`);
    }
    // web (HTML) and JATS XML editions of published articles
    for (const [kind, render] of [['html', articleWebHTML], ['xml', articleJATS]]) {
      for (const [loc, rel] of Object.entries(a[kind] || {})) {
        wanted.add(path.join(ASSETS, rel));
        if (onlyLocale && loc !== onlyLocale) continue;
        fs.mkdirSync(path.dirname(path.join(ASSETS, rel)), { recursive: true });
        fs.writeFileSync(path.join(ASSETS, rel), render(a, ctx, loc));
      }
    }
  }
}

if (doCovers) {
  await page.setViewportSize({ width: 600, height: 848 });
  for (const [i, issue] of data.issues.entries()) {
    for (const [loc, rel] of Object.entries(issue.cover)) {
      wanted.add(path.join(ASSETS, rel));
      if (onlyLocale && loc !== onlyLocale) continue;
      await page.setContent(coverHTML(issue, i, loc), { waitUntil: 'load' });
      await page.evaluate(() => document.fonts.ready);
      const out = path.join(ASSETS, rel);
      await page.screenshot({ path: out, type: 'jpeg', quality: 88, clip: { x: 0, y: 0, width: 600, height: 848 } });
      console.log(`${rel}  ${(fs.statSync(out).size / 1024).toFixed(0)} KB`);
    }
  }
}

// remove files of earlier builds that journal.json no longer refers to
if (doPdfs && doCovers && !only && !onlyLocale && !webOnly) {
  for (const dir of ['pdf', 'covers', 'html', 'xml']) {
    if (!fs.existsSync(path.join(ASSETS, dir))) continue;
    for (const f of fs.readdirSync(path.join(ASSETS, dir))) {
      const full = path.join(ASSETS, dir, f);
      if (!wanted.has(full)) { fs.unlinkSync(full); console.log(`removed ${dir}/${f}`); }
    }
  }
}
await browser.close();
