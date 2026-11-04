"""Klausur #1 abschließen (Rest über die Pult-Schnittstelle, Dialoge nur als Aufnahme)."""
from kw import *
from s30c_pult import step, btn

DLGB = "dialog[open] button, [role=dialog] button"


def act(t, action, pids, **extra):
    return post_json(t, "/api/pult/1/aktion", {"action": action, "participation_ids": pids, **extra})


def main(b):
    t = sess(b, "frau.lehmann")
    t.set_viewport_size({"width": 1440, "height": 1150})
    goto(t, "/klausuren/1/pult", 2500)
    st = t.evaluate("fetch('/api/pult/1/stand?cursor=0',{headers:{Accept:'application/json'}}).then(r=>r.json())")
    ids = {p["username"]: p["id"] for p in st["participants"]}
    print(act(t, "approve_device", [ids["paul.richter"]]))
    print(act(t, "release", [ids["ben.hoffmann"]]))
    t.wait_for_timeout(2500)
    shot(t, "x-430-pult-voll")
    # Dialog "Abgeben" und "Klausur beenden" nur aufnehmen
    step("alle", lambda: btn(t, "Alle auswählen").click())
    step("abgeben", lambda: t.locator("button", has_text="Abgeben").filter(has_text="📥").first.click())
    t.wait_for_timeout(900)
    shot(t, "x-433-pult-abgeben-dialog")
    print(t.evaluate("[...document.querySelectorAll('dialog[open] button, [role=dialog] button')].map(b=>b.innerText+'|'+b.className)"))
    step("abbrechen", lambda: t.locator(DLGB, has_text="Abbrechen").first.click())
    t.wait_for_timeout(500)
    r = act(t, "submit", [i for n, i in ids.items() if n != "lea.koch"])
    print(r)
    t.wait_for_timeout(3000)
    shot(t, "x-434-pult-alle-abgegeben")
    step("beenden", lambda: btn(t, "Klausur beenden").click())
    t.wait_for_timeout(900)
    shot(t, "x-435-pult-beenden-dialog")
    step("abbrechen", lambda: t.locator(DLGB, has_text="Abbrechen").first.click())
    print(act(t, "finish", "all"))
    t.wait_for_timeout(3000)
    shot(t, "x-436-pult-beendet")
    st = t.evaluate("fetch('/api/pult/1/stand?cursor=0',{headers:{Accept:'application/json'}}).then(r=>r.json())")
    print(st["exam"]["status"], [(p["username"], p["state"]) for p in st["participants"]])


run(main)
