"""Aufnahmen Admin: Anmeldung, Verwaltung (Kapitel 2 und 3). Vorher: tests/e2e/reset.sh"""
from pathlib import Path
from kw import *

DATA = Path(__file__).parent / "data"


def main(b):
    # Abgemeldet: Anmeldeseite, Fehler, Zugangs-QR
    ctx = new_ctx(b)
    pg = ctx.new_page()
    goto(pg, "/login")
    shot(pg, "c-100-login")
    pg.fill("#username", "frau.lehmann")
    pg.fill("#password", "falsch")
    pg.click("form[data-login-form] button[type=submit]")
    settle(pg)
    shot(pg, "c-101-login-fehler")
    goto(pg, "/healthz")
    print("healthz", pg.inner_text("body"))
    pg.close()

    ctx = new_ctx(b)
    pg = login(ctx, "admin")
    shot(pg, "f-110-dashboard-admin")
    # Dunkelmodus
    pg.locator("button[data-theme-toggle], .theme-toggle, button[aria-label*='Dunkel'], button[title*='Dunkel']").first.click()
    pg.wait_for_timeout(400)
    shot(pg, "f-111-dunkelmodus")
    pg.locator("button[data-theme-toggle], .theme-toggle, button[aria-label*='Dunkel'], button[title*='Dunkel']").first.click()
    pg.wait_for_timeout(300)

    goto(pg, "/admin/einstellungen")
    shot(pg, "120-einstellungen-1")
    shot_at(pg, "121-einstellungen-2", text="Anmeldung von Lehrkräften", offset=90)
    shot_at(pg, "122-einstellungen-3", text="Seitenpasswort", offset=90)

    goto(pg, "/admin/benutzer")
    shot(pg, "130-benutzer-liste")
    goto(pg, "/admin/benutzer/neu")
    shot(pg, "131-benutzer-neu")
    # Schüler-Formular mit Eingaben
    pg.fill("#firstname", "Tim")
    pg.fill("#lastname", "Neumann")
    pg.wait_for_timeout(700)
    shot(pg, "132-benutzer-neu-ausgefuellt")
    goto(pg, "/admin/benutzer/6")
    shot(pg, "133-benutzer-bearbeiten-na")
    shot_at(pg, "134-benutzer-bearbeiten-unten", text="Konto löschen", offset=300)

    goto(pg, "/admin/benutzer/import")
    shot(pg, "135-import-start")
    pg.set_input_files("#csv", str(DATA / "beispiel-import.csv"))
    pg.click("button:has-text('Vorschau erstellen')")
    settle(pg)
    shot(pg, "136-import-vorschau")
    pg.click("button:has-text('3 Schüler:innen importieren')")
    settle(pg)
    shot(pg, "137-import-ergebnis")

    goto(pg, "/admin/kurse")
    shot(pg, "140-kurse")
    goto(pg, "/admin/kurse/1")
    shot(pg, "141-kurs-detail")
    shot_at(pg, "142-kurs-detail-unten", text="Mitglieder (6)", offset=90)
    goto(pg, "/admin/kurse/neu")
    shot(pg, "143-kurs-neu")

    goto(pg, "/admin/zugangskarten")
    shot(pg, "150-zugangskarten")

    goto(pg, "/admin/notenschluessel")
    shot(pg, "160-notenschluessel")
    goto(pg, "/admin/notenschluessel/2")
    shot(pg, "161-notenschluessel-bearbeiten")
    goto(pg, "/admin/operatoren")
    shot(pg, "162-operatoren")
    shot_at(pg, "163-operatoren-neu", text="Neuer Operator", offset=90)

    goto(pg, "/admin/raeume")
    shot(pg, "170-raeume")
    goto(pg, "/admin/raeume/1")
    shot(pg, "171-raum-sitzplan")

    goto(pg, "/admin/aufbewahrung")
    shot(pg, "180-aufbewahrung")
    goto(pg, "/admin/berechtigungen")
    shot(pg, "181-berechtigungen")
    goto(pg, "/admin/berechtigungen?benutzer=2")
    shot(pg, "182-berechtigungen-lehrkraft")
    shot_at(pg, "183-berechtigungen-lehrkraft-2", text="Räume", offset=90)
    goto(pg, "/admin/berechtigungen/gruppen/neu")
    shot(pg, "184-gruppe-neu")
    goto(pg, "/admin/audit-log")
    shot(pg, "185-audit-log")


run(main)
