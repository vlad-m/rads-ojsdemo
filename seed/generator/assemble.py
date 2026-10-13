#!/usr/bin/env python3
"""Assemble seed/content/journal.json (and generator/bodies.json) from generator/src/*.json.

Source files carry an extra "body" object per article (full text used only for the PDFs).
It is stripped from journal.json and written to generator/bodies.json, keyed by slug.
Page ranges are NOT set here: build_assets.mjs measures the rendered PDFs and writes
"pages" back into journal.json. Existing page values are preserved if journal.json exists.
"""
import json
import os

import i18n

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, "src")
SEED = os.path.dirname(HERE)
OUT_JSON = os.path.join(SEED, "content", "journal.json")
OUT_BODIES = os.path.join(HERE, "bodies.json")

ARTICLE_KEYS = ["slug", "section", "locale", "title", "subtitle", "abstract", "keywords", "authors",
                "pages", "dateSubmitted", "dateAccepted", "citations", "pdf", "fundingAgency", "stage"]


def load(name):
    path = os.path.join(SRC, name)
    if not os.path.exists(path) and name != "meta.json":
        print(f"warning: {name} missing, skipped")
        return []
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def main():
    old_pages = {}
    if os.path.exists(OUT_JSON):
        with open(OUT_JSON, encoding="utf-8") as f:
            old = json.load(f)
        for iss in old.get("issues", []):
            for a in iss.get("articles", []):
                if a.get("pages"):
                    old_pages[a["slug"]] = a["pages"]

    meta = load("meta.json")
    groups = {iss["articlesFile"]: load(iss["articlesFile"]) for iss in meta["issues"]}
    groups[meta["submissionsFile"]] = load(meta["submissionsFile"])
    missing = i18n.apply(meta, groups, i18n.EXTRA_LOCALES)
    for loc, keys in sorted(missing.items()):
        print(f"warning: {loc}: {len(keys)} text(s) not translated yet (e.g. {keys[0]}); the English text is used")
    order = ["ru", "en"] + i18n.EXTRA_LOCALES
    locales = [l for l in order if l in ("ru", "en") or l not in missing or len(missing[l]) < 50]
    bodies = {}

    def clean(a, published, stage=None):
        a = dict(a)
        bodies[a["slug"]] = a.pop("body")
        # one PDF per language the full text exists in
        a["pdf"] = {l: f"pdf/{a['slug']}.{l}.pdf" for l in order if l in bodies[a["slug"]]}
        if published:
            a["pages"] = old_pages.get(a["slug"], a.get("pages", ""))
        else:
            a.pop("pages", None)
            a.pop("dateAccepted", None)
            a["stage"] = stage or a.get("stage")
        return {k: a[k] for k in ARTICLE_KEYS if k in a}

    issues = []
    for iss in meta["issues"]:
        arts = groups[iss.pop("articlesFile")]
        iss["articles"] = [clean(a, True) for a in arts]
        base = os.path.splitext(iss["cover"] if isinstance(iss["cover"], str) else f"covers/v{iss['volume']}n{iss['number']}")[0]
        iss["cover"] = {l: f"{base}.{l}.jpg" for l in locales}
        issues.append(iss)

    subs = [clean(a, False, a.get("stage")) for a in groups[meta.pop("submissionsFile")]]
    meta["journal"]["contentLocales"] = locales

    journal = {
        "journal": meta["journal"],
        "sections": meta["sections"],
        "users": meta["users"],
        "issues": issues,
        "submissions": subs,
        "announcements": meta["announcements"],
    }
    os.makedirs(os.path.dirname(OUT_JSON), exist_ok=True)
    with open(OUT_JSON, "w", encoding="utf-8") as f:
        json.dump(journal, f, ensure_ascii=False, indent=2)
        f.write("\n")
    with open(OUT_BODIES, "w", encoding="utf-8") as f:
        json.dump(bodies, f, ensure_ascii=False, indent=1)
        f.write("\n")
    with open(os.path.join(HERE, "labels.json"), "w", encoding="utf-8") as f:
        json.dump({l: i18n.pdf_labels(l) for l in order}, f, ensure_ascii=False, indent=1)
        f.write("\n")
    n = sum(len(i["articles"]) for i in issues)
    print(f"journal.json: {len(issues)} issues, {n} articles, {len(subs)} submissions; bodies.json: {len(bodies)}")


if __name__ == "__main__":
    main()
