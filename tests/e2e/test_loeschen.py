"""Löschen per Tastatur im Aufgaben-Editor, in Formularlisten und in Diagramm-Editoren.

Entf und Rücktaste löschen das ausgewählte Element (im Aufgaben-Editor mit Rückfrage), in Eingabefeldern
löscht die Rücktaste nur Zeichen. Der Aufgaben-Editor arbeitet an einer eigenen Kopie von Klausur 1 (Entwurf).
"""
from __future__ import annotations

import re

import pytest

from conftest import BASE, TEACHER, js_errors, login, new_context


@pytest.fixture(scope="module")
def editor(browser):
    t = login(new_context(browser), *TEACHER)
    t.goto(f"{BASE}/klausuren/1")
    t.get_by_role("button", name="Als Vorlage kopieren").click()
    t.wait_for_url(re.compile(r"/klausuren/\d+/einstellungen"))
    exam = int(re.search(r"/klausuren/(\d+)/", t.url).group(1))
    t.goto(f"{BASE}/klausuren/{exam}/aufgaben")
    t.locator(".te-node").first.wait_for(timeout=15000)
    return t


def nodes(t) -> list[str]:
    return [n.inner_text() for n in t.locator(".te-node").all()]


def node(t, title: str):
    return t.locator(".te-node", has_text=title).first


def test_entf_auf_markierter_aufgabe_fragt_nach(editor):
    t = editor
    before = nodes(t)
    assert any("Bubblesort" in n for n in before)

    messages: list[str] = []

    def dismiss(d):
        messages.append(d.message)
        d.dismiss()

    # Abbrechen: nichts wird gelöscht
    t.once("dialog", dismiss)
    node(t, "Bubblesort").click()
    t.keyboard.press("Delete")
    t.wait_for_timeout(500)
    assert len(messages) == 1 and "wirklich löschen" in messages[0] and "Bubblesort" in messages[0]
    assert nodes(t) == before

    # Bestätigen: Aufgabe verschwindet
    t.once("dialog", lambda d: d.accept())
    node(t, "Bubblesort").focus()
    t.keyboard.press("Delete")
    t.wait_for_function("() => ![...document.querySelectorAll('.te-node')].some(n => n.textContent.includes('Bubblesort'))", timeout=10000)
    assert len(nodes(t)) == len(before) - 1


def test_ruecktaste_in_der_liste_loescht_mit_rueckfrage(editor):
    t = editor
    before = nodes(t)
    t.once("dialog", lambda d: d.accept())
    node(t, "Begriffe").click()
    t.keyboard.press("Backspace")
    t.wait_for_function("() => ![...document.querySelectorAll('.te-node')].some(n => n.textContent.includes('Begriffe'))", timeout=10000)
    assert len(nodes(t)) == len(before) - 1


def test_listeneintrag_im_formular_per_taste_loeschen(editor):
    t = editor
    node(t, "Datentypen").click()
    rows = t.locator(".te-item[aria-label^='Antwortoption']")
    rows.first.wait_for(timeout=10000)
    n = rows.count()
    assert n >= 3, n
    last_text = rows.nth(n - 1).locator("textarea").input_value()

    # Zeile fokussieren (Klick auf einen freien Bereich der Zeile wählt sie ebenfalls) und Entf drücken
    rows.nth(n - 1).focus()
    assert "is-selected" in (rows.nth(n - 1).get_attribute("class") or "")
    t.keyboard.press("Delete")
    t.wait_for_function(f"() => document.querySelectorAll(\".te-item[aria-label^='Antwortoption']\").length === {n - 1}", timeout=5000)
    assert rows.count() == n - 1
    assert last_text == "" or last_text not in [r.locator("textarea").input_value() for r in rows.all()]

    # Rücktaste auf einer fokussierten Zeile löscht ebenfalls
    rows.nth(rows.count() - 1).focus()
    t.keyboard.press("Backspace")
    t.wait_for_function(f"() => document.querySelectorAll(\".te-item[aria-label^='Antwortoption']\").length === {n - 2}", timeout=5000)

    # Der Knopf "Löschen" wirkt auf die Auswahl; unter der Mindestzahl (2) ist er gesperrt
    while rows.count() > 2:
        rows.nth(0).focus()
        t.get_by_role("button", name=re.compile("Ausgewählte Zeile löschen")).click()
        t.wait_for_timeout(200)
    assert rows.count() == 2
    rows.nth(0).focus()
    assert t.get_by_role("button", name=re.compile("Ausgewählte Zeile löschen")).is_disabled()
    t.keyboard.press("Delete")
    t.wait_for_timeout(300)
    assert rows.count() == 2


def test_ruecktaste_im_textfeld_loescht_nur_zeichen(editor):
    t = editor
    node(t, "Datentypen").click()
    rows = t.locator(".te-item[aria-label^='Antwortoption']")
    rows.first.wait_for(timeout=10000)
    n = rows.count()
    area = rows.first.locator("textarea")
    area.click()
    area.fill("abc")
    t.keyboard.press("Backspace")
    assert area.input_value() == "ab"
    t.keyboard.press("Delete")  # am Textende: nichts zu löschen, aber auch keine Zeile
    assert rows.count() == n
    # Auch im Titelfeld der Aufgabe
    title = t.get_by_label(re.compile("^Titel")).first
    assert title.count() > 0
    value = title.input_value()
    count = len(nodes(t))
    title.click()
    t.keyboard.press("End")
    t.keyboard.press("Backspace")
    assert title.input_value() == value[:-1]
    assert len(nodes(t)) == count, "Rücktaste im Titelfeld darf keine Aufgabe löschen"


def test_diagramm_element_per_ruecktaste_loeschen(browser):
    t = login(new_context(browser), *TEACHER)
    errors: list[str] = []
    t.on("pageerror", lambda e: errors.append(str(e)))
    t.goto(f"{BASE}/klausuren/3/vorschau")
    t.get_by_text("Klassendiagramm Bibliothek").first.click()
    items = t.locator(".dg-item")
    t.get_by_role("button", name="＋ Klasse").first.click()
    t.get_by_role("button", name="＋ Klasse").first.click()
    t.wait_for_function("() => document.querySelectorAll('.dg-item').length >= 2", timeout=10000)
    n = items.count()

    # Rücktaste in einem Textfeld des Eigenschaften-Bereichs löscht nur Zeichen
    field = t.locator(".dg-inspector input[type=text], .dg-inspector input:not([type])").first
    if field.count() > 0 and field.is_visible():
        field.click()
        field.fill("Abc")
        t.keyboard.press("Backspace")
        assert field.input_value() == "Ab"
        assert items.count() == n

    # Element anklicken und mit der Rücktaste löschen
    items.first.focus()
    t.keyboard.press("Backspace")
    t.wait_for_function(f"() => document.querySelectorAll('.dg-item').length === {n - 1}", timeout=5000)

    # Entf auf dem nächsten Element
    items.first.focus()
    t.keyboard.press("Delete")
    t.wait_for_function(f"() => document.querySelectorAll('.dg-item').length === {n - 2}", timeout=5000)

    # Strg+Z holt es zurück (Fokus bleibt auf der Fläche, Tasten kommen an)
    t.keyboard.press("Control+z")
    t.wait_for_function(f"() => document.querySelectorAll('.dg-item').length === {n - 1}", timeout=5000)
    assert errors == [], errors
