<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Passwortgeschützte .seb-Datei (Präfix "pswd").
 *
 * Aufbau nach https://safeexambrowser.org/developer/seb-file-format.html und der Umsetzung in
 * SEB für Windows (SafeExamBrowser.Configuration/Cryptography/PasswordEncryption.cs):
 *
 *     Datei = gzip( "pswd" . Daten )
 *     Daten = 0x02 | 0x01 | Salz Verschlüsselung (8) | Salz HMAC (8) | IV (16) | Chiffrat | HMAC (32)
 *     Chiffrat = AES-256-CBC (PKCS7) über gzip(XML), Schlüssel = PBKDF2-HMAC-SHA1(Passwort, Salz, 10000, 32)
 *     HMAC = HMAC-SHA256 über alles davor (Kopf, Salze, IV, Chiffrat), Schlüssel = PBKDF2(Passwort, Salz HMAC)
 *
 * Das ist das Datenformat von RNCryptor in Version 2. Der Config Key ändert sich durch die
 * Verschlüsselung nicht, SEB berechnet ihn aus den entschlüsselten Einstellungen.
 */
final class SebEncryption
{
    private const PREFIX = 'pswd';
    private const VERSION = "\x02";
    private const OPTIONS = "\x01";
    private const ITERATIONS = 10000;

    public static function encrypt(string $xml, string $password): string
    {
        if ($password === '') {
            throw new RuntimeException('Ohne Passwort wird nicht verschlüsselt.');
        }
        $encSalt = random_bytes(8);
        $macSalt = random_bytes(8);
        $iv = random_bytes(16);
        $encKey = hash_pbkdf2('sha1', $password, $encSalt, self::ITERATIONS, 32, true);
        $macKey = hash_pbkdf2('sha1', $password, $macSalt, self::ITERATIONS, 32, true);
        $inner = gzencode($xml, 9);
        $cipher = openssl_encrypt((string) $inner, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
        if ($inner === false || $cipher === false) {
            throw new RuntimeException('Die SEB-Datei konnte nicht verschlüsselt werden.');
        }
        $message = self::VERSION . self::OPTIONS . $encSalt . $macSalt . $iv . $cipher;
        $message .= hash_hmac('sha256', $message, $macKey, true);

        return (string) gzencode(self::PREFIX . $message, 9);
    }

    /** Gegenstück für Tests und Diagnose: liefert die XML-plist oder wirft bei falschem Passwort. */
    public static function decrypt(string $file, string $password): string
    {
        $outer = @gzdecode($file);
        if ($outer === false || !str_starts_with($outer, self::PREFIX)) {
            throw new RuntimeException('Keine passwortgeschützte SEB-Datei.');
        }
        $message = substr($outer, strlen(self::PREFIX));
        if (strlen($message) < 2 + 8 + 8 + 16 + 16 + 32) {
            throw new RuntimeException('Die SEB-Datei ist beschädigt.');
        }
        if ($message[0] !== self::VERSION || $message[1] !== self::OPTIONS) {
            throw new RuntimeException('Unbekanntes Verschlüsselungsformat (erwartet RNCryptor-Version 2).');
        }
        $body = substr($message, 0, -32);
        $mac = substr($message, -32);
        $macKey = hash_pbkdf2('sha1', $password, substr($message, 10, 8), self::ITERATIONS, 32, true);
        if (!hash_equals(hash_hmac('sha256', $body, $macKey, true), $mac)) {
            throw new RuntimeException('Falsches Passwort oder beschädigte Datei.');
        }
        $encKey = hash_pbkdf2('sha1', $password, substr($message, 2, 8), self::ITERATIONS, 32, true);
        $inner = openssl_decrypt(substr($body, 34), 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, substr($message, 18, 16));
        $xml = $inner === false ? false : @gzdecode($inner);
        if ($xml === false) {
            throw new RuntimeException('Die SEB-Datei ist beschädigt.');
        }

        return $xml;
    }
}
