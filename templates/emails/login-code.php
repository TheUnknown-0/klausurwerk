<?php
/**
 * HTML-Teil der Anmeldecode-Mail (kein Layout).
 * Erwartet: $name, $code.
 * Mail-Clients kennen keine Variablen und kein externes CSS: die Werte sind hier fest
 * (Papier, Tinte, roter Heftrand wie im Design-System "Klausurbogen").
 */
?>
<!doctype html>
<html lang="de">
<body style="margin:0;padding:0;background:#f4f1e8;font-family:'Atkinson Hyperlegible','Segoe UI',Arial,sans-serif;color:#1b1d28;">
<div style="max-width:480px;margin:0 auto;padding:24px 16px;">
    <div style="background:#fffdf8;border-radius:4px;padding:28px 28px 24px 44px;border:1px solid #b9b09b;border-left:1px solid #b9b09b;text-align:left;">
        <h1 style="font-family:Georgia,'Times New Roman',serif;font-weight:500;font-size:22px;margin:0 0 16px;">Dein Anmeldecode</h1>
        <?php if ($name !== ''): ?><p style="margin:0 0 8px;">Hallo <?= e($name) ?>,</p><?php endif; ?>
        <p style="font-family:Consolas,'Courier New',monospace;font-size:32px;letter-spacing:8px;margin:16px 0;padding:10px 0;color:#1f3fa8;border-top:1px solid #c9d2ee;border-bottom:1px solid #c9d2ee;">
            <?= e($code) ?>
        </p>
        <p style="margin:0 0 4px;color:#464a5c;font-size:14px;">Gültig für 10 Minuten, nur einmal verwendbar.</p>
        <p style="margin:16px 0 0;color:#7a7c88;font-size:12px;">
            Falls du diese Anmeldung nicht angefordert hast, kannst du diese Mail ignorieren.
        </p>
    </div>
</div>
</body>
</html>
