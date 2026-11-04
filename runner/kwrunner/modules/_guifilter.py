"""Filter für die Java-GUI-Bibliothek (kw.gui): zieht GUI-Zeilen aus der Standardausgabe.

Die Bibliothek schreibt ihre Bildschirm-Änderungen als Zeilen ``\\x01KWGUI:<json>`` auf stdout.
``GuiView`` liest die Chunks eines Laufs inkrementell und liefert eine Liste von Einträgen:

* ``{"stream": "stdout|stderr|system", "text": "..."}`` für normale Ausgabe,
* ``{"gui": {...}}`` für einen Frame der Oberfläche.

Beide Arten stehen in einer gemeinsamen Liste, sodass ein einziger Offset (wie bei /runs)
genügt und wiederholte Abrufe mit demselben Offset dieselben Frames liefern.
Der Unterstrich im Dateinamen verhindert, dass der Server dieses Modul als Routenmodul lädt.
"""
from __future__ import annotations

import json

GUI_PREFIX = "\x01KWGUI:"
MAX_FRAME_CHARS = 2 * 1024 * 1024
MAX_FRAMES = 200000


class GuiView:
    def __init__(self):
        self.items: list[dict] = []
        self.consumed = 0          # Anzahl bereits verarbeiteter Chunks des Prozesses
        self.mode = "start"        # start (normaler Text) | head (Präfix wird erkannt) | gui | skip
        self.head = ""             # möglicher Zeilenanfang, der noch kein Präfix bestätigt
        self.buf = ""              # Inhalt einer GUI-Zeile bis zum Zeilenende
        self.frames = 0
        self.closed = False

    # --- Verarbeitung ------------------------------------------------------------
    def update(self, chunks: list[dict]) -> None:
        """Verarbeitet die seit dem letzten Aufruf neuen Chunks (``chunks`` = komplette Liste)."""
        for chunk in chunks[self.consumed:]:
            if chunk.get("stream") == "stdout":
                self._feed(chunk.get("text", ""))
            else:
                self.items.append({"stream": chunk.get("stream", "system"), "text": chunk.get("text", "")})
        self.consumed = len(chunks)

    def close(self, extra: list[dict] | None = None) -> None:
        """Nach dem Ende des Laufs: Rest einer unvollständigen Zeile ausgeben, eigene Hinweise anhängen."""
        if self.closed:
            return
        self.closed = True
        if self.mode == "head" and self.head:
            self.items.append({"stream": "stdout", "text": self.head})
        self.head = ""
        for chunk in extra or []:
            self.items.append({"stream": chunk.get("stream", "system"), "text": chunk.get("text", "")})

    def _feed(self, text: str) -> None:
        plain: list[str] = []
        i = 0
        n = len(text)
        while i < n:
            if self.mode == "start":
                # Das Präfix darf mitten in einer Zeile stehen (print("Eingabe: ") ohne Zeilenende).
                j = text.find(GUI_PREFIX[0], i)
                if j < 0:
                    plain.append(text[i:])
                    i = n
                else:
                    plain.append(text[i:j])
                    self.head = GUI_PREFIX[0]
                    i = j + 1
                    self.mode = "head"
            elif self.mode == "head":
                candidate = self.head + text[i]
                if GUI_PREFIX.startswith(candidate):
                    self.head = candidate
                    i += 1
                    if len(candidate) == len(GUI_PREFIX):
                        self.mode = "gui"
                        self.head = ""
                        self.buf = ""
                else:
                    plain.append(self.head)  # doch kein Präfix; das aktuelle Zeichen wird neu betrachtet
                    self.head = ""
                    self.mode = "start"
            else:  # gui oder skip (zu lange Zeile)
                j = text.find("\n", i)
                piece = text[i:] if j < 0 else text[i:j]
                i = n if j < 0 else j + 1
                if self.mode == "gui":
                    self.buf += piece
                    if len(self.buf) > MAX_FRAME_CHARS:
                        self.buf = ""
                        self.mode = "skip"
                if j >= 0:
                    if self.mode == "gui":
                        self._frame(self.buf)
                    self.buf = ""
                    self.mode = "start"
        if plain:
            joined = "".join(plain)
            if joined:
                self.items.append({"stream": "stdout", "text": joined})

    def _frame(self, raw: str) -> None:
        if self.frames >= MAX_FRAMES:
            return
        try:
            data = json.loads(raw)
        except ValueError:
            return
        if isinstance(data, dict):
            self.frames += 1
            self.items.append({"gui": data})

    # --- Abruf -------------------------------------------------------------------
    def slice(self, offset: int) -> tuple[list[dict], list[dict], int]:
        """(Ausgabe, GUI-Frames, neuer Offset) ab ``offset``."""
        part = self.items[max(0, offset):]
        output = [item for item in part if "stream" in item]
        frames = [item["gui"] for item in part if "gui" in item]
        return output, frames, len(self.items)
