"""Rauchtest: alle wichtigen Seiten je Rolle laden ohne Serverfehler und ohne JS-Fehler."""
from __future__ import annotations

import pytest

from conftest import ADMIN, BASE, TEACHER, js_errors, login, new_context, student

ADMIN_PAGES = [
    "/dashboard", "/klausuren", "/klausuren/neu", "/archiv", "/admin/kurse", "/admin/raeume",
    "/admin/benutzer", "/admin/benutzer/neu", "/admin/benutzer/import", "/admin/zugangskarten",
    "/admin/notenschluessel", "/admin/operatoren", "/admin/berechtigungen", "/admin/einstellungen",
    "/admin/aufbewahrung", "/admin/audit-log", "/passwort-aendern",
]
TEACHER_PAGES = [
    "/dashboard", "/klausuren", "/klausuren/1", "/klausuren/1/einstellungen", "/klausuren/1/aufgaben",
    "/klausuren/1/material", "/klausuren/1/seb", "/klausuren/1/pult",
    "/klausuren/1/vorschau", "/klausuren/2/pult", "/archiv", "/admin/kurse", "/admin/benutzer",
]
STUDENT_PAGES = ["/meine-klausuren", "/meine-ergebnisse", "/klausur/2"]
FORBIDDEN_FOR_STUDENT = ["/dashboard", "/klausuren", "/klausuren/1/pult", "/admin/benutzer", "/archiv"]


def visit(page, path: str) -> int:
    response = page.goto(BASE + path)
    page.wait_for_timeout(1200)
    return response.status if response else 0


@pytest.mark.parametrize("who,pages", [(ADMIN, ADMIN_PAGES), (TEACHER, TEACHER_PAGES)], ids=["admin", "lehrkraft"])
def test_seiten_ohne_fehler(browser, who, pages):
    page = login(new_context(browser), *who)
    failures = []
    for path in pages:
        page.kw_errors.clear()
        status = visit(page, path)
        errors = js_errors(page)
        if status >= 400 or errors:
            failures.append((path, status, errors[:3]))
    assert failures == []


def test_schuelerseiten_und_sperren(browser):
    page = login(new_context(browser), *student("ben.hoffmann"))
    failures = []
    for path in STUDENT_PAGES:
        page.kw_errors.clear()
        status = visit(page, path)
        if status >= 400 or js_errors(page):
            failures.append((path, status, js_errors(page)[:3]))
    for path in FORBIDDEN_FOR_STUDENT:
        status = visit(page, path)
        if status not in (403, 404):
            failures.append((path, status, "sollte gesperrt sein"))
    assert failures == []
