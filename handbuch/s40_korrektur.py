"""Klausur #1 korrigieren, Ergebnisse, Freigabe, Rückgabe an die Schüler:innen (Kapitel 6, Schülerkapitel 7.10)."""
from kw import *
from s30c_pult import step

DLG = "dialog[open] button.btn-primary, [role=dialog] button.btn-primary, .modal button.btn-primary"
EX = 1


def main(b):
    import time
    time.sleep(12)  # Herzschlag des vorigen Tabs (localStorage) muss abgelaufen sein
    t = sess(b, "frau.lehmann")
    t.on("dialog", lambda d: d.accept())  # native Rueckfragen (data-confirm) bestaetigen
    t.set_viewport_size({"width": 1440, "height": 1000})
    goto(t, f"/klausuren/{EX}")
    shot(t, "600-klausur-beendet")
    goto(t, f"/klausuren/{EX}/korrektur")
    shot(t, "601-korrektur-vor-beginn")
    step("beginnen", lambda: t.get_by_role("button", name=re.compile("Korrektur beginnen")).click())
    t.wait_for_timeout(2500)
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(1500)
    shot(t, "602-korrektur-uebersicht-leer")
    step("vorbewerten", lambda: t.get_by_role("button", name=re.compile("Automatisch vorbewerten")).click())
    t.wait_for_timeout(1200)
    shot(t, "603-vorbewertung-laeuft")
    for _ in range(40):
        t.wait_for_timeout(2000)
        if not t.get_by_role("button", name=re.compile("Automatisch vorbewerten")).count() or t.get_by_role("button", name=re.compile("Automatisch vorbewerten")).first.is_enabled():
            break
    goto(t, f"/klausuren/{EX}/korrektur", 1500)
    shot(t, "604-matrix-vorschlaege")
    step("vorschlaege", lambda: t.get_by_role("button", name=re.compile("Alle Vorschläge übernehmen|Vorschläge übernehmen")).first.click())
    t.wait_for_timeout(2500)
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(1500)
    goto(t, f"/klausuren/{EX}/korrektur", 1500)
    shot(t, "605-matrix-uebernommen")
    # Einzelansichten (Mia = Teilnahme 1)
    goto(t, f"/klausuren/{EX}/korrektur/1?aufgabe=5", 2500)
    shot(t, "610-einzel-code")
    # Zeilenkommentar
    step("zeile", lambda: t.locator(".cm-line").nth(1).click())
    step("komm", lambda: t.get_by_role("button", name=re.compile("Kommentar zu Zeile")).first.click())
    t.wait_for_timeout(600)
    step("komm text", lambda: t.locator("textarea:visible").last.fill("Gute Lösung. Tipp: Der Schleifenzähler wird nicht benutzt, ein Unterstrich zeigt das an."))
    step("komm save", lambda: t.locator("textarea:visible").last.press("Control+Enter"))
    t.wait_for_timeout(1200)
    step("punkte", lambda: t.locator("input[inputmode], input[type=text]:visible, input[type=number]:visible").first.fill("5"))
    t.wait_for_timeout(1500)
    shot(t, "611-einzel-kommentar-punkte")
    goto(t, f"/klausuren/{EX}/korrektur/1?aufgabe=4", 2000)
    shot(t, "612-einzel-text")
    goto(t, f"/klausuren/{EX}/korrektur/1?aufgabe=2", 2000)
    shot(t, "613-einzel-choice")
    goto(t, f"/klausuren/{EX}/korrektur/1?aufgabe=5&modus=aufgabe", 2000)
    shot(t, "614-eine-aufgabe-fuer-alle")
    goto(t, f"/klausuren/{EX}/korrektur?anonym=1", 1500)
    shot(t, "615-anonym")
    # Zweitkorrektur: herr.yilmaz darf korrigieren
    goto(t, f"/klausuren/{EX}", 800)
    step("grade", lambda: t.locator("input[name=can_grade]").first.check())
    step("grade save", lambda: t.locator("form[action$='/lehrkraefte/3'] button[type=submit]").first.click())
    t.wait_for_timeout(1500)
    y = sess(b, "herr.yilmaz")
    goto(y, f"/klausuren/{EX}/korrektur/1?aufgabe=5", 2500)
    step("y punkte", lambda: y.locator("input[type=text]:visible, input[type=number]:visible").first.fill("4"))
    y.wait_for_timeout(1500)
    shot(y, "616-zweitkorrektur-yilmaz")
    goto(t, f"/klausuren/{EX}/korrektur?fremde=1", 1500)
    shot(t, "617-matrix-abweichung")
    # Restliche Zellen per Schnittstelle (je 1 Punkt), damit alle vollständig sind
    stand = post_json(t, f"/api/korrektur/{EX}/vorschlaege", {})
    d = post_json(t, f"/api/klausuren/{EX}/aufgaben", None, "GET")["data"]
    tasks = d.get("tasks") or d.get("data") or d if isinstance(d, dict) else d
    leaves = [x["id"] for x in tasks if x.get("answer_type")]
    for pid in range(1, 7):
        for tid in leaves:
            c = post_json(t, f"/api/korrektur/{EX}/zelle/{pid}/{tid}", None, "GET")["data"] or {}
            if c.get("own_points") is None:
                post_json(t, f"/api/korrektur/{EX}/bewertung", {"participation_id": pid, "task_id": tid, "points": 1})
    goto(t, f"/klausuren/{EX}/ergebnisse", 2000)
    shot(t, "620-ergebnisse")
    shot_at(t, "621-klausurspiegel", text="Klausurspiegel", offset=100)
    step("alle", lambda: t.get_by_role("button", name=re.compile("Alle vollständig bewerteten abschließen")).click())
    t.wait_for_timeout(1500)
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(2000)
    goto(t, f"/klausuren/{EX}/ergebnisse", 1500)
    shot(t, "622-ergebnisse-abgeschlossen")
    # PDF
    r = t.request.get(s(f"/klausuren/{EX}/ergebnisse/pdf/1"))
    (Path(__file__).parent / "data" / "ergebnis.pdf").write_bytes(r.body())
    step("freigeben", lambda: t.get_by_role("button", name=re.compile("Ergebnisse freigeben")).first.click())
    t.wait_for_timeout(1000)
    shot(t, "623-freigeben-dialog")
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(2500)
    shot(t, "624-zurueckgegeben")
    m = sess(b, "mia.becker")
    goto(m, "/meine-ergebnisse", 1000)
    shot(m, "x-700-meine-ergebnisse")
    goto(m, f"/meine-ergebnisse/{EX}", 2000)
    shot(m, "x-701-ergebnis-ansicht")
    shot_at(m, "x-702-ergebnis-aufgabe", text="Fibonacci", offset=100)


run(main)
