#!/usr/bin/env node
// Build article PDFs and issue cover PNGs from seed/content/journal.json + generator/bodies.json.
// Usage: node build_assets.mjs [--only <slug>] [--no-covers] [--no-pdfs]
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
const doCovers = !args.includes('--no-covers') && !only;
const doPdfs = !args.includes('--no-pdfs');

const data = JSON.parse(fs.readFileSync(JSON_PATH, 'utf8'));
const bodies = JSON.parse(fs.readFileSync(path.join(HERE, 'bodies.json'), 'utf8'));
const J = data.journal;

// ---------------------------------------------------------------- helpers
const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const C = { navy: '#14213D', burgundy: '#8C1C2C', gold: '#B08D57', slate: '#5B6B8C', ink: '#1d1d1f', muted: '#6b6f7a', rule: '#d9d4c7' };

let FONT_CSS = null;
function fontFaces() {
  if (FONT_CSS) return FONT_CSS;
  // fonts are inlined as data: URIs (pages are loaded via setContent, where file:// URLs are blocked)
  const f = (file) => `data:font/woff2;base64,${fs.readFileSync(path.join(FONT_DIR, file)).toString('base64')}`;
  const CYR = 'U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116';
  const LAT = 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD';
  const out = [];
  for (const [w, st, base] of [[400, 'normal', 'source-serif-4-%s-400-normal'], [400, 'italic', 'source-serif-4-%s-400-italic'], [600, 'normal', 'source-serif-4-%s-600-normal'], [700, 'normal', 'source-serif-4-%s-700-normal']]) {
    for (const [sub, rng] of [['latin', LAT], ['cyrillic', CYR]]) out.push(`@font-face{font-family:'Source Serif 4';font-style:${st};font-weight:${w};src:url('${f(base.replace('%s', sub) + '.woff2')}') format('woff2');unicode-range:${rng};}`);
  }
  for (const w of [400, 500, 600]) {
    for (const [sub, rng] of [['latin', LAT], ['cyrillic', CYR]]) out.push(`@font-face{font-family:'InterW';font-style:normal;font-weight:${w};src:url('${f(`inter-${sub}-${w}-normal.woff2`)}') format('woff2');unicode-range:${rng};}`);
  }
  FONT_CSS = out.join('\n');
  return FONT_CSS;
}

const L = {
  ru: {
    vol: (i) => `Т. ${i.volume}, № ${i.number} (${i.year})`, pp: 'С.', received: 'Поступила', accepted: 'Принята', published: 'Опубликована', submitted: 'Подана',
    abstract: 'Аннотация', keywords: 'Ключевые слова', corr: 'Автор для переписки', funding: 'Финансирование',
    intro: 'Введение', methods: 'Материалы и методы', results: 'Результаты', discussion: 'Обсуждение', conclusion: 'Заключение', refs: 'Список литературы',
    cite: 'Для цитирования', manuscript: 'Рукопись на рассмотрении', manuscriptNote: 'Рукопись представлена в редакцию и находится в процессе редакционной обработки. Не для цитирования.',
    sections: { ART: 'Научная статья', REV: 'Обзор', SHC: 'Краткое сообщение' }, licence: 'Распространяется по лицензии CC BY 4.0',
  },
  en: {
    vol: (i) => `Vol. ${i.volume}, No. ${i.number} (${i.year})`, pp: 'pp.', received: 'Received', accepted: 'Accepted', published: 'Published', submitted: 'Submitted',
    abstract: 'Abstract', keywords: 'Keywords', corr: 'Corresponding author', funding: 'Funding',
    intro: 'Introduction', methods: 'Materials and Methods', results: 'Results', discussion: 'Discussion', conclusion: 'Conclusion', refs: 'References',
    cite: 'For citation', manuscript: 'Manuscript under review', manuscriptNote: 'This manuscript has been submitted to the journal and is undergoing editorial processing. Not for citation.',
    sections: { ART: 'Research Article', REV: 'Review', SHC: 'Short Communication' }, licence: 'Licensed under CC BY 4.0',
  },
};

function fmtDate(iso, loc) {
  const [y, m, d] = iso.split('-').map(Number);
  if (loc === 'ru') return `${String(d).padStart(2, '0')}.${String(m).padStart(2, '0')}.${y}`;
  const mon = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][m - 1];
  return `${d} ${mon} ${y}`;
}
const num = (v, loc) => (loc === 'ru' ? String(v).replace('.', ',') : String(v));
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
function articleHTML(a, ctx) {
  const loc = a.locale, alt = loc === 'ru' ? 'en' : 'ru', T = L[loc];
  const b = bodies[a.slug];
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
      : `${a.authors.map((x) => initials(x, 'en')).join(', ')} ${esc(a.title.en)}. <i>${esc(J.abbreviation.en)}</i> ${issue.year};${issue.volume}(${issue.number}):${pages}.`)
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
body { margin: 0; font-family: 'Source Serif 4', serif; font-size: 9.3pt; line-height: 1.4; color: ${C.ink}; -webkit-print-color-adjust: exact; }
.mast { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2.2pt solid ${C.navy}; padding-bottom: 2.4mm; margin-bottom: 1mm; }
.mast .jn { font: 600 13pt 'Source Serif 4', serif; color: ${C.navy}; letter-spacing: .01em; }
.mast .jn small { display: block; font: 500 7pt InterW, sans-serif; letter-spacing: .14em; text-transform: uppercase; color: ${C.gold}; margin-bottom: 1mm; }
.mast .meta { text-align: right; font: 400 7.6pt/1.5 InterW, sans-serif; color: ${C.navy}; }
.mast .meta b { color: ${C.burgundy}; font-weight: 600; }
.subrule { border-top: .6pt solid ${C.gold}; margin-bottom: 6mm; }
.kind { font: 600 7.6pt InterW, sans-serif; letter-spacing: .16em; text-transform: uppercase; color: ${C.burgundy}; margin-bottom: 2.5mm; }
.kind.ms { color: #fff; background: ${C.burgundy}; display: inline-block; padding: 1mm 2.5mm; border-radius: 1mm; margin-left: 2mm; letter-spacing: .1em; }
h1 { font: 700 17pt/1.18 'Source Serif 4', serif; color: ${C.navy}; margin: 0 0 1.5mm; }
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

  const issn = `ISSN ${J.onlineIssn} (${loc === 'ru' ? 'онлайн' : 'online'})`;
  const mastMeta = published
    ? `${T.vol(issue)}<br><b>${T.pp} ${pages}</b> · ${issn}`
    : `${issn}<br><b>${T.manuscript}</b>`;
  const sec = (n, key, html) => `<h3><span class="n">${n}</span>${T[key]}</h3>${html}`;

  return `<!doctype html><html lang="${loc}"><head><meta charset="utf-8"><title>${esc(a.title[loc])}</title><style>${css}</style></head><body>
<header class="mast"><div class="jn"><small>${loc === 'ru' ? esc(J.publisherInstitution) : 'Academia Research Publishing Centre'}</small>${esc(J.name[loc])}</div><div class="meta">${mastMeta}</div></header>
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

function countryName(code, lc) {
  const m = { RU: ['Россия', 'Russia'], KZ: ['Казахстан', 'Kazakhstan'], DE: ['Германия', 'Germany'], RS: ['Сербия', 'Serbia'], BY: ['Беларусь', 'Belarus'], AM: ['Армения', 'Armenia'], UZ: ['Узбекистан', 'Uzbekistan'], NO: ['Норвегия', 'Norway'] };
  return (m[code] || [code, code])[lc === 'ru' ? 0 : 1];
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
function coverHTML(issue, idx) {
  const accents = ['#B08D57', '#5E9EA0', '#B4505E'];
  const acc = accents[idx % accents.length];
  const motif = [motifContours, motifLattice, motifNetwork][idx % 3](acc);
  return `<!doctype html><html><head><meta charset="utf-8"><style>${fontFaces()}
html,body{margin:0;width:600px;height:848px;overflow:hidden}
body{background:${C.navy};color:#F3EEE3;font-family:'Source Serif 4',serif;position:relative}
.frame{position:absolute;inset:22px;border:1px solid ${C.gold}}
.frame2{position:absolute;inset:28px;border:.5px solid rgba(176,141,87,.55)}
.top{position:absolute;top:58px;left:60px;right:60px;display:flex;justify-content:space-between;font:500 10.5px InterW,sans-serif;letter-spacing:.2em;color:${C.gold};text-transform:uppercase}
.name{position:absolute;top:104px;left:60px;right:60px;font-weight:600;font-size:40px;line-height:1.12;letter-spacing:.005em}
.rule{position:absolute;top:262px;left:60px;width:96px;height:2px;background:${acc}}
.vol{position:absolute;top:282px;left:60px;font:500 15px InterW,sans-serif;letter-spacing:.16em;color:${C.gold}}
.motif{position:absolute;top:322px;left:29px;right:29px;height:262px;overflow:hidden}.motif svg{margin-left:-29px;display:block;width:600px;height:262px}
.theme{position:absolute;left:60px;right:60px;bottom:128px;font-style:italic;font-size:25px;line-height:1.25;color:#fff}
.theme small{display:block;font:500 9.5px InterW,sans-serif;font-style:normal;letter-spacing:.24em;color:${acc};margin-bottom:10px;text-transform:uppercase}
.pub{position:absolute;left:60px;right:60px;bottom:58px;border-top:1px solid ${C.gold};padding-top:14px;display:flex;justify-content:space-between;font:400 11px InterW,sans-serif;color:#D9D2C3;letter-spacing:.04em}
</style></head><body>
<div class="frame"></div><div class="frame2"></div>
<div class="top"><span>ISSN ${J.onlineIssn}</span><span>${esc(J.acronym.ru)} · Открытый доступ</span></div>
<div class="name">${esc(J.name.ru)}</div>
<div class="rule"></div>
<div class="vol">ТОМ ${issue.volume} · № ${issue.number} · ${issue.year}</div>
<div class="motif">${motif}</div>
<div class="theme"><small>Тема выпуска</small>${esc(issue.title.ru)}</div>
<div class="pub"><span>${esc(J.publisherInstitution)}</span><span>${esc(J.abbreviation.ru)}</span></div>
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
    p += `<path d="${d}Z" fill="none" stroke="${k % 4 === 0 ? acc : '#B08D57'}" stroke-opacity="${k % 4 === 0 ? 0.85 : 0.32}" stroke-width="${k % 4 === 0 ? 1.2 : 0.7}"/>`;
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
    p += `<path d="${d}Z" fill="${hl ? acc : 'none'}" fill-opacity="${hl ? 0.28 * op : 0}" stroke="${hl ? acc : '#B08D57'}" stroke-opacity="${(hl ? 0.9 : 0.38) * op}" stroke-width="${hl ? 1.1 : 0.7}"/>`;
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
    p += `<path d="${d}" fill="none" stroke="#B08D57" stroke-opacity="${0.18 + w * 0.05}" stroke-width="0.8"/>`;
  }
  nodes.forEach((a, i) => nodes.forEach((b, j) => {
    if (j <= i) return;
    const dd = Math.hypot(a[0] - b[0], a[1] - b[1]);
    if (dd < 88) p += `<line x1="${a[0].toFixed(1)}" y1="${a[1].toFixed(1)}" x2="${b[0].toFixed(1)}" y2="${b[1].toFixed(1)}" stroke="#B08D57" stroke-opacity="${(0.55 - dd / 200).toFixed(2)}" stroke-width="0.7"/>`;
  }));
  nodes.forEach((a, i) => { p += `<circle cx="${a[0].toFixed(1)}" cy="${a[1].toFixed(1)}" r="${i % 5 === 0 ? 4 : 2.2}" fill="${i % 5 === 0 ? acc : '#E8DCC4'}" fill-opacity="${i % 5 === 0 ? 0.95 : 0.7}"/>`; });
  return `<svg viewBox="0 0 600 330" preserveAspectRatio="xMidYMid slice">${p}</svg>`;
}

// ---------------------------------------------------------------- main
const browser = await chromium.launch();
const page = await browser.newPage();
fs.mkdirSync(path.join(ASSETS, 'pdf'), { recursive: true });
fs.mkdirSync(path.join(ASSETS, 'covers'), { recursive: true });

if (doPdfs) {
  // pass 1: measure page counts and assign sequential page ranges per issue
  let changed = false;
  if (!only) {
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
  // pass 2: final render
  const jobs = [
    ...data.issues.flatMap((issue) => issue.articles.map((a) => [a, { issue }])),
    ...data.submissions.map((a) => [a, { issue: null }]),
  ].filter(([a]) => !only || a.slug === only);
  for (const [a, ctx] of jobs) {
    const out = path.join(ASSETS, a.pdf);
    const n = await renderPdf(page, articleHTML(a, ctx), out);
    const exp = a.pages ? a.pages.split('-').reduce((x, y) => y - x + 1) : null;
    const warn = ctx.issue && exp !== n ? `  WARNING: expected ${exp} pages` : '';
    console.log(`${a.pdf}  ${n} pp  ${(fs.statSync(out).size / 1024).toFixed(0)} KB${warn}`);
  }
}

if (doCovers) {
  await page.setViewportSize({ width: 600, height: 848 });
  for (const [i, issue] of data.issues.entries()) {
    await page.setContent(coverHTML(issue, i), { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);
    const out = path.join(ASSETS, issue.cover);
    await page.screenshot({ path: out, clip: { x: 0, y: 0, width: 600, height: 848 } });
    console.log(`${issue.cover}  ${(fs.statSync(out).size / 1024).toFixed(0)} KB`);
  }
}
await browser.close();
