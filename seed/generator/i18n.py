#!/usr/bin/env python3
"""Translations of the demo content into the extra interface languages.

Source texts are ru/en pairs in src/*.json; each article's body exists in one
language only. `python3 i18n.py extract` writes the texts to translate as
src/i18n/_source/<chunk>.json ({key: {"en": ..., "ru": ...}} for metadata,
{key: {"<article language>": ...}} for bodies). A translation for locale L is
stored as src/i18n/L/<chunk>.json ({key: text}); assemble.py merges them with
apply().
"""
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, "src")
I18N = os.path.join(SRC, "i18n")
EXTRA_LOCALES = ["es", "fr", "el", "it", "pt", "zh_Hans", "kk", "az", "uz_Latn", "ky", "sr_Cyrl", "mk", "sq", "mn"]
CYRILLIC = {"kk", "ky", "sr_Cyrl", "mk", "mn"}
# not translated: personal names (Cyrillic-script locales use the Russian form, the rest the English one)
NAME_KEYS = {"givenName", "familyName"}
SKIP_KEYS = {"cta", "logo", "uiLocales", "branding.logo"}
LETTERS = re.compile(r"[^\W\d_]", re.UNICODE)
CHUNK_CHARS = 22000
# fixed words printed in the PDFs and on the covers (build_assets.mjs)
PDF_LABELS_EN = {
    "vol": "Vol. {volume}, No. {number} ({year})", "pp": "pp.", "received": "Received", "accepted": "Accepted",
    "published": "Published", "submitted": "Submitted", "abstract": "Abstract", "keywords": "Keywords",
    "corr": "Corresponding author", "funding": "Funding", "intro": "Introduction", "methods": "Materials and Methods",
    "results": "Results", "discussion": "Discussion", "conclusion": "Conclusion", "refs": "References",
    "cite": "For citation", "manuscript": "Manuscript under review",
    "manuscriptNote": "This manuscript has been submitted to the journal and is undergoing editorial processing. Not for citation.",
    "ART": "Research Article", "REV": "Review", "SHC": "Short Communication", "licence": "Licensed under CC BY 4.0",
    "online": "online", "openAccess": "Open access", "publisher": "Tsifra LLC",
}
PDF_LABELS_RU = {
    "vol": "Т. {volume}, № {number} ({year})", "pp": "С.", "received": "Поступила", "accepted": "Принята",
    "published": "Опубликована", "submitted": "Подана", "abstract": "Аннотация", "keywords": "Ключевые слова",
    "corr": "Автор для переписки", "funding": "Финансирование", "intro": "Введение", "methods": "Материалы и методы",
    "results": "Результаты", "discussion": "Обсуждение", "conclusion": "Заключение", "refs": "Список литературы",
    "cite": "Для цитирования", "manuscript": "Рукопись на рассмотрении",
    "manuscriptNote": "Рукопись представлена в редакцию и находится в процессе редакционной обработки. Не для цитирования.",
    "ART": "Научная статья", "REV": "Обзор", "SHC": "Краткое сообщение", "licence": "Распространяется по лицензии CC BY 4.0",
    "online": "онлайн", "openAccess": "Открытый доступ", "publisher": "ООО «Цифра»",
}


def item_id(item, idx):
    for k in ("slug", "ref", "username"):
        if isinstance(item, dict) and k in item:
            return str(item[k])
    if isinstance(item, dict) and "volume" in item and "number" in item:
        return f"v{item['volume']}n{item['number']}"
    return str(idx)


def is_pair(v):
    return isinstance(v, dict) and v and set(v) <= {"ru", "en"} and "ru" in v and "en" in v


def walk_pairs(node, path, out):
    """Yield (key, dict) for every ru/en pair under node; dicts are the live objects."""
    if is_pair(node):
        out.append((path, node))
    elif isinstance(node, dict):
        for k, v in node.items():
            if k in NAME_KEYS or k in SKIP_KEYS or k == "body":
                continue
            walk_pairs(v, f"{path}.{k}" if path else k, out)
    elif isinstance(node, list):
        for i, v in enumerate(node):
            walk_pairs(v, f"{path}.{item_id(v, i)}", out)
    return out


def walk_body(node, path, out):
    """Yield (key, container, index) for every translatable string of a body."""
    if isinstance(node, dict):
        for k, v in node.items():
            if k == "values" or k == "kind":
                continue
            if isinstance(v, str):
                if LETTERS.search(v):
                    out.append((f"{path}.{k}", node, k))
            else:
                walk_body(v, f"{path}.{k}", out)
    elif isinstance(node, list):
        for i, v in enumerate(node):
            if isinstance(v, str):
                if LETTERS.search(v):
                    out.append((f"{path}.{i}", node, i))
            else:
                walk_body(v, f"{path}.{i}", out)
    return out


def load_sources():
    with open(os.path.join(SRC, "meta.json"), encoding="utf-8") as f:
        meta = json.load(f)
    groups = {}
    for iss in meta["issues"]:
        with open(os.path.join(SRC, iss["articlesFile"]), encoding="utf-8") as f:
            groups[iss["articlesFile"]] = json.load(f)
    with open(os.path.join(SRC, meta["submissionsFile"]), encoding="utf-8") as f:
        groups[meta["submissionsFile"]] = json.load(f)
    return meta, groups


def collect(meta, groups):
    """→ (pairs, bodies): pairs = [(key, pairdict)], bodies = [(key, lang, container, index)]."""
    pairs = walk_pairs({k: v for k, v in meta.items() if k not in ("submissionsFile",)}, "", [])
    bodies = []
    for arts in groups.values():
        for a in arts:
            pairs += walk_pairs(a, f"article.{a['slug']}", [])
            for key, cont, idx in walk_body(a["body"], f"body.{a['slug']}", []):
                bodies.append((key, a["locale"], cont, idx))
    return pairs, bodies


def extract():
    meta, groups = load_sources()
    pairs, bodies = collect(meta, groups)
    entries = [(k, {"en": p["en"], "ru": p["ru"]}) for k, p in pairs]
    entries += [(k, {lang: c[i]}) for k, lang, c, i in bodies]
    out = os.path.join(I18N, "_source")
    os.makedirs(out, exist_ok=True)
    for f in os.listdir(out):
        os.remove(os.path.join(out, f))
    chunks, cur, size, prefix = [], {}, 0, None
    for k, v in entries:
        grp = k.split(".")[0] + ("." + k.split(".")[1] if k.startswith(("article.", "body.")) else "")
        n = len(json.dumps(v, ensure_ascii=False))
        # keep one article together; cut between groups once the chunk is big enough
        if cur and size + n > CHUNK_CHARS and grp != prefix:
            chunks.append(cur)
            cur, size = {}, 0
        cur[k] = v
        size += n
        prefix = grp
    if cur:
        chunks.append(cur)
    for i, c in enumerate(chunks, 1):
        with open(os.path.join(out, f"{i:02d}.json"), "w", encoding="utf-8") as f:
            json.dump(c, f, ensure_ascii=False, indent=1)
            f.write("\n")
    chunks_pdf = {f"pdf.{k}": {"en": v, "ru": PDF_LABELS_RU[k]} for k, v in PDF_LABELS_EN.items()}
    with open(os.path.join(out, f"{len(chunks) + 1:02d}.json"), "w", encoding="utf-8") as f:
        json.dump(chunks_pdf, f, ensure_ascii=False, indent=1)
        f.write("\n")
    print(f"{len(entries)} texts in {len(chunks)} chunks + PDF labels → {out}")


def load_translations(locale):
    d = os.path.join(I18N, locale)
    tr = {}
    if os.path.isdir(d):
        for f in sorted(os.listdir(d)):
            if f.endswith(".json"):
                with open(os.path.join(d, f), encoding="utf-8") as fh:
                    tr.update(json.load(fh))
    return tr


def apply(meta, groups, locales):
    """Add the translations to meta/groups in place.

    Pairs get extra locale keys; names get the ru/en form; bodies become
    {locale: body} (ru and en always present when translated)."""
    pairs, _ = collect(meta, groups)
    missing = {}
    trs = {l: load_translations(l) for l in set(locales) | {"ru", "en"}}
    for loc in locales:
        for k, p in pairs:
            if k in trs[loc]:
                p[loc] = trs[loc][k]
            else:
                missing.setdefault(loc, []).append(k)

    def names(node):
        if isinstance(node, dict):
            for k, v in node.items():
                if k in NAME_KEYS and is_pair(v):
                    for loc in locales:
                        v.setdefault(loc, v["ru"] if loc in CYRILLIC else v["en"])
                else:
                    names(v)
        elif isinstance(node, list):
            for v in node:
                names(v)
    names(meta)
    names(groups)

    for arts in groups.values():
        for a in arts:
            src = a["body"]
            per = {a["locale"]: src}
            for loc in sorted(set(locales) | {"ru", "en"}):
                if loc == a["locale"]:
                    continue
                body = json.loads(json.dumps(src))
                todo = walk_body(body, f"body.{a['slug']}", [])
                if not all(k in trs[loc] for k, _, _ in todo):
                    if todo:
                        missing.setdefault(loc, []).append(f"body.{a['slug']}")
                    continue
                for k, cont, idx in todo:
                    cont[idx] = trs[loc][k]
                per[loc] = body
            a["body"] = per
    return missing


# cover lines: [volume line, "issue theme" heading]
COVER_LABELS = {
    "ru": ["Том {volume} · № {number} · {year}", "Тема выпуска"],
    "en": ["Vol. {volume} · No. {number} · {year}", "Issue theme"],
    "es": ["Vol. {volume} · N.º {number} · {year}", "Tema del número"],
    "fr": ["Vol. {volume} · n° {number} · {year}", "Thème du numéro"],
    "el": ["Τόμ. {volume} · Τεύχ. {number} · {year}", "Θέμα τεύχους"],
    "it": ["Vol. {volume} · N. {number} · {year}", "Tema del numero"],
    "pt": ["Vol. {volume} · N.º {number} · {year}", "Tema do número"],
    "zh_Hans": ["第{volume}卷 · 第{number}期 · {year}", "本期主题"],
    "kk": ["{volume}-том · № {number} · {year}", "Шығарылым тақырыбы"],
    "az": ["Cild {volume} · № {number} · {year}", "Buraxılışın mövzusu"],
    "uz_Latn": ["{volume}-jild · {number}-son · {year}", "Son mavzusi"],
    "ky": ["{volume}-том · № {number} · {year}", "Чыгарылыштын темасы"],
    "sr_Cyrl": ["Том {volume} · бр. {number} · {year}", "Тема броја"],
    "mk": ["Том {volume} · бр. {number} · {year}", "Тема на бројот"],
    "sq": ["Vëll. {volume} · Nr. {number} · {year}", "Tema e numrit"],
    "mn": ["{volume}-р боть · № {number} · {year}", "Дугаарын сэдэв"],
}


def pdf_labels(locale):
    if locale == "ru":
        out = dict(PDF_LABELS_RU)
    else:
        tr = load_translations(locale)
        out = {k: tr.get(f"pdf.{k}", v) for k, v in PDF_LABELS_EN.items()}
    out["coverVol"], out["coverTheme"] = COVER_LABELS.get(locale, COVER_LABELS["en"])
    return out


def check():
    """Report keys missing or extra per locale (for the translators)."""
    meta, groups = load_sources()
    pairs, bodies = collect(meta, groups)
    keys = {k for k, _ in pairs} | {k for k, *_ in bodies}
    for loc in ["ru", "en"] + EXTRA_LOCALES:
        tr = load_translations(loc)
        if loc in ("ru", "en"):
            need = {k for k, lang, *_ in bodies if lang != loc}
        else:
            need = keys
        if loc not in ("ru", "en"):
            need |= {f"pdf.{k}" for k in PDF_LABELS_EN}
        miss, extra = need - set(tr), set(tr) - keys - {f"pdf.{k}" for k in PDF_LABELS_EN}
        print(f"{loc:8} {len(need) - len(miss):5}/{len(need):<5} missing {len(miss):4}  unknown {len(extra)}")


if __name__ == "__main__":
    {"extract": extract, "check": check}[sys.argv[1] if len(sys.argv) > 1 else "check"]()
