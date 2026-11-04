"""Live-Wand, Ende zu Ende: Einstellungen, Beitritt mit Bildschirmfreigabe (Fake-Bildschirmquelle von
Chromium), Live-Daten und Bilder im Pult, Fokus, Alarm ohne Sperre, Rechte, Aufräumen am Klausurende.

Arbeitet an einer eigenen Kopie von Klausur 1 und stört die übrigen Tests nicht.
"""
from __future__ import annotations

import re
import subprocess
from pathlib import Path

import pytest

from conftest import BASE, TEACHER, js_errors, login, new_context, student

ROOT = Path(__file__).resolve().parents[2]
MARKER = "// LIVE-MARKER-4711"

# Merkt sich die Streams von getDisplayMedia, damit der Test die Freigabe wie die Schülerin beenden kann.
INIT_SCRIPT = """
(() => {
  const md = navigator.mediaDevices;
  if (!md || !md.getDisplayMedia) return;
  const orig = md.getDisplayMedia.bind(md);
  window.__streams = [];
  md.getDisplayMedia = async (...a) => { const s = await orig(...a); window.__streams.push(s); return s; };
})();
"""


def api(page, path: str, method: str = "GET", body: dict | None = None) -> dict:
    return page.evaluate(
        """async ([path, method, body]) => {
            const headers = {Accept: 'application/json', 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content};
            if (body) headers['Content-Type'] = 'application/json';
            const r = await fetch(path, {method, headers, body: body ? JSON.stringify(body) : undefined});
            let data = null; try { data = await r.json(); } catch (e) {}
            return {status: r.status, data};
        }""",
        [path, method, body],
    )


def pult_action(page, exam: int, action: str, pids, **extra) -> dict:
    return api(page, f"/api/pult/{exam}/aktion", "POST", {"action": action, "participation_ids": pids, **extra})["data"]


def stand(page, exam: int) -> dict:
    return api(page, f"/api/pult/{exam}/stand?cursor=0")["data"]


def poll(page, fn, timeout_ms: int = 30000, step: int = 500, what: str = "Bedingung"):
    waited = 0
    last = None
    while waited <= timeout_ms:
        last = fn()
        if last:
            return last
        page.wait_for_timeout(step)
        waited += step
    raise AssertionError(f"Zeitüberschreitung: {what} (zuletzt {last!r})")


def live_entry(teacher, exam: int, pid: int) -> dict:
    res = api(teacher, f"/api/pult/{exam}/live")
    assert res["status"] == 200, res
    for p in res["data"]["data"]["participants"]:
        if p["participation_id"] == pid:
            return p
    return {}


def live_dir_listing(exam: int) -> str:
    out = subprocess.run(
        ["docker", "compose", "exec", "-T", "app", "sh", "-c", f"ls -A /run/klausurwerk-live/e{exam} 2>/dev/null || echo __fehlt__"],
        cwd=ROOT, capture_output=True, stdin=subprocess.DEVNULL, text=True, encoding="utf-8",
    )
    return out.stdout.strip()


@pytest.fixture(scope="module")
def scene(browser):
    teacher = login(new_context(browser), *TEACHER)
    # Eigene Kopie von Klausur 1 anlegen, Bildschirmfreigabe einschalten, fertigstellen
    teacher.goto(f"{BASE}/klausuren/1")
    teacher.get_by_role("button", name="Als Vorlage kopieren").click()
    teacher.wait_for_url(re.compile(r"/klausuren/\d+/einstellungen"))
    exam = int(re.search(r"/klausuren/(\d+)/", teacher.url).group(1))
    assert exam > 4

    assert teacher.locator("#s-live_view").is_checked(), "live_view ist standardmäßig an"
    assert not teacher.locator("#s-screen_share").is_checked(), "screen_share ist standardmäßig aus"
    teacher.locator("#s-screen_share").check()
    teacher.get_by_role("button", name="Einstellungen speichern").click()
    teacher.wait_for_load_state("networkidle")
    assert teacher.locator("#s-screen_share").is_checked()
    assert teacher.locator("#s-live_view").is_checked()

    teacher.goto(f"{BASE}/klausuren/{exam}")
    teacher.get_by_role("button", name="Fertigstellen").click()
    teacher.wait_for_load_state("networkidle")

    teacher.goto(f"{BASE}/klausuren/{exam}/pult")
    teacher.get_by_role("button", name=re.compile("Warteraum öffnen")).click()
    poll(teacher, lambda: stand(teacher, exam)["exam"]["status"] == "lobby", 10000, what="Warteraum")

    ctx = new_context(browser)
    ctx.add_init_script(INIT_SCRIPT)
    mia = login(ctx, *student("mia.becker"))
    mia.goto(f"{BASE}/klausur/{exam}")
    # Beitritt, dann der Warteraum-Schritt "Bildschirm freigeben": "Bereit" bleibt bis dahin gesperrt
    share = mia.get_by_role("button", name="Bildschirm freigeben")
    share.wait_for(state="visible", timeout=15000)
    poll(mia, lambda: share.is_enabled(), 10000, what="Freigabe-Knopf aktiv")
    ready = mia.get_by_role("button", name=re.compile("^Bereit"))
    assert ready.is_disabled()
    share.click()
    mia.get_by_text("Bildschirm wird übertragen").first.wait_for(timeout=15000)
    poll(mia, lambda: ready.is_enabled(), 10000, what="Bereit freigeschaltet")
    ready.click()
    mia.wait_for_timeout(1500)

    teacher.get_by_role("button", name=re.compile("Starten|Klausur starten")).first.click()
    teacher.get_by_role("button", name="Jetzt starten").click()
    poll(teacher, lambda: stand(teacher, exam)["exam"]["status"] == "running", 15000, what="Klausur läuft")
    mia.wait_for_timeout(2500)
    overlay = mia.get_by_role("button", name=re.compile("beginnen|Weiter im Vollbild|Vollbild"))
    if overlay.count() > 0 and overlay.first.is_visible():
        overlay.first.click()
    mia.wait_for_selector("text=Datentypen", timeout=15000)

    pid = next(p["id"] for p in stand(teacher, exam)["participants"] if p["username"] == "mia.becker")
    yield {"exam": exam, "teacher": teacher, "mia": mia, "pid": pid, "ctx": ctx}


def test_live_daten_mit_code_ausschnitt(scene):
    exam, teacher, mia, pid = scene["exam"], scene["teacher"], scene["mia"], scene["pid"]
    mia.get_by_text("Klasse Konto").first.click()
    editor = mia.locator(".cm-content:visible").first
    editor.wait_for(timeout=15000)
    editor.click()
    mia.keyboard.press("Control+End")
    mia.keyboard.type("\n" + MARKER)

    entry = poll(
        teacher,
        lambda: (e := live_entry(teacher, exam, pid)) and e.get("live") and MARKER in (e["live"].get("code_excerpt") or "") and e,
        30000, what="Code-Ausschnitt im Pult",
    )
    live = entry["live"]
    assert live["cursor_line"] and live["cursor_line"] >= 1
    assert live["excerpt_start_line"] >= 1
    assert len(live["code_excerpt"].splitlines()) <= 60
    assert live["task_id"]
    assert entry["live_age_ms"] is not None and entry["live_age_ms"] < 20000
    settings = api(teacher, f"/api/pult/{exam}/live")["data"]["data"]["settings"]
    assert settings == {"live_view": True, "screen_share": True}


def test_bild_kommt_an(scene):
    exam, teacher, pid = scene["exam"], scene["teacher"], scene["pid"]
    entry = poll(teacher, lambda: (e := live_entry(teacher, exam, pid)) and e.get("screen") and e,
                 30000, what="Bildschirm-Standbild")
    assert entry["screen"]["surface"] == "monitor"
    assert entry["screen"]["width"] <= 481, "Übersicht: kleines Bild (~480 px)"
    res = teacher.evaluate(
        """async (url) => { const r = await fetch(url); const b = new Uint8Array(await r.arrayBuffer());
             return {status: r.status, type: r.headers.get('content-type'), cache: r.headers.get('cache-control'), head: Array.from(b.slice(0, 3))}; }""",
        f"/api/pult/{exam}/bildschirm/{pid}",
    )
    assert res["status"] == 200
    assert res["type"].startswith("image/jpeg")
    assert "no-store" in res["cache"]
    assert res["head"] == [0xFF, 0xD8, 0xFF]
    # Die Schülerin sieht die deutliche Anzeige
    badge = scene["mia"].locator(".share-badge")
    assert badge.is_visible()
    assert "Bildschirm wird übertragen" in badge.inner_text()


def test_wand_zeigt_karte_und_fokus_erhoeht_den_takt(scene):
    exam, teacher, pid = scene["exam"], scene["teacher"], scene["pid"]
    teacher.reload()
    teacher.get_by_role("button", name=re.compile("Live-Wand")).click()
    card = teacher.locator(".pult-wall-card", has_text="Mia").first
    card.wait_for(timeout=15000)
    poll(teacher, lambda: MARKER in card.inner_text(), 15000, what="Code-Ausschnitt auf der Karte")
    poll(teacher, lambda: card.locator("img.pult-wall-img").evaluate("i => i.complete && i.naturalWidth > 0"),
         20000, what="Bild auf der Karte")
    assert card.locator(".pult-wall-line.is-cursor").count() == 1

    # Ohne Fokus: kleine Bilder
    assert live_entry(teacher, exam, pid)["screen"]["width"] <= 481

    focus_posts: list[str] = []
    teacher.on("request", lambda r: focus_posts.append((r.post_data or "").replace(" ", "")) if r.url.endswith(f"/api/pult/{exam}/fokus") else None)
    card.click()
    teacher.locator(".pult-wall-focus").first.wait_for(timeout=10000)
    poll(teacher, lambda: any(f'"participation_id":{pid}' in p for p in focus_posts), 5000, what="Fokus gesendet")

    # Großes Bild (~1280 px) und etwa 1 Bild pro Sekunde
    big = poll(teacher, lambda: (e := live_entry(teacher, exam, pid)).get("screen") and e["screen"]["width"] >= 1000 and e["screen"],
               30000, what="Großes Bild im Fokus")
    assert big["width"] <= 1281
    seen = {big["ts_ms"]}
    for _ in range(14):
        teacher.wait_for_timeout(500)
        seen.add(live_entry(teacher, exam, pid)["screen"]["ts_ms"])
    assert len(seen) >= 4, f"Fokus: erwartet etwa 1 Bild/s, gesehen {len(seen) - 1} neue in 7 s"

    # Schließen hebt den Fokus auf; danach wieder kleine Bilder im Übersichtstakt
    teacher.keyboard.press("Escape")
    poll(teacher, lambda: any('"participation_id":null' in p for p in focus_posts), 5000, what="Fokus aufgehoben")
    small = poll(teacher, lambda: (s := live_entry(teacher, exam, pid)["screen"]) and s["width"] <= 481 and s,
                 30000, what="Wieder kleine Bilder")
    assert small["width"] <= 481


def test_rechte_der_schuelerin(scene):
    exam, mia, pid = scene["exam"], scene["mia"], scene["pid"]
    assert api(mia, f"/api/pult/{exam}/live")["status"] == 403
    status = mia.evaluate("async (u) => (await fetch(u, {headers: {Accept: 'image/jpeg'}})).status", f"/api/pult/{exam}/bildschirm/{pid}")
    assert status == 403
    assert api(mia, f"/api/pult/{exam}/fokus", "POST", {"participation_id": pid})["status"] == 403
    # Bild einer anderen Person: ein zweites Konto sieht es ebenfalls nicht
    other = login(new_context(scene["ctx"].browser), *student("noah.schulz"))
    other.goto(f"{BASE}/klausur/{exam}")
    assert other.evaluate("async (u) => (await fetch(u)).status", f"/api/pult/{exam}/bildschirm/{pid}") == 403
    assert api(other, f"/api/pult/{exam}/live")["status"] == 403
    other.context.close()
    # Anonym
    anon_ctx = new_context(scene["ctx"].browser)
    assert anon_ctx.request.get(f"{BASE}/api/pult/{exam}/live", headers={"Accept": "application/json"}).status == 401
    anon_ctx.close()
    # Bild-Endpunkt der Schülerin prüft die JPEG-Signatur
    bad = api(mia, f"/api/klausur/{exam}/bildschirm", "POST",
              {"jpeg": "data:image/jpeg;base64,AAAA", "width": 10, "height": 10, "surface": "monitor"})
    assert bad["status"] == 422


def test_ende_der_freigabe_ist_alarm_ohne_sperre(scene):
    exam, teacher, mia = scene["exam"], scene["teacher"], scene["mia"]
    n = mia.evaluate("() => { window.__streams.forEach(s => s.getTracks().forEach(t => { t.stop(); t.dispatchEvent(new Event('ended')); })); return window.__streams.length; }")
    assert n >= 1

    def alarm():
        s = stand(teacher, exam)
        return any(e["type"] == "screen_share_stopped" and e["severity"] == "alarm" for e in s["events"]) and s

    s = poll(teacher, alarm, 20000, what="Alarm screen_share_stopped")
    p = next(p for p in s["participants"] if p["username"] == "mia.becker")
    assert p["state"] == "active", "Keine Sperre, sie arbeitet weiter"
    assert not p.get("lock_reason")
    # Schülerseite: Hinweis zum erneuten Freigeben, Badge weg
    mia.get_by_role("button", name="Erneut freigeben").first.wait_for(timeout=10000)
    assert mia.locator(".share-badge").is_hidden()
    # Alarm im Pult sichtbar
    teacher.locator(".pult-alarm", has_text="Mia").first.wait_for(state="visible", timeout=20000)
    # Erneute Freigabe läuft wieder an
    mia.get_by_role("button", name="Erneut freigeben").first.click()
    mia.locator(".share-badge").wait_for(state="visible", timeout=15000)
    assert stand(teacher, exam)["participants"][0]["state"] != "locked"


def test_nach_klausurende_sind_bilder_weg(scene):
    exam, teacher, pid = scene["exam"], scene["teacher"], scene["pid"]
    assert live_dir_listing(exam) not in ("", "__fehlt__"), "vor dem Ende liegen Daten im tmpfs"
    pids = [p["id"] for p in stand(teacher, exam)["participants"]]
    res = pult_action(teacher, exam, "submit", pids)
    assert res["success"], res
    res = pult_action(teacher, exam, "finish", "all")
    assert res["success"], res
    assert stand(teacher, exam)["exam"]["status"] == "finished"

    def gone():
        return teacher.evaluate("async (u) => (await fetch(u)).status", f"/api/pult/{exam}/bildschirm/{pid}") == 404

    poll(teacher, gone, 10000, what="Bild liefert 404")
    listing = live_dir_listing(exam)
    assert listing in ("", "__fehlt__"), f"tmpfs-Verzeichnis nicht leer: {listing}"
    entry = live_entry(teacher, exam, pid)
    assert not entry or (entry.get("live") is None and entry.get("screen") is None)
    # Die Schülerseite wird nicht mehr angenommen
    mia = scene["mia"]
    late = api(mia, f"/api/klausur/{exam}/live", "POST",
               {"task_id": None, "view": "task", "file": None, "cursor_line": None, "code_excerpt": "",
                "excerpt_start_line": 0, "console_tail": "", "client_ms": 0})
    assert late["status"] == 409
    # Bekannte Ausnahmen: die Camera-Meldung kommt von Chromium bei getDisplayMedia mit dem Fake-UI-Startargument
    # (die App ruft keine Kamera auf), die 422 stammt vom absichtlich ungültigen Bild in test_rechte_der_schuelerin.
    ignore = re.compile(r"Permissions policy violation: camera|status of 422")
    for page in (teacher, mia):
        errors = [e for e in js_errors(page) if not ignore.search(e)]
        assert errors == [], errors


def test_pult_hat_link_zur_korrektur(scene):
    """QA-Befund 4: Nach dem Klausurende führt ein Knopf im Pult direkt zur Korrektur."""
    exam, teacher = scene["exam"], scene["teacher"]
    teacher.goto(f"{BASE}/klausuren/{exam}/pult")
    link = teacher.get_by_role("link", name=re.compile("Zur Korrektur"))
    link.wait_for(state="visible", timeout=15000)
    assert link.get_attribute("href").rstrip("/").endswith(f"/klausuren/{exam}/korrektur")
    link.click()
    teacher.wait_for_url(re.compile(rf"/klausuren/{exam}/korrektur"))
    assert teacher.get_by_role("button", name=re.compile("Korrektur beginnen")).count() > 0


def test_korrektur_enter_in_letzter_zelle_speichert(scene):
    """QA-Befund 1: Enter im Punktefeld der letzten Zelle speichert und meldet es, statt ins Leere zu laufen."""
    exam, t = scene["exam"], scene["teacher"]
    t.goto(f"{BASE}/klausuren/{exam}/korrektur")
    t.get_by_role("button", name=re.compile("Korrektur beginnen")).click()
    t.wait_for_load_state("networkidle")
    pids = [p["id"] for p in stand(t, exam)["participants"]]
    t.goto(f"{BASE}/klausuren/{exam}/korrektur/{min(pids)}")
    field = t.locator("#g-points")
    last = None
    for _ in range(60):
        field.wait_for(state="visible", timeout=15000)
        t.wait_for_function("() => document.querySelector('#g-points') && !document.querySelector('#g-points').disabled")
        field.fill("0")
        url = t.url
        field.press("Enter")
        t.wait_for_timeout(700)
        if t.get_by_text("Das war die letzte Zelle. Gespeichert.").count() > 0:
            last = url
            break
        # Weitergeschaltet: das Feld gehört jetzt zur nächsten Zelle
        poll(t, lambda: t.url != url, 8000, what="nächste Zelle")
    assert last, "Die letzte Zelle wurde nie erreicht"
    m = re.search(r"/korrektur/(\d+)\?.*aufgabe=(\d+)", last)
    assert m, last
    cell = api(t, f"/api/korrektur/{exam}/zelle/{m.group(1)}/{m.group(2)}")
    assert cell["status"] == 200, cell
    own = cell["data"].get("data", cell["data"])
    own = own.get("own_points") if isinstance(own, dict) else None
    assert own is not None and float(own) == 0.0, f"Letzte Zelle nicht gespeichert: {cell['data']}"
