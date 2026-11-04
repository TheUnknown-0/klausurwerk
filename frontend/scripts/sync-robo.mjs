// Erzeugt frontend/src/robo/python-source.js aus runner/libs/python/robo.py.
// Aufruf: node scripts/sync-robo.mjs   (ein Test prueft, dass beide Dateien zusammenpassen)
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const src = fs.readFileSync(path.resolve(here, '../../runner/libs/python/robo.py'), 'utf8').replace(/\r\n/g, '\n');
if (src.includes('`') || src.includes('${')) {
    throw new Error('robo.py darf weder Backtick noch dollar+geschweifte Klammer enthalten (String.raw).');
}
const out = `/**
 * Python-Quelltext des Moduls 'robo' für Pyodide. GENERIERT aus runner/libs/python/robo.py
 * mit "node scripts/sync-robo.mjs", nicht von Hand ändern (frontend/test/robo-python-source.test.js prüft das).
 */
export const ROBO_PY = String.raw\`${src}\`;
`;
fs.writeFileSync(path.resolve(here, '../src/robo/python-source.js'), out);
console.log('python-source.js geschrieben (' + src.length + ' Zeichen).');
