<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Web\WebError;
use App\Services\Web\WebFetcher;
use App\Services\Web\WebRewriter;
use App\Services\Web\WebSites;
use App\Services\Web\WebToken;
use PHPUnit\Framework\TestCase;

/** Eingebauter Browser: Freigaberegeln, Schutz gegen interne Ziele, Umschreiben, Marken. */
final class WebBrowserTest extends TestCase
{
    public function testFreigabeAlsAdressanfang(): void
    {
        $sites = WebSites::normalize([
            ['url' => 'docs.python.org/3/library/', 'label' => 'Python'],
            ['url' => 'https://de.wikipedia.org/wiki/Bubblesort', 'exact' => true],
        ]);
        self::assertSame('https://docs.python.org/3/library/', $sites[0]['url']);
        self::assertTrue(WebSites::allows($sites, 'https://docs.python.org/3/library/os.html'));
        self::assertTrue(WebSites::allows($sites, 'http://docs.python.org/3/library/'));
        self::assertTrue(WebSites::allows($sites, 'https://www.docs.python.org/3/library/re.html#flags'));
        self::assertFalse(WebSites::allows($sites, 'https://docs.python.org/3/tutorial/'));
        self::assertFalse(WebSites::allows($sites, 'https://docs.python.org.evil.example/3/library/'));
        self::assertFalse(WebSites::allows($sites, 'https://evil.example/?x=https://docs.python.org/3/library/'));
        // Genau eine Seite
        self::assertTrue(WebSites::allows($sites, 'https://de.wikipedia.org/wiki/Bubblesort'));
        self::assertFalse(WebSites::allows($sites, 'https://de.wikipedia.org/wiki/Quicksort'));
        self::assertFalse(WebSites::allows($sites, 'https://de.wikipedia.org/wiki/Bubblesort/x'));
        // Pfadgrenze: /3/library erlaubt nicht /3/libraryx
        $noSlash = WebSites::normalize([['url' => 'https://docs.python.org/3/library']]);
        self::assertTrue(WebSites::allows($noSlash, 'https://docs.python.org/3/library/os.html'));
        self::assertFalse(WebSites::allows($noSlash, 'https://docs.python.org/3/libraryx'));
        // Ohne Freigabe: alles gesperrt
        self::assertFalse(WebSites::allows([], 'https://docs.python.org/'));
        self::assertFalse(WebSites::allows($sites, 'javascript:alert(1)'));
        self::assertFalse(WebSites::allows($sites, 'file:///etc/passwd'));
    }

    public function testUngueltigeFreigabenWerdenAbgelehnt(): void
    {
        self::assertSame([], WebSites::normalize([['url' => '  ']]));
        $this->expectException(\InvalidArgumentException::class);
        WebSites::normalize([['url' => 'ftp://example.org/']]);
    }

    public function testNurOeffentlicheZiele(): void
    {
        foreach (['10.0.0.5', '192.168.1.1', '172.20.0.3', '127.0.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fd00::1', 'fe80::1', '::ffff:10.0.0.1'] as $ip) {
            self::assertFalse(WebFetcher::isPublicIp($ip), $ip);
        }
        self::assertTrue(WebFetcher::isPublicIp('151.101.0.223'));
        self::assertTrue(WebFetcher::isPublicIp('2a04:4e42::223'));

        $fetcher = new WebFetcher(sys_get_temp_dir(), static fn (string $host): array => $host === 'intern.example' ? ['10.1.2.3'] : ['93.184.215.14']);
        self::assertSame('93.184.215.14', $fetcher->target('https://docs.example/x')['ip']);
        foreach (['https://intern.example/', 'http://127.0.0.1/', 'https://docs.example:8080/', 'gopher://docs.example/', 'https://user:pw@docs.example/'] as $url) {
            try {
                $fetcher->target($url);
                self::fail('Ziel nicht gesperrt: ' . $url);
            } catch (WebError) {
                self::assertTrue(true);
            }
        }
    }

    public function testAdressenAufloesen(): void
    {
        $b = 'https://docs.example/3/library/os.html?x=1';
        self::assertSame('https://docs.example/3/library/re.html', WebRewriter::resolve($b, 're.html'));
        self::assertSame('https://docs.example/3/index.html', WebRewriter::resolve($b, '../index.html'));
        self::assertSame('https://docs.example/a', WebRewriter::resolve($b, '/a'));
        self::assertSame('https://cdn.example/x.css', WebRewriter::resolve($b, '//cdn.example/x.css'));
        self::assertSame('https://docs.example/3/library/os.html?y=2', WebRewriter::resolve($b, '?y=2'));
        self::assertSame('https://docs.example/', WebRewriter::resolve($b, '../../../../'));
    }

    public function testUmschreibenEntferntAktivesUndLeitetLinksUm(): void
    {
        $rw = new WebRewriter(
            'https://docs.example/3/',
            static fn (string $u): string => '/web/T?u=' . rawurlencode($u),
            static fn (string $u): string => '/web/T?u=' . rawurlencode($u) . '&s=sig',
        );
        $html = '<html><head><script>alert(1)</script><meta http-equiv="refresh" content="0;url=https://evil.example">'
            . '<link rel="stylesheet" href="style.css" integrity="sha-x" crossorigin><base href="https://evil.example/"></head>'
            . '<body onload="x()"><a href="os.html" target="_blank" onclick="y()">OS</a> <a href="javascript:alert(2)">J</a>'
            . '<img src="//cdn.example/p.png" srcset="a.png 1x, b.png 2x"><iframe src="https://evil.example"></iframe>'
            . '<noscript><p id="ns">ohne Skript</p></noscript><svg><script>alert(3)</script></svg>'
            . '<form action="search.html"><input name="q"></form><form method="post" action="/login"><input name="pw"></form>'
            . '<div style="background:url(bg.png)">x</div></body></html>';
        $out = $rw->html($html, 'utf-8', '/web/T?u=back');

        self::assertStringNotContainsString('<script', $out);
        self::assertStringNotContainsString('alert(', $out);
        self::assertStringNotContainsString('onload', $out);
        self::assertStringNotContainsString('onclick', $out);
        self::assertStringNotContainsString('<iframe', $out);
        self::assertStringNotContainsString('http-equiv', $out);
        self::assertStringNotContainsString('<base', $out);
        self::assertStringNotContainsString('integrity', $out);
        self::assertStringNotContainsString('target=', $out);
        self::assertStringNotContainsString('javascript:', $out);
        self::assertStringContainsString('href="/web/T?u=' . rawurlencode('https://docs.example/3/os.html') . '"', $out);
        self::assertStringContainsString(rawurlencode('https://cdn.example/p.png') . '&amp;s=sig', $out);
        self::assertStringContainsString(rawurlencode('https://docs.example/3/style.css') . '&amp;s=sig', $out);
        self::assertStringContainsString(rawurlencode('https://docs.example/3/bg.png'), $out);
        self::assertStringContainsString('ohne Skript', $out);
        self::assertStringContainsString('name="__kw_u"', $out);
        self::assertMatchesRegularExpression('/<input[^>]*name="pw"[^>]*disabled/', $out);
        self::assertStringContainsString('← Zurück', $out);
    }

    public function testCssWirdUmgeschrieben(): void
    {
        $rw = new WebRewriter('https://docs.example/css/', static fn (string $u): string => $u, static fn (string $u): string => 'A(' . $u . ')');
        $css = $rw->css('@import "base.css"; .x{background:url(\'../img/a.png\')} .y{background:url(data:image/png;base64,AAAA)} .z{width:expression(alert(1))}');
        self::assertStringContainsString('A(https://docs.example/css/base.css)', $css);
        self::assertStringContainsString('A(https://docs.example/img/a.png)', $css);
        self::assertStringContainsString('data:image/png;base64,AAAA', $css);
        self::assertStringNotContainsString('expression(', $css);
    }

    public function testMarkeUndSignatur(): void
    {
        $t = new WebToken(str_repeat('ab', 32));
        $token = $t->issue(3, 15, 'p', 77, 1_000_000);
        self::assertSame(['e' => 3, 't' => 15, 'k' => 'p', 'i' => 77, 'x' => 1_000_000 + WebToken::TTL], $t->verify($token, 1_000_001));
        self::assertNull($t->verify($token, 1_000_000 + WebToken::TTL + 1), 'abgelaufen');
        self::assertNull((new WebToken(str_repeat('cd', 32)))->verify($token, 1_000_001), 'fremder Schlüssel');
        [$payload, $mac] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode((string) json_encode(['e' => 3, 't' => 15, 'k' => 'u', 'i' => 1, 'x' => 2_000_000])), '+/', '-_'), '=');
        self::assertNull($t->verify($forged . '.' . $mac, 1_000_001), 'veränderte Nutzdaten');
        $sig = $t->asset($token, 'https://cdn.example/a.png');
        self::assertTrue($t->assetValid($token, 'https://cdn.example/a.png', $sig));
        self::assertFalse($t->assetValid($token, 'https://cdn.example/b.png', $sig));
        self::assertNotEmpty($payload);
    }
}
