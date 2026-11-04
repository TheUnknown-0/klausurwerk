/**
 * Frontend-Build für Klausurwerk.
 *
 *  1. Bündelt jede Datei unter src/entries/*.js zu public/assets/build/<name>.js
 *     (ES-Module, minifiziert, ohne Source-Maps).
 *  2. Verschleiert alle Bundles, deren Name mit "exam" oder "worker-" beginnt
 *     (Schüleransicht und ihre Worker). Das hält Neugierige ab; geheim ist im
 *     Browser trotzdem nichts. Lösungen erreichen den Browser nie.
 *  3. Legt die Laufzeiten lokal ab (public/assets/vendor/):
 *       pyodide/   Kern aus dem npm-Paket + ausgewählte Pakete (numpy, matplotlib …)
 *       sqljs/     sql-wasm.js + sql-wasm.wasm
 *       katex/     CSS + Schriften
 *
 * Aufruf: npm run build   |   npm run build -- --watch   |   --no-obfuscate
 * Umgebungsvariable KW_SKIP_PY_PACKAGES=1 überspringt den Download der
 * Python-Zusatzpakete (z. B. offline in der Entwicklung).
 */
import { build, context } from 'esbuild';
import JavaScriptObfuscator from 'javascript-obfuscator';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
/** Paketverzeichnis unter node_modules (unabhängig von "exports"-Feldern). */
const pkgDir = (name) => path.join(here, 'node_modules', ...name.split('/'));
const here = path.dirname(fileURLToPath(import.meta.url));
const publicDir = path.resolve(here, '..', 'public');
const outDir = path.join(publicDir, 'assets', 'build');
const vendorDir = path.join(publicDir, 'assets', 'vendor');
const args = new Set(process.argv.slice(2));
const watch = args.has('--watch');
const obfuscate = !args.has('--no-obfuscate') && !watch;

/** Python-Pakete, die eine Klausur zuschalten kann (inkl. Abhängigkeiten). */
const PY_PACKAGES = ['numpy', 'matplotlib'];

function entries() {
    const dir = path.join(here, 'src', 'entries');
    if (!fs.existsSync(dir)) {
        return [];
    }
    return fs.readdirSync(dir)
        .filter((f) => f.endsWith('.js'))
        .map((f) => path.join(dir, f));
}

function isStudentBundle(file) {
    const base = path.basename(file);
    return base.startsWith('exam') || base.startsWith('worker-');
}

const esbuildOptions = (files) => ({
    entryPoints: files,
    outdir: outDir,
    bundle: true,
    format: 'esm',
    splitting: false,
    minify: !watch,
    sourcemap: false,
    target: ['es2022'],
    legalComments: 'none',
    logLevel: 'info',
    loader: { '.css': 'text', '.txt': 'text', '.svg': 'text' },
    define: { 'process.env.NODE_ENV': watch ? '"development"' : '"production"' },
});

function obfuscateBundles() {
    for (const file of fs.readdirSync(outDir)) {
        if (!file.endsWith('.js') || !isStudentBundle(file)) {
            continue;
        }
        const full = path.join(outDir, file);
        const code = fs.readFileSync(full, 'utf8');
        const result = JavaScriptObfuscator.obfuscate(code, {
            target: 'browser',
            compact: true,
            identifierNamesGenerator: 'hexadecimal',
            renameGlobals: false,
            stringArray: true,
            stringArrayThreshold: 0.6,
            stringArrayEncoding: ['base64'],
            splitStrings: false,
            controlFlowFlattening: false,
            deadCodeInjection: false,
            selfDefending: false,
            // debugProtection würde DevTools-Pausen erzwingen und schadet der
            // Performance; Erkennung übernimmt die Überwachung selbst.
            debugProtection: false,
            sourceMap: false,
            transformObjectKeys: false,
            unicodeEscapeSequence: false,
        });
        fs.writeFileSync(full, result.getObfuscatedCode());
        console.log(`  verschleiert: ${file}`);
    }
}

function copyDir(src, dest, filter = () => true) {
    fs.mkdirSync(dest, { recursive: true });
    for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
        const from = path.join(src, entry.name);
        const to = path.join(dest, entry.name);
        if (entry.isDirectory()) {
            copyDir(from, to, filter);
        } else if (filter(entry.name)) {
            fs.copyFileSync(from, to);
        }
    }
}

async function vendor() {
    // Pyodide-Kern
    const pyodideSrc = pkgDir('pyodide');
    const pyodideDest = path.join(vendorDir, 'pyodide');
    copyDir(pyodideSrc, pyodideDest, (name) => !name.endsWith('.map') && !name.endsWith('.html')
        && !name.endsWith('.d.ts') && name !== 'package.json' && name !== 'README.md');

    // Zusatzpakete aus dem Pyodide-CDN herunterladen (zur Build-Zeit, nicht zur Laufzeit).
    if (process.env.KW_SKIP_PY_PACKAGES !== '1') {
        const lock = JSON.parse(fs.readFileSync(path.join(pyodideSrc, 'pyodide-lock.json'), 'utf8'));
        const version = JSON.parse(fs.readFileSync(path.join(pyodideSrc, 'package.json'), 'utf8')).version;
        const wanted = new Set();
        const visit = (name) => {
            const key = name.toLowerCase();
            if (wanted.has(key) || !lock.packages[key]) {
                return;
            }
            wanted.add(key);
            for (const dep of lock.packages[key].depends || []) {
                visit(dep);
            }
        };
        PY_PACKAGES.forEach(visit);
        for (const key of wanted) {
            const file = lock.packages[key].file_name;
            const target = path.join(pyodideDest, file);
            if (fs.existsSync(target)) {
                continue;
            }
            const url = `https://cdn.jsdelivr.net/pyodide/v${version}/full/${file}`;
            const res = await fetch(url);
            if (!res.ok) {
                throw new Error(`Download fehlgeschlagen: ${url} (${res.status})`);
            }
            fs.writeFileSync(target, Buffer.from(await res.arrayBuffer()));
            console.log(`  Python-Paket: ${file}`);
        }
    }

    // sql.js
    const sqlSrc = path.join(pkgDir('sql.js'), 'dist');
    const sqlDest = path.join(vendorDir, 'sqljs');
    fs.mkdirSync(sqlDest, { recursive: true });
    for (const f of ['sql-wasm.js', 'sql-wasm.wasm']) {
        fs.copyFileSync(path.join(sqlSrc, f), path.join(sqlDest, f));
    }

    // KaTeX (CSS + Schriften), JS wird gebündelt
    const katexSrc = path.join(pkgDir('katex'), 'dist');
    const katexDest = path.join(vendorDir, 'katex');
    fs.mkdirSync(katexDest, { recursive: true });
    fs.copyFileSync(path.join(katexSrc, 'katex.min.css'), path.join(katexDest, 'katex.min.css'));
    copyDir(path.join(katexSrc, 'fonts'), path.join(katexDest, 'fonts'), (n) => n.endsWith('.woff2'));
}

fs.mkdirSync(outDir, { recursive: true });
const files = entries();

if (watch) {
    const ctx = await context(esbuildOptions(files));
    await ctx.watch();
    console.log('Beobachte src/ … (Strg+C beendet)');
} else {
    for (const f of fs.readdirSync(outDir)) {
        fs.rmSync(path.join(outDir, f), { recursive: true, force: true });
    }
    if (files.length > 0) {
        await build(esbuildOptions(files));
    }
    if (obfuscate) {
        obfuscateBundles();
    }
    await vendor();
    console.log('Build fertig.');
}
