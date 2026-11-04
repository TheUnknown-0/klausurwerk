<?php
/** Topbar: Mobile-Sidebar-Toggle, Theme-Umschalter, Benutzermenü. */
$user = $auth->user();
?>
<header class="topbar">
    <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Navigation öffnen">☰</button>
    <div class="topbar-spacer"></div>

    <button class="btn btn-sm" type="button" data-toggle-theme aria-label="Hell-/Dunkelmodus umschalten">◐</button>

    <?php if ($user !== null): ?>
        <div class="menu">
            <button class="user-chip btn btn-sm" type="button" data-menu-toggle>
                <span class="avatar"><?= e(mb_strtoupper(mb_substr(display_name($user), 0, 1))) ?></span>
                <span class="nowrap"><?= e(display_name($user)) ?></span>
            </button>
            <div class="menu-list" hidden>
                <?php if ($user['role'] !== 'student'): ?>
                    <a class="menu-item" href="<?= e($ctx->url('/passwort-aendern')) ?>">🔒 Passwort ändern</a>
                <?php endif; ?>
                <form method="post" action="<?= e($ctx->url('/logout')) ?>">
                    <?= $csrf->field() ?>
                    <button class="menu-item danger" type="submit">🚪 Abmelden</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</header>
