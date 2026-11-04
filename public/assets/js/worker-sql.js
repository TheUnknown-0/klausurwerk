/**
 * Web Worker für SQL (sql.js / SQLite als WebAssembly). Klassischer Worker,
 * weil sql-wasm.js ein UMD-Skript ist (importScripts), ohne eval und ohne
 * Inline-Code: passt zur CSP (script-src 'self' 'wasm-unsafe-eval').
 *
 * Nachrichten vom Hauptthread:
 *   { type:'init', base }                            sql.js laden (base = URL von assets.sqljs)
 *   { type:'run', runId, setup, sql, maxKeep }       frische Datenbank, Setup, Anweisungen
 *   { type:'schema', runId, setup }                  Tabellen und Spalten der frischen Datenbank
 * Antworten:
 *   { type:'ready' } | { type:'init-error', message }
 *   { type:'result', runId, setupError, results }    results: je Anweisung
 *        { sql, columns, rows, totalRows, totalCapped, rowsModified, error }
 *   { type:'schema', runId, setupError, tables }
 */
'use strict';

var SQL = null;
var COUNT_CAP = 100000;

function post(message) {
    self.postMessage(message);
}

function errorText(e) {
    return String((e && e.message) || e || 'Unbekannter Fehler');
}

function openDatabase(setup) {
    var db = new SQL.Database();
    var setupError = null;
    if (setup && String(setup).trim() !== '') {
        try {
            db.run(String(setup));
        } catch (e) {
            setupError = errorText(e);
        }
    }
    try {
        db.run('PRAGMA foreign_keys = ON');
    } catch (e) {
        // ohne Fremdschlüsselprüfung weiterarbeiten
    }
    return { db: db, setupError: setupError };
}

/** Erste Anweisung eines Textrests (für Fehlermeldungen bei Syntaxfehlern). */
function firstStatement(rest) {
    var text = String(rest || '').trim();
    var idx = text.indexOf(';');
    return (idx >= 0 ? text.slice(0, idx + 1) : text).slice(0, 400);
}

function runStatements(db, sql, maxKeep) {
    var results = [];
    var it = db.iterateStatements(sql);
    for (;;) {
        var step;
        try {
            step = it.next();
        } catch (e) {
            results.push({ sql: firstStatement(it.getRemainingSQL()), error: errorText(e) });
            break;
        }
        if (step.done) {
            break;
        }
        var stmt = step.value;
        var text = stmt.getSQL();
        try {
            var columns = stmt.getColumnNames();
            if (columns.length > 0) {
                var rows = [];
                var total = 0;
                var capped = false;
                while (stmt.step()) {
                    total++;
                    if (rows.length < maxKeep) {
                        rows.push(stmt.get());
                    }
                    if (total >= COUNT_CAP) {
                        capped = true;
                        break;
                    }
                }
                results.push({ sql: text, columns: columns, rows: rows, totalRows: total, totalCapped: capped, rowsModified: 0, error: null });
            } else {
                while (stmt.step()) {
                    // Anweisung ohne Ergebnisspalten vollständig ausführen
                }
                results.push({ sql: text, columns: [], rows: [], totalRows: 0, totalCapped: false, rowsModified: db.getRowsModified(), error: null });
            }
        } catch (e) {
            results.push({ sql: text, error: errorText(e) });
            it.return();
            break;
        }
    }
    return results;
}

function quoteIdent(name) {
    return '"' + String(name).replace(/"/g, '""') + '"';
}

function readSchema(db) {
    var tables = [];
    var list = db.exec("SELECT name, type FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY type DESC, name");
    var rows = list.length > 0 ? list[0].values : [];
    rows.forEach(function (row) {
        var name = row[0];
        var cols = [];
        var info = db.exec('PRAGMA table_info(' + quoteIdent(name) + ')');
        var fks = {};
        try {
            var fk = db.exec('PRAGMA foreign_key_list(' + quoteIdent(name) + ')');
            if (fk.length > 0) {
                fk[0].values.forEach(function (f) {
                    fks[f[3]] = f[2] + '.' + (f[4] || '');
                });
            }
        } catch (e) {
            // Sichten haben keine Fremdschlüssel
        }
        if (info.length > 0) {
            info[0].values.forEach(function (c) {
                cols.push({ name: c[1], type: c[2], notnull: !!c[3], pk: c[5] > 0, fk: fks[c[1]] || null });
            });
        }
        tables.push({ name: name, type: row[1], columns: cols });
    });
    return tables;
}

self.onmessage = function (event) {
    var m = event.data;
    try {
        if (m.type === 'init') {
            importScripts(m.base + 'sql-wasm.js');
            self.initSqlJs({ locateFile: function (file) { return m.base + file; } }).then(function (lib) {
                SQL = lib;
                post({ type: 'ready' });
            }, function (e) {
                post({ type: 'init-error', message: errorText(e) });
            });
        } else if (m.type === 'run') {
            var opened = openDatabase(m.setup);
            var results = [];
            try {
                if (!opened.setupError) {
                    results = runStatements(opened.db, String(m.sql || ''), m.maxKeep || 501);
                }
            } finally {
                opened.db.close();
            }
            post({ type: 'result', runId: m.runId, setupError: opened.setupError, results: results });
        } else if (m.type === 'schema') {
            var o = openDatabase(m.setup);
            var tables = [];
            try {
                if (!o.setupError) {
                    tables = readSchema(o.db);
                }
            } finally {
                o.db.close();
            }
            post({ type: 'schema', runId: m.runId, setupError: o.setupError, tables: tables });
        }
    } catch (e) {
        if (m.type === 'init') {
            post({ type: 'init-error', message: errorText(e) });
        } else {
            post({ type: m.type === 'schema' ? 'schema' : 'result', runId: m.runId, setupError: errorText(e), results: [], tables: [] });
        }
    }
};
