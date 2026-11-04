"""Zugangskarten-PDF (Kurs 10a aus dem Import) und Technik-Check (Probe #4)."""
from kw import *
from s30c_pult import step, btn

DATA = Path(__file__).parent / "data"
DLG = "dialog[open] button.btn-primary, [role=dialog] button.btn-primary, .modal button.btn-primary"


def main(b):
    import time
    time.sleep(12)  # Herzschlag des vorigen Tabs (localStorage) muss abgelaufen sein
    a = sess(b, "admin")
    import sys
    if "--probe" in sys.argv:
        return probe(b)
    goto(a, "/admin/zugangskarten", 800)
    opts = a.evaluate("[...document.querySelectorAll('#kurs-select option')].map(o=>o.value+'='+o.text)")
    print(opts)
    val = [o.split("=")[0] for o in opts if "10a" in o][0]
    a.select_option("#kurs-select", val)
    a.locator("input[name=bestaetigt]").last.check()
    a.wait_for_timeout(400)
    shot(a, "151-zugangskarten-ausgefuellt")
    import base64
    b64 = a.evaluate("""async () => {
        const forms = [...document.querySelectorAll('form')].filter(f => f.method === 'post' && f.querySelector('input[name=bestaetigt]') && f.querySelector('#kurs-select'));
        const f = forms[0];
        const fd = new FormData(f);
        const r = await fetch(f.action, {method: 'POST', body: fd});
        const buf = new Uint8Array(await r.arrayBuffer());
        let s = ''; for (const c of buf) s += String.fromCharCode(c);
        return r.status + ':' + btoa(s);
    }""")
    st, data = b64.split(":", 1)
    print("status", st)
    (DATA / "zugangskarten.pdf").write_bytes(base64.b64decode(data))
    print("pdf", (DATA / "zugangskarten.pdf").stat().st_size)

    probe(b)


def probe(b):
    t = sess(b, "frau.lehmann")
    goto(t, "/klausuren/4", 800)
    print(t.evaluate("[...document.querySelectorAll('button')].map(b=>b.innerText.trim()).filter(x=>x)"))
    step("bereit", lambda: t.get_by_role("button", name=re.compile("Fertigstellen")).first.click())
    t.wait_for_timeout(1500)
    shot(t, "216-technik-check-bereit")
    goto(t, "/klausuren/4/pult", 1500)
    step("lobby", lambda: t.get_by_role("button", name=re.compile("Warteraum öffnen")).click())
    t.wait_for_timeout(1500)
    m = sess(b, "mia.becker")
    goto(m, "/klausur/4", 1800)
    shot(m, "x-560-technik-check-warteraum")
    step("bereit", lambda: m.get_by_role("button", name=re.compile("^Bereit")).click())
    m.wait_for_timeout(2500)
    shot(m, "x-561-technik-check-bereit")
    shot(t, "x-440-pult-technik-check")
    m.context.storage_state(path=str(STATE / "mia.becker.json"))


run(main)
