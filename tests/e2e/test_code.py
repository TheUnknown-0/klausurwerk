"""Code-Ausführung im echten Browser: Python (Pyodide, interaktives input()), Java (Runner), SQL (sql.js)."""
from __future__ import annotations

import re

from conftest import BASE, TEACHER, login, new_context, student


def test_python_interaktive_eingabe(browser):
    s = login(new_context(browser), *student("noah.schulz"))
    s.goto(f"{BASE}/klausur/2")
    s.get_by_role("button", name=re.compile("^Bereit")).click()
    s.wait_for_selector(".cm-content", timeout=20000)
    assert s.evaluate("window.crossOriginIsolated"), "Seite muss cross-origin-isolated sein (SharedArrayBuffer)"
    s.locator(".cm-content").first.click()
    s.keyboard.press("Control+End")
    s.keyboard.type("\nprint(sum(int(z) for z in zahl))\n")
    s.get_by_role("button", name=re.compile("Ausführen")).first.click()
    field = s.locator("form.cw-console-input input")
    field.wait_for(state="visible", timeout=60000)
    field.fill("4711")
    field.press("Enter")
    s.wait_for_selector("text=Programm beendet", timeout=30000)
    assert "13" in s.locator(".cw-console, [class*=console]").first.inner_text()


def test_java_und_sql_in_der_vorschau(browser):
    t = login(new_context(browser), *TEACHER)
    t.goto(f"{BASE}/klausuren/1/vorschau")
    t.get_by_text("Klasse Konto").first.click()
    t.get_by_role("button", name=re.compile("Ausführen")).first.click()
    t.wait_for_selector("text=Programm beendet", timeout=60000)
    body = t.locator("body").inner_text()
    assert "false" in body and "0" in body

    t.get_by_text("SQL: Bibliothek").first.click()
    t.locator(".cm-content:visible").first.click()
    t.keyboard.press("Control+A")
    t.keyboard.type("SELECT titel, jahr FROM buch WHERE ausgeliehen = 0 ORDER BY jahr DESC;")
    t.get_by_role("button", name=re.compile("Ausführen")).first.click()
    t.wait_for_selector("text=3 Zeilen", timeout=30000)
    assert "Tschick" in t.locator("body").inner_text()
