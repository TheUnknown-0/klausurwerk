/**
 * Bundle der Schüleransicht (Seite /klausur/{id} und Vorschau).
 * Alle Antwort-Widgets registrieren sich über widgets/all.js.
 */
import '../widgets/all.js';
import { bootData } from '../lib/api.js';
import { ExamApp } from '../exam/app.js';

const root = document.getElementById('exam-app');
if (root) {
    const app = new ExamApp(root, bootData('exam-boot'));
    app.start().catch((err) => {
        console.error('Start der Klausurseite fehlgeschlagen', err);
        app.showMessage('Die Klausur kann nicht angezeigt werden', 'Es ist ein unerwarteter Fehler aufgetreten. Lade die Seite neu.', true);
    });
}
