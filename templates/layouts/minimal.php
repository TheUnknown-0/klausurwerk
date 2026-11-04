<?php
/**
 * Minimal-Layout: Login, Fehlerseiten, Setup.
 * Erwartet: $content, optional $title, $wide (bool), $pageScripts, $bundles.
 */
$baseUrl = $ctx->config['app']['base_url'];
$appName = $ctx->settings->get('app_name') ?: 'Klausurwerk';
$schoolName = $ctx->settings->get('school_name');
$logo = $ctx->settings->get('school_logo');
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
<div class="minimal-wrap">
    <div class="minimal-card<?= !empty($wide) ? ' wide' : '' ?>">
        <div class="brand-row">
            <?php if (!empty($logo)): ?>
                <img class="brand-logo" style="width:38px;height:38px;"
                     src="<?= e($ctx->url('/medien/logos/' . $logo)) ?>" alt="">
            <?php else: ?>
                <span class="brand-mark brand-mark-lg">K</span>
            <?php endif; ?>
            <div>
                <strong style="font-family:var(--font-display);font-size:1.05rem;"><?= e($appName) ?></strong>
                <?php if (!empty($schoolName)): ?>
                    <div class="text-sm text-soft"><?= e($schoolName) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?= $view->renderPartial('partials/flash') ?>
        <?= $content ?>
    </div>
</div>
<script src="<?= e(asset('js/app.js', $baseUrl)) ?>"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= e(asset('js/' . $script, $baseUrl)) ?>"></script>
<?php endforeach; ?>
<?php foreach ($bundles ?? [] as $bundleFile): ?>
    <script type="module" src="<?= e(bundle($bundleFile, $baseUrl)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
