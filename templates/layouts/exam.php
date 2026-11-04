<?php
/**
 * Klausur-Layout für die Schüleransicht: keine Sidebar, keine Topbar,
 * volle Fensterfläche. Die gesamte Oberfläche baut das Bundle selbst auf.
 * Erwartet: $content (Mount-Punkt + JSON-Bootstrap), $title,
 *   $bundles (list<string> unter /assets/build/), $pageStyles.
 * Bewusst ohne app.js/live-search.js: das Bundle bringt alles mit.
 */
$baseUrl = $ctx->config['app']['base_url'];
$appName = $ctx->settings->get('app_name') ?: 'Klausurwerk';
?><!DOCTYPE html>
<html lang="de" class="no-js exam-root">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrf->token()) ?>">
    <meta name="base-url" content="<?= e($baseUrl) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(isset($title) ? $title . ' · ' . $appName : $appName) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg', $baseUrl)) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css', $baseUrl)) ?>">
<?php foreach ($pageStyles ?? [] as $style): ?>
    <link rel="stylesheet" href="<?= e(asset('css/' . $style, $baseUrl)) ?>">
<?php endforeach; ?>
    <script src="<?= e(asset('js/theme-init.js', $baseUrl)) ?>"></script>
</head>
<body class="exam-body">
<?= $content ?>
<?php foreach ($bundles ?? [] as $bundleFile): ?>
    <script type="module" src="<?= e(bundle($bundleFile, $baseUrl)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
