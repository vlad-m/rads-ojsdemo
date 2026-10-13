# JIR demo seed generator

Builds the demo content for the fictional journal «Вестник междисциплинарных исследований» /
Journal of Interdisciplinary Research (`jir`).

## Files

- `src/meta.json`: journal settings, sections, users, issue metadata and announcements.
- `src/articles_v*.json`, `src/submissions.json`: articles. Each one also has a `body` object
  (section paragraphs, chart data, table) that is used only for the PDF.
- `src/i18n/<locale>/NN.json`: translations into the other interface languages (and the English/Russian
  versions of the bodies), keyed like `src/i18n/_source/NN.json`. `python3 i18n.py extract` rewrites the
  sources from `src/*.json`; `python3 i18n.py check` lists what is missing per language. Personal names are
  not translated: Cyrillic-script languages use the Russian form, the others the English one.
- `assemble.py` merges the translations and writes `../content/journal.json` (with `body` removed, `pdf` and
  `cover` as `{locale: path}`, `journal.contentLocales`), `bodies.json` (`{slug: {locale: body}}`) and
  `labels.json` (the fixed words printed in PDFs and on covers).
- `build_assets.mjs` renders `../assets/pdf/<slug>.<locale>.pdf` and `../assets/covers/v<vol>n<num>.<locale>.jpg` from
  `journal.json` and `bodies.json` with headless Chromium (Playwright).
- `validate.py` checks the JSON and the assets: ru/en pairs, ISSN check digits, unique slugs,
  files exist, abstract word counts, page sequence, cover size and PDF size.

## Re-run

```bash
cd seed/generator
python3 assemble.py        # only needed after editing src/*.json
node build_assets.mjs      # all PDFs and covers (about 5 s)
python3 validate.py
```

Options: `node build_assets.mjs --only <slug>` (one PDF), `--no-covers`, `--no-pdfs`.

The script loads Playwright from the global npm root (`npm root -g`/playwright) and uses Chromium from
`$PLAYWRIGHT_BROWSERS_PATH` (here `/opt/pw-browsers`). Do not run `playwright install`.

## Notes

- **Page ranges are computed.** For each issue, pass 1 renders every article and counts its pages.
  It then gives each article a sequential range starting at page 5 and writes these `pages` values
  back into `journal.json`. Pass 2 renders the final PDFs. Running headers and footers show those
  page numbers (CSS `@page` margin boxes, with the page counter offset on `html`).
  `assemble.py` keeps any existing `pages` values.
- **Fonts.** Source Serif 4 (body) and Inter (headings and UI), latin and cyrillic subsets, come from
  `plugins/themes/academicJournal/fonts/`. They are inlined as base64 `@font-face` data URIs, because
  `file://` URLs are blocked for pages loaded with `setContent`. A few rare glyphs (κ, θ, ≤, subscript
  digits, ✉) fall back to system Liberation or DejaVu fonts.
- **Unpublished submissions** are rendered as manuscripts: a "Рукопись на рассмотрении" badge, the
  submission date only, no journal page range, and pages numbered from 1.
- **Covers** are 600×848 screenshots at device scale 1. Each issue has its own motif (contour lines,
  hexagonal lattice, network) and accent colour.
