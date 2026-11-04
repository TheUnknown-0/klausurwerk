"""Gemeinsame Helfer für die Ende-zu-Ende-Tests (Playwright, Chromium).

Voraussetzung: laufender Compose-Stack (http://localhost:9030) mit Demo-Daten.
Vor dem Lauf setzt reset.sh die Datenbank zurück (löscht alle Daten!).

    tests/e2e/reset.sh && python -m pytest tests/e2e -x -q
"""
from __future__ import annotations

import os
import re

import pytest
from playwright.sync_api import Browser, BrowserContext, Page, sync_playwright

BASE = os.environ.get("KW_BASE", "http://localhost:9030")
TEACHER = ("frau.lehmann", "Demo2026!")
ADMIN = ("admin", "Demo2026!")


def student(name: str) -> tuple[str, str]:
    return name, f"{name}-2026"


@pytest.fixture(scope="session")
def browser() -> Browser:
    with sync_playwright() as p:
        b = p.chromium.launch(headless=os.environ.get("KW_HEADED") != "1",
                              args=["--autoplay-policy=no-user-gesture-required",
                                    # Fake-Bildschirmquelle für getDisplayMedia (Live-Wand): ganzer Bildschirm ohne Dialog
                                    "--use-fake-ui-for-media-stream",
                                    "--auto-select-desktop-capture-source=Entire screen"])
        yield b
        b.close()


def new_context(browser: Browser) -> BrowserContext:
    return browser.new_context(locale="de-DE", timezone_id="Europe/Berlin",
                               viewport={"width": 1440, "height": 900})


def login(ctx: BrowserContext, user: str, password: str) -> Page:
    page = ctx.new_page()
    errors: list[str] = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
    page.kw_errors = errors  # type: ignore[attr-defined]
    page.goto(f"{BASE}/login")
    page.fill("#username", user)
    page.fill("#password", password)
    page.click("form[data-login-form] button[type=submit]")
    page.wait_for_load_state("networkidle")
    assert "/login" not in page.url, f"Login fehlgeschlagen für {user}: {page.url}"
    return page


def js_errors(page: Page) -> list[str]:
    """Konsolen- und Skriptfehler, ohne bekannte, harmlose Meldungen."""
    ignore = re.compile(r"favicon|Failed to load resource: the server responded with a status of 40[349]")
    return [e for e in getattr(page, "kw_errors", []) if not ignore.search(e)]
