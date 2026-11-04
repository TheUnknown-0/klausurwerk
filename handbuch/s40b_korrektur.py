"""Klausur #1 korrigieren, Ergebnisse, Freigabe, Rückgabe an die Schüler:innen (Kapitel 6, Schülerkapitel 7.10)."""
from kw import *
from s30c_pult import step

DLG = "dialog[open] button.btn-primary, [role=dialog] button.btn-primary, .modal button.btn-primary"
EX = 1


def main(b):
    t = sess(b, "frau.lehmann")
    t.set_viewport_size({"width": 1440, "height": 1000})
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
