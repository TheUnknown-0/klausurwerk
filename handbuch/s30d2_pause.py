"""Klausur #1, Teil D (neu): Replay im Detailfenster, Pause fuer alle. Laeuft zwischen s30c und s30e."""
import time
from kw import *
from s30c_pult import step, tile, btn

DLG = "dialog[open] button.btn-primary, dialog[open] button.btn-danger"


def main(b):
    time.sleep(12)  # Herzschlag des vorigen Tabs (localStorage) muss abgelaufen sein
    t = sess(b, "frau.lehmann")
    t.set_viewport_size({"width": 1440, "height": 1100})
    mia = sess(b, "mia.becker"); goto(mia, "/klausur/1", 1500)
    goto(t, "/klausuren/1/pult", 2500)
    step("detail", lambda: tile(t, 4).locator(".pult-tile-detail").click())
    t.wait_for_timeout(1500)
    step("replay", lambda: t.get_by_role("button", name=re.compile("Replay")).first.click())
    t.wait_for_timeout(2500); shot(t, "x-424-replay")
    step("replay play", lambda: t.get_by_role("button", name=re.compile("Abspielen")).first.click())
    t.wait_for_timeout(2500); shot(t, "x-425-replay-laeuft")
    for _ in range(3):
        t.keyboard.press("Escape"); t.wait_for_timeout(500)
    step("pause", lambda: btn(t, "Alle pausieren").click())
    t.wait_for_timeout(800); shot(t, "x-428-pult-pause-dialog")
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(3500); shot(mia, "x-528-pause"); shot(t, "x-429-pult-pausiert")
    step("fortsetzen", lambda: btn(t, "Alle fortsetzen").click())
    t.wait_for_timeout(800)
    step("dlg", lambda: t.locator(DLG).first.click())
    t.wait_for_timeout(3000)
    mia.context.storage_state(path=str(STATE / "mia.becker.json"))


if __name__ == "__main__":
    run(main)
