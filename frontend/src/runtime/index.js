/**
 * Platzhalter: Laufzeitumgebung für Code-Ausführung (Python/SQL im Browser,
 * Java über /api/ausfuehren). Schnittstelle: frontend/src/lib/widgets.js, „runtime“.
 */
export function createRuntime() {
    return {
        canRun: () => false,
        run() {
            throw new Error('Ausführung ist noch nicht verfügbar.');
        },
    };
}
