/**
 * Bundle des Lehrer-Pults (Seite /klausuren/{id}/pult).
 * Die Antwort-Widgets (nur lesend, Modus "monitor") registrieren sich über widgets/all.js.
 */
import '../widgets/all.js';
import { bootData } from '../lib/api.js';
import { mountPult } from '../pult/app.js';

const root = document.getElementById('pult-app');
const boot = bootData('pult-boot');
if (root && boot) {
    mountPult(root, boot);
} else if (root) {
    root.textContent = 'Das Pult konnte nicht gestartet werden (Startdaten fehlen). Lade die Seite neu.';
}
