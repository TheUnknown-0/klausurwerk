<?php
/**
 * Sidebar-Navigation: rollen- und berechtigungsabhängig.
 * Neue Bereiche werden hier eingetragen (eine Stelle für die Navigation).
 */

use App\Core\Permissions as P;

$user = $auth->user();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$appName = $ctx->settings->get('app_name') ?: 'Klausurwerk';
$schoolName = $ctx->settings->get('school_name');
$logo = $ctx->settings->get('school_logo');
$base = $ctx->config['app']['base_url'];

$navLink = static function (string $url, string $icon, string $label) use ($currentPath): string {
    $active = $currentPath === $url || ($url !== '/' && str_starts_with($currentPath, rtrim($url, '/') . '/'));

    return '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . e($url) . '">'
        . '<span class="nav-icon">' . icon($icon) . '</span>' . e($label) . '</a>';
};
$u = static fn (string $path): string => $ctx->url($path);
?>
<aside class="sidebar">
    <a class="sidebar-brand" href="<?= e($ctx->url('/')) ?>">
        <?php if (!empty($logo)): ?>
            <img class="brand-logo" src="<?= e($ctx->url('/medien/logos/' . $logo)) ?>" alt="">
        <?php else: ?>
            <span class="brand-mark">K</span>
        <?php endif; ?>
        <span><?= e($appName) ?></span>
    </a>
    <?php if (!empty($schoolName)): ?>
        <div class="sidebar-school"><?= e((string) $schoolName) ?></div>
    <?php endif; ?>

    <nav class="sidebar-nav">
        <?php if ($user !== null):
            $role = $user['role'];
            $can = static fn (string $p): bool => $auth->can($p);
            $sections = [];

            if ($role === 'student') {
                $sections[] = ['Meine Klausuren', [
                    [$u('/meine-klausuren'), 'file-pen-line', 'Klausuren & Übungen', true],
                    [$u('/meine-ergebnisse'), 'file-text', 'Ergebnisse', true],
                ]];
            }

            if (in_array($role, ['admin', 'teacher'], true)) {
                $sections[] = ['Unterricht', [
                    [$u('/dashboard'), 'house', 'Übersicht', true],
                    [$u('/klausuren'), 'file-pen-line', 'Klausuren', $can(P::KLAUSUREN_ERSTELLEN) || $ctx->exams->visibleExamIds() !== []],
                    [$u('/archiv'), 'archive', 'Archiv', $can(P::ARCHIV_DURCHSUCHEN)],
                    // KI-Assistent: sichtbar, sobald die Schule ihn freigeschaltet hat (Admins immer, zum Einschalten).
                    [$u('/ki'), 'bot', 'KI-Assistent', $role === 'admin' || $ctx->settings->getBool(\App\Services\Ai\AiAccess::SETTING)],
                    [$u('/admin/kurse'), 'graduation-cap', 'Kurse', $can(P::KURSE_SEHEN)],
                    [$u('/admin/raeume'), 'armchair', 'Räume', $can(P::RAEUME_VERWALTEN)],
                ]];
                $sections[] = ['Verwaltung', [
                    [$u('/admin/benutzer'), 'users', 'Benutzer', $can(P::BENUTZER_SEHEN)],
                    [$u('/admin/zugangskarten'), 'id-card', 'Zugangskarten', $can(P::ZUGANGSKARTEN_DRUCKEN)],
                    [$u('/admin/notenschluessel'), 'scale', 'Notenschlüssel', $can(P::NOTENSCHLUESSEL_BEARBEITEN)],
                    [$u('/admin/operatoren'), 'type', 'Operatoren', $can(P::OPERATOREN_BEARBEITEN)],
                    [$u('/admin/berechtigungen'), 'key-round', 'Berechtigungen', $can(P::BERECHTIGUNGEN_SEHEN)],
                    [$u('/admin/einstellungen'), 'settings', 'Einstellungen', $can(P::EINSTELLUNGEN_SEHEN)],
                    [$u('/admin/aufbewahrung'), 'trash-2', 'Aufbewahrung', $can(P::AUFBEWAHRUNG_VERWALTEN)],
                    [$u('/admin/audit-log'), 'scroll-text', 'Audit-Log', $can(P::AUDIT_LOGS_SEHEN)],
                ]];
            }

            foreach ($sections as [$sectionTitle, $items]) {
                $items = array_filter($items, static fn (array $item): bool => $item[3]);
                if ($items === []) {
                    continue;
                }
                echo '<div class="nav-section">' . e($sectionTitle) . '</div>';
                foreach ($items as [$url, $icon, $label]) {
                    echo $navLink($url, $icon, $label);
                }
            }
        endif; ?>
    </nav>

    <div class="sidebar-footer">
        <?php $version = \App\Services\Changelog::currentVersion(); ?>
        <?php if (!empty($update)): ?>
            <button class="version-link" type="button" data-open-modal="version-dialog" title="Änderungen und Updates">
                Klausurwerk <?= e($version) ?>
                <span class="version-dot" data-update-dot<?= $update['available'] ? '' : ' hidden' ?>><span class="sr-only">(Update verfügbar)</span></span>
            </button>
        <?php else: ?>
            <span class="version-label">Klausurwerk <?= e($version) ?></span>
        <?php endif; ?>
    </div>
</aside>
