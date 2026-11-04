/**
 * Verständliche Fehlermeldungen für Python-Fehler (reine Logik).
 *
 * Eingabe ist die vom Python-Runner gelieferte Beschreibung:
 *   { type, message, file, line, column, traceback, frames: [{file, line}] }
 */

/** Kurze Hinweise nach Fehlerart und Meldungsmuster. */
export function pythonHint(type, message) {
    const msg = String(message ?? '');
    let m;
    switch (type) {
        case 'NameError':
            m = /name '([^']+)' is not defined/.exec(msg);
            return m
                ? `Der Name „${m[1]}“ ist nicht bekannt. Prüfe die Schreibweise (Groß- und Kleinschreibung zählt), ob du ihn vorher zugewiesen hast oder ob ein import fehlt.`
                : 'Ein Name wird benutzt, bevor er einen Wert hat.';
        case 'UnboundLocalError':
            return 'Eine Variable wird in der Funktion gelesen, bevor sie dort einen Wert bekommt. Eventuell fehlt ein „global“.';
        case 'IndentationError':
        case 'TabError':
            return 'Die Einrückung stimmt nicht. Zeilen im gleichen Block müssen gleich weit eingerückt sein (4 Leerzeichen).';
        case 'SyntaxError':
            if (/expected ':'/.test(msg)) {
                return 'Am Zeilenende fehlt ein Doppelpunkt (nach if, else, for, while, def, class).';
            }
            if (/was never closed|unterminated|EOF/.test(msg)) {
                return 'Eine Klammer oder ein Anführungszeichen wurde nicht geschlossen.';
            }
            if (/invalid syntax/.test(msg)) {
                return 'Hier stimmt der Aufbau nicht. Schau auch in die Zeile davor: oft fehlt dort etwas (Klammer, Komma, Doppelpunkt).';
            }
            if (/Missing parentheses|print/.test(msg)) {
                return 'print braucht Klammern: print("Text").';
            }
            return 'Der Programmtext ist kein gültiges Python. Schau dir die markierte Stelle und die Zeile davor an.';
        case 'TypeError':
            if (/can only concatenate str/.test(msg) || /unsupported operand type\(s\) for \+: '(int|float)' and 'str'/.test(msg)) {
                return 'Text und Zahl lassen sich nicht mit + verbinden. Wandle die Zahl mit str() um oder die Eingabe mit int() bzw. float().';
            }
            if (/unsupported operand/.test(msg)) {
                return 'Diese Rechnung geht mit den vorhandenen Datentypen nicht. Prüfe, ob du Text (str) statt Zahl hast, zum Beispiel nach input().';
            }
            if (/not callable/.test(msg)) {
                return 'Etwas, das keine Funktion ist, wird mit Klammern aufgerufen. Ist ein Variablenname doppelt vergeben?';
            }
            if (/takes \d+ positional argument|missing \d+ required|positional arguments? but/.test(msg)) {
                return 'Die Anzahl der übergebenen Werte passt nicht zur Funktion. Vergleiche Aufruf und Definition.';
            }
            if (/not subscriptable/.test(msg)) {
                return 'Auf diesen Wert kannst du nicht mit [ ] zugreifen (er ist keine Liste, Zeichenkette oder Tabelle).';
            }
            if (/not iterable/.test(msg)) {
                return 'Über diesen Wert kann keine Schleife laufen. Meinst du range(...)?';
            }
            return 'Ein Wert hat nicht den passenden Datentyp.';
        case 'ValueError':
            if (/invalid literal for int|could not convert string to float/.test(msg)) {
                return 'Der Text lässt sich nicht in eine Zahl umwandeln (zum Beispiel wegen Buchstaben, Komma statt Punkt oder einer leeren Eingabe).';
            }
            return 'Der Wert hat den richtigen Typ, aber einen unpassenden Inhalt.';
        case 'IndexError':
            return 'Der Index liegt außerhalb der Liste bzw. Zeichenkette. Das erste Element hat den Index 0, das letzte len(liste) - 1.';
        case 'KeyError':
            return 'Der Schlüssel gibt es im Dictionary nicht. Prüfe die Schreibweise oder nutze „in“ bzw. get().';
        case 'AttributeError':
            return 'Dieses Objekt hat die genannte Eigenschaft oder Methode nicht. Prüfe die Schreibweise und den Datentyp.';
        case 'ZeroDivisionError':
            return 'Es wird durch null geteilt. Prüfe den Nenner vor der Rechnung.';
        case 'RecursionError':
            return 'Die Funktion ruft sich zu oft selbst auf. Fehlt der Abbruchfall oder wird er nie erreicht?';
        case 'FileNotFoundError':
            return 'Die Datei gibt es nicht. Prüfe den Namen (Groß- und Kleinschreibung) und ob die Datei zur Aufgabe gehört.';
        case 'ModuleNotFoundError':
        case 'ImportError':
            m = /No module named '([^']+)'/.exec(msg);
            return m
                ? `Das Modul „${m[1]}“ ist hier nicht verfügbar. Es gibt nur die Standardbibliothek und die für diese Klausur freigegebenen Pakete.`
                : 'Das Modul oder der Name lässt sich nicht importieren.';
        case 'EOFError':
            return 'Das Programm wollte eine Eingabe lesen, es gibt aber keine mehr. Trage die Eingaben vorab im Feld „Eingabe“ ein oder gib sie in der Konsole ein.';
        case 'AssertionError':
            return 'Eine assert-Prüfung ist fehlgeschlagen.';
        case 'MemoryError':
            return 'Das Programm braucht zu viel Speicher.';
        case 'OverflowError':
            return 'Die Zahl ist zu groß für diese Rechnung.';
        default:
            return '';
    }
}

/** Ort als Text: "main.py, Zeile 3". */
export function locationText(err) {
    if (!err?.line) {
        return '';
    }
    return err.file ? `${err.file}, Zeile ${err.line}` : `Zeile ${err.line}`;
}

/**
 * Baut den Text, der als Fehlerausgabe in die Konsole kommt.
 * @returns {string}
 */
export function formatPythonError(err) {
    if (!err) {
        return '';
    }
    const lines = [];
    const where = locationText(err);
    lines.push(where ? `Fehler in ${where}` : 'Fehler');
    const msg = err.message ? `${err.type}: ${err.message}` : String(err.type ?? 'Fehler');
    lines.push(msg);
    const hint = pythonHint(err.type, err.message);
    if (hint) {
        lines.push(`Hinweis: ${hint}`);
    }
    const tb = String(err.traceback ?? '').trimEnd();
    if (tb) {
        lines.push('', 'Genauer Ablauf (Traceback):', tb);
    }
    return `${lines.join('\n')}\n`;
}

/** Diagnosen (für den Editor) aus einem Fehler: alle Stellen im eigenen Code. */
export function pythonDiagnostics(err) {
    if (!err) {
        return [];
    }
    const hint = pythonHint(err.type, err.message);
    const message = `${err.type}: ${err.message ?? ''}`.trim() + (hint ? `\n${hint}` : '');
    const out = [];
    if (err.line) {
        out.push({ file: err.file ?? null, line: err.line, column: err.column ?? null, message, severity: 'error' });
    }
    // weitere Aufrufstellen als Warnung markieren, die letzte Stelle ist der Fehler selbst
    for (const f of err.frames ?? []) {
        if (f.line && !(f.file === err.file && f.line === err.line)) {
            out.push({ file: f.file ?? null, line: f.line, column: null, message: 'Aufrufstelle des Fehlers', severity: 'warning' });
        }
    }
    return out;
}
