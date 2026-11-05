"""Aufnahmen zum vereinfachten Anlegen (Kapitel 4.1 bis 4.3): Anlegeformular, Profile,
Einstellungen mit "Erweitert", Fragenliste und Aufgaben-Editor mit Autosave und Typauswahl als Kacheln.

Speichert nichts: Formulare werden nur ausgefüllt, nicht abgeschickt. Nutzt die Entwurfs-Kopie
der Demo-Klausur (Klausur 6), damit Profile und Editor bearbeitbar erscheinen.
"""
from kw import *

DRAFT = 6


def main(b):
    ctx = new_ctx(b)
    pg = login(ctx, "frau.lehmann")

    goto(pg, "/klausuren/neu")
    pg.fill("#title", "Klausur Q1: Rekursion")
    pg.fill("#duration_minutes", "90")
    pg.select_option("#template", "profil:streng")
    shot(pg, "202-klausur-neu")

    goto(pg, f"/klausuren/{DRAFT}/einstellungen")
    shot_at(pg, "205b-einstellungen-profile", text="Schnellstart mit Profil", offset=80)
    shot_at(pg, "206-einstellungen-ueberwachung", text="Bei Verstoß sperren", offset=140)
    shot_at(pg, "208-einstellungen-editor", text="Editor-Hilfen und Hilfsmittel", offset=80)
    pg.locator("details.settings-advanced > summary").click()
    pg.wait_for_timeout(300)
    shot_at(pg, "206b-einstellungen-live", text="Live-Ansicht der Klausurseite", offset=260)
    shot_at(pg, "207-einstellungen-darstellung", text="Darstellung und Ausführung", offset=80)

    goto(pg, f"/klausuren/{DRAFT}/aufgaben", 500)
    shot(pg, "f-302-fragenliste")
    pg.locator('.qf-card.is-preview:has(.qf-title:text-is("Datentypen"))').click()
    pg.wait_for_timeout(700)
    shot_at(pg, "f-303-fragenliste-karte", selector=".qf-card.is-active", offset=110)
    detail_view(pg)
    pg.locator(".te-tree-list").get_by_text("Datentypen", exact=True).first.click()
    pg.wait_for_timeout(900)
    shot(pg, "f-300-editor-choice-1")
    pg.get_by_role("button", name="Aufgabe", exact=True).click()
    pg.wait_for_timeout(600)
    shot(pg, "f-301-editor-typauswahl")


run(main)
