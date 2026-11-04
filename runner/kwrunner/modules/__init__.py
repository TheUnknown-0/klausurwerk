"""Erweiterungspunkt für zusätzliche Routen.

Jedes Modul in diesem Paket (Dateiname ohne Unterstrich am Anfang) kann eine
Funktion ``register(registry, ctx)`` anbieten. ``registry.add(methode, muster,
handler)`` hängt eine Route ein, ``ctx`` ist der gemeinsame Kontext (Konfiguration,
Lauf-Verwaltung, Hilfsfunktionen aus ``kwrunner.sandbox`` und ``kwrunner.javatools``).

Beispiel (``modules/robo.py``)::

    def register(registry, ctx):
        registry.add("POST", r"/robo", handle_robo)

    def handle_robo(ctx, req):
        body = req.json()
        return 200, {"ok": True}

Handler liefern ``(statuscode, dict)``; Authentifizierung (X-Runner-Token) und
Fehlerbehandlung übernimmt der Server.
"""
