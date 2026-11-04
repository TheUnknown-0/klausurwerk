<?php
/**
 * Haupt-Layout für eingeloggte Bereiche (Sidebar + Topbar).
 * Erwartet: $content (HTML), optional $title,
 *   $pageScripts (list<string>, Pfade relativ zu /assets/js/, unbundled),
 *   $bundles (list<string>, Dateinamen unter /assets/build/, z. B. 'pult.js'),
 *   $pageStyles (list<string>, Pfade relativ zu /assets/css/),
 *   $wideContent (bool, volle Breite z. B. für Pult/Editoren).
 * Über View::share: $ctx, $auth, $csrf.
 */
$baseUrl = $ctx->config['app']['base_url'];
$appName = $ctx->settings->get('app_name') ?: 'Klausurwerk';
?><!DOCTYPE html>
<html lang="de" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrf->token()) ?>">
    <meta name="base-url" content="<?= e($baseUrl) ?>">
    <title><?= e(isset($title) ? $title . ' · ' . $appName : $appName) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg', $baseUrl)) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css', $baseUrl)) ?>">
<?php foreach ($pageStyles ?? [] as $style): ?>
    <link rel="stylesheet" href="<?= e(asset('css/' . $style, $baseUrl)) ?>">
<?php endforeach; ?>
    <script src="<?= e(asset('js/theme-init.js', $baseUrl)) ?>"></script>
</head>
<body>
<div class="app-shell">
    <?= $view->renderPartial('partials/sidebar') ?>
    <div class="sidebar-backdrop" hidden></div>
    <div class="app-main">
        <?= $view->renderPartial('partials/topbar', ['title' => $title ?? null]) ?>
        <main class="app-content<?= !empty($wideContent) ? ' app-content-wide' : '' ?>">
            <?= $view->renderPartial('partials/flash') ?>
            <?= $content ?>
        </main>
    </div>
</div>
<script src="<?= e(asset('js/app.js', $baseUrl)) ?>"></script>
<script src="<?= e(asset('js/live-search.js', $baseUrl)) ?>"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= e(asset('js/' . $script, $baseUrl)) ?>"></script>
<?php endforeach; ?>
<?php foreach ($bundles ?? [] as $bundleFile): ?>
    <script type="module" src="<?= e(bundle($bundleFile, $baseUrl)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
