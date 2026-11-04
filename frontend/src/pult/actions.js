/**
 * Aktionen des Pults: Definitionen (Beschriftung, passende Zustände, Dialoge) und Ausführung mit Rückmeldung.
 */
import { toast } from '../lib/dom.js';
import { confirmDialog, promptDialog } from './dialogs.js';
import { runAction } from './store.js';
import { plural } from './util.js';

const NOT_SUBMITTED = ['waiting', 'active', 'locked', 'absent', 'paused', 'submit_requested', 'device_pending'];
const ANY = [...NOT_SUBMITTED, 'submitted'];

/**
 * Personen-Aktionen der Aktionsleiste und der Detailansicht.
 * `states`: Zustände, für die die Aktion sinnvoll ist; andere Personen der Auswahl werden übersprungen.
 */
export const PERSON_ACTIONS = [
    { key: 'release', label: 'Freigeben', icon: '🔓', states: ['locked', 'absent', 'device_pending'] },
    { key: 'lock', label: 'Sperren', icon: '🔒', states: ['active'] },
    { key: 'set_absent', label: 'Abwesend (Toilette)', icon: '🚻', states: ['active'] },
    { key: 'pause', label: 'Pause', icon: '⏸', states: ['active'] },
    { key: 'resume', label: 'Fortsetzen', icon: '▶', states: ['paused'] },
    { key: 'add5', label: '+5 min', icon: '', states: NOT_SUBMITTED },
    { key: 'add10', label: '+10 min', icon: '', states: NOT_SUBMITTED },
    { key: 'add_custom', label: 'Zeit …', icon: '⏱', states: NOT_SUBMITTED },
    { key: 'submit', label: 'Abgeben', icon: '📥', states: NOT_SUBMITTED, danger: true },
    { key: 'reopen', label: 'Abgabe zurücknehmen', icon: '↩', states: ['submitted'] },
    { key: 'announce', label: 'Durchsage', icon: '📢', states: ANY },
];

export function createActions(store, { refresh }) {
    const names = (ids) => ids.map((id) => store.participant(id)?.name).filter(Boolean);

    function report(label, result) {
        if (!result.ok && result.errors.length === 0) {
            toast(`${label}: ${result.error ?? 'Das hat nicht geklappt.'}`, 'error', 8000);
            return;
        }
        if (result.errors.length > 0) {
            const lines = result.errors.slice(0, 4).map((e) => `${e.name ?? `Teilnahme ${e.participation_id}`}: ${e.error}`);
            const more = result.errors.length > 4 ? ` (und ${result.errors.length - 4} weitere)` : '';
            toast(`${label}: ${plural(result.affected, 'Person', 'Personen')} erledigt, Fehler bei ${lines.join('; ')}${more}`, 'warning', 10000);
            return;
        }
        toast(`${label}: ${plural(result.affected, 'Person', 'Personen')}.`, 'success', 3500);
    }

    async function exec(action, label, ids, extra) {
        const result = await runAction(store, action, ids, extra);
        report(label, result);
        refresh();
        return result;
    }

    /** Führt eine Personen-Aktion für die übergebenen Teilnahme-IDs aus (nur passende Zustände). */
    async function runPerson(key, allIds) {
        const def = PERSON_ACTIONS.find((a) => a.key === key);
        if (!def) return;
        const ids = allIds.filter((id) => def.states.includes(store.participant(id)?.state));
        if (ids.length === 0) {
            toast(`„${def.label}“ passt zu keiner der ausgewählten Personen.`, 'info');
            return;
        }
        const skipped = allIds.length - ids.length;
        const who = ids.length === 1 ? (names(ids)[0] ?? 'Person') : plural(ids.length, 'Person', 'Personen');
        const note = skipped > 0 ? `\n(${plural(skipped, 'weitere Person passt', 'weitere Personen passen')} nicht und wird übersprungen.)` : '';

        switch (key) {
            case 'release':
            case 'set_absent':
            case 'pause':
            case 'resume':
                return exec(key, def.label, ids);
            case 'add5':
                return exec('add_time', '+5 min', ids, { minutes: 5 });
            case 'add10':
                return exec('add_time', '+10 min', ids, { minutes: 10 });
            case 'add_custom': {
                const value = await promptDialog({
                    title: 'Zeit ändern',
                    label: `Minuten für ${who} (negativ nimmt Zusatzzeit zurück)`,
                    type: 'number',
                    value: '15',
                    min: -600,
                    max: 600,
                    confirmLabel: 'Anwenden',
                    help: 'Die Zusatzzeit kann nicht unter 0 sinken.',
                });
                const minutes = Number.parseInt(value ?? '', 10);
                if (value === null) return;
                if (!Number.isFinite(minutes) || minutes === 0) {
                    toast('Bitte gib eine Minutenzahl ungleich 0 an.', 'warning');
                    return;
                }
                return exec('add_time', `${minutes > 0 ? '+' : ''}${minutes} min`, ids, { minutes });
            }
            case 'lock': {
                const reason = await promptDialog({
                    title: 'Sperren',
                    label: `Grund für die Sperre (${who})`,
                    value: '',
                    required: false,
                    confirmLabel: 'Sperren',
                    help: 'Der Grund erscheint auf dem Bildschirm der Person. Leer lassen für „Von der Lehrkraft gesperrt“.',
                });
                if (reason === null) return;
                return exec('lock', 'Gesperrt', ids, { message: reason });
            }
            case 'submit': {
                const ok = await confirmDialog({
                    title: 'Abgabe bestätigen',
                    message: `${who} jetzt abgeben lassen?\nDanach sind keine Änderungen mehr möglich (die Abgabe kann zurückgenommen werden).${note}`,
                    confirmLabel: 'Abgeben',
                    danger: true,
                });
                return ok ? exec('submit', 'Abgegeben', ids) : undefined;
            }
            case 'reopen': {
                const reason = await promptDialog({
                    title: 'Abgabe zurücknehmen',
                    label: `Begründung (${who})`,
                    type: 'textarea',
                    confirmLabel: 'Zurücknehmen',
                    help: 'Die Begründung wird im Protokoll gespeichert.',
                });
                return reason === null ? undefined : exec('reopen', 'Abgabe zurückgenommen', ids, { message: reason });
            }
            case 'announce': {
                const text = await promptDialog({
                    title: 'Durchsage',
                    label: `Text an ${who}`,
                    type: 'textarea',
                    confirmLabel: 'Senden',
                    maxLength: 500,
                });
                return text === null ? undefined : exec('announce', 'Durchsage gesendet', ids, { message: text });
            }
            default:
        }
    }

    /** Durchsage an alle (Kopfleiste). */
    async function announceAll() {
        const text = await promptDialog({
            title: 'Durchsage an alle',
            label: 'Text für alle Teilnehmenden',
            type: 'textarea',
            confirmLabel: 'Senden',
            maxLength: 500,
        });
        if (text === null) return;
        const result = await runAction(store, 'announce', 'all', { message: text });
        if (result.ok) {
            toast('Durchsage an alle gesendet.', 'success', 3500);
        } else {
            toast(result.error ?? 'Die Durchsage konnte nicht gesendet werden.', 'error', 8000);
        }
        refresh();
    }

    // ---- Klausur-Aktionen ----
    async function examAction(key) {
        const { participants, exam } = store.state;
        const waiting = participants.filter((p) => p.state === 'waiting');
        const notReady = waiting.filter((p) => !p.ready);

        let confirmation = null;
        switch (key) {
            case 'start':
                confirmation = {
                    title: 'Klausur starten',
                    message: `Die Klausur jetzt für alle starten?\n${plural(waiting.length, 'Person ist', 'Personen sind')} im Warteraum`
                        + (notReady.length > 0 ? `, davon ${notReady.length} noch nicht „Bereit“` : '')
                        + `.\nDie Uhr (${exam?.duration_minutes ? `${exam.duration_minutes} min` : 'ohne Limit'}) läuft sofort.`,
                    confirmLabel: 'Jetzt starten',
                };
                break;
            case 'pause_all':
                confirmation = { title: 'Alle pausieren', message: 'Die Uhr hält für alle an und alle Bildschirme werden gesperrt. Fortfahren?', confirmLabel: 'Pausieren' };
                break;
            case 'finish': {
                const open = participants.filter((p) => p.state !== 'submitted');
                confirmation = {
                    title: 'Klausur beenden',
                    message: open.length > 0
                        ? `${plural(open.length, 'Person hat', 'Personen haben')} noch nicht abgegeben:\n${open.slice(0, 8).map((p) => p.name).join(', ')}${open.length > 8 ? ' …' : ''}\nDie Klausur kann erst beendet werden, wenn alle abgegeben haben. Du kannst vorher mit „Alle auswählen“ und „Abgeben“ alle abgeben lassen.`
                        : 'Alle haben abgegeben. Die Klausur jetzt beenden?',
                    confirmLabel: open.length > 0 ? 'Trotzdem versuchen' : 'Beenden',
                    danger: true,
                };
                break;
            }
            default:
        }
        if (confirmation && !(await confirmDialog(confirmation))) {
            return;
        }
        const labels = { open_lobby: 'Warteraum geöffnet', start: 'Klausur gestartet', pause_all: 'Alle pausiert', resume_all: 'Alle fortgesetzt', finish: 'Klausur beendet' };
        const result = await runAction(store, key);
        if (result.ok) {
            const warn = result.errors.length > 0 ? ` (${plural(result.errors.length, 'Fehler', 'Fehler')})` : '';
            toast(`${labels[key]}${warn}`, result.errors.length > 0 ? 'warning' : 'success', 4000);
        } else {
            const detail = (result.errors ?? []).slice(0, 5).map((e) => e.name ?? e.participation_id).join(', ');
            toast(`${result.error ?? 'Das hat nicht geklappt.'}${detail ? ` Offen: ${detail}` : ''}`, 'error', 9000);
        }
        refresh();
    }

    /** Anfrage bearbeiten (Annehmen/Ablehnen). */
    async function resolveRequest(request, participation, accept) {
        const map = {
            hand: { yes: 'ack_hand', no: 'ack_hand' },
            release: { yes: 'release', no: 'reject_release' },
            submit: { yes: 'approve_submit', no: 'reject_submit' },
            device: { yes: 'approve_device', no: 'reject_device' },
        };
        const action = map[request.type]?.[accept ? 'yes' : 'no'];
        if (!action) return;
        const result = await runAction(store, action, [participation.id]);
        if (!result.ok || result.errors.length > 0) {
            const text = result.error ?? result.errors[0]?.error ?? 'Das hat nicht geklappt.';
            toast(`${participation.name}: ${text}`, 'error', 7000);
        }
        refresh();
    }

    return { runPerson, examAction, announceAll, resolveRequest };
}
