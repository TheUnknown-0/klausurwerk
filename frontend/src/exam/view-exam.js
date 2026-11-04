/**
 * Klausuroberfläche: Kopfzeile, Aufgabenleiste, Aufgabenseiten mit Widgets,
 * Hilfsmittel-Seitenleiste, Banner und Durchsagen.
 */
import { h, setTrustedHtml, replace } from '../lib/dom.js';
import { enhanceRichText } from '../lib/rich-text.js';
import { answerWidget, FallbackWidget } from '../lib/widgets.js';
import { flattenTasks, isAnswerable, summarize, taskStatus } from './answers.js';
import { AidsPanel } from './aids.js';
import { saveStatusText } from './clock.js';

const lsGet = (k) => {
    try {
        return localStorage.getItem(k);
    } catch {
        return null;
    }
};
const lsSet = (k, v) => {
    try {
        localStorage.setItem(k, v);
    } catch {
        // gesperrt: ohne Merken weiterarbeiten
    }
};

const fmtPoints = (p) => String(Math.round(Number(p) * 100) / 100).replace('.', ',');
const STATUS_TEXT = { empty: 'offen', done: 'bearbeitet', flagged: 'später' };
const STATUS_ICON = { empty: '○', done: '●', flagged: '⚑' };

export class ExamView {
    /**
     * @param {{boot: object, tasks: object[], settings: object, answers: object, materials: object[],
     *   operators: object[], env: object, mode: string, preview: boolean, assets: object,
     *   callbacks: {onChange: Function, onFlag: Function, onHand: Function, onSubmit: Function,
     *               onMounted: Function, onCurrent: Function}}} o
     */
    constructor(o) {
        this.o = o;
        this.tasks = o.tasks ?? [];
        this.settings = o.settings ?? {};
        this.answers = o.answers;
        this.flat = flattenTasks(this.tasks);
        this.byId = new Map(this.flat.map((x) => [x.task.id, x]));
        this.touched = new Set();
        this.widgets = new Map(); // taskId -> { inst, host, task }
        this.blocks = new Map(); // taskId -> block element
        this.pages = new Map(); // topId -> page element
        this.navItems = new Map();
        this.flagBtns = new Map();
        this.readOnly = false;
        this.currentTop = null;
        this.currentTaskId = null;
        this.banners = new Map();
        this.announcements = [];
        this.key = `kw-exam-${o.boot.exam_id}`;
        this.onKey = (e) => this.handleKey(e);

        this.buildHeader();
        this.aids = new AidsPanel({
            settings: this.settings, materials: o.materials, operators: o.operators, assets: o.assets,
            onToggle: (open) => {
                this.aidsBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                this.layout.classList.toggle('aids-open', open);
            },
        });
        this.buildNav();
        this.main = h('main', { class: 'exam-main', id: 'exam-main', tabindex: '-1' });
        for (const t of this.tasks) {
            const page = h('section', { class: 'task-page', hidden: true, dataset: { top: t.id }, 'aria-label': `Aufgabe ${t.number ?? ''}` });
            page.append(this.buildBlock(t, 0));
            page.append(this.buildPager(t));
            this.pages.set(t.id, page);
            this.main.append(page);
        }
        if (this.tasks.length === 0) {
            this.main.append(h('div', { class: 'empty-state' }, h('div', { class: 'empty-icon', 'aria-hidden': 'true' }, '📄'), h('p', {}, 'Zu dieser Klausur gibt es keine Aufgaben.')));
        }
        this.bannerHost = h('div', { class: 'exam-banners', 'aria-live': 'polite' });
        this.layout = h('div', { class: 'exam-layout' }, this.nav, this.main, this.aids.el);
        this.el = h('div', { class: 'exam-view', dataset: { mode: o.mode } }, this.header, this.bannerHost, this.layout);
        this.finalize();
        this.applyFontSize();
        const split = Number(lsGet(`${this.key}-split`));
        this.setSplit(Number.isFinite(split) && split >= 20 && split <= 80 ? split : 42, false);
        document.addEventListener('keydown', this.onKey);

        if (o.preview) {
            this.banner('preview', 'Vorschau: Es wird nichts gespeichert und nichts an die Lehrkraft gemeldet. Deine Eingaben bleiben nur in diesem Fenster.', { type: 'info' });
        }
    }

    // ---------- Aufbau ----------

    buildHeader() {
        const { boot } = this.o;
        this.clockValue = h('span', { class: 'clock-value' }, '--:--');
        this.clockLabel = h('span', { class: 'clock-label' }, '');
        this.clock = h('div', { class: 'exam-clock', role: 'timer', 'aria-label': 'Restzeit' }, this.clockValue, this.clockLabel);
        this.saveEl = h('span', { class: 'save-status', role: 'status' }, '');
        this.connEl = h('span', { class: 'conn', dataset: { state: 'ok' }, role: 'status' }, h('span', { class: 'conn-dot', 'aria-hidden': 'true' }), h('span', { class: 'conn-text' }, 'verbunden'));

        const btn = (cls, label, text, handler, extra = {}) => {
            const b = h('button', { type: 'button', class: `btn btn-sm ${cls}`, 'aria-label': label, title: label, ...extra }, text);
            b.addEventListener('click', handler);
            return b;
        };
        this.navBtn = btn('nav-toggle', 'Aufgabenliste ein- oder ausblenden', '☰ Aufgaben', () => this.toggleNav(), { 'aria-expanded': 'false' });
        this.handBtn = btn('hand-btn', 'Hand heben', '✋ Hand', () => this.o.callbacks.onHand(), { 'aria-pressed': 'false' });
        this.aidsBtn = btn('aids-btn', 'Hilfsmittel öffnen', '🧰 Hilfsmittel', () => this.aids.toggle(), { 'aria-expanded': 'false' });
        this.annBtn = btn('ann-btn', 'Durchsagen anzeigen', '📢 0', () => this.showAnnouncementList(), { hidden: true });
        this.fontDown = btn('', 'Schrift kleiner', 'A−', () => this.changeFont(-1));
        this.fontUp = btn('', 'Schrift größer', 'A+', () => this.changeFont(1));
        this.themeBtn = btn('', 'Hell oder Dunkel umschalten', '◐', () => this.toggleTheme());
        this.submitBtn = btn('btn-primary submit-btn', 'Klausur abgeben', 'Abgeben', () => this.o.callbacks.onSubmit());

        const canAct = !this.o.preview;
        this.header = h('header', { class: 'exam-header' },
            this.navBtn,
            h('h1', { class: 'exam-title' }, boot.title ?? 'Klausur'),
            this.clock,
            h('div', { class: 'exam-statusbar' }, this.saveEl, this.connEl),
            h('div', { class: 'exam-actions' },
                canAct ? this.handBtn : null,
                this.annBtn,
                h('span', { class: 'aids-slot' }),
                this.fontDown, this.fontUp, this.themeBtn,
                canAct && this.settings.allow_self_submit && this.o.mode !== 'probe' ? this.submitBtn : null));
        this.aidsSlot = this.header.querySelector('.aids-slot');
    }

    buildNav() {
        const list = h('ul', { class: 'nav-list' });
        for (const { task, depth } of this.flat) {
            const status = h('span', { class: 'nav-status', 'aria-hidden': 'true' }, STATUS_ICON.empty);
            const sr = h('span', { class: 'sr-only' }, '');
            const meta = [];
            if (this.settings.show_points && task.points !== null && task.points !== undefined && Number(task.points) > 0) {
                meta.push(h('span', { class: 'nav-points' }, `${fmtPoints(task.points)} P.`));
            }
            const item = h('button', {
                type: 'button', class: 'nav-item', dataset: { task: task.id, depth },
                style: { paddingLeft: `${10 + depth * 14}px` },
            },
                status,
                h('span', { class: 'nav-num' }, task.number ?? ''),
                h('span', { class: 'nav-title' }, task.title || (isAnswerable(task) ? '' : 'Aufgabe')),
                ...meta, sr);
            item.addEventListener('click', () => {
                this.show(task.id, true);
                this.closeNavOnMobile();
            });
            this.navItems.set(task.id, { item, status, sr });
            list.append(h('li', {}, item));
        }
        this.progressText = h('span', { class: 'progress-text' }, '');
        this.progressBar = h('span', { style: { width: '0%' } });
        const progress = h('div', { class: 'nav-progress' },
            this.progressText,
            h('div', { class: 'progress', role: 'progressbar', 'aria-label': 'Fortschritt', 'aria-valuemin': '0', 'aria-valuemax': '100' }, this.progressBar));
        this.progressEl = progress.querySelector('.progress');
        this.nav = h('nav', { class: 'exam-nav', 'aria-label': 'Aufgaben' }, progress, list,
            h('p', { class: 'nav-hint' }, 'Alt + Pfeil: nächste oder vorige Aufgabe'));
    }

    buildBlock(task, depth) {
        const answerable = isAnswerable(task);
        const num = task.number ? String(task.number) : '';
        const heading = h(depth === 0 ? 'h2' : 'h3', { class: 'task-title', tabindex: '-1' },
            h('span', { class: 'task-num' }, depth === 0 ? `Aufgabe ${num}` : num),
            task.title ? h('span', { class: 'task-name' }, task.title) : null,
            this.settings.show_points && Number(task.points) > 0 ? h('span', { class: 'badge badge-primary task-points' }, `${fmtPoints(task.points)} Punkte`) : null);
        const head = h('div', { class: 'task-head' }, heading);
        if (answerable && !this.o.preview) {
            const flag = h('button', { type: 'button', class: 'btn btn-sm flag-btn', 'aria-pressed': 'false' }, '⚑ Später ansehen');
            flag.addEventListener('click', () => this.toggleFlag(task));
            this.flagBtns.set(task.id, flag);
            head.append(flag);
        }
        const body = h('div', { class: 'task-body rich' });
        if (task.body_html) {
            setTrustedHtml(body, task.body_html);
            try {
                enhanceRichText(body);
            } catch {
                // Formeln/Code bleiben dann unformatiert
            }
        }
        const textPart = h('div', { class: 'task-text' }, head, body);
        const divider = this.buildDivider();
        const host = h('div', { class: 'task-widget' });
        const children = h('div', { class: 'task-children' });
        const block = h('section', { class: `task-block depth-${depth}`, id: `task-${task.id}`, dataset: { task: task.id } }, textPart, divider, host);
        if ((task.children ?? []).length > 0) {
            for (const c of task.children) {
                children.append(this.buildBlock(c, depth + 1));
            }
            block.append(children);
        }
        block.addEventListener('focusin', () => this.setCurrentTask(task.id, false));
        this.blocks.set(task.id, { block, host, divider, task, mounted: false });
        return block;
    }

    buildDivider() {
        const d = h('div', {
            class: 'split-divider', role: 'separator', 'aria-orientation': 'vertical', tabindex: '0', hidden: true,
            'aria-label': 'Trennlinie zwischen Aufgabentext und Editor verschieben', 'aria-valuemin': '20', 'aria-valuemax': '80',
        });
        d.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            d.setPointerCapture(e.pointerId);
            const rect = d.parentElement.getBoundingClientRect();
            const move = (ev) => this.setSplit(((ev.clientX - rect.left) / rect.width) * 100, false);
            const up = () => {
                d.removeEventListener('pointermove', move);
                d.removeEventListener('pointerup', up);
                d.removeEventListener('pointercancel', up);
                lsSet(`${this.key}-split`, String(Math.round(this.split)));
            };
            d.addEventListener('pointermove', move);
            d.addEventListener('pointerup', up);
            d.addEventListener('pointercancel', up);
        });
        d.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                e.preventDefault();
                this.setSplit(this.split + (e.key === 'ArrowLeft' ? -3 : 3), true);
            } else if (e.key === 'Home' || e.key === 'Enter') {
                e.preventDefault();
                this.setSplit(42, true);
            }
        });
        d.addEventListener('dblclick', () => this.setSplit(42, true));
        return d;
    }

    setSplit(pct, persist) {
        this.split = Math.max(20, Math.min(80, pct));
        this.el?.style.setProperty('--split', `${this.split}%`);
        for (const b of this.blocks.values()) {
            b.divider.setAttribute('aria-valuenow', String(Math.round(this.split)));
        }
        if (persist) {
            lsSet(`${this.key}-split`, String(Math.round(this.split)));
        }
    }

    buildPager(task) {
        const idx = this.tasks.indexOf(task);
        const prev = this.tasks[idx - 1];
        const next = this.tasks[idx + 1];
        const mk = (label, target) => {
            const b = h('button', { type: 'button', class: 'btn' }, label);
            b.addEventListener('click', () => this.show(target.id, true));
            return b;
        };
        return h('div', { class: 'task-pager' }, prev ? mk('← Vorherige Aufgabe', prev) : h('span'), next ? mk('Nächste Aufgabe →', next) : h('span'));
    }

    // ---------- Navigation ----------

    topOf(id) {
        let node = this.byId.get(id);
        while (node?.parent) {
            node = this.byId.get(node.parent.id);
        }
        return node?.task ?? null;
    }

    /** Zeigt die Seite der Aufgabe; Teilaufgaben werden angesprungen. */
    show(taskId, focus = false) {
        const top = this.topOf(taskId);
        if (!top) {
            return;
        }
        if (this.currentTop !== top.id) {
            if (this.currentTop !== null) {
                this.pages.get(this.currentTop).hidden = true;
            }
            this.currentTop = top.id;
            const page = this.pages.get(top.id);
            page.hidden = false;
            this.mountPage(top);
        }
        this.setCurrentTask(taskId, true);
        const entry = this.blocks.get(taskId);
        if (entry) {
            if (taskId === top.id) {
                this.main.scrollTop = 0;
                if (focus) {
                    entry.block.querySelector('.task-title')?.focus({ preventScroll: true });
                }
            } else {
                entry.block.scrollIntoView({ block: 'start', behavior: 'smooth' });
                if (focus) {
                    entry.block.querySelector('.task-title')?.focus({ preventScroll: true });
                }
            }
        }
        lsSet(`${this.key}-last`, String(taskId));
    }

    setCurrentTask(id, fromNav) {
        if (this.currentTaskId === id) {
            return;
        }
        this.currentTaskId = id;
        for (const [tid, n] of this.navItems) {
            if (tid === id) {
                n.item.setAttribute('aria-current', 'true');
            } else {
                n.item.removeAttribute('aria-current');
            }
        }
        this.o.callbacks.onCurrent?.(id, fromNav);
    }

    restorePosition() {
        const last = Number(lsGet(`${this.key}-last`));
        const first = this.tasks[0]?.id;
        this.show(this.byId.has(last) ? last : first);
    }

    step(delta) {
        const idx = this.tasks.findIndex((t) => t.id === this.currentTop);
        const target = this.tasks[idx + delta];
        if (target) {
            this.show(target.id, true);
        }
    }

    handleKey(e) {
        if (!e.altKey || e.ctrlKey || e.metaKey) {
            return;
        }
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
            e.preventDefault();
            this.step(1);
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
            e.preventDefault();
            this.step(-1);
        }
    }

    toggleNav() {
        const open = !this.nav.classList.contains('is-open');
        this.nav.classList.toggle('is-open', open);
        this.navBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    closeNavOnMobile() {
        this.nav.classList.remove('is-open');
        this.navBtn.setAttribute('aria-expanded', 'false');
    }

    // ---------- Widgets ----------

    mountPage(top) {
        const walk = (t) => {
            this.mountBlock(t);
            (t.children ?? []).forEach(walk);
        };
        walk(top);
        this.refreshStatuses();
    }

    mountBlock(task) {
        const entry = this.blocks.get(task.id);
        if (!entry || entry.mounted) {
            return;
        }
        entry.mounted = true;
        if (!isAnswerable(task)) {
            return;
        }
        const Widget = answerWidget(task.answer_type) ?? FallbackWidget;
        const ctx = {
            task,
            content: this.answers[String(task.id)]?.content ?? null,
            readOnly: this.readOnly,
            mode: this.o.preview ? 'preview' : 'exam',
            env: this.o.env,
            onChange: (content, meta) => this.handleChange(task, content, meta),
        };
        try {
            const inst = Widget.mount(entry.host, ctx);
            this.widgets.set(task.id, { inst, task });
            if (inst?.layout === 'split') {
                entry.block.classList.add('is-split');
                entry.divider.hidden = false;
            }
            if (this.readOnly) {
                inst?.setReadOnly?.(true);
            }
            let content = null;
            try {
                content = inst?.getContent?.() ?? null;
            } catch {
                content = null;
            }
            this.o.callbacks.onMounted?.(task, content);
        } catch (err) {
            console.warn('Widget konnte nicht geladen werden', task.id, err);
            entry.host.replaceChildren(h('div', { class: 'alert alert-error', role: 'alert' },
                'Dieses Antwortfeld konnte nicht geladen werden. Deine übrigen Aufgaben sind nicht betroffen. Sag der Lehrkraft Bescheid.'));
        }
        this.updateFlagButton(task.id);
    }

    handleChange(task, content, meta) {
        if (this.readOnly) {
            return;
        }
        this.touched.add(String(task.id));
        const prev = this.answers[String(task.id)];
        this.answers[String(task.id)] = { content, version: prev?.version ?? 0, flagged: !!prev?.flagged };
        this.refreshStatuses();
        this.o.callbacks.onChange(task, content, meta);
    }

    toggleFlag(task) {
        if (this.readOnly) {
            return;
        }
        const id = String(task.id);
        const prev = this.answers[id];
        const flagged = !prev?.flagged;
        let content = prev?.content ?? null;
        try {
            content = this.widgets.get(task.id)?.inst?.getContent?.() ?? content;
        } catch {
            // letzter bekannter Inhalt
        }
        this.answers[id] = { content, version: prev?.version ?? 0, flagged };
        this.updateFlagButton(task.id);
        this.refreshStatuses();
        this.o.callbacks.onFlag(task, flagged, content);
    }

    updateFlagButton(taskId) {
        const b = this.flagBtns.get(taskId);
        if (b) {
            const on = !!this.answers[String(taskId)]?.flagged;
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.classList.toggle('is-on', on);
            b.textContent = on ? '⚑ Markiert (später ansehen)' : '⚑ Später ansehen';
        }
    }

    setReadOnly(flag) {
        this.readOnly = !!flag;
        this.el.classList.toggle('is-locked', this.readOnly);
        for (const { inst } of this.widgets.values()) {
            try {
                inst?.setReadOnly?.(this.readOnly);
            } catch {
                // Widget ohne Unterstützung
            }
        }
        for (const b of this.flagBtns.values()) {
            b.disabled = this.readOnly;
        }
        this.submitBtn.disabled = this.readOnly;
    }

    /** Aktuelle Inhalte aller gemounteten Widgets (z. B. vor Abgabe). */
    collectContents() {
        const out = {};
        for (const [id, { inst }] of this.widgets) {
            try {
                out[id] = inst.getContent();
            } catch {
                // überspringen
            }
        }
        return out;
    }

    // ---------- Status ----------

    refreshStatuses() {
        for (const [id, n] of this.navItems) {
            const task = this.byId.get(id).task;
            if (!isAnswerable(task)) {
                n.status.textContent = '';
                n.item.dataset.status = 'container';
                continue;
            }
            const st = taskStatus(task, this.answers[String(id)], this.touched.has(String(id)));
            n.status.textContent = STATUS_ICON[st];
            n.sr.textContent = `, ${STATUS_TEXT[st]}`;
            n.item.dataset.status = st;
        }
        const s = summarize(this.tasks, this.answers, this.touched);
        this.progressText.textContent = s.total > 0 ? `${s.answered} von ${s.total} bearbeitet` : 'Keine Antwortfelder';
        const pct = s.total > 0 ? Math.round((s.answered / s.total) * 100) : 0;
        this.progressBar.style.width = `${pct}%`;
        this.progressEl.setAttribute('aria-valuenow', String(pct));
    }

    getSummary() {
        return summarize(this.tasks, this.answers, this.touched);
    }

    setClock(view) {
        this.clockValue.textContent = view.text;
        this.clockLabel.textContent = view.label;
        this.clock.dataset.kind = view.kind;
        this.clock.setAttribute('aria-label', `${view.label}: ${view.text}`);
    }

    setSave(status, lastSavedAt) {
        const age = lastSavedAt === null || lastSavedAt === undefined ? 0 : Date.now() - lastSavedAt;
        this.saveEl.textContent = saveStatusText(status, age);
        this.saveEl.dataset.state = status.kind;
    }

    setConnection(state, text) {
        this.connEl.dataset.state = state;
        this.connEl.querySelector('.conn-text').textContent = text;
    }

    setHand(raised) {
        this.handBtn.setAttribute('aria-pressed', raised ? 'true' : 'false');
        this.handBtn.classList.toggle('is-on', raised);
        this.handBtn.textContent = raised ? '✋ Hand unten' : '✋ Hand';
        this.handBtn.setAttribute('aria-label', raised ? 'Hand wieder senken' : 'Hand heben');
    }

    // ---------- Banner und Durchsagen ----------

    banner(key, text, { type = 'info', dismissible = true } = {}) {
        this.clearBanner(key);
        const el = h('div', { class: `exam-banner alert alert-${type}`, dataset: { key } }, h('span', { class: 'banner-text' }, text));
        if (dismissible) {
            const close = h('button', { type: 'button', class: 'btn btn-sm btn-ghost', 'aria-label': 'Hinweis schließen' }, 'OK');
            close.addEventListener('click', () => this.clearBanner(key));
            el.append(close);
        }
        this.banners.set(key, el);
        this.bannerHost.append(el);
        return el;
    }

    clearBanner(key) {
        this.banners.get(key)?.remove();
        this.banners.delete(key);
    }

    setAnnouncements(list) {
        this.announcements = list;
        this.annBtn.hidden = list.length === 0;
        this.annBtn.textContent = `📢 ${list.length}`;
    }

    showAnnouncementList() {
        const dlg = h('dialog', { class: 'modal', 'aria-labelledby': 'ann-title' },
            h('div', { class: 'modal-header' }, h('h3', { id: 'ann-title' }, 'Durchsagen')),
            h('div', { class: 'modal-body' },
                this.announcements.length === 0 ? h('p', {}, 'Es gab noch keine Durchsagen.') : h('ul', { class: 'ann-list' },
                    ...[...this.announcements].reverse().map((a) => h('li', {},
                        h('time', { class: 'ann-time' }, new Date(a.created_at_ms).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })),
                        ' ', a.message, a.personal ? h('span', { class: 'badge badge-info' }, 'an dich') : null)))),
            h('div', { class: 'modal-footer' }, (() => {
                const b = h('button', { type: 'button', class: 'btn btn-primary' }, 'Schließen');
                b.addEventListener('click', () => {
                    dlg.close();
                    dlg.remove();
                });
                return b;
            })()));
        dlg.addEventListener('close', () => dlg.remove());
        document.body.append(dlg);
        dlg.showModal();
    }

    // ---------- Darstellung ----------

    toggleTheme() {
        const cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        const next = cur === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        lsSet('kw-theme', next);
    }

    applyFontSize() {
        const base = Number(this.settings.editor?.font_size) || 15;
        const delta = Number(lsGet(`${this.key}-fs`)) || 0;
        this.fontSize = Math.max(11, Math.min(28, base + delta));
        this.el.style.setProperty('--exam-fs', `${this.fontSize}px`);
    }

    changeFont(dir) {
        const base = Number(this.settings.editor?.font_size) || 15;
        const next = Math.max(11, Math.min(28, this.fontSize + dir));
        lsSet(`${this.key}-fs`, String(next - base));
        this.fontSize = next;
        this.el.style.setProperty('--exam-fs', `${next}px`);
    }

    /** Hängt die Hilfsmittel-Schaltfläche nur an, wenn es Hilfsmittel gibt. */
    finalize() {
        if (this.aids.hasAids) {
            replace(this.aidsSlot, this.aidsBtn);
        } else {
            this.aidsSlot.remove();
        }
    }

    focusMain() {
        this.main.focus({ preventScroll: true });
    }

    destroy() {
        document.removeEventListener('keydown', this.onKey);
        for (const { inst } of this.widgets.values()) {
            try {
                inst?.destroy?.();
            } catch {
                // egal
            }
        }
        this.el.remove();
    }
}
