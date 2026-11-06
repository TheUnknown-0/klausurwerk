<?php
/** Topbar: Mobile-Sidebar-Toggle, Update-Hinweis (nur Admins, $update), Theme-Umschalter, Benutzermenü. */
$user = $auth->user();
?>
<header class="topbar">
    <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Navigation öffnen"><?= icon('menu') ?></button>
    <div class="topbar-spacer"></div>

    <?php if (!empty($update)): ?>
        <button class="btn btn-sm update-pill" type="button" data-open-modal="version-dialog" data-update-pill<?= $update['available'] ? '' : ' hidden' ?>>
            <?= icon('download') ?> <span data-update-pill-label>Update auf <?= e((string) $update['latest']) ?></span>
        </button>
    <?php endif; ?>

    <button class="btn btn-sm" type="button" data-toggle-theme aria-label="Hell-/Dunkelmodus umschalten" title="Hell/Dunkel"><?= icon('sun-moon') ?></button>

    <?php if ($user !== null): ?>
        <div class="menu">
            <button class="user-chip btn btn-sm" type="button" data-menu-toggle>
                <span class="avatar"><?= e(mb_strtoupper(mb_substr(display_name($user), 0, 1))) ?></span>
                <span class="nowrap"><?= e(display_name($user)) ?></span>
            </button>
            <div class="menu-list" hidden>
                <?php if ($user['role'] !== 'student'): ?>
                    <a class="menu-item" href="<?= e($ctx->url('/passwort-aendern')) ?>"><?= icon('key-round') ?> Passwort ändern</a>
                <?php endif; ?>
                <form method="post" action="<?= e($ctx->url('/logout')) ?>">
                    <?= $csrf->field() ?>
                    <button class="menu-item danger" type="submit"><?= icon('log-out') ?> Abmelden</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</header>
