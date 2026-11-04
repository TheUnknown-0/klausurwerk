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
        . '<span class="nav-icon" aria-hidden="true">' . $icon . '</span>' . e($label) . '</a>';
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
                    [$u('/meine-klausuren'), '📝', 'Klausuren & Übungen', true],
                    [$u('/meine-ergebnisse'), '📄', 'Ergebnisse', true],
                ]];
            }

            if (in_array($role, ['admin', 'teacher'], true)) {
                $sections[] = ['Unterricht', [
                    [$u('/dashboard'), '🏠', 'Übersicht', true],
                    [$u('/klausuren'), '📝', 'Klausuren', $can(P::KLAUSUREN_ERSTELLEN) || $ctx->exams->visibleExamIds() !== []],
                    [$u('/archiv'), '🗂️', 'Archiv', $can(P::ARCHIV_DURCHSUCHEN)],
                    [$u('/admin/kurse'), '👩‍🎓', 'Kurse', $can(P::KURSE_SEHEN)],
                    [$u('/admin/raeume'), '🪑', 'Räume', $can(P::RAEUME_VERWALTEN)],
                ]];
                $sections[] = ['Verwaltung', [
                    [$u('/admin/benutzer'), '👥', 'Benutzer', $can(P::BENUTZER_SEHEN)],
                    [$u('/admin/zugangskarten'), '🪪', 'Zugangskarten', $can(P::ZUGANGSKARTEN_DRUCKEN)],
                    [$u('/admin/notenschluessel'), '🎓', 'Notenschlüssel', $can(P::NOTENSCHLUESSEL_BEARBEITEN)],
                    [$u('/admin/operatoren'), '🔤', 'Operatoren', $can(P::OPERATOREN_BEARBEITEN)],
                    [$u('/admin/berechtigungen'), '🔑', 'Berechtigungen', $can(P::BERECHTIGUNGEN_SEHEN)],
                    [$u('/admin/einstellungen'), '⚙️', 'Einstellungen', $can(P::EINSTELLUNGEN_SEHEN)],
                    [$u('/admin/aufbewahrung'), '🗑️', 'Aufbewahrung', $can(P::AUFBEWAHRUNG_VERWALTEN)],
                    [$u('/admin/audit-log'), '📜', 'Audit-Log', $can(P::AUDIT_LOGS_SEHEN)],
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
        <?= e($appName) ?>
    </div>
</aside>
