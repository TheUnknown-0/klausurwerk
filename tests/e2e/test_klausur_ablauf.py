"""Kernablauf einer Klausur, Ende zu Ende im echten Browser.

Lehrkraft öffnet den Warteraum, zwei Schüler:innen treten bei, Start,
Antworten speichern, Verstoß (Vollbild verlassen) sperrt, Freigabe,
Abgabe-Anfrage und Bestätigung, Abgabe durch die Lehrkraft, Klausur beenden.
Läuft gegen die Demo-Daten aus bin/demo.php (Klausur #1).
"""
from __future__ import annotations

import os
import re
from pathlib import Path

from conftest import BASE, TEACHER, js_errors, login, new_context, student

SHOTS = Path(os.environ.get("KW_SHOTS", Path(__file__).parent / "shots"))
SHOTS.mkdir(exist_ok=True)
EXAM = 1


def shot(page, name: str) -> None:
    page.screenshot(path=str(SHOTS / f"{name}.png"), full_page=False)


def api_state(page) -> dict:
    """Aktueller Pult-Stand über die API (gleiche Sitzung wie die Seite)."""
    return page.evaluate(f"fetch('/api/pult/{EXAM}/stand?cursor=0', {{headers: {{Accept: 'application/json'}}}}).then(r => r.json())")


def participant(stand: dict, username: str) -> dict:
    for p in stand["participants"]:
        if p.get("username") == username:
            return p
    raise AssertionError(f"{username} nicht im Pult: {[p.get('username') for p in stand['participants']]}")


def pult_action(page, action: str, pids, **extra) -> dict:
    body = {"action": action, "participation_ids": pids, **extra}
    return page.evaluate(
        """([url, body]) => fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json',
             'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content}, body: JSON.stringify(body)}).then(r => r.json())""",
        [f"/api/pult/{EXAM}/aktion", body],
    )


def wait_state(teacher, username: str, state: str, timeout_ms: int = 15000) -> dict:
    deadline = timeout_ms
    while deadline > 0:
        p = participant(api_state(teacher), username)
        if p["state"] == state:
            return p
        teacher.wait_for_timeout(500)
        deadline -= 500
    raise AssertionError(f"{username}: Zustand {p['state']} statt {state}")


def test_kompletter_klausurablauf(browser):
    teacher = login(new_context(browser), *TEACHER)
    teacher.goto(f"{BASE}/klausuren/{EXAM}/pult")
    teacher.get_by_role("button", name=re.compile("Warteraum öffnen")).click()
    teacher.wait_for_timeout(1500)
    assert api_state(teacher)["exam"]["status"] == "lobby"

    pages = {}
    for name in ("mia.becker", "noah.schulz"):
        s = login(new_context(browser), *student(name))
        s.goto(f"{BASE}/klausur/{EXAM}")
        s.get_by_role("button", name=re.compile("^Bereit")).click()
        s.wait_for_timeout(1500)
        pages[name] = s
    shot(pages["mia.becker"], "10-warteraum")

    for name in pages:
        assert participant(api_state(teacher), name)["state"] == "waiting"

    # Start: Bestätigungsdialog im Pult annehmen
    teacher.get_by_role("button", name=re.compile("Starten|Klausur starten")).first.click()
    teacher.get_by_role("button", name="Jetzt starten").click()
    teacher.wait_for_timeout(2500)
    stand = api_state(teacher)
    assert stand["exam"]["status"] == "running"
    shot(teacher, "11-pult-laeuft")

    mia = pages["mia.becker"]
    # Nach dem Start lädt die Schüleransicht die Aufgaben (ggf. Klick für Vollbild)
    mia.wait_for_timeout(3000)
    overlay = mia.get_by_role("button", name=re.compile("beginnen|Weiter im Vollbild|Vollbild"))
    if overlay.count() > 0 and overlay.first.is_visible():
        overlay.first.click()
    mia.wait_for_selector("text=Datentypen", timeout=15000)
    shot(mia, "12-klausur-mia")

    # Multiple Choice beantworten und Autosave abwarten
    mia.get_by_text("Datentypen").first.click()
    mia.wait_for_timeout(500)
    boxes = mia.locator("input[type=checkbox]")
    assert boxes.count() >= 2
    boxes.nth(0).check()
    mia.wait_for_timeout(6000)
    p = participant(api_state(teacher), "mia.becker")
    assert p["progress"]["answered"] >= 1, p

    # Verstoß: Vollbild verlassen bzw. Fenster verborgen -> Sperre
    mia.evaluate("document.fullscreenElement ? document.exitFullscreen() : window.dispatchEvent(new Event('blur'))")
    mia.evaluate("Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => 'hidden'}); document.dispatchEvent(new Event('visibilitychange'))")
    locked = wait_state(teacher, "mia.becker", "locked")
    assert locked["lock_reason"]
    shot(mia, "13-gesperrt")
    stand = api_state(teacher)
    assert any(e["type"] in ("fullscreen_exit", "visibility_hidden", "window_blur") and e["severity"] == "alarm" for e in stand["events"])

    # Freigabe durch die Lehrkraft
    mia.evaluate("Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => 'visible'}); document.dispatchEvent(new Event('visibilitychange'))")
    res = pult_action(teacher, "release", [locked["id"]])
    assert res["success"], res
    wait_state(teacher, "mia.becker", "active")

    # Zusatzzeit und Durchsage
    res = pult_action(teacher, "add_time", [locked["id"]], minutes=10)
    assert res["success"], res
    assert participant(api_state(teacher), "mia.becker")["clock"]["extra_minutes"] == 10
    res = pult_action(teacher, "announce", "all", message="Aufgabe 3: gemeint ist die iterative Lösung.")
    assert res["success"], res
    mia.wait_for_selector("text=gemeint ist die iterative Lösung", timeout=10000)

    # Abgabe-Anfrage durch Mia, Bestätigung durch die Lehrkraft
    res = mia.evaluate(
        """() => fetch('/api/klausur/1/anfrage', {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json',
             'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content}, body: JSON.stringify({type: 'submit'})}).then(r => r.json())"""
    )
    assert res["success"], res
    p = wait_state(teacher, "mia.becker", "submit_requested")
    res = pult_action(teacher, "approve_submit", [p["id"]])
    assert res["success"], res
    wait_state(teacher, "mia.becker", "submitted")
    mia.wait_for_timeout(3000)
    shot(mia, "14-abgegeben")

    # Noah gibt die Lehrkraft ab, danach Klausur beenden
    noah = participant(api_state(teacher), "noah.schulz")
    res = pult_action(teacher, "submit", [noah["id"]])
    assert res["success"], res
    res = pult_action(teacher, "finish", "all")
    assert res["success"], res
    assert api_state(teacher)["exam"]["status"] == "finished"

    for page in [teacher, *pages.values()]:
        assert js_errors(page) == [], js_errors(page)
