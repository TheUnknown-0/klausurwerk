"""Sicherheit aus Sicht einer Schülerin, gegen den echten Stack.

- Aufgabeninhalte verraten keine Lösungen (auch nicht in config).
- Pult-, Lehrkräfte- und Verwaltungs-APIs sind gesperrt.
- Aufgaben sind vor dem Start nicht abrufbar.
- POST ohne CSRF-Token wird abgelehnt.
- Fremde Klausuren (ohne Kursmitgliedschaft) sind nicht erreichbar.
"""
from __future__ import annotations

import json
import re

from conftest import BASE, js_errors, login, new_context, student

SECRET_KEYS = re.compile(r'"(correct|model_answer|expectation_md|tests|accepted|solution|pairs_solution|expected_query)"\s*:')


def fetch(page, path: str, method: str = "GET", body: dict | None = None, csrf: bool = True) -> tuple[int, str]:
    return tuple(page.evaluate(
        """async ([path, method, body, csrf]) => {
            const headers = {Accept: 'application/json'};
            if (csrf) headers['X-CSRF-Token'] = document.querySelector('meta[name=csrf-token]').content;
            if (body) headers['Content-Type'] = 'application/json';
            const r = await fetch(path, {method, headers, body: body ? JSON.stringify(body) : undefined});
            return [r.status, await r.text()];
        }""",
        [path, method, body, csrf],
    ))


def test_inhalt_ohne_loesungen(browser):
    page = login(new_context(browser), *student("emma.wagner"))
    page.goto(f"{BASE}/klausur/2")
    page.get_by_role("button", name=re.compile("^Bereit")).click()
    page.wait_for_timeout(1500)
    status, text = fetch(page, "/api/klausur/2/inhalt")
    assert status == 200, text[:300]
    data = json.loads(text)
    assert data["tasks"], "Übung sollte Aufgaben liefern"
    assert not SECRET_KEYS.search(text), SECRET_KEYS.search(text).group(0)
    assert "a, b = b, a + b" not in text and "sum(int(z) for z in zahl)" not in text, "Musterlösung im Inhalt"


def test_sperren_fuer_schueler(browser):
    page = login(new_context(browser), *student("emma.wagner"))
    page.goto(f"{BASE}/meine-klausuren")
    checks = [
        ("/api/pult/1/stand?cursor=0", "GET", None),
        ("/api/pult/1/aktion", "POST", {"action": "start", "participation_ids": "all"}),
        ("/api/pult/1/antworten/1", "GET", None),
        ("/api/klausuren/1/aufgaben", "GET", None),
        ("/api/benutzer/suche?q=a&role=student", "GET", None),
        ("/api/markdown/vorschau", "POST", {"markdown": "x"}),
    ]
    for path, method, body in checks:
        status, text = fetch(page, path, method, body)
        assert status in (401, 403, 404), (path, status, text[:200])


def test_inhalt_vor_dem_start_gesperrt(browser):
    """Klausur #3 (Technik-Check) ist Entwurf; Klausur #1 nach Reset 'bereit' oder beendet."""
    page = login(new_context(browser), *student("lea.koch"))
    page.goto(f"{BASE}/meine-klausuren")
    for exam in (1, 3):
        status, text = fetch(page, f"/api/klausur/{exam}/inhalt")
        assert status in (403, 404, 409), (exam, status, text[:200])
        assert not SECRET_KEYS.search(text)


def test_post_ohne_csrf_abgelehnt(browser):
    page = login(new_context(browser), *student("lea.koch"))
    page.goto(f"{BASE}/meine-klausuren")
    status, _ = fetch(page, "/api/klausur/2/anfrage", "POST", {"type": "hand"}, csrf=False)
    assert status == 419


def test_keine_js_fehler_auf_login(browser):
    ctx = new_context(browser)
    page = ctx.new_page()
    errors: list[str] = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.goto(f"{BASE}/login#u=test&p=geheim")
    assert page.input_value("#username") == "test"
    assert "geheim" not in page.url, "Passwort aus dem QR-Fragment muss aus der Adresse verschwinden"
    assert errors == []
