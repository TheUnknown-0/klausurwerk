"""Klausur #1, Teil E: Anfragen bearbeiten, Abgabe, Beenden (nach s30d, frisches Pult ohne Detailfenster)."""
from kw import *
from s30c_pult import step, btn

DLG = "dialog[open] button.btn-primary, [role=dialog] button.btn-primary, .modal button.btn-primary, dialog[open] button.btn-danger"


def overlay(p):
    ov = p.get_by_role("button", name=re.compile("Klicke, um zu beginnen|beginnen|Weiter im Vollbild"))
    if ov.count() and ov.first.is_visible():
        ov.first.click()
        p.wait_for_timeout(800)


def main(b):
    import time
    time.sleep(12)  # Herzschlag des vorigen Tabs (localStorage) muss abgelaufen sein
    t = sess(b, "frau.lehmann")
    t.set_viewport_size({"width": 1440, "height": 1150})
    lea = sess(b, "lea.koch"); goto(lea, "/klausur/1", 1500); overlay(lea)
    ben = sess(b, "ben.hoffmann"); goto(ben, "/klausur/1", 1500)
    pctx = new_ctx(b, "paul-neu"); paul = pctx.new_page(); goto(paul, "/klausur/1", 1500)
    goto(t, "/klausuren/1/pult", 2500)
    shot(t, "x-426-pult-anfragen")
    print(t.evaluate("[...document.querySelectorAll('button')].map(b=>b.innerText.trim()).filter(x=>x)"))
    # Gerätewechsel für Paul freigeben
    shot(t, "x-427-pult-geraetewechsel-dialog")
    step("paul", lambda: t.locator("li.pult-request-device").get_by_role("button", name="Freigeben").first.click())
    t.wait_for_timeout(3500)
    shot(paul, "x-527-geraetewechsel-freigegeben")
    # Ben freigeben (Anfrage), Hand quittieren
    step("ben freigeben", lambda: t.locator("li.pult-request-release").get_by_role("button", name="Freigeben").first.click())
    t.wait_for_timeout(1500)
    step("hand", lambda: t.get_by_role("button", name="Quittieren").first.click())
    t.wait_for_timeout(1500)
    # Lea gibt ab
    overlay(lea)
    step("lea abgeben", lambda: lea.locator("button", has_text="Abgeben").first.click())
    lea.wait_for_timeout(900)
    shot(lea, "x-526-abgabe-dialog")
    step("lea ok", lambda: lea.locator(DLG).first.click())
    lea.wait_for_timeout(3500)
    shot(lea, "x-529-abgabe-angefragt")
    shot(t, "x-431-pult-abgabe-anfrage")
    print(t.evaluate("[...document.querySelectorAll('button')].map(b=>b.innerText.trim()).filter(x=>x)"))
    step("bestaetigen", lambda: t.get_by_role("button", name=re.compile("Abgabe bestätigen|Bestätigen")).first.click())
    t.wait_for_timeout(1200)
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(3000)
    shot(lea, "x-530-abgegeben")
    shot(t, "x-432-pult-eine-abgegeben")
    step("alle", lambda: btn(t, "Alle auswählen").click())
    step("abgeben", lambda: t.get_by_role("button", name="Abgeben", exact=True).first.click())
    t.wait_for_timeout(900)
    shot(t, "x-433-pult-abgeben-dialog")
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(3500)
    shot(t, "x-434-pult-alle-abgegeben")
    step("beenden", lambda: btn(t, "Klausur beenden").click())
    t.wait_for_timeout(900)
    shot(t, "x-435-pult-beenden-dialog")
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(3000)
    shot(t, "x-436-pult-beendet")
    r = t.evaluate("fetch('/api/pult/1/stand?cursor=0',{headers:{Accept:'application/json'}}).then(r=>r.json())")
    print(r["exam"]["status"], [(p["username"], p["state"]) for p in r["participants"]])


run(main)
