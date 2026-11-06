<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Changelog;
use PHPUnit\Framework\TestCase;

/** Versionsnummer und Änderungsverlauf aus CHANGELOG.md. */
final class ChangelogTest extends TestCase
{
    public function testParserLiestVersionenDatumUndPunkte(): void
    {
        $md = "# Änderungen\n\nEinleitung mit `## 9.9.9` im Text.\n\n"
            . "## 1.2.0 (2026-11-06)\n\n- Erster Punkt\n  über zwei Zeilen.\n- Zweiter Punkt\n\n"
            . "## v1.1.0\n\n* Ohne Datum\n\nFließtext wird ignoriert.\n";
        $entries = Changelog::parse($md);

        self::assertCount(2, $entries);
        self::assertSame('1.2.0', $entries[0]['version']);
        self::assertSame('2026-11-06', $entries[0]['date']);
        self::assertSame(['Erster Punkt über zwei Zeilen.', 'Zweiter Punkt'], $entries[0]['items']);
        self::assertSame('1.1.0', $entries[1]['version']);
        self::assertNull($entries[1]['date']);
        self::assertSame(['Ohne Datum'], $entries[1]['items']);
    }

    public function testFremdeUeberschriftBeendetAbschnitt(): void
    {
        $entries = Changelog::parse("## 1.0.0\n- a\n## Sonstiges\n- b\n");
        self::assertSame(['a'], $entries[0]['items']);
        self::assertCount(1, $entries);
    }

    public function testCrlfUndLeererText(): void
    {
        self::assertSame([], Changelog::parse(''));
        self::assertSame([], Changelog::parse("<html>Not Found</html>"));
        self::assertSame('2.0.0', Changelog::parse("## 2.0.0 (2027-01-01)\r\n- x\r\n")[0]['version']);
    }

    public function testNeuereVersionen(): void
    {
        $entries = Changelog::parse("## 1.10.0\n- c\n## 1.9.0\n- b\n## 1.2.0\n- a\n");
        $newer = Changelog::newerThan($entries, '1.9.0');
        self::assertSame(['1.10.0'], array_column($newer, 'version'));
        self::assertSame([], Changelog::newerThan($entries, '1.10.0'));
    }

    public function testPunktWirdEscaped(): void
    {
        self::assertSame(
            '&lt;script&gt; <code>sudo ./update.sh</code> <strong>wichtig</strong>',
            Changelog::itemHtml('<script> `sudo ./update.sh` **wichtig**'),
        );
        self::assertSame('<code>&lt;b&gt;</code>', Changelog::itemHtml('`<b>`'));
    }

    public function testDatum(): void
    {
        self::assertSame('6. November 2026', Changelog::formatDate('2026-11-06'));
        self::assertNull(Changelog::formatDate('2026-13-01'));
        self::assertNull(Changelog::formatDate(null));
    }

    /** Die mitgelieferte CHANGELOG.md muss lesbar sein: Versionen absteigend, jede mit Datum und Punkten. */
    public function testMitgelieferteDatei(): void
    {
        $entries = Changelog::local();
        self::assertNotSame([], $entries, 'CHANGELOG.md fehlt oder hat keine Version.');
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Changelog::currentVersion());

        $previous = null;
        foreach ($entries as $entry) {
            self::assertNotNull($entry['date'], 'Version ' . $entry['version'] . ' ohne Datum');
            self::assertNotSame([], $entry['items'], 'Version ' . $entry['version'] . ' ohne Punkte');
            if ($previous !== null) {
                self::assertTrue(
                    version_compare($previous, $entry['version'], '>'),
                    "Versionen nicht absteigend: {$previous} vor {$entry['version']}",
                );
            }
            $previous = $entry['version'];
        }
    }
}
