"""Live-Wand, Löschen per Auswahl und aktualisierte Pult-Aufnahmen.

Legt eine eigene Kopie von Klausur 1 an (Bildschirmfreigabe an), lässt sechs Schüler:innen beitreten und
die Klausur starten. Die Bildschirmfreigabe ist in Chromium mit einer Zeichenfläche ersetzt, die ein
Bildschirmfoto der jeweiligen Klausurseite zeigt (realistischer als die Testquelle von Chromium).
Setzt die Datenbank nicht zurück, läuft nach s20 (Demo-Daten genügen). Ergebnis in shots/.
"""
import re
import time

from kw import *

NAMES = ["mia.becker", "noah.schulz", "emma.wagner", "ben.hoffmann", "lea.koch", "paul.richter"]
# welche Aufgabe wer offen hat (Text im Aufgabenbaum der Klausur)
TASKS = {"mia.becker": "Klasse Konto", "noah.schulz": "Fibonacci", "emma.wagner": "SQL: Bibliothek",
         "ben.hoffmann": "Datentypen", "lea.koch": "Klasse Konto", "paul.richter": "Fibonacci"}

KONTO_MIA = """public class Konto {
    private int kontostand;

    public void einzahlen(int betrag) {
        if (betrag > 0) kontostand += betrag;
    }

    public boolean abheben(int betrag) {
        if (betrag > kontostand) return false;
        kontostand -= betrag;
        return true;
    }

    public int getKontostand() {
        return kontostand;
    }
}
"""
KONTO_LEA = """public class Konto {
    private int kontostand;

    public void einzahlen(int betrag) {
        if (betrag > 0) kontostand += betrag;
    }

    public boolean abheben(int betrag) {
        // TODO
        return false;
    }

    public int getKontostand() {
        return kontostand;
    }
}
"""
FIB_NOAH = """def fib(n):
    a, b = 0, 1
    for _ in range(n):
        a, b = b, a + b
    return a


if __name__ == "__main__":
    n = int(input("n = "))
    print(fib(n))
"""
# Code und Zeile, in der der Cursor steht
CODE = {"mia.becker": (KONTO_MIA, 10), "lea.koch": (KONTO_LEA, 5), "noah.schulz": (FIB_NOAH, 4)}

INIT = """
(() => {
  const md = navigator.mediaDevices;
  if (!md) return;
  window.__streams = [];
  const img = new Image();
  window.__setShot = (u) => { img.src = u; };
  const c = document.createElement('canvas'); c.width = 1440; c.height = 900;
  const ctx = c.getContext('2d');
  setInterval(() => {
    if (img.complete && img.naturalWidth) ctx.drawImage(img, 0, 0, c.width, c.height);
    else { ctx.fillStyle = '#fbf8f0'; ctx.fillRect(0, 0, c.width, c.height); }
  }, 250);
  md.getDisplayMedia = async () => {
    const s = c.captureStream(4);
    const t = s.getVideoTracks()[0];
    const gs = t.getSettings.bind(t);
    t.getSettings = () => ({ ...gs(), displaySurface: 'monitor' });
    window.__streams.push(s);
    return s;
  };
})();
"""


def poll(fn, page, timeout=30000, step=500):
    waited = 0
    while waited <= timeout:
        v = fn()
        if v:
            return v
        page.wait_for_timeout(step)
        waited += step
    raise AssertionError("Zeitüberschreitung")


def refresh(pages):
    """Bildschirmfoto jeder Schülerseite als neues Standbild der Freigabe."""
    import base64
    for p in pages.values():
        raw = p.screenshot(type="jpeg", quality=72, scale="css")
        p.evaluate("u => window.__setShot(u)", "data:image/jpeg;base64," + base64.b64encode(raw).decode())


def editor_shots(b):
    t = sess(b, "frau.lehmann")
    # Diagramm mit gewähltem Element (Klausur 3, UML; ungespeichert)
    goto(t, "/klausuren/3/aufgaben", 600)
    t.get_by_text("Klassendiagramm Bibliothek").first.click()
    t.wait_for_timeout(1200)
    t.get_by_role("button", name=re.compile("Klasse")).first.click()
    t.wait_for_timeout(600)
    sel = t.locator(".kw-diagram svg g[data-id], svg [data-node-id], svg g.node, svg .dg-node").first
    if sel.count():
        sel.click()
        t.wait_for_timeout(400)
    shot_at(t, "321-diagramm-auswahl", text="Musterlösung", offset=60)
    t.close()


def main(b):
    editor_shots(b)

    # --- Eigene Kopie von Klausur 1 mit Bildschirmfreigabe ---
    t = sess(b, "frau.lehmann")
    goto(t, "/klausuren/1")
    t.get_by_role("button", name="Als Vorlage kopieren").click()
    t.wait_for_url(re.compile(r"/klausuren/\d+/einstellungen"))
    exam = int(re.search(r"/klausuren/(\d+)/", t.url).group(1))
    settle(t)
    # Aufgabenliste mit gewählter Aufgabe (frische Kopie, noch Entwurf: Struktur nicht gesperrt; nichts wird gelöscht)
    goto(t, f"/klausuren/{exam}/aufgaben", 600)
    t.get_by_text("Bubblesort").first.click()
    t.wait_for_timeout(500)
    shot(t, "x-320-aufgabenliste-auswahl", selector=".te-tree, .te-list, [role=listbox]", pad=14)
    goto(t, f"/klausuren/{exam}/einstellungen", 600)
    settle(t)
    t.locator("#s-screen_share").check()
    t.wait_for_timeout(6000)
    shot_at(t, "206b-einstellungen-live", text="Live-Ansicht der Klausurseite", offset=260)
    t.get_by_role("button", name="Einstellungen speichern").click()
    t.wait_for_load_state("networkidle")
    goto(t, f"/klausuren/{exam}")
    t.get_by_role("button", name="Fertigstellen").click()
    t.wait_for_load_state("networkidle")
    goto(t, f"/klausuren/{exam}/pult", 1500)
    t.get_by_role("button", name=re.compile("Warteraum öffnen")).click()
    t.wait_for_timeout(1500)

    # --- Schüler:innen: Warteraum mit Freigabe ---
    pages = {}
    for n in NAMES:
        ctx = new_ctx(b)
        ctx.add_init_script(INIT)
        p = login(ctx, n)
        goto(p, f"/klausur/{exam}", 800)
        pages[n] = p
    mia = pages["mia.becker"]
    mia.get_by_role("button", name="Bildschirm freigeben").wait_for(timeout=15000)
    poll(lambda: mia.get_by_role("button", name="Bildschirm freigeben").is_enabled(), mia, 10000)
    shot(mia, "x-570-warteraum-freigabe")
    for n, p in pages.items():
        refresh({n: p})
        p.get_by_role("button", name="Bildschirm freigeben").click()
        p.get_by_text("Bildschirm wird übertragen").first.wait_for(timeout=15000)
    shot(mia, "x-571-warteraum-freigabe-aktiv")
    for p in pages.values():
        rb = p.get_by_role("button", name=re.compile("^Bereit"))
        poll(lambda: rb.is_enabled(), p, 10000)
        rb.click()
    t.wait_for_timeout(2500)

    # --- Start ---
    t.get_by_role("button", name=re.compile("Starten|Klausur starten")).first.click()
    t.get_by_role("button", name="Jetzt starten").click()
    t.wait_for_timeout(3000)
    for n, p in pages.items():
        ov = p.get_by_role("button", name=re.compile("beginnen|Weiter im Vollbild|Vollbild"))
        if ov.count() and ov.first.is_visible():
            ov.first.click()
            p.wait_for_timeout(800)
        p.get_by_text(TASKS[n], exact=False).first.click()
        p.wait_for_timeout(900)
    # Code einsetzen (insert_text: ohne automatische Einrückung des Editors), Cursor in die Arbeitszeile
    for n, (code, line) in CODE.items():
        pg = pages[n]
        pg.locator(".cm-content:visible").first.click()
        pg.keyboard.press("Control+A")
        pg.keyboard.insert_text(code)
        pg.keyboard.press("Control+Home")
        for _ in range(line - 1):
            pg.keyboard.press("ArrowDown")
        pg.keyboard.press("End")
        pg.wait_for_timeout(300)
    # Python ausführen, damit die Konsole etwas zeigt
    noah = pages["noah.schulz"]
    run_btn = noah.get_by_role("button", name=re.compile("Ausführen"))
    if run_btn.count():
        run_btn.first.click()
        noah.wait_for_timeout(9000)
        inp = noah.locator("input.console-input, .console input, [class*=console] input, [class*=console] textarea").first
        if inp.count():
            inp.fill("10")
            inp.press("Enter")
            noah.wait_for_timeout(2500)
    for _ in range(2):
        refresh(pages)
        t.wait_for_timeout(1500)
    t.wait_for_timeout(8000)
    refresh(pages)
    t.wait_for_timeout(7000)

    # --- Aufnahmen ---
    shot(t.context.pages[0] if False else mia, "x-572-klausur-uebertragung", clip={"x": 0, "y": 0, "width": 1440, "height": 140})
    shot(mia, "x-573-klausur-mit-anzeige")
    goto(t, f"/klausuren/{exam}/pult", 3000)
    t.get_by_role("button", name=re.compile("Ton aktivieren")).click()
    t.wait_for_timeout(800)
    shot(t, "x-411-pult-arbeitet")

    # Verstoß (Alarm mit Sperre): Paul
    paul = pages["paul.richter"]
    paul.evaluate("window.dispatchEvent(new Event('blur'))")
    paul.evaluate("Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => 'hidden'}); document.dispatchEvent(new Event('visibilitychange'))")
    t.wait_for_timeout(3500)
    shot(t, "x-412-pult-alarm")
    paul.evaluate("Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => 'visible'}); document.dispatchEvent(new Event('visibilitychange'))")
    t.locator(".pult-tile", has_text="Paul").first.click()
    t.get_by_role("button", name=re.compile("Freigeben")).first.click()
    t.wait_for_timeout(2500)
    try:
        paul.get_by_role("button", name=re.compile("Vollbild|Weiter")).first.click()
    except Exception:
        pass
    refresh(pages)

    # Live-Wand
    t.get_by_role("button", name=re.compile("Live-Wand")).click()
    t.locator(".pult-wall-card").first.wait_for(timeout=15000)
    t.wait_for_timeout(2500)
    refresh(pages)
    t.wait_for_timeout(6500)
    t.set_viewport_size({"width": 1440, "height": 1450})
    t.wait_for_timeout(800)
    shot(t, "x-441-pult-livewall")
    t.set_viewport_size({"width": 1440, "height": 900})

    # Großansicht
    card = t.locator(".pult-wall-card", has_text="Mia").first
    card.click()
    t.locator(".pult-wall-focus").first.wait_for(timeout=10000)
    for _ in range(3):
        refresh({"mia.becker": mia})
        t.wait_for_timeout(1500)
    t.wait_for_timeout(2000)
    shot(t, "x-442-pult-fokus")
    t.keyboard.press("Escape")
    t.wait_for_timeout(800)

    # Freigabe beenden: Noah
    noah.evaluate("() => { window.__streams.forEach(s => s.getTracks().forEach(t => { t.stop(); t.dispatchEvent(new Event('ended')); })); }")
    t.wait_for_timeout(4500)
    t.evaluate("window.scrollTo(0, 0)")
    t.set_viewport_size({"width": 1440, "height": 1450})
    t.wait_for_timeout(800)
    shot(t, "x-443-pult-alarm-freigabe")
    t.set_viewport_size({"width": 1440, "height": 900})
    noah.get_by_role("button", name="Erneut freigeben").first.wait_for(timeout=10000)
    shot(noah, "x-574-freigabe-beendet")
    noah.get_by_role("button", name="Erneut freigeben").first.click()
    noah.wait_for_timeout(2500)

    # Abgabe und Ende: Link zur Korrektur
    t.get_by_role("button", name=re.compile("Sitzplan")).first.click()
    t.wait_for_timeout(600)
    r = post_json(t, f"/api/pult/{exam}/stand?cursor=0", None, "GET")
    pids = [p["id"] for p in r["data"]["participants"]]
    post_json(t, f"/api/pult/{exam}/aktion", {"action": "submit", "participation_ids": pids})
    t.wait_for_timeout(2500)
    post_json(t, f"/api/pult/{exam}/aktion", {"action": "finish", "participation_ids": "all"})
    t.wait_for_timeout(3000)
    goto(t, f"/klausuren/{exam}/pult", 2500)
    ta = t.get_by_role("button", name=re.compile("Ton aktivieren"))
    if ta.count() and ta.first.is_visible():
        ta.first.click()
        t.wait_for_timeout(800)
    shot(t, "x-436-pult-beendet")
    print("Klausur", exam)


if __name__ == "__main__":
    run(main)
