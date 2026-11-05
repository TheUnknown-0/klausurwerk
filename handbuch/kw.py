"""Hilfen für Screenshots der Klausurwerk (Playwright, Chromium)."""
from __future__ import annotations

import os
import re
from pathlib import Path

from playwright.sync_api import Browser, BrowserContext, Page, sync_playwright

BASE = "http://localhost:9030"
PW = "Demo2026!"
OUT = Path(__file__).parent / "shots"
OUT.mkdir(exist_ok=True)

STATE = Path(__file__).parent / "states"
STATE.mkdir(exist_ok=True)


def s(path: str) -> str:
    """Vollständige URL."""
    return f"{BASE}{path}"


def new_ctx(browser: Browser, user: str | None = None, mobile: bool = False) -> BrowserContext:
    """Neuer Browserkontext. Jeder Kontext ist für Klausurwerk ein neues Gerät."""
    kw = dict(locale="de-DE", timezone_id="Europe/Berlin", color_scheme="light")
    if mobile:
        kw.update(viewport={"width": 390, "height": 844}, device_scale_factor=2.5,
                  is_mobile=True, has_touch=True)
    else:
        kw.update(viewport={"width": 1440, "height": 900}, device_scale_factor=1.25)
    state = STATE / f"{user}.json" if user else None
    if state and state.exists():
        kw["storage_state"] = str(state)
    return browser.new_context(**kw)


def student_pw(user: str) -> str:
    return f"{user}-2026"


def login(ctx: BrowserContext, user: str, password: str | None = None) -> Page:
    if password is None:
        password = PW if user in ("admin", "frau.lehmann", "herr.yilmaz") else student_pw(user)
    page = ctx.new_page()
    page.goto(f"{BASE}/login")
    page.fill("#username", user)
    page.fill("#password", password)
    page.click("form[data-login-form] button[type=submit]")
    page.wait_for_load_state("networkidle")
    ctx.storage_state(path=str(STATE / f"{user}.json"))
    return page


def sess(browser: Browser, user: str) -> Page:
    """Sitzung eines Kontos: gespeicherten Zustand (inkl. Geräte-Cookie) laden, sonst anmelden."""
    ctx = new_ctx(browser, user)
    page = ctx.new_page()
    page.goto(f"{BASE}/dashboard" if user in ("admin", "frau.lehmann", "herr.yilmaz") else f"{BASE}/meine-klausuren")
    if "/login" in page.url:
        page.close()
        return login(ctx, user)
    settle(page)
    return page


def settle(page: Page) -> None:
    page.wait_for_load_state("networkidle")
    page.evaluate("document.fonts && document.fonts.ready")
    page.wait_for_timeout(250)


def shot(page: Page, name: str, *, full: bool = False, selector: str | None = None,
         clip: dict | None = None, pad: int = 0) -> Path:
    settle(page)
    path = OUT / f"{name}.jpg"
    opts = dict(path=str(path), type="jpeg", quality=88, animations="disabled")
    if selector:
        el = page.locator(selector).first
        el.scroll_into_view_if_needed()
        if pad:
            box = el.bounding_box()
            opts["clip"] = {"x": max(box["x"] - pad, 0), "y": max(box["y"] - pad, 0),
                            "width": box["width"] + 2 * pad, "height": box["height"] + 2 * pad}
            page.screenshot(**opts)
        else:
            el.screenshot(**opts)
    else:
        if clip:
            opts["clip"] = clip
        if full:
            vp = page.viewport_size
            h = page.evaluate("Math.max(document.documentElement.scrollHeight, document.body.scrollHeight)")
            page.set_viewport_size({"width": vp["width"], "height": max(min(h, 6000), vp["height"])})
            page.wait_for_timeout(200)
            page.screenshot(**opts)
            page.set_viewport_size(vp)
        else:
            page.screenshot(**opts)
    print("shot", name)
    return path


def text(page: Page, selector: str = "main") -> str:
    loc = page.locator(selector)
    t = loc.first.inner_text() if loc.count() else page.inner_text("body")
    return re.sub(r"\n{3,}", "\n\n", t)


def run(fn, headless: bool = True):
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=headless)
        try:
            fn(browser)
        finally:
            browser.close()


HIDE_FLASH = ""


def hide_flash(page: Page) -> None:
    page.add_style_tag(content=HIDE_FLASH)


FORM_JS = r"""
() => [...document.querySelectorAll('form')].map(f => {
  const fields = [...f.querySelectorAll('input,select,textarea,button')].filter(el => el.type !== 'hidden' || el.name !== '_csrf').map(el => {
    let label = '';
    if (el.id) { const l = document.querySelector(`label[for="${el.id}"]`); if (l) label = l.innerText.trim(); }
    if (!label) { const l = el.closest('label'); if (l) label = l.innerText.trim(); }
    let opts = el.tagName === 'SELECT' ? ' [' + [...el.options].map(o => o.value + '=' + o.text).join(' | ').slice(0, 300) + ']' : '';
    return `  ${el.tagName.toLowerCase()}:${el.type||''} name=${el.name||''} id=${el.id||''} val=${(el.value||'').slice(0,40)} label=${label.slice(0,60)}${opts}`;
  });
  return `FORM ${f.method} ${f.getAttribute('action')||''}\n` + fields.join('\n');
}).join('\n')
"""


def dump(page: Page, url: str, out) -> None:
    page.goto(url)
    settle(page)
    out.write(f"\n\n######## {url} -> {page.url}\n")
    out.write(text(page))
    out.write("\n--- FORMS ---\n")
    out.write(page.evaluate(FORM_JS))


def dismiss(page: Page) -> None:
    """Schließt das Benachrichtigungs-Fenster nach dem Login, falls offen."""
    m = page.locator("#notifications-modal[open]")
    if m.count():
        btn = m.locator("[data-close-modal], button:has-text('Verstanden'), button:has-text('Schließen')").first
        btn.click()
        page.wait_for_timeout(400)


def fetch_pdf(page: Page, url: str, out: str, form: dict | None = None) -> None:
    """Lädt eine PDF/Datei mit der Sitzung der Seite (GET oder POST mit CSRF)."""
    if form is None:
        r = page.request.get(url)
    else:
        token = page.locator("meta[name=csrf-token]").get_attribute("content")
        data = {"_csrf": token, **form}
        r = page.request.post(url, form=data)
    assert r.ok, (r.status, r.text()[:300])
    Path(out).write_bytes(r.body())
    print("saved", out, r.headers.get("content-type"), len(r.body()))


def goto(page: Page, path: str, wait: int = 300) -> None:
    page.goto(s(path))
    settle(page)
    if wait:
        page.wait_for_timeout(wait)


def shot_at(page: Page, name: str, selector: str | None = None, text: str | None = None, offset: int = 120) -> Path:
    """Blättert so, dass das Element oben im Fenster steht, und nimmt das Fenster auf."""
    if selector:
        page.locator(selector).first.evaluate("(el, o) => { const r = el.getBoundingClientRect(); window.scrollTo(0, window.scrollY + r.top - o); }", offset)
    elif text:
        page.get_by_text(text, exact=False).first.evaluate("(el, o) => { const r = el.getBoundingClientRect(); window.scrollTo(0, window.scrollY + r.top - o); }", offset)
    page.wait_for_timeout(200)
    return shot(page, name)


def detail_view(page: Page) -> None:
    """Aufgaben-Editor in die Ansicht "Detail-Editor" schalten (Standard ist die Fragenliste)."""
    btn = page.locator('.te-viewbar [data-view="detail"]')
    if btn.count() and btn.get_attribute("aria-pressed") != "true":
        btn.click()
        page.wait_for_timeout(600)


def post_json(page: Page, path: str, body: dict | None = None, method: str = "POST") -> dict:
    """JSON-Aufruf mit der Sitzung der Seite (CSRF aus dem Meta-Tag)."""
    return page.evaluate(
        """async ([path, method, body]) => {
            const headers = {Accept: 'application/json', 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content};
            if (body) headers['Content-Type'] = 'application/json';
            const r = await fetch(path, {method, headers, body: body ? JSON.stringify(body) : undefined});
            let data = null; try { data = await r.json(); } catch (e) {}
            return {status: r.status, data};
        }""", [path, method, body])
