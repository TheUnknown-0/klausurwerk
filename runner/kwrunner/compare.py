"""Vergleich von Programmausgaben (exact, trim, ignore_whitespace, contains, regex)."""
from __future__ import annotations

import re

MODES = ("exact", "trim", "ignore_whitespace", "contains", "regex")


def normalize_newlines(text: str) -> str:
    return text.replace("\r\n", "\n").replace("\r", "\n")


def compare_output(actual: str, expected: str, mode: str) -> tuple[bool, str | None]:
    """(passt, Fehlermeldung bei ungültigem Muster). Unbekannter Modus gilt als ``trim``."""
    actual = normalize_newlines(actual)
    expected = normalize_newlines(expected)
    if mode == "exact":
        return actual == expected, None
    if mode == "ignore_whitespace":
        return "".join(actual.split()) == "".join(expected.split()), None
    if mode == "contains":
        return expected.strip() in actual, None
    if mode == "regex":
        if len(expected) > 2000:
            return False, "Der reguläre Ausdruck der Aufgabe ist zu lang."
        try:
            pattern = re.compile(expected, re.M | re.S)
        except re.error as exc:
            return False, "Der reguläre Ausdruck der Aufgabe ist ungültig (" + str(exc)[:100] + ")."
        return pattern.search(actual) is not None, None
    # trim (Standard): Ränder und Zeilenenden-Leerzeichen ignorieren
    def clean(text: str) -> str:
        return "\n".join(line.rstrip() for line in text.strip().split("\n"))
    return clean(actual) == clean(expected), None


def show(text: str, limit: int = 160) -> str:
    text = normalize_newlines(text).replace("\n", "\\n")
    return text if len(text) <= limit else text[:limit] + "..."
