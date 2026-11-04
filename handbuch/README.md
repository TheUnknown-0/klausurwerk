# Klausurwerk-Handbuch

Quellen und Werkzeuge für das Handbuch (Gesamtausgabe und drei Teilhefte als PDF).

| Datei / Ordner | Inhalt |
|---|---|
| `manual/00.html` | Willkommen, Sprachhinweis, Schnelleinstieg, Platzhalter für das Inhaltsverzeichnis |
| `manual/01.html` bis `07.html` | Kapitel 1 bis 7 (Überblick, Installation, Verwaltung, Vorbereiten, Klausurtag, Korrektur, Schüler:innen) |
| `manual/08.html` | Anhänge A bis E (Rechte, Tastenkürzel, Ereignisse, FAQ, Datenschutzvorlage) |
| `manual/04-typen.html` | Abschnitt 4.6 (16 Antworttypen), **erzeugt** von `gen_types.py` |
| `manual/style.css`, `manual/cover.html` | Gestaltung (Indigo `#3730a3`, Schriften aus `public/assets/fonts`) und Titelseite |
| `manual/build.py` | baut das PDF |
| `teile_trennen.py` | baut die drei Teilhefte |
| `kw.py` | Hilfen für die Aufnahmen (Playwright) |
| `s10_admin.py` ... `s60_karten_probe.py` | Aufnahme-Skripte |
| `shots/` | Bildschirmfotos (1440 x 900, Faktor 1,25) |
| `data/` | Beispiel-CSV und PDFs aus der Anwendung (Zugangskarten, Ergebnis) |

## PDF neu bauen

Voraussetzung: Python mit `playwright` (Chromium installiert), `pymupdf` und `pillow`.

```bash
python gen_types.py                 # nur nach Änderungen an den Typbeschreibungen
python manual/build.py              # Klausurwerk-Handbuch.pdf (gesamt)
python teile_trennen.py             # -Admin.pdf, -Lehrkraefte.pdf, -Schueler.pdf
```

`build.py` rendert zweimal: Im ersten Durchlauf werden die Seitenzahlen der Kapitel und Abschnitte gesammelt,
im zweiten das Inhaltsverzeichnis damit gefüllt. Danach werden die Titelseite vorangestellt, Lesezeichen und
Metadaten („Klausurwerk Handbuch“) gesetzt. Abbildungen im Text schreiben Sie als `<figure><img src="img/NAME.jpg"><figcaption><b>{fig}</b> Text</figcaption></figure>`;
`{fig}` wird je Kapitel zu „Abb. 3.2“. Der Zuschnitt der Bilder hängt vom Namenspräfix ab:
`x-` unverändert, `f-` mit Seitenleiste, `c-` fester Ausschnitt (Tabelle `CROPS` in `build.py`), sonst ohne Seitenleiste und Kopfzeile.

## Screenshots erneuern

Der Stack muss unter `http://localhost:9030` laufen. **Die Aufnahmen setzen die Datenbank zurück** (löscht alle Daten):

```bash
bash tests/e2e/reset.sh                 # Demo-Daten einspielen
cd handbuch
python s10_admin.py                     # Verwaltung (Admin)
python s20_lehrkraft.py                 # Vorbereitung, Editor und Vorschau je Antworttyp
python s30a_lauf.py --neu               # Warteraum, Beitritt, Start (legt states/ an, ein Browserprofil je Person)
python s30b_schueler.py                 # Schüleransicht von Mia ausfüllen
python s30b2_schueler.py                # Zuordnung und Reihenfolge
python s30c_pult.py                     # Verstoß, Hand, Zeit, Toilette, Durchsage, Gerätewechsel
python s30e_ende.py                     # Anfragen, Abgabe (Dialoge)
python s30f_fertig.py                   # Rest über die Pult-Schnittstelle, Klausur beenden
python s40_korrektur.py                 # Korrektur, Ergebnisse, Freigabe, Rückgabe
python s50_typen.py && python s50b_diagramme.py   # Übung (Turtle, Robo) und Test mit allen Diagrammtypen
python s60_karten_probe.py              # Zugangskarten-PDF und Technik-Check
```

Hinweise: Jeder Browserkontext ist für Klausurwerk ein neues Gerät. Deshalb speichern die Skripte die Sitzung
(Cookies, Gerätekennung) je Benutzer in `states/` und laden sie wieder (`kw.sess`). Pult-Bestätigungen sind eigene
Dialoge (Knopf „Jetzt starten“ usw.). Passwörter: `admin`, `frau.lehmann`, `herr.yilmaz` mit `Demo2026!`, Schüler:innen
mit `<benutzername>-2026`. Wenn die Anwendung ihr Aussehen ändert, passen Sie Selektoren in den Skripten an.
`python gen_types.py` und `python manual/build.py` bauen die Texte danach neu.
