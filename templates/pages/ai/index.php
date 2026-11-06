<?php
/**
 * KI-Assistent: Einrichtung des KI-Zugangs (MCP) Schritt für Schritt.
 * Erwartet: $enabled, $isAdmin, $mcpUrl, $isHttps, $isLocal, $baseIsGuessed, $grants, $allGrants.
 */

$fmt = static fn (?string $ts): string => $ts ? date('d.m.Y H:i', (int) strtotime($ts)) : 'noch nie';
$grantRow = static function (array $g, bool $showUser) use ($fmt, $ctx, $csrf): string {
    $name = trim((string) ($g['firstname'] ?? '') . ' ' . (string) ($g['lastname'] ?? '')) ?: (string) $g['username'];
    ob_start(); ?>
    <tr>
        <td><strong><?= e((string) ($g['client_name'] ?: 'KI-Programm')) ?></strong></td>
        <?php if ($showUser): ?><td><?= e($name) ?></td><?php endif; ?>
        <td><?= e($fmt((string) $g['created_at'])) ?></td>
        <td><?= e($fmt($g['last_used_at'] !== null ? (string) $g['last_used_at'] : null)) ?></td>
        <td class="text-right">
            <form method="post" action="<?= e($ctx->url('/ki/zugaenge/' . (int) $g['id'] . '/beenden')) ?>">
                <?= $csrf->field() ?>
                <button class="btn btn-sm btn-danger-ghost" type="submit"><?= icon('x') ?> Beenden</button>
            </form>
        </td>
    </tr>
    <?php return (string) ob_get_clean();
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Unterricht</div>
        <h1 class="page-title">KI-Assistent</h1>
        <p class="page-sub">Klausuren und Aufgaben zusammen mit einer KI erstellen: Claude, ChatGPT oder ein anderes Programm, das MCP versteht.</p>
    </div>
</div>

<div class="ai-steps">

    <!-- Schritt 1: Freischaltung -->
    <section class="card card-pad mb-2 ai-step<?= $enabled ? ' is-done' : '' ?>">
        <h2 class="mt-0"><span class="ai-step-num">1</span> Freischaltung durch die Schule</h2>
        <?php if ($enabled): ?>
            <p class="mb-0"><span class="badge badge-success">Eingeschaltet</span> Der KI-Zugang ist an dieser Schule freigeschaltet.</p>
        <?php else: ?>
            <p><span class="badge">Ausgeschaltet</span> Solange der KI-Zugang aus ist, kann sich kein KI-Programm verbinden.</p>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
            <form method="post" action="<?= e($ctx->url('/ki/schalter')) ?>" class="mt-2">
                <?= $csrf->field() ?>
                <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                <?php if ($enabled): ?>
                    <button class="btn" type="submit">KI-Zugang ausschalten (beendet alle Verbindungen)</button>
                <?php else: ?>
                    <p class="text-sm text-soft">Vor dem Einschalten bedenken: Das KI-Programm bekommt die Inhalte der
                    Klausuren der jeweiligen Lehrkraft, auch Musterlösungen und Erwartungshorizonte, und verarbeitet sie
                    beim Anbieter der KI. Schülerdaten (Namen, Antworten, Noten) bleiben immer in Klausurwerk.
                    Bitte die Vorgaben der Schule zum Datenschutz beachten.</p>
                    <button class="btn btn-primary" type="submit"><?= icon('check') ?> KI-Zugang einschalten</button>
                <?php endif; ?>
            </form>
        <?php elseif (!$enabled): ?>
            <p class="mb-0">Bitte die Admins deiner Schule, ihn freizuschalten (auf dieser Seite, Schritt 1).</p>
        <?php endif; ?>
    </section>

    <!-- Schritt 2: Adresse -->
    <section class="card card-pad mb-2 ai-step">
        <h2 class="mt-0"><span class="ai-step-num">2</span> Adresse kopieren</h2>
        <p>Diese Adresse trägst du gleich im KI-Programm ein:</p>
        <div class="input-group ai-url">
            <input class="input" type="text" id="ai-mcp-url" value="<?= e($mcpUrl) ?>" readonly aria-label="MCP-Adresse">
            <button class="btn btn-primary" type="button" data-copy-target="ai-mcp-url"><?= icon('copy') ?> Kopieren</button>
        </div>
        <?php if (!$isHttps || $isLocal): ?>
            <div class="alert alert-warning mt-2 mb-0">
                <div>
                    <strong>Diese Adresse erreichen Claude und ChatGPT nicht.</strong>
                    Ihre Server laufen im Internet und brauchen eine öffentliche Adresse mit HTTPS, etwa
                    <code>https://klausur.meine-schule.de/mcp</code>.
                    <?php if ($baseIsGuessed): ?>Unter <a href="<?= e($ctx->url('/admin/einstellungen')) ?>">Einstellungen</a> die öffentliche Adresse eintragen.<?php endif; ?>
                    Programme auf demselben Rechner (Claude Code) funktionieren auch so.
                </div>
            </div>
        <?php endif; ?>
    </section>

    <!-- Schritt 3: Programm verbinden -->
    <section class="card card-pad mb-2 ai-step">
        <h2 class="mt-0"><span class="ai-step-num">3</span> Im KI-Programm verbinden</h2>
        <p>Wähle dein Programm. Am Ende öffnet sich ein Fenster von Klausurwerk: anmelden und auf <strong>Erlauben</strong> klicken.
        Die Bezeichnungen in den Programmen ändern sich ab und zu; gemeint ist immer ein <em>eigener Connector</em> bzw. <em>MCP-Server</em>.</p>

        <details class="ai-app" open>
            <summary><?= icon('bot') ?> Claude (Web, Desktop-App, Handy)</summary>
            <ol class="ai-list">
                <li>Auf <a href="https://claude.ai" target="_blank" rel="noopener">claude.ai</a> oder in der Claude-App unten links auf deinen Namen, dann <strong>Einstellungen › Connectors</strong>.</li>
                <li><strong>Eigenen Connector hinzufügen</strong> (Custom Connector) wählen.</li>
                <li>Name: <code>Klausurwerk</code>, Adresse: die kopierte Adresse aus Schritt 2. Mit <strong>Hinzufügen</strong> bestätigen.</li>
                <li>Beim neuen Connector auf <strong>Verbinden</strong> klicken, bei Klausurwerk anmelden und <strong>Erlauben</strong>.</li>
                <li>Im Chat über das Werkzeug-Symbol prüfen, dass <em>Klausurwerk</em> eingeschaltet ist.</li>
            </ol>
            <p class="text-sm text-soft">In Teams und Unternehmen fügen oft die Organisations-Admins Connectors hinzu. Je nach Tarif ist die Zahl eigener Connectors begrenzt.</p>
        </details>

        <details class="ai-app">
            <summary><?= icon('bot') ?> ChatGPT</summary>
            <ol class="ai-list">
                <li>In ChatGPT <strong>Einstellungen › Apps &amp; Connectors</strong> öffnen.</li>
                <li>Unter <strong>Erweitert</strong> den <strong>Entwicklermodus</strong> einschalten (nötig für eigene Connectors).</li>
                <li><strong>Erstellen</strong> wählen: Name <code>Klausurwerk</code>, Adresse aus Schritt 2, Authentifizierung <strong>OAuth</strong>.</li>
                <li>Speichern, dann <strong>Verbinden</strong>, bei Klausurwerk anmelden und <strong>Erlauben</strong>.</li>
                <li>Im Chat über <strong>+ › Mehr</strong> bzw. das Werkzeug-Menü den Connector auswählen.</li>
            </ol>
        </details>

        <details class="ai-app">
            <summary><?= icon('square-terminal') ?> Claude Code (Kommandozeile)</summary>
            <div class="ai-cmd" id="ai-cmd-claude-code">claude mcp add --transport http klausurwerk <?= e($mcpUrl) ?></div>
            <p><button class="btn btn-sm" type="button" data-copy-target="ai-cmd-claude-code"><?= icon('copy') ?> Befehl kopieren</button></p>
            <p class="text-sm text-soft">Danach in Claude Code <code>/mcp</code> eingeben, <em>klausurwerk</em> wählen und die Anmeldung im Browser abschließen.</p>
        </details>

        <details class="ai-app">
            <summary><?= icon('code-xml') ?> Andere Programme (Cursor, VS Code, LibreChat, ...)</summary>
            <p>Klausurwerk ist ein MCP-Server mit Transport <strong>Streamable HTTP</strong> und Anmeldung per <strong>OAuth</strong>
            (Programme melden sich selbst an, eine Client-ID ist nicht nötig). Die Adresse aus Schritt 2 als MCP-Server eintragen.</p>
            <p class="text-sm text-soft">Beispiel für eine Konfigurationsdatei (mcp.json):</p>
            <div class="ai-cmd" id="ai-cmd-json">{ "mcpServers": { "klausurwerk": { "type": "http", "url": "<?= e($mcpUrl) ?>" } } }</div>
        </details>
    </section>

    <!-- Schritt 4: Ausprobieren -->
    <section class="card card-pad mb-2 ai-step">
        <h2 class="mt-0"><span class="ai-step-num">4</span> Ausprobieren</h2>
        <p>Schreib der KI einfach, was du brauchst. Ein paar Beispiele:</p>
        <ul class="ai-prompts">
            <li>„Lege in Klausurwerk eine Übung für meinen Kurs 11b zu Schleifen in Python an: zwei Auswahlaufgaben, ein Lückentext und zwei Programmieraufgaben mit Tests.“</li>
            <li>„Schau dir meine Klausur ‚Q1 Modellierung‘ an und ergänze zu jeder Aufgabe einen Erwartungshorizont.“</li>
            <li>„Suche im Archiv nach Aufgaben zu SQL-Joins und übernimm die zwei besten in meine Klausur.“</li>
            <li>„Prüfe die Klausur auf fehlende Lösungen und Punkte und erkläre mir, was noch fehlt.“</li>
        </ul>
        <p class="text-sm text-soft mb-0">Die KI meldet nach jeder neuen Aufgabe einen Vorschau-Link. Prüfe jede Aufgabe selbst, bevor du sie einsetzt:
        Die Verantwortung für die Klausur bleibt bei dir.</p>
    </section>

    <!-- Verbindungen -->
    <section class="card card-pad mb-2">
        <h2 class="mt-0">Deine Verbindungen</h2>
        <?php if ($grants === []): ?>
            <p class="text-soft mb-0">Noch kein KI-Programm verbunden.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Programm</th><th>Verbunden seit</th><th>Zuletzt benutzt</th><th></th></tr></thead>
                    <tbody><?php foreach ($grants as $g) { echo $grantRow($g, false); } ?></tbody>
                </table>
            </div>
            <p class="text-sm text-soft mb-0">„Beenden“ sperrt das Programm sofort aus. Zum erneuten Verbinden im Programm wieder auf „Verbinden“ klicken.</p>
        <?php endif; ?>
    </section>

    <?php if ($isAdmin && $allGrants !== []): ?>
        <section class="card card-pad mb-2">
            <h2 class="mt-0">Alle Verbindungen der Schule</h2>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Programm</th><th>Lehrkraft</th><th>Verbunden seit</th><th>Zuletzt benutzt</th><th></th></tr></thead>
                    <tbody><?php foreach ($allGrants as $g) { echo $grantRow($g, true); } ?></tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <details class="card card-pad mb-2">
        <summary><strong>Was die KI darf und was nicht</strong></summary>
        <ul>
            <li>Sie arbeitet mit deinen Rechten: nur Klausuren, die du auch in Klausurwerk siehst und bearbeiten darfst.</li>
            <li>Klausuren anlegen und ändern, Aufgaben anlegen, ändern, verschieben, duplizieren und löschen, Material hochladen und löschen, im Archiv suchen und übernehmen.</li>
            <li>Gestartete und abgeschlossene Klausuren sind gesperrt, genau wie im Editor.</li>
            <li>Kein Zugriff auf Schülerdaten: Namen, Zugangskarten, Antworten, Korrektur, Ergebnisse und Noten.</li>
            <li>Jede Änderung steht im Audit-Log mit dem Namen des Programms.</li>
            <li>Schlüssel gelten eine Stunde und werden im Hintergrund erneuert; nach 30 Tagen ohne Nutzung endet die Verbindung von selbst.</li>
        </ul>
    </details>
</div>
