<?php
/**
 * HTML-Teil der Anmeldecode-Mail (kein Layout).
 * Erwartet: $name, $code.
 */
?>
<!doctype html>
<html lang="de">
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1a1e24;">
<div style="max-width:480px;margin:0 auto;padding:24px 16px;">
    <div style="background:#ffffff;border-radius:8px;padding:24px;border:1px solid #e2e5ea;text-align:center;">
        <h1 style="font-size:16px;margin:0 0 16px;">Dein Anmeldecode</h1>
        <?php if ($name !== ''): ?><p style="margin:0 0 8px;">Hallo <?= e($name) ?>,</p><?php endif; ?>
        <p style="font-size:32px;font-weight:bold;letter-spacing:6px;margin:16px 0;color:#3730a3;">
            <?= e($code) ?>
        </p>
        <p style="margin:0 0 4px;color:#6b7280;font-size:13px;">Gültig für 10 Minuten, nur einmal verwendbar.</p>
        <p style="margin:16px 0 0;color:#9aa1ab;font-size:12px;">
            Falls du diese Anmeldung nicht angefordert hast, kannst du diese Mail ignorieren.
        </p>
    </div>
</div>
</body>
</html>
