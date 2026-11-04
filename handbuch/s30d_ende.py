"""Klausur #1, Teil D: Detailansicht, Replay, Gerätewechsel, Pause, Abgabe, Beenden."""
from kw import *
from s30c_pult import step, tile, btn
import sys

DLG = "dialog[open] button.btn-primary, [role=dialog] button.btn-primary, .modal button.btn-primary"


def main(b):
    t = sess(b, "frau.lehmann")
    t.set_viewport_size({"width": 1440, "height": 1100})
    mia = sess(b, "mia.becker"); goto(mia, "/klausur/1", 1500)
    lea = sess(b, "lea.koch"); goto(lea, "/klausur/1", 1500)
    pctx = new_ctx(b, "paul-neu"); paul = pctx.new_page(); goto(paul, "/klausur/1", 1500)
    goto(t, "/klausuren/1/pult", 2500)
    shot(t, "x-430-pult-voll")
    # Detail Protokoll / Zeit & Gerät / Replay
    step("detail", lambda: tile(t, 4).locator(".pult-tile-detail").click())
    t.wait_for_timeout(1500)
    step("tab protokoll", lambda: t.locator("button.tab", has_text="Protokoll").click())
    t.wait_for_timeout(1200); shot(t, "x-421-pult-detail-protokoll")
    step("tab zeit", lambda: t.locator("button.tab", has_text="Zeit").click())
    t.wait_for_timeout(1200); shot(t, "x-422-pult-detail-zeit-geraet")
    step("tab antworten", lambda: t.locator("button.tab", has_text="Antworten").click())
    t.wait_for_timeout(800)
    step("replay", lambda: t.get_by_role("button", name=re.compile("Replay")).first.click())
    t.wait_for_timeout(2500); shot(t, "x-424-replay")
    step("replay play", lambda: t.get_by_role("button", name=re.compile("Abspielen|▶")).first.click())
    t.wait_for_timeout(2500); shot(t, "x-425-replay-laeuft")
    step("close", lambda: btn(t, "Schließen").click())
    t.wait_for_timeout(500)
    step("close2", lambda: btn(t, "Schließen").click())
    # Anfragen: Gerätewechsel und Hand
    t.wait_for_timeout(1500)
    shot(t, "x-426-pult-anfragen")
    print(t.evaluate("[...document.querySelectorAll('[class*=request] button, [class*=anfrage] button')].map(b=>b.innerText)"))
    step("paul freigeben", lambda: t.locator("[class*=request]", has_text="Paul").get_by_role("button", name=re.compile("Freigeben|Wechsel")).first.click())
    t.wait_for_timeout(1500)
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(3500); shot(paul, "x-527-geraetewechsel-freigegeben"); shot(t, "x-427-pult-nach-wechsel")
    step("hand quittieren", lambda: t.get_by_role("button", name="Quittieren").first.click())
    # Pause alle
    step("pause", lambda: btn(t, "Alle pausieren").click())
    t.wait_for_timeout(800); shot(t, "x-428-pult-pause-dialog")
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(3500); shot(mia, "x-528-pause"); shot(t, "x-429-pult-pausiert")
    step("fortsetzen", lambda: btn(t, "Alle fortsetzen|Fortsetzen").click())
    t.wait_for_timeout(800)
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(3000)
    # Lea gibt ab
    step("lea abgeben", lambda: lea.locator("button", has_text="Abgeben").first.click())
    lea.wait_for_timeout(900); shot(lea, "x-526-abgabe-dialog")
    print(lea.evaluate("[...document.querySelectorAll('dialog[open] button, [role=dialog] button')].map(b=>b.innerText)"))
    step("lea ok", lambda: lea.locator(DLG).first.click())
    lea.wait_for_timeout(3500); shot(lea, "x-529-abgabe-angefragt"); shot(t, "x-431-pult-abgabe-anfrage")
    step("abgabe bestaetigen", lambda: t.locator("[class*=request]", has_text="Lea").get_by_role("button").first.click())
    t.wait_for_timeout(1500)
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(3000); shot(lea, "x-530-abgegeben"); shot(t, "x-432-pult-eine-abgegeben")
    # Alle übrigen abgeben
    step("alle", lambda: btn(t, "Alle auswählen").click())
    step("abgeben", lambda: t.locator("button", has_text="📥 Abgeben").first.click())
    t.wait_for_timeout(800); shot(t, "x-433-pult-abgeben-dialog")
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(3500); shot(t, "x-434-pult-alle-abgegeben")
    step("beenden", lambda: btn(t, "Klausur beenden").click())
    t.wait_for_timeout(800); shot(t, "x-435-pult-beenden-dialog")
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(3000); shot(t, "x-436-pult-beendet")
    shot(mia, "x-531-beendet")


if __name__ == "__main__":
    run(main)
