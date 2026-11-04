"""Aufnahmen Lehrkraft: Vorbereitung (Kapitel 4). Danach Aufgaben-Editor und Vorschau je Antworttyp."""
from kw import *

TYPES = [  # (Klausur, Titel, Kurzname)
    (1, "Datentypen", "choice"), (1, "Zählschleife", "gaps"), (1, "Laufzeit", "text"),
    (1, "Fibonacci in Python", "code-python"), (1, "Klasse Konto", "code-java"), (1, "SQL: Bibliothek", "code-sql"),
    (1, "Begriffe", "matching"), (1, "Bubblesort", "ordering"),
    (3, "Klassendiagramm Bibliothek", "uml"), (3, "Struktogramm", "structogram"), (3, "Programmablaufplan", "flowchart"),
    (3, "Schaltung", "logic"), (3, "Wahrheitstabelle", "truth_table"), (3, "Endlicher Automat", "automaton"),
    (3, "ER-Modell", "er"), (3, "Relationenschema", "relational"), (3, "Skizze", "drawing"),
    (2, "Quersumme", "code-input"), (2, "Turtle: Quadrat", "code-turtle"), (2, "Robo sammelt Schrauben", "robo"),
]


def main(b):
    ctx = new_ctx(b)
    pg = login(ctx, "frau.lehmann")
    shot(pg, "f-200-dashboard-lehrkraft")
    goto(pg, "/klausuren"); shot(pg, "201-klausuren-liste")
    goto(pg, "/klausuren/neu")
    pg.fill("#title", "Klausur Q1: Beispiel")
    pg.select_option("#course_id", "1"); pg.fill("#duration_minutes", "90"); pg.select_option("#room_id", "1")
    shot(pg, "202-klausur-neu")
    goto(pg, "/klausuren/1"); shot(pg, "203-klausur-seite")
    shot_at(pg, "204-klausur-lehrkraefte", text="Lehrkraft hinzufügen", offset=500)
    goto(pg, "/klausuren/1/einstellungen"); shot(pg, "205-einstellungen-1")
    shot_at(pg, "206-einstellungen-ueberwachung", text="Bei Verstoß sperren", offset=140)
    shot_at(pg, "207-einstellungen-darstellung", text="Darstellung und Ausführung", offset=100)
    shot_at(pg, "208-einstellungen-editor", text="Editor-Komfort", offset=100)
    shot_at(pg, "209-einstellungen-hilfsmittel", text="Taschenrechner", offset=500)
    goto(pg, "/klausuren/1/material"); shot(pg, "210-material")
    goto(pg, "/klausuren/1/seb"); shot(pg, "211-seb")
    goto(pg, "/archiv"); shot(pg, "212-archiv")
    shot_at(pg, "213-archiv-ergebnisse", text="In Klausur kopieren", offset=300)
    goto(pg, "/klausuren/import"); shot(pg, "214-import")
    goto(pg, "/klausuren/4"); shot(pg, "215-technik-check")
    goto(pg, "/klausuren/1/pult"); shot(pg, "x-216-pult-bereit")

    for ex, title, name in TYPES:
        goto(pg, f"/klausuren/{ex}/aufgaben", 500)
        pg.locator(".te-tree-list").get_by_text(title, exact=True).first.click()
        pg.wait_for_timeout(900)
        shot(pg, f"f-300-editor-{name}-1")
        shot_at(pg, f"f-300-editor-{name}-2", text="Antworttyp", offset=90)
    for ex, title, name in TYPES:
        goto(pg, f"/klausuren/{ex}/vorschau", 700)
        pg.get_by_text(title, exact=True).first.click()
        pg.wait_for_timeout(1200)
        shot(pg, f"x-310-vorschau-{name}")


run(main)
