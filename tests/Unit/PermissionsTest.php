<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Permissions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Statische Prüfung des Rechtekatalogs, ohne Datenbank.
 *
 * Stellt sicher, dass Permissions::catalog(), ::dependencies() und
 * ::defaultsForRole() untereinander und mit den Konstanten konsistent
 * bleiben, auch wenn neue Berechtigungen dazukommen.
 */
final class PermissionsTest extends TestCase
{
    /** @return array<string, string> Konstantenname => Wert, nur die granularen Berechtigungen (GRANULAR_ROLES ausgenommen). */
    private function permissionConstants(): array
    {
        $constants = (new ReflectionClass(Permissions::class))->getConstants();
        unset($constants['GRANULAR_ROLES']);

        return $constants;
    }

    public function testAlleKonstantenSindEindeutigeNichtLeereStrings(): void
    {
        $constants = $this->permissionConstants();
        self::assertNotEmpty($constants, 'Es sollten Berechtigungs-Konstanten deklariert sein.');

        $values = array_values($constants);
        foreach ($constants as $name => $value) {
            self::assertIsString($value, "Konstante {$name} ist kein String.");
            self::assertNotSame('', $value, "Konstante {$name} ist ein leerer String.");
        }
        self::assertSame(
            count($values),
            count(array_unique($values)),
            'Berechtigungs-Konstanten müssen eindeutige Werte haben.',
        );
    }

    public function testJedeKonstanteHatEinenKatalogEintrag(): void
    {
        $constants = $this->permissionConstants();
        $catalogKeys = Permissions::all();

        foreach ($constants as $name => $value) {
            self::assertContains(
                $value,
                $catalogKeys,
                "Berechtigung {$name} ({$value}) fehlt in Permissions::catalog().",
            );
        }
    }

    public function testKatalogEnthaeltNurDeklarierteKonstanten(): void
    {
        $constantValues = array_values($this->permissionConstants());

        foreach (Permissions::all() as $key) {
            self::assertContains(
                $key,
                $constantValues,
                "Katalog-Key '{$key}' hat keine passende Permissions::-Konstante.",
            );
        }
    }

    public function testKatalogHatKeineDoppeltenKeysUndNichtLeereLabels(): void
    {
        $seen = [];
        foreach (Permissions::catalog() as $group => $entries) {
            self::assertNotSame('', trim($group), 'Gruppenname darf nicht leer sein.');
            self::assertNotEmpty($entries, "Gruppe '{$group}' hat keine Einträge.");

            foreach ($entries as $key => $label) {
                self::assertArrayNotHasKey($key, $seen, "Berechtigung '{$key}' kommt im Katalog mehrfach vor.");
                $seen[$key] = true;

                self::assertIsString($label);
                self::assertNotSame('', trim($label), "Label für '{$key}' ist leer.");
            }
        }
    }

    public function testExistsStimmtMitAllUeberein(): void
    {
        foreach (Permissions::all() as $key) {
            self::assertTrue(Permissions::exists($key));
        }
        self::assertFalse(Permissions::exists('keine_echte_berechtigung'));
        self::assertFalse(Permissions::exists(''));
    }

    public function testAbhaengigkeitenReferenzierenNurBekanntePermissions(): void
    {
        $all = Permissions::all();
        foreach (Permissions::dependencies() as $permission => $requires) {
            self::assertContains(
                $permission,
                $all,
                "dependencies()-Key '{$permission}' ist keine bekannte Berechtigung.",
            );
            foreach ($requires as $required) {
                self::assertContains(
                    $required,
                    $all,
                    "'{$permission}' benötigt unbekannte Berechtigung '{$required}'.",
                );
                self::assertNotSame(
                    $permission,
                    $required,
                    "'{$permission}' darf sich nicht selbst voraussetzen.",
                );
            }
        }
    }

    public function testAbhaengigkeitenSindZyklenfrei(): void
    {
        $deps = Permissions::dependencies();

        foreach (array_keys($deps) as $start) {
            $this->assertNoCycleFrom($start, $deps);
        }
    }

    /**
     * Iterative Tiefensuche mit explizitem Rekursionsstapel, erkennt Zyklen
     * unabhängig davon, wie Permissions::requiredFor() selbst mit ihnen umgeht.
     *
     * @param array<string, list<string>> $deps
     */
    private function assertNoCycleFrom(string $start, array $deps): void
    {
        $stack = [[$start, [$start]]];
        while ($stack !== []) {
            [$current, $path] = array_pop($stack);
            foreach ($deps[$current] ?? [] as $next) {
                self::assertNotContains(
                    $next,
                    $path,
                    'Zyklus in Permissions::dependencies(): ' . implode(' -> ', [...$path, $next]),
                );
                $stack[] = [$next, [...$path, $next]];
            }
        }
    }

    public function testRequiredForListetAlleTransitivenVoraussetzungenAuf(): void
    {
        // BERECHTIGUNGSGRUPPEN_VERWALTEN -> BERECHTIGUNGEN_SEHEN -> BENUTZER_SEHEN
        $required = Permissions::requiredFor(Permissions::BERECHTIGUNGSGRUPPEN_VERWALTEN);

        self::assertContains(Permissions::BERECHTIGUNGEN_SEHEN, $required);
        self::assertContains(Permissions::BENUTZER_SEHEN, $required);
    }

    public function testRequiredForUndDependentsOfSindZueinanderInvers(): void
    {
        foreach (Permissions::dependencies() as $permission => $directRequires) {
            foreach ($directRequires as $required) {
                self::assertContains(
                    $permission,
                    Permissions::dependentsOf($required),
                    "'{$permission}' setzt '{$required}' voraus, taucht aber nicht in dependentsOf('{$required}') auf.",
                );
            }
        }
    }

    public function testPermissionOhneAbhaengigkeitenHatLeereRequiredForUndKannDependentsHaben(): void
    {
        self::assertSame([], Permissions::requiredFor('nicht_vorhanden'));
        self::assertSame([], Permissions::dependentsOf('nicht_vorhanden'));
    }

    public function testRollenDefaultsEnthaltenNurBekanntePermissions(): void
    {
        $all = Permissions::all();

        foreach ([...Permissions::GRANULAR_ROLES, 'admin', 'student', 'unbekannte_rolle'] as $role) {
            foreach (Permissions::defaultsForRole($role) as $permission) {
                self::assertContains(
                    $permission,
                    $all,
                    "defaultsForRole('{$role}') enthält unbekannte Berechtigung '{$permission}'.",
                );
            }
        }
    }

    public function testNurGranulareRollenDuerfenGranulareRechteTragen(): void
    {
        foreach (Permissions::GRANULAR_ROLES as $role) {
            self::assertTrue(Permissions::allowsGranular($role));
        }
        foreach (['admin', 'student', 'orga', ''] as $role) {
            self::assertFalse(Permissions::allowsGranular($role));
        }
    }

    public function testNurLehrkraefteTragenGranulareRechte(): void
    {
        // Laut Architektur: nur Lehrkräfte; admin hat implizit alles, student nie etwas.
        self::assertSame(['teacher'], Permissions::GRANULAR_ROLES);
    }
}
