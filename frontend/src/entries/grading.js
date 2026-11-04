/**
 * Bundle der Korrektur und der Rückgabe. Eine Seite genügt je einem der Einstiegspunkte:
 *   #grading-overview   Korrektur-Übersicht (Filter, automatische Vorbewertung)
 *   #grading-app        Einzelansicht der Korrektur
 *   #results-app        Ergebnisse (Rückmeldung, Abschluss)
 *   #review-app         Rückgabe an Schüler:innen
 * Antwort-Widgets registrieren sich über widgets/all.js.
 */
import '../widgets/all.js';
import { bootData } from '../lib/api.js';
import { mountGrading } from '../grading/app.js';
import { mountOverview } from '../grading/overview.js';
import { mountResults } from '../grading/results.js';
import { mountReview } from '../grading/review.js';

const overview = document.getElementById('grading-overview');
if (overview) {
    mountOverview(overview);
}

const app = document.getElementById('grading-app');
if (app) {
    const boot = bootData('grading-boot');
    if (boot) {
        mountGrading(app, boot);
    } else {
        app.textContent = 'Die Korrektur konnte nicht gestartet werden (Startdaten fehlen). Lade die Seite neu.';
    }
}

const results = document.getElementById('results-app');
if (results) {
    mountResults(results);
}

const review = document.getElementById('review-app');
if (review) {
    const boot = bootData('review-boot');
    if (boot) {
        mountReview(review, boot);
    } else {
        review.textContent = 'Die Aufgaben konnten nicht angezeigt werden. Lade die Seite neu.';
    }
}
