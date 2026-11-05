<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

/** Client-IP und Schema der Anfrage: Proxy-Header zählen nur von vertrauenswürdigen Proxys. */
final class RequestTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('TRUSTED_PROXIES');
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTPS'], $_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    public function testOhneProxyZaehltNurDieDirekteVerbindung(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        self::assertSame('10.0.0.5', Request::clientIp(), 'Ein Schulrechner darf keine Absenderadresse behaupten');
        self::assertFalse(Request::isHttps());
        self::assertSame('http', Request::scheme());
    }

    public function testLoopbackIstStandardmaessigVertrauenswuerdig(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'HTTPS';

        self::assertSame('203.0.113.9', Request::clientIp());
        self::assertTrue(Request::isHttps());
    }

    public function testKonfigurierteProxysAlsAdresseOderNetz(): void
    {
        putenv('TRUSTED_PROXIES=172.18.0.0/16, 2001:db8::1');

        $_SERVER['REMOTE_ADDR'] = '172.18.0.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        self::assertSame('198.51.100.2', Request::clientIp());
        self::assertTrue(Request::isHttps());

        $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
        self::assertTrue(Request::isTrustedProxy());

        // Mit eigener Liste ist Loopback nicht mehr automatisch dabei.
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        self::assertFalse(Request::isTrustedProxy());
        self::assertSame('127.0.0.1', Request::clientIp());
        self::assertFalse(Request::isHttps());
    }

    public function testUngueltigeWeitergeleiteteAdresseFaelltAufRemoteZurueck(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'nicht-eine-ip';
        self::assertSame('127.0.0.1', Request::clientIp());
    }

    public function testDirektesHttpsUndHerkunft(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'klausur.schule.example:8443';
        self::assertTrue(Request::isHttps());
        self::assertSame('https://klausur.schule.example:8443', Request::origin());

        $_SERVER['HTTPS'] = 'off';
        unset($_SERVER['HTTP_HOST']);
        self::assertSame('http://localhost', Request::origin());
    }

    public function testCidrPruefung(): void
    {
        self::assertTrue(Request::ipInCidr('192.168.1.77', '192.168.0.0/16'));
        self::assertFalse(Request::ipInCidr('192.169.1.77', '192.168.0.0/16'));
        self::assertTrue(Request::ipInCidr('10.0.0.1', '10.0.0.1'));
        self::assertTrue(Request::ipInCidr('10.0.0.130', '10.0.0.128/25'));
        self::assertFalse(Request::ipInCidr('10.0.0.127', '10.0.0.128/25'));
        self::assertFalse(Request::ipInCidr('::1', '127.0.0.0/8'), 'IPv4 und IPv6 mischen sich nicht');
        self::assertFalse(Request::ipInCidr('kaputt', '10.0.0.0/8'));
    }
}
