"""Test #3: Schüleransicht aller Diagrammtypen (nach s50; entsperrt bei Bedarf über das Pult)."""
from kw import *
from s30c_pult import step
from s30b_schueler import pick
from s50_typen import TITLES3, overlay


def main(b):
    import time
    time.sleep(12)  # Herzschlag des vorigen Tabs (localStorage) muss abgelaufen sein
    t = sess(b, "frau.lehmann")
    goto(t, "/klausuren/3/pult", 1500)
    st = t.evaluate("fetch('/api/pult/3/stand?cursor=0',{headers:{Accept:'application/json'}}).then(r=>r.json())")
    pid = [p["id"] for p in st["participants"] if p["username"] == "mia.becker"][0]
    m = sess(b, "mia.becker")
    goto(m, "/klausur/3", 2000)

    def unlock():
        for _ in range(4):
            if m.get_by_text("Die Klausur ist gesperrt").count() or m.get_by_role("button", name=re.compile("Klicke, um zu beginnen")).count():
                post_json(t, "/api/pult/3/aktion", {"action": "release", "participation_ids": [pid]})
                m.wait_for_timeout(3000)
                overlay(m)
                m.wait_for_timeout(800)
            else:
                break

    unlock()
    for k, title in TITLES3.items():
        unlock()
        step(k, lambda: pick(m, title))
        unlock()
        step(k, lambda: pick(m, title))
        m.wait_for_timeout(900)
        shot(m, f"x-550-schueler-{k}")
    m.context.storage_state(path=str(STATE / "mia.becker.json"))


run(main)
