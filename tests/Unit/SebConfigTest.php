<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SebConfig;
use App\Services\TaskTree;
use PHPUnit\Framework\TestCase;

/**
 * Die SEB-Dokumentation nennt keinen prüfbaren Testvektor (Beispiel-JSON und Beispiel-Hash
 * gehören nicht zusammen). Geprüft werden deshalb die dokumentierten Regeln einzeln.
 */
final class SebConfigTest extends TestCase
{
    public function testJsonIstSortiertOhneLeerraumUndEscaping(): void
    {
        $json = SebConfig::sebJson([
            'startURL' => 'https://x.de/a/b',
            'allowWLAN' => false,
            'allowWlan' => true,
            'Beta' => 1,
            'alpha' => 'ä/ö',
            'originatorVersion' => 'SEB_OSX_3.0',
        ]);
        // Kleinbuchstabe vor Großbuchstabe bei gleichem Namen, sonst ohne Beachtung der Schreibweise.
        self::assertSame('{"allowWlan":true,"allowWLAN":false,"alpha":"ä/ö","Beta":1,"startURL":"https://x.de/a/b"}', $json);
    }

    public function testVerschachtelteSortierungInListenUndLeereDictsEntfallen(): void
    {
        $json = SebConfig::sebJson([
            'rules' => [['regex' => false, 'action' => 1, 'expression' => 'x/*']],
            'empty' => (object) [],
            'list' => [],
            'nested' => ['z' => 1, 'a' => ['b' => 2, 'a' => (object) []]],
        ]);
        self::assertSame('{"list":[],"nested":{"a":{"b":2},"z":1},"rules":[{"action":1,"expression":"x/*","regex":false}]}', $json);
    }

    public function testConfigKeyIstSha256DesJsonUndIgnoriertOriginatorVersion(): void
    {
        $a = ['b' => 1, 'a' => 'x', 'originatorVersion' => '1'];
        $b = ['a' => 'x', 'b' => 1, 'originatorVersion' => '2'];
        self::assertSame(hash('sha256', '{"a":"x","b":1}'), SebConfig::configKey($a));
        self::assertSame(SebConfig::configKey($a), SebConfig::configKey($b));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', SebConfig::configKey($a));
    }

    public function testBuildEnthaeltAdressenHashUndGueltigesPlist(): void
    {
        $built = SebConfig::build(['id' => 12, 'title' => 'Test'], 'https://klausur.example.org/', 'geheim');
        $xml = $built['xml'];

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'plist muss wohlgeformtes XML sein');
        self::assertStringContainsString('<key>startURL</key>', $xml);
        self::assertStringContainsString('<string>https://klausur.example.org/klausur/12</string>', $xml);
        self::assertStringContainsString('<string>https://klausur.example.org/seb-beenden</string>', $xml);
        self::assertStringContainsString('<key>sendBrowserExamKey</key>', $xml);
        self::assertStringContainsString('<string>' . hash('sha256', 'geheim') . '</string>', $xml);
        self::assertStringNotContainsString('geheim<', $xml, 'Passwort nie im Klartext');
        self::assertStringContainsString('<string>https://klausur.example.org/*</string>', $xml);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $built['config_key']);
    }

    public function testBuildOhnePasswortHatKeinenQuitHashUndGleicheEingabeGleicherKey(): void
    {
        $a = SebConfig::build(['id' => 1], 'http://localhost:8080', '');
        $b = SebConfig::build(['id' => 1], 'http://localhost:8080', '');
        $c = SebConfig::build(['id' => 2], 'http://localhost:8080', '');
        self::assertStringNotContainsString('hashedQuitPassword', $a['xml']);
        self::assertSame($a['config_key'], $b['config_key']);
        self::assertNotSame($a['config_key'], $c['config_key']);
    }

    public function testBuildEscapedSonderzeichenImXml(): void
    {
        $built = SebConfig::build(['id' => 3], 'https://a.de/p?x=1&y=2', '');
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($built['xml']));
        self::assertStringContainsString('x=1&amp;y=2', $built['xml']);
    }

    public function testVerifyRequest(): void
    {
        $key = str_repeat('ab', 32);
        $url = 'https://klausur.example.org/klausur/12';
        $header = hash('sha256', $url . $key);
        self::assertTrue(SebConfig::verifyRequest($key, $url, $header));
        self::assertTrue(SebConfig::verifyRequest($key, $url . '#frag', strtoupper($header)), 'Fragment zählt nicht, Header ist unabhängig von der Schreibweise');
        self::assertFalse(SebConfig::verifyRequest($key, $url . '/x', $header));
        self::assertFalse(SebConfig::verifyRequest($key, $url, null));
        self::assertFalse(SebConfig::verifyRequest($key, $url, ''));
        self::assertFalse(SebConfig::verifyRequest('', $url, $header));
    }

    // ---------- TaskTree (hier mitgeprüft, ebenfalls ohne Datenbank) ----------

    public function testTaskTreeNummeriertUndSortiert(): void
    {
        $rows = [
            ['id' => 3, 'parent_id' => 1, 'position' => 1, 'title' => 'b'],
            ['id' => 2, 'parent_id' => 1, 'position' => 0, 'title' => 'a'],
            ['id' => 5, 'parent_id' => null, 'position' => 1, 'title' => 'zwei'],
            ['id' => 1, 'parent_id' => null, 'position' => 0, 'title' => 'eins'],
        ];
        $flat = TaskTree::flatten(TaskTree::build($rows));
        self::assertSame([1, 2, 3, 5], array_column($flat, 'id'));
        self::assertSame(['1', '1a', '1b', '2'], array_column($flat, 'number'));
        self::assertSame([0, 1, 1, 0], array_column($flat, 'depth'));
    }

    public function testTaskTreeBuchstabenNachZ(): void
    {
        self::assertSame('a', TaskTree::letters(0));
        self::assertSame('z', TaskTree::letters(25));
        self::assertSame('aa', TaskTree::letters(26));
    }
}
