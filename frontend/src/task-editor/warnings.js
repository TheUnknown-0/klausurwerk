/**
 * Hinweise im Aufgaben-Editor (nur Hinweis, nie ein Hindernis).
 * Spiegel der Fertigstellen-Prüfung im Server (TaskService::testPointsWarning).
 */

/** Warnung, wenn die Testpunkte nicht der Aufgabenpunktzahl entsprechen; sonst ''. */
export function testPointsWarning(points, tests) {
    if (!Array.isArray(tests) || tests.length === 0) {
        return '';
    }
    const sum = tests.reduce((acc, t) => acc + (Number(t?.points ?? 1) || 0), 0);
    const total = Number(points) || 0;
    if (Math.abs(sum - total) < 0.005) {
        return '';
    }
    const f = (n) => String(Math.round(n * 100) / 100).replace('.', ',');
    return `Die Tests ergeben zusammen ${f(sum)} Punkte, die Aufgabe ist aber mit ${f(total)} Punkten bewertet. `
        + 'Die automatische Vorbewertung schlägt deshalb nicht die volle Punktzahl vor.';
}
