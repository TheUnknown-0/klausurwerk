"""Klausur #1, Teil C: Pult-Aktionen im Lauf (Verstoss, Hand, Zeit, Toilette, Durchsage, Pause, Geraetewechsel)."""
import traceback
from kw import *

NAMES = ["mia.becker", "noah.schulz", "emma.wagner", "ben.hoffmann", "lea.koch", "paul.richter"]


def step(label, fn):
    try:
        fn()
    except Exception as e:
        print("FEHLER bei", label, str(e).splitlines()[0][:200])


def tile(t, user_id):
    return t.locator(f".pult-tile[data-user-id='{user_id}']")


def btn(page, pattern, first=True):
    return page.get_by_role("button", name=re.compile(pattern)).first


def main(b):
    S = {}
    for n in NAMES:
        p = sess(b, n)
        goto(p, "/klausur/1", 1200)
        ov = p.get_by_role("button", name=re.compile("beginnen|Weiter im Vollbild|Vollbild"))
        if ov.count() and ov.first.is_visible():
            ov.first.click(); p.wait_for_timeout(800)
        S[n] = p
    t = sess(b, "frau.lehmann")
    goto(t, "/klausuren/1/pult", 2500)
    shot(t, "x-410-pult-ton-aktivieren")
    step("ton", lambda: btn(t, "Ton aktivieren").click())
    t.wait_for_timeout(800)
    shot(t, "x-411-pult-arbeitet")

    # Verstoss: Noah
    noah = S["noah.schulz"]
    noah.evaluate("window.dispatchEvent(new Event('blur'))")
    noah.evaluate("Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => 'hidden'}); document.dispatchEvent(new Event('visibilitychange'))")
    t.wait_for_timeout(3500)
    shot(noah, "x-520-gesperrt")
    shot(t, "x-412-pult-alarm")
    noah.evaluate("Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => 'visible'}); document.dispatchEvent(new Event('visibilitychange'))")
    step("select noah", lambda: tile(t, 5).click())
    t.wait_for_timeout(600)
    shot(t, "x-413-pult-kachel-gewaehlt")
    step("freigeben", lambda: btn(t, "Freigeben").click())
    t.wait_for_timeout(3000)
    shot(noah, "x-521-freigabe-fortsetzen")
    shot(t, "x-414-pult-nach-freigabe")
    step("noah fullscreen btn", lambda: (noah.get_by_role("button", name=re.compile("Vollbild|Weiter")).first.click()))

    # Hand heben: Lea
    lea = S["lea.koch"]
    step("hand", lambda: btn(lea, "Hand").click())
    lea.wait_for_timeout(500)
    shot(lea, "x-522-hand-gehoben")
    t.wait_for_timeout(3000)
    shot(t, "x-415-pult-hand")

    # Zeit: Emma
    step("select emma", lambda: tile(t, 6).click())
    step("+10", lambda: btn(t, r"\+10 min").click())
    t.wait_for_timeout(1500)
    step("zeit dialog", lambda: btn(t, "Zeit …").click())
    t.wait_for_timeout(700)
    shot(t, "x-416-pult-zeit-dialog")
    step("zeit schliessen", lambda: btn(t, "Abbrechen").click())

    # Toilette: Ben
    ben = S["ben.hoffmann"]
    step("sel ben", lambda: (tile(t, 7).click()))
    step("toilette", lambda: btn(t, "Abwesend").click())
    t.wait_for_timeout(1000)
    shot(t, "x-417-pult-toilette-dialog")
    step("toilette ok", lambda: (t.locator("dialog[open] button.btn-primary, .modal button.btn-primary, [role=dialog] button.btn-primary").first.click()))
    t.wait_for_timeout(3500)
    shot(ben, "x-523-abwesend")
    step("ben zurueck", lambda: btn(ben, "Zurück|Freigabe").click())
    ben.wait_for_timeout(2500)
    shot(t, "x-418-pult-freigabe-anfrage")

    # Durchsage
    step("durchsage", lambda: btn(t, "Durchsage an alle").click())
    t.wait_for_timeout(700)
    shot(t, "x-419-pult-durchsage-dialog")
    step("durchsage text", lambda: t.locator("dialog[open] textarea, [role=dialog] textarea, .modal textarea").first.fill("Aufgabe 2: Die Funktion heißt fib, bitte nicht umbenennen."))
    step("durchsage senden", lambda: (t.locator("dialog[open] button.btn-primary, [role=dialog] button.btn-primary, .modal button.btn-primary").first.click()))
    t.wait_for_timeout(3500)
    shot(S["mia.becker"], "x-524-durchsage-banner")

    # Details: Mia
    step("detail", lambda: tile(t, 4).locator(".pult-tile-detail").click())
    t.wait_for_timeout(2500)
    shot(t, "x-420-pult-detail-antworten")
    step("tab protokoll", lambda: t.get_by_role("tab", name="Protokoll").click())
    t.wait_for_timeout(1200)
    shot(t, "x-421-pult-detail-protokoll")
    step("tab zeit", lambda: t.get_by_role("tab", name=re.compile("Zeit")).click())
    t.wait_for_timeout(1200)
    shot(t, "x-422-pult-detail-zeit-geraet")
    print(text(t, "body")[-1500:])
    step("schliessen", lambda: btn(t, "Schließen").click())

    # Geraetewechsel: Paul an neuem Geraet
    ctx2 = new_ctx(b)
    p2 = login(ctx2, "paul.richter")
    goto(p2, "/klausur/1", 2500)
    shot(p2, "x-525-geraetewechsel-wartet")
    t.wait_for_timeout(3500)
    shot(t, "x-423-pult-geraetewechsel")
    print(text(t, "body")[:1200])

    # Selbst abgeben: Lea
    step("lea abgeben", lambda: btn(lea, "^Abgeben").click())
    lea.wait_for_timeout(800)
    shot(lea, "x-526-abgabe-dialog")
    for n, p in S.items():
        p.context.storage_state(path=str(STATE / f"{n}.json"))
    ctx2.storage_state(path=str(STATE / "paul-neu.json"))
    input_wait = 0
    t.wait_for_timeout(500)


if __name__ == "__main__":
    run(main)
