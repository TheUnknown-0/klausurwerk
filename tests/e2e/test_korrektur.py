"""Korrektur nach dem Klausurablauf (läuft nach test_klausur_ablauf.py):
Korrektur beginnen, automatisch vorbewerten (Auswahl + Code-Tests im Runner),
Punkte vergeben, Ergebnisse abschließen und freigeben, Rückgabe an die Schülerin.
"""
from __future__ import annotations

import re
import subprocess
from pathlib import Path

from conftest import BASE, TEACHER, js_errors, login, new_context, student

EXAM = 1


def fetch_json(page, path: str, method: str = "GET", body: dict | None = None):
    return page.evaluate(
        """async ([path, method, body]) => {
            const headers = {Accept: 'application/json', 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content};
            if (body) headers['Content-Type'] = 'application/json';
            const r = await fetch(path, {method, headers, body: body ? JSON.stringify(body) : undefined});
            let data = null; try { data = await r.json(); } catch (e) {}
            return {status: r.status, data};
        }""",
        [path, method, body],
    )


ROOT = Path(__file__).resolve().parents[2]


def sql(query: str) -> list[list[str]]:
    """Abfrage gegen die Datenbank des Compose-Stacks (nur für Testvorbereitung)."""
    env = dict(line.split("=", 1) for line in (ROOT / ".env").read_text(encoding="utf-8").splitlines() if "=" in line and not line.startswith("#"))
    out = subprocess.run(
        ["docker", "compose", "exec", "-T", "-e", f"MYSQL_PWD={env['DB_ROOT_PASS']}", "db", "mariadb", "-uroot", "-N", "-B",
         env.get("DB_NAME", "klausurwerk"), "-e", query],
        cwd=ROOT, capture_output=True, stdin=subprocess.DEVNULL, text=True, encoding="utf-8", check=True,
    ).stdout
    return [row.split("	") for row in out.strip().splitlines() if row]


def test_korrektur_und_rueckgabe(browser):
    t = login(new_context(browser), *TEACHER)
    t.goto(f"{BASE}/klausuren/{EXAM}/korrektur")
    t.get_by_role("button", name=re.compile("Korrektur beginnen")).click()
    t.wait_for_load_state("networkidle")

    # Vorbewertung als Stapel, bis nichts mehr offen ist
    for _ in range(30):
        res = fetch_json(t, f"/api/korrektur/{EXAM}/vorbewertung", "POST", {})
        assert res["status"] == 200, res
        status = res["data"].get("status", res["data"])
        if not status.get("pending"):
            break
        t.wait_for_timeout(500)
    assert status.get("errors", 0) == 0, status

    t.goto(f"{BASE}/klausuren/{EXAM}/korrektur")
    t.wait_for_timeout(1500)
    t.screenshot(path="shots/51-korrektur-matrix.png", full_page=True)

    # Vorschläge übernehmen, Ergebnisse abschließen, freigeben
    res = fetch_json(t, f"/api/korrektur/{EXAM}/vorschlaege", "POST", {})
    assert res["status"] == 200, res

    # Restliche Aufgaben von Hand bewerten (Freitext usw.): je 1 Punkt
    pids = [int(r[0]) for r in sql(f"SELECT id FROM participations WHERE exam_id = {EXAM} AND state = 'submitted'")]
    leaves = [int(r[0]) for r in sql(f"SELECT id FROM tasks WHERE exam_id = {EXAM} AND answer_type IS NOT NULL")]
    assert pids and leaves
    for pid in pids:
        for tid in leaves:
            graded = sql(f"SELECT COUNT(*) FROM grading_entries WHERE participation_id = {pid} AND task_id = {tid} AND points IS NOT NULL")
            if graded[0][0] == "0":
                res = fetch_json(t, f"/api/korrektur/{EXAM}/bewertung", "POST", {"participation_id": pid, "task_id": tid, "points": 1})
                assert res["status"] == 200, res
    t.goto(f"{BASE}/klausuren/{EXAM}/ergebnisse")
    t.wait_for_timeout(1500)
    t.screenshot(path="shots/52-ergebnisse.png", full_page=True)
    assert "Mia Becker" in t.locator("main").inner_text()

    t.once("dialog", lambda d: d.accept())
    t.get_by_role("button", name=re.compile("Alle vollständig bewerteten abschließen")).click()
    t.wait_for_load_state("networkidle")
    assert sql(f"SELECT COUNT(*) FROM participation_results r JOIN participations p ON p.id = r.participation_id WHERE p.exam_id = {EXAM} AND r.finalized_at IS NOT NULL")[0][0] == str(len(pids))
    t.goto(f"{BASE}/klausuren/{EXAM}/ergebnisse")
    t.once("dialog", lambda d: d.accept())
    frei = t.get_by_role("button", name=re.compile("Ergebnisse freigeben"))
    assert frei.count() > 0
    frei.first.click()
    t.wait_for_load_state("networkidle")
    assert js_errors(t) == [], js_errors(t)

    # PDF einer Person
    pdf = t.request.get(f"{BASE}/klausuren/{EXAM}/ergebnisse/pdf")
    assert pdf.ok and pdf.body()[:4] == b"%PDF"

    # Rückgabe: Mia sieht ihre Klausur, aber keine Musterlösung
    s = login(new_context(browser), *student("mia.becker"))
    s.goto(f"{BASE}/meine-ergebnisse")
    assert "Algorithmen und Datenbanken" in s.locator("main").inner_text()
    assert "noch nicht abgeschlossen" not in s.locator("main").inner_text()
    s.goto(f"{BASE}/meine-ergebnisse/{EXAM}")
    s.wait_for_timeout(1500)
    s.screenshot(path="shots/53-rueckgabe.png", full_page=True)
    text = s.locator("main").inner_text()
    assert "Datentypen" in text
    assert "a, b = b, a + b" not in s.content(), "Musterlösung darf nicht erscheinen"
    assert js_errors(s) == [], js_errors(s)
