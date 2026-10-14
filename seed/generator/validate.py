#!/usr/bin/env python3
"""Sanity checks for seed/content/journal.json and the generated assets."""
import json
import os
import re
import sys
from collections import Counter

HERE = os.path.dirname(os.path.abspath(__file__))
SEED = os.path.dirname(HERE)
ASSETS = os.path.join(SEED, "assets")
errors = []


def err(msg):
    errors.append(msg)


def issn_ok(s):
    m = re.fullmatch(r"(\d{4})-(\d{3})([\dX])", s)
    if not m:
        return False
    d = [int(c) for c in m.group(1) + m.group(2)]
    r = (11 - sum(x * w for x, w in zip(d, range(8, 1, -1))) % 11) % 11
    return m.group(3) == ("X" if r == 10 else str(r))


def walk(o, path="$"):
    if isinstance(o, dict):
        if set(o) & {"ru", "en"}:
            for k in ("ru", "en"):
                v = o.get(k)
                if v in (None, "", []):
                    err(f"{path}: missing/empty '{k}'")
        for k, v in o.items():
            walk(v, f"{path}.{k}")
    elif isinstance(o, list):
        for i, v in enumerate(o):
            walk(v, f"{path}[{i}]")


def words(html):
    return len(re.sub(r"<[^>]+>", " ", html).split())


with open(os.path.join(SEED, "content", "journal.json"), encoding="utf-8") as f:
    data = json.load(f)

walk(data)
J = data["journal"]
for k in ("onlineIssn", "printIssn"):
    if J.get(k) and not issn_ok(J[k]):
        err(f"invalid ISSN {k}={J[k]}")

text = json.dumps(data, ensure_ascii=False)
for e in set(re.findall(r"[\w.+-]+@[\w.-]+", text)):
    if not e.endswith("@example.org"):
        err(f"non-example.org email: {e}")
if "orcid" in text.lower():
    err("ORCID found")

arts = [a for i in data["issues"] for a in i["articles"]]
subs = data["submissions"]
slugs = Counter(a["slug"] for a in arts + subs)
for s, n in slugs.items():
    if n > 1:
        err(f"duplicate slug {s}")

sections = {s["ref"] for s in data["sections"]}
for a in arts + subs:
    s = a["slug"]
    if a["section"] not in sections:
        err(f"{s}: unknown section")
    if a["locale"] not in a["pdf"]:
        err(f"{s}: no PDF in the article language")
    for loc, rel in a["pdf"].items():
        if not os.path.isfile(os.path.join(ASSETS, rel)):
            err(f"{s}: missing {rel}")
        elif os.path.getsize(os.path.join(ASSETS, rel)) > 600 * 1024:
            err(f"{s}: {rel} > 600 KB")
    if a in arts:
        for kind in ("html", "xml"):
            if set(a.get(kind, {})) != set(a["pdf"]):
                err(f"{s}: {kind} editions do not match the PDFs")
            for rel in a.get(kind, {}).values():
                if not os.path.isfile(os.path.join(ASSETS, rel)):
                    err(f"{s}: missing {rel}")
    missing_pdf = set(J.get("contentLocales", [])) - set(a["pdf"])
    if missing_pdf:
        err(f"{s}: no PDF for {sorted(missing_pdf)}")
    for loc in J.get("contentLocales", []):
        if not a["title"].get(loc) or not a["abstract"].get(loc) or not a["keywords"].get(loc):
            err(f"{s}: title/abstract/keywords missing in {loc}")
    for lc in ("ru", "en"):
        w = words(a["abstract"][lc])
        if not 150 <= w <= 250:
            err(f"{s}: abstract {lc} has {w} words")
        if not 4 <= len(a["keywords"][lc]) <= 6:
            err(f"{s}: {len(a['keywords'][lc])} keywords ({lc})")
    if not 6 <= len(a["citations"]) <= 10:
        err(f"{s}: {len(a['citations'])} citations")
    if not 1 <= len(a["authors"]) <= 4 or sum(x["primaryContact"] for x in a["authors"]) != 1:
        err(f"{s}: authors/primaryContact problem")
    if "doi" in " ".join(a["citations"]).lower():
        err(f"{s}: DOI in citations")

for iss in data["issues"]:
    for loc, rel in iss["cover"].items():
        if not os.path.isfile(os.path.join(ASSETS, rel)):
            err(f"missing cover {rel}")
        elif os.path.getsize(os.path.join(ASSETS, rel)) > 200 * 1024:
            err(f"{rel} > 200 KB")
    prev = None
    for a in iss["articles"]:
        if not a.get("dateAccepted") or not re.fullmatch(r"\d+-\d+", a.get("pages", "")):
            err(f"{a['slug']}: missing dateAccepted/pages")
            continue
        p0, p1 = map(int, a["pages"].split("-"))
        if prev is not None and p0 != prev + 1:
            err(f"{a['slug']}: pages not sequential")
        prev = p1
        if not a["dateSubmitted"] < a["dateAccepted"] < iss["datePublished"]:
            err(f"{a['slug']}: date order")

stages = Counter(s["stage"] for s in subs)
if stages != Counter({"submission": 2, "externalReview": 1, "editorial": 1}):
    err(f"stages: {dict(stages)}")
user_emails = {u["email"] for u in data["users"]}
author_users = [u for u in data["users"] if "author" in u["roles"]]
if not any(au["email"] == u["email"] for u in author_users for s in subs for au in s["authors"]):
    err("no submission author matches an author user")
roles = Counter(r for u in data["users"] for r in u["roles"])
print("roles:", dict(roles))
print(f"issues={len(data['issues'])} articles={len(arts)} submissions={len(subs)} users={len(data['users'])} "
      f"announcements={len(data['announcements'])} ISSN online={J['onlineIssn']} print={J['printIssn']}")
if errors:
    print("\n".join("ERROR: " + e for e in errors))
    sys.exit(1)
print("OK: all checks passed")
