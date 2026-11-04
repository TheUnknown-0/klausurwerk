"""Baut das Klausurwerk-Handbuch (Aufruf: python build.py [gesamt|admin|lehrkraefte|schueler]): Bilder vorbereiten, HTML zusammensetzen,
mit Chromium als PDF rendern, Inhaltsverzeichnis mit Seitenzahlen füllen,
Titelseite voranstellen und Lesezeichen setzen."""
from __future__ import annotations

import re
import sys
from pathlib import Path

import fitz
from PIL import Image
from playwright.sync_api import sync_playwright

HERE = Path(__file__).parent
SHOTS = HERE.parent / "shots"
PDFS = HERE.parent / "pdfs"
IMG = HERE / "img"
IMG.mkdir(exist_ok=True)

PARTS = ["00.html", "01.html", "02.html", "03.html", "04.html", "05.html", "06.html", "07.html", "08.html"]

# Teile: Kapitelkennungen, die in das jeweilige Heft kommen (None = alles)
TEILE = {
    "gesamt": (None, "Klausurwerk-Handbuch.pdf", "Handbuch", "Gesamtausgabe"),
    "admin": ({"k1", "k2", "k3", "ka", "kd"}, "Klausurwerk-Handbuch-Admin.pdf", "Handbuch Administration", "Heft für Administratoren"),
    "lehrkraefte": ({"k1", "k4", "k5", "k6", "ka", "kb", "kc", "kd", "ke"}, "Klausurwerk-Handbuch-Lehrkraefte.pdf", "Handbuch Lehrkräfte", "Heft für Lehrkräfte"),
    "schueler": ({"k7", "kb", "kd"}, "Klausurwerk-Handbuch-Schueler.pdf", "Handbuch Schüler:innen", "Heft für Schüler:innen"),
}
TEIL = sys.argv[1] if len(sys.argv) > 1 and sys.argv[1] in TEILE else "gesamt"
KEEP, OUTNAME, TITLE, SUBTITLE = TEILE[TEIL]
OUT = HERE.parent / OUTNAME

# Zuschnitt der Bilder nach Namenspräfix: x- unverändert, f- mit Seitenleiste, c- Ausschnitt (CROPS),
# sonst ohne Seitenleiste und Kopfzeile.
CROPS = {"c-100-login": (540, 90, 1660, 900), "c-101-login-fehler": (540, 90, 1660, 900)}
SIDEBAR_X, TOPBAR_Y = 312, 86
# Aufnahmen mit viel leerem Hintergrund (Karten in der Mitte): auf den Inhalt zuschneiden
TRIM = ("x-501", "x-502", "x-503", "x-520", "x-521", "x-523", "x-525", "x-526", "x-528", "x-529", "x-530", "x-531",
        "x-560", "x-561", "x-700")
CROPS.update({"x-517-matching": (0, 0, 1800, 760), "x-518-ordering": (0, 0, 1800, 760)})


def trim(im: Image.Image, pad: int = 50) -> Image.Image:
    from PIL import ImageChops
    bg = Image.new("RGB", im.size, im.getpixel((4, 4)))
    box = ImageChops.difference(im, bg).convert("L").point(lambda v: 255 if v > 12 else 0).getbbox()
    if not box:
        return im
    x0, y0, x1, y1 = box
    return im.crop((max(x0 - pad, 0), max(y0 - pad, 0), min(x1 + pad, im.width), min(y1 + pad, im.height)))


def prep_image(name: str, maxh: int | None) -> None:
    dst = IMG / f"{name}.jpg"
    if name.startswith("pdf-"):
        base, page = name[4:].rsplit("-", 1)
        doc = fitz.open(HERE.parent / "data" / f"{base}.pdf")
        pix = doc[int(page) - 1].get_pixmap(dpi=150)
        pix.save(str(IMG / f"{name}.png"))
        Image.open(IMG / f"{name}.png").convert("RGB").save(dst, quality=86)
        (IMG / f"{name}.png").unlink()
        return
    im = Image.open(SHOTS / f"{name}.jpg").convert("RGB")
    w, h = im.size
    if name in CROPS:
        im = im.crop(CROPS[name])
    elif name.startswith(TRIM):
        im = trim(im)
    elif name.startswith("x-") and not name.startswith("x-4") and name != "x-216-pult-bereit":
        pass
    elif name.startswith("f-300-editor") and name.endswith("-2"):
        im = im.crop((770, 86, w, min(h, 1000)))
    elif name.startswith(("x-550", "x-310")):
        im = im.crop((335, 70, w, min(h, 1000)))
    elif name.startswith("f-") and not name.startswith("f-300-editor"):
        im = im.crop((0, 0, w, min(h, maxh or 1500)))
    else:
        im = im.crop((SIDEBAR_X, TOPBAR_Y, w, min(h, TOPBAR_Y + (maxh or 1350))))
    if im.width > 1500:
        im = im.resize((1500, round(im.height * 1500 / im.width)), Image.LANCZOS)
    im.save(dst, quality=84, optimize=True)


PARTNOTE = {t: f'<div class="box note"><span class="t">{SUB}</span><p>{txt}</p></div>' for t, SUB, txt in (
    ("admin", "Dieses Heft: Administration", "Es enthält die Kapitel 1 bis 3 sowie die Anhänge A und D. Das Heft für Lehrkräfte (Kapitel 4 bis 6) und das Heft für Schüler (Kapitel 7) gibt es als eigene Dateien. Verweise auf Kapitel, die in diesem Heft fehlen, beziehen sich auf die Gesamtausgabe."),
    ("lehrkraefte", "Dieses Heft: Lehrkräfte", "Es enthält die Kapitel 1 und 4 bis 6 sowie die Anhänge A bis E. Installation und Verwaltung (Kapitel 2 und 3) stehen im Heft für Administratoren, die Bedienung durch Schüler (Kapitel 7) im Heft für Schüler. Verweise auf Kapitel, die in diesem Heft fehlen, beziehen sich auf die Gesamtausgabe."),
    ("schueler", "Dieses Heft: Schüler:innen", "Es enthält nur Kapitel 7 (in der Du-Form) mit den Anhängen B und D. Die Hinweise zum Lesen und zur Sprache auf dieser Seite gelten für die ganze Anleitung; für dich zählt Kapitel 7. Bei Fragen wende dich an deine Lehrkraft."),
)}


def assemble() -> tuple[str, list[tuple[int, str, str]]]:
    body = "\n".join((HERE / p).read_text(encoding="utf-8") for p in PARTS)
    body = body.replace("<!--PARTNOTE-->", PARTNOTE.get(TEIL, ""))
    if TEIL != "gesamt":  # im Teilheft ersetzt der Hinweis oben den Schnelleinstieg
        body = re.sub(r'<div class="box tip"><span class="t">Schnelleinstieg nach Rolle</span>.*?</ul>\s*</div>', "", body, count=1, flags=re.S)
    body = body.replace("<!--TYPEN-->", (HERE / "04-typen.html").read_text(encoding="utf-8"))
    if KEEP is not None:
        # Kapitel ausserhalb des Heftes entfernen (jedes Kapitel ist ein section mit id kX)
        pieces = re.split(r'(?=<section class="chapter" id="k[a-z0-9]+")', body)
        kept = []
        for pc in pieces:
            m = re.match(r'<section class="chapter" id="(k[a-z0-9]+)"', pc)
            if m is None or m.group(1) in KEEP:
                kept.append(pc)
        body = "".join(kept)

    # Bilder vorbereiten
    cover = cover_html()
    for m in re.finditer(r'<img src="img/([^"/]+)\.jpg"(?: data-maxh="(\d+)")?', body + cover):
        prep_image(m.group(1), int(m.group(2)) if m.group(2) else None)

    # Abbildungsnummern je Kapitel
    out, chap, fig = [], "0", 0
    for piece in re.split(r'(<section class="chapter" id="k[a-z0-9]+"|\{fig\})', body):
        if piece.startswith('<section class="chapter"'):
            chap = re.search(r'id="k([a-z0-9]+)"', piece).group(1).upper()
            fig = 0
            out.append(piece)
        elif piece == "{fig}":
            fig += 1
            out.append(f"Abb. {chap}.{fig}")
        else:
            out.append(piece)
    body = "".join(out)

    # Hohe Bilder schmaler setzen, damit sie nicht ganze Seiten sprengen
    def size_img(m: re.Match) -> str:
        fig_cls, name = m.group(1) or "", m.group(2)
        if any(c in fig_cls for c in ("phone", "doc", "half")):
            return m.group(0)
        with Image.open(IMG / f"{name}.jpg") as im:
            ratio = im.height / im.width
        base = 72 if "narrow" in fig_cls else 100
        max_h = 150.0 if "narrow" not in fig_cls else 120.0
        width = min(base, max_h / (174 * ratio) * 100)
        if width >= base - 0.5:
            return m.group(0)
        return m.group(0).replace('<img ', f'<img style="width:{width:.1f}%;margin:0 auto" ', 1)

    body = re.sub(r'<figure(?: class="([^"]*)")?><img src="img/([^"]+)\.jpg"', size_img, body)

    # Gliederung sammeln und unsichtbare Marker setzen
    toc: list[tuple[int, str, str]] = []

    def chap_sub(m: re.Match) -> str:
        cid, title = m.group(1), m.group(2)
        toc.append((1, cid, title))
        return m.group(0)

    body = re.sub(r'<section class="chapter" id="(k[a-z0-9]+)" data-title="([^"]+)">', chap_sub, body)
    # h1 der Kapitel und h2 mit Marker versehen
    body = re.sub(r'(<div class="kicker">[^<]*</div>)', lambda m: m.group(1), body)

    entries: list[tuple[int, str, str]] = []
    pos = 0
    for m in re.finditer(r'<section class="chapter" id="(k[a-z0-9]+)" data-title="([^"]+)">|<h2 id="([^"]+)"[^>]*><span class="num">([^<]+)</span>([^<]+)</h2>', body):
        if m.group(1):
            entries.append((1, m.group(1), m.group(2)))
        else:
            entries.append((2, m.group(3), f"{m.group(4)} {m.group(5)}"))

    def mark_h2(m: re.Match) -> str:
        return m.group(0).replace("</h2>", f'<span class="mk">@@{m.group(1)}@@</span></h2>')

    body = re.sub(r'<h2 id="([^"]+)"[^>]*>.*?</h2>', mark_h2, body)

    def mark_ch(m: re.Match) -> str:
        return m.group(0) + f'<span class="mk">@@{m.group(1)}@@</span>'

    body = re.sub(r'<section class="chapter" id="(k[a-z0-9]+)" data-title="[^"]+">', mark_ch, body)
    return body, entries


def prep_cover() -> None:
    d = IMG / "cover"
    d.mkdir(exist_ok=True)
    for src, dst, box in (("x-411-pult-arbeitet", "pult", None), ("x-519-aufgabenleiste-fortschritt", "student", None), ("x-412-pult-alarm", "alarm", None)):
        f = SHOTS / f"{src}.jpg"
        if not f.exists():
            f = next(SHOTS.glob("x-4*.jpg"))
        im = Image.open(f).convert("RGB")
        if im.width > 1400:
            im = im.resize((1400, round(im.height * 1400 / im.width)), Image.LANCZOS)
        im.save(d / f"{dst}.jpg", quality=82)


def cover_html() -> str:
    prep_cover()
    return (HERE / "cover.html").read_text(encoding="utf-8").replace("@@PART@@", SUBTITLE)


def toc_html(entries, pages: dict[str, int]) -> str:
    rows, n = [], 0
    for level, eid, title in entries:
        pg = pages.get(eid, 0)
        if level == 1:
            n += 1 if eid[1:].isdigit() else 0
            num = eid[1:].upper() if not eid[1:].isdigit() else str(n)
            rows.append(f'<li class="ch"><a href="#{eid}"><span class="n">{num}</span><span class="ttl">{title}</span>'
                        f'<span class="dots"></span><span class="pg">{pg}</span></a></li>')
        else:
            rows.append(f'<li class="sub"><a href="#{eid}"><span class="ttl">{title}</span>'
                        f'<span class="dots"></span><span class="pg">{pg}</span></a></li>')
    return "<ol>" + "\n".join(rows) + "</ol>"


def page_html(body: str) -> str:
    return f"""<!doctype html><html lang="de"><head><meta charset="utf-8">
<title>Klausurwerk Handbuch</title><link rel="stylesheet" href="style.css">
<style>.mk{{font-size:1px;color:transparent;position:absolute;}}</style></head>
<body>{body}</body></html>"""


FOOTER = f"""<div style="width:100%;font-family:'Segoe UI',sans-serif;font-size:7.5px;color:#6b6f8e;padding:0 18mm;display:flex;justify-content:space-between;">
<span>Klausurwerk · {TITLE}</span><span><span class="pageNumber"></span></span></div>"""


def render(page, html: str, path: Path) -> None:
    (HERE / "_build.html").write_text(html, encoding="utf-8")
    page.goto((HERE / "_build.html").as_uri())
    page.wait_for_load_state("networkidle")
    page.evaluate("document.fonts.ready")
    page.pdf(path=str(path), format="A4", print_background=True, display_header_footer=True,
             header_template="<div></div>", footer_template=FOOTER,
             margin={"top": "17mm", "bottom": "19mm", "left": "18mm", "right": "18mm"})


def find_pages(pdf: Path) -> dict[str, int]:
    doc = fitz.open(pdf)
    pages: dict[str, int] = {}
    for i, pg in enumerate(doc):
        for mk in re.findall(r"@@([a-z0-9-]+)@@", pg.get_text()):
            pages.setdefault(mk, i + 1)
    return pages


def main() -> None:
    body, entries = assemble()
    tmp = HERE / "_pass.pdf"
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page()
        pages = {e[1]: 88 for e in entries}
        for _ in range(2):
            html = page_html(body.replace("<!--TOC-->", toc_html(entries, pages)))
            render(page, html, tmp)
            pages = find_pages(tmp)
        # Titelseite
        (HERE / "_cover.html").write_text(cover_html(), encoding="utf-8")
        page.goto((HERE / "_cover.html").as_uri())
        page.wait_for_load_state("networkidle")
        page.evaluate("document.fonts.ready")
        page.pdf(path=str(HERE / "_cover.pdf"), format="A4", print_background=True,
                 margin={"top": "0", "bottom": "0", "left": "0", "right": "0"})
        browser.close()

    final = fitz.open(HERE / "_cover.pdf")
    final.insert_pdf(fitz.open(tmp))
    toc = [[lvl, title, pages.get(eid, 1) + 1] for lvl, eid, title in entries]
    final.set_toc([[1, "Titel", 1], [1, "Willkommen", 2], [1, "Inhalt", 3]] + toc)
    final.set_page_labels([{"startpage": 0, "prefix": "Titel", "style": ""},
                           {"startpage": 1, "style": "D", "firstpagenum": 1}])
    final.set_metadata({"title": "Klausurwerk Handbuch" if TEIL == "gesamt" else f"Klausurwerk Handbuch ({TITLE})", "author": "Klausurwerk", "subject": "Bedienungsanleitung",
                        "keywords": "Klausurwerk, Handbuch, Anleitung"})
    final.save(OUT, garbage=4, deflate=True)
    print("fertig:", OUT, final.page_count, "Seiten", round(OUT.stat().st_size / 1e6, 1), "MB")
    missing = [e for e in entries if e[1] not in pages]
    if missing:
        print("ohne Seitenzahl:", missing)


if __name__ == "__main__":
    main()
