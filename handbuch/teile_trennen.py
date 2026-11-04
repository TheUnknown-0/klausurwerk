"""Erzeugt aus den Kapiteln die drei Teilhefte (jeweils mit eigener Titelseite, eigenem Inhaltsverzeichnis
und eigenen Lesezeichen) und prüft die Seitenzahlen.

  Admin:       Kapitel 1 bis 3, Anhang A (Rechte) und D (Fragen)
  Lehrkräfte:  Kapitel 1, 4, 5, 6, Anhang A bis E
  Schüler:     Kapitel 7, Anhang B (Tastenkürzel) und D (Fragen)

Aufruf: python teile_trennen.py        (baut danach Klausurwerk-Handbuch-Admin.pdf, -Lehrkraefte.pdf, -Schueler.pdf)
Das Gesamt-PDF baut: python manual/build.py
"""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

import fitz

HERE = Path(__file__).parent
TEILE = ["admin", "lehrkraefte", "schueler"]


def main() -> None:
    for teil in TEILE:
        subprocess.run([sys.executable, str(HERE / "manual" / "build.py"), teil], check=True)
    for name in ("Klausurwerk-Handbuch-Admin.pdf", "Klausurwerk-Handbuch-Lehrkraefte.pdf", "Klausurwerk-Handbuch-Schueler.pdf"):
        doc = fitz.open(HERE / name)
        print(f"{name}: {doc.page_count} Seiten, {len(doc.get_toc())} Lesezeichen")


if __name__ == "__main__":
    main()
