<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SebEncryption;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SebEncryptionTest extends TestCase
{
    private const XML = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<plist version=\"1.0\"><dict><key>startURL</key><string>https://x.de/klausur/1</string><key>Prüfung</key><string>äöü</string></dict></plist>\n";

    public function testRundreiseMitUmlautPasswort(): void
    {
        $file = SebEncryption::encrypt(self::XML, 'Geheim-ÄÖÜ-2026');
        self::assertSame(self::XML, SebEncryption::decrypt($file, 'Geheim-ÄÖÜ-2026'));
    }

    public function testAufbauEntsprichtDemSebFormat(): void
    {
        $file = SebEncryption::encrypt(self::XML, 'pw');
        self::assertSame("\x1f\x8b", substr($file, 0, 2), 'Äußere Hülle ist gzip');
        $outer = gzdecode($file);
        self::assertIsString($outer);
        self::assertSame('pswd', substr($outer, 0, 4));
        self::assertSame("\x02\x01", substr($outer, 4, 2), 'RNCryptor-Version 2, Optionen 1');
        // Kopf 2 + Salze 16 + IV 16 + HMAC 32, Chiffrat in ganzen AES-Blöcken
        $cipherLength = strlen($outer) - 4 - 2 - 16 - 16 - 32;
        self::assertGreaterThan(0, $cipherLength);
        self::assertSame(0, $cipherLength % 16);
        self::assertStringNotContainsString('startURL', $outer);
    }

    public function testZufallsSalzeMachenJedeDateiAnders(): void
    {
        self::assertNotSame(SebEncryption::encrypt(self::XML, 'pw'), SebEncryption::encrypt(self::XML, 'pw'));
    }

    public function testFalschesPasswortWirdErkannt(): void
    {
        $file = SebEncryption::encrypt(self::XML, 'richtig');
        $this->expectException(RuntimeException::class);
        SebEncryption::decrypt($file, 'falsch');
    }

    public function testVeraenderteDateiWirdErkannt(): void
    {
        $outer = (string) gzdecode(SebEncryption::encrypt(self::XML, 'pw'));
        $outer[40] = chr(ord($outer[40]) ^ 1);
        $this->expectException(RuntimeException::class);
        SebEncryption::decrypt((string) gzencode($outer), 'pw');
    }

    public function testOhnePasswortKeineVerschluesselung(): void
    {
        $this->expectException(RuntimeException::class);
        SebEncryption::encrypt(self::XML, '');
    }
}
