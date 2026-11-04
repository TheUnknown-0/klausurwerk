"""Klausur #1 durchspielen, Teil A: Warteraum, Beitritt, Start. Vorher: reset.sh und states/ leeren (--neu)."""
import sys
from kw import *

STUDENTS = ["mia.becker", "noah.schulz", "emma.wagner", "ben.hoffmann", "lea.koch", "paul.richter"]


def main(b):
    if "--neu" in sys.argv:
        for f in STATE.glob("*.json"):
            f.unlink()
    t = sess(b, "frau.lehmann")
    goto(t, "/klausuren/1/pult")
    t.get_by_role("button", name=re.compile("Warteraum öffnen")).click()
    t.wait_for_timeout(1500)
    shot(t, "x-400-pult-warteraum-leer")
    pages = {}
    for i, n in enumerate(STUDENTS):
        p = sess(b, n)
        if n == "mia.becker":
            shot(p, "x-500-meine-klausuren")
        goto(p, "/klausur/1")
        if n == "mia.becker":
            shot(p, "x-501-warteraum-vor-bereit")
        p.get_by_role("button", name=re.compile("^Bereit")).click()
        p.wait_for_timeout(1200)
        if n == "mia.becker":
            shot(p, "x-502-warteraum-bereit")
        pages[n] = p
        p.context.storage_state(path=str(STATE / f"{n}.json"))
        if i == 1:
            t.wait_for_timeout(2500)
            shot(t, "x-401-pult-warteraum-zwei")
    t.wait_for_timeout(2500)
    shot(t, "x-402-pult-warteraum-voll")
    print(text(t, "body")[:3000])
    t.get_by_role("button", name=re.compile("Starten|Klausur starten")).first.click()
    t.wait_for_timeout(800)
    shot(t, "x-403-pult-start-dialog")
    t.get_by_role("button", name="Jetzt starten").click()
    t.wait_for_timeout(3000)
    shot(t, "x-404-pult-laeuft")
    for n, p in pages.items():
        p.wait_for_timeout(2500)
        ov = p.get_by_role("button", name=re.compile("beginnen|Weiter im Vollbild|Vollbild"))
        if n == "mia.becker":
            shot(p, "x-503-start-overlay")
        if ov.count() and ov.first.is_visible():
            ov.first.click()
        p.wait_for_timeout(1500)
    shot(pages["mia.becker"], "x-504-klausur-erste-ansicht")
run(main)
