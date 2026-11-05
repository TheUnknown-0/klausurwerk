<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\FirstSetup;
use App\Services\Settings;
use Tests\Support\DatabaseTestCase;

/** Erst-Einrichtung, wie sie /setup und bin/setup.php nutzen. */
final class FirstSetupTest extends DatabaseTestCase
{
    private function input(array $overrides = []): array
    {
        return array_merge([
            'username' => 'admin',
            'password' => 'geheim123',
            'password_confirm' => 'geheim123',
            'school_name' => '  Testschule  ',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'email' => 'ada@example.org',
            'public_base_url' => 'https://klausur.example.org/',
        ], $overrides);
    }

    public function testLegtAdminSchulnameUndAdresseAn(): void
    {
        $this->db->run('DELETE FROM users');
        $settings = new Settings($this->db);
        $setup = new FirstSetup($this->db, $settings);
        self::assertTrue($setup->isNeeded());

        [$data, $error] = FirstSetup::validate($this->input());
        self::assertNull($error);
        $id = $setup->run($data);

        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
        self::assertSame('admin', $user['role']);
        self::assertSame('Ada', $user['firstname']);
        self::assertTrue(password_verify('geheim123', $user['password']));
        self::assertSame('Testschule', $settings->get('school_name'));
        self::assertSame('https://klausur.example.org', $settings->get('public_base_url'));
        self::assertFalse($setup->isNeeded());
    }

    public function testZweiteEinrichtungWirdAbgelehnt(): void
    {
        $this->db->run('DELETE FROM users');
        $setup = new FirstSetup($this->db, new Settings($this->db));
        [$data] = FirstSetup::validate($this->input());
        $setup->run($data);

        [$second] = FirstSetup::validate($this->input(['username' => 'eindringling']));
        $this->expectException(\RuntimeException::class);
        $setup->run($second);
    }

    public function testOhneAdresseBleibtEinstellungLeer(): void
    {
        $this->db->run('DELETE FROM users');
        $settings = new Settings($this->db);
        $settings->delete('public_base_url');
        [$data] = FirstSetup::validate($this->input(['public_base_url' => '', 'email' => '', 'firstname' => '']));
        (new FirstSetup($this->db, $settings))->run($data);

        self::assertNull($settings->get('public_base_url'));
        $user = $this->db->fetchOne("SELECT * FROM users WHERE username = 'admin'");
        self::assertNull($user['email']);
        self::assertSame('Admin', $user['firstname']);
    }

    /** @return iterable<string, array{0: array<string, string>}> */
    public static function ungueltigeEingaben(): iterable
    {
        yield 'Benutzername zu kurz' => [['username' => 'ab']];
        yield 'Passwort zu kurz' => [['password' => 'kurz', 'password_confirm' => 'kurz']];
        yield 'Passwörter verschieden' => [['password_confirm' => 'anders123']];
        yield 'Schulname fehlt' => [['school_name' => ' ']];
        yield 'E-Mail ungültig' => [['email' => 'keine-mail']];
        yield 'Adresse ohne Schema' => [['public_base_url' => 'klausur.example.org']];
        yield 'Adresse mit falschem Schema' => [['public_base_url' => 'ftp://klausur.example.org']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigeEingaben')]
    public function testUngueltigeEingabenWerdenAbgelehnt(array $overrides): void
    {
        [$data, $error] = FirstSetup::validate($this->input($overrides));
        self::assertNull($data);
        self::assertIsString($error);
    }
}
