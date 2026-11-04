<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Katalog aller granularen Berechtigungen.
 *
 * Konvention: Jede Berechtigung wird ausschließlich über die Konstante
 * referenziert (nie als String-Literal), damit Umbenennungen sicher sind.
 * Rollen: admin hat implizit alle Rechte; teacher trägt granulare Rechte;
 * student hat nur rollen-spezifische Seiten.
 *
 * Rechte an einer einzelnen Klausur (Pult, Lösungen, Korrektur) laufen NICHT
 * hierüber, sondern über Services\ExamAccess (Hauptlehrkraft / exam_staff).
 */
final class Permissions
{
    /** Rollen, die granulare Berechtigungen tragen können. */
    public const GRANULAR_ROLES = ['teacher'];

    // Klausuren
    public const KLAUSUREN_ERSTELLEN = 'klausuren_erstellen';
    public const ARCHIV_DURCHSUCHEN = 'archiv_durchsuchen';
    public const KLAUSUREN_IMPORTIEREN = 'klausuren_importieren';
    /** Alle Klausuren sehen und betreuen, auch fremde (z. B. Fachbereichsleitung). */
    public const ALLE_KLAUSUREN = 'alle_klausuren';

    // Kurse
    public const KURSE_SEHEN = 'kurse_sehen';
    public const KURSE_BEARBEITEN = 'kurse_bearbeiten';
    /** Auch Kurse anderer Lehrkräfte bearbeiten. */
    public const ALLE_KURSE = 'alle_kurse';

    // Benutzer
    public const BENUTZER_SEHEN = 'benutzer_sehen';
    public const BENUTZER_ERSTELLEN = 'benutzer_erstellen';
    public const BENUTZER_BEARBEITEN = 'benutzer_bearbeiten';
    public const BENUTZER_LOESCHEN = 'benutzer_loeschen';
    public const BENUTZER_IMPORTIEREN = 'benutzer_importieren';
    public const BENUTZER_PASSWORT_ZURUECKSETZEN = 'benutzer_passwort_zuruecksetzen';
    public const ZUGANGSKARTEN_DRUCKEN = 'zugangskarten_drucken';
    public const NACHTEILSAUSGLEICH_BEARBEITEN = 'nachteilsausgleich_bearbeiten';

    // Räume
    public const RAEUME_VERWALTEN = 'raeume_verwalten';

    // Stammdaten
    public const OPERATOREN_BEARBEITEN = 'operatoren_bearbeiten';
    public const NOTENSCHLUESSEL_BEARBEITEN = 'notenschluessel_bearbeiten';

    // Berechtigungen
    public const BERECHTIGUNGEN_SEHEN = 'berechtigungen_sehen';
    public const BERECHTIGUNGEN_VERGEBEN = 'berechtigungen_vergeben';
    public const BERECHTIGUNGSGRUPPEN_VERWALTEN = 'berechtigungsgruppen_verwalten';

    // Einstellungen & Sonstiges
    public const EINSTELLUNGEN_SEHEN = 'einstellungen_sehen';
    public const EINSTELLUNGEN_BEARBEITEN = 'einstellungen_bearbeiten';
    public const AUFBEWAHRUNG_VERWALTEN = 'aufbewahrung_verwalten';
    public const AUDIT_LOGS_SEHEN = 'audit_logs_sehen';

    /**
     * Katalog: Gruppe => (Key => Label).
     *
     * @return array<string, array<string, string>>
     */
    public static function catalog(): array
    {
        return [
            'Klausuren' => [
                self::KLAUSUREN_ERSTELLEN => 'Klausuren erstellen',
                self::ARCHIV_DURCHSUCHEN => 'Archiv früherer Klausuren durchsuchen',
                self::KLAUSUREN_IMPORTIEREN => 'Klausuren importieren/exportieren',
                self::ALLE_KLAUSUREN => 'Alle Klausuren betreuen (auch fremde)',
            ],
            'Kurse' => [
                self::KURSE_SEHEN => 'Kurse sehen',
                self::KURSE_BEARBEITEN => 'Eigene Kurse anlegen und bearbeiten',
                self::ALLE_KURSE => 'Alle Kurse bearbeiten',
            ],
            'Benutzer' => [
                self::BENUTZER_SEHEN => 'Benutzer sehen',
                self::BENUTZER_ERSTELLEN => 'Schüler:innen anlegen',
                self::BENUTZER_BEARBEITEN => 'Schüler:innen bearbeiten',
                self::BENUTZER_LOESCHEN => 'Schüler:innen löschen',
                self::BENUTZER_IMPORTIEREN => 'Schüler:innen per CSV importieren',
                self::BENUTZER_PASSWORT_ZURUECKSETZEN => 'Passwörter zurücksetzen',
                self::ZUGANGSKARTEN_DRUCKEN => 'Zugangskarten drucken',
                self::NACHTEILSAUSGLEICH_BEARBEITEN => 'Nachteilsausgleich festlegen',
            ],
            'Räume & Stammdaten' => [
                self::RAEUME_VERWALTEN => 'Räume verwalten',
                self::OPERATOREN_BEARBEITEN => 'Operatorenliste bearbeiten',
                self::NOTENSCHLUESSEL_BEARBEITEN => 'Notenschlüssel bearbeiten',
            ],
            'Berechtigungen' => [
                self::BERECHTIGUNGEN_SEHEN => 'Berechtigungen sehen',
                self::BERECHTIGUNGEN_VERGEBEN => 'Berechtigungen vergeben',
                self::BERECHTIGUNGSGRUPPEN_VERWALTEN => 'Berechtigungsgruppen verwalten',
            ],
            'System' => [
                self::EINSTELLUNGEN_SEHEN => 'Einstellungen sehen',
                self::EINSTELLUNGEN_BEARBEITEN => 'Einstellungen bearbeiten',
                self::AUFBEWAHRUNG_VERWALTEN => 'Aufbewahrungsfristen und Löschung',
                self::AUDIT_LOGS_SEHEN => 'Audit-Log sehen',
            ],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        $keys = [];
        foreach (self::catalog() as $items) {
            foreach (array_keys($items) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    public static function allowsGranular(string $role): bool
    {
        return in_array($role, self::GRANULAR_ROLES, true);
    }

    /**
     * Abhängigkeiten: Key => Liste der Rechte, die zwingend mit vorliegen müssen.
     *
     * @return array<string, list<string>>
     */
    public static function dependencies(): array
    {
        return [
            self::KLAUSUREN_IMPORTIEREN => [self::KLAUSUREN_ERSTELLEN],
            self::ARCHIV_DURCHSUCHEN => [self::KLAUSUREN_ERSTELLEN],

            self::KURSE_BEARBEITEN => [self::KURSE_SEHEN],
            self::ALLE_KURSE => [self::KURSE_BEARBEITEN],

            self::BENUTZER_ERSTELLEN => [self::BENUTZER_SEHEN],
            self::BENUTZER_BEARBEITEN => [self::BENUTZER_SEHEN],
            self::BENUTZER_LOESCHEN => [self::BENUTZER_SEHEN],
            self::BENUTZER_IMPORTIEREN => [self::BENUTZER_ERSTELLEN],
            self::BENUTZER_PASSWORT_ZURUECKSETZEN => [self::BENUTZER_SEHEN],
            self::ZUGANGSKARTEN_DRUCKEN => [self::BENUTZER_PASSWORT_ZURUECKSETZEN],
            self::NACHTEILSAUSGLEICH_BEARBEITEN => [self::BENUTZER_SEHEN],

            self::BERECHTIGUNGEN_SEHEN => [self::BENUTZER_SEHEN],
            self::BERECHTIGUNGEN_VERGEBEN => [self::BERECHTIGUNGEN_SEHEN],
            self::BERECHTIGUNGSGRUPPEN_VERWALTEN => [self::BERECHTIGUNGEN_SEHEN],

            self::EINSTELLUNGEN_BEARBEITEN => [self::EINSTELLUNGEN_SEHEN],
            self::AUFBEWAHRUNG_VERWALTEN => [self::EINSTELLUNGEN_SEHEN],
        ];
    }

    /**
     * Alle Voraussetzungen einer Berechtigung, rekursiv aufgelöst
     * (ohne die Berechtigung selbst).
     *
     * @return list<string>
     */
    public static function requiredFor(string $permission): array
    {
        $deps = self::dependencies();
        $result = [];
        $stack = $deps[$permission] ?? [];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (in_array($current, $result, true)) {
                continue;
            }
            $result[] = $current;
            foreach ($deps[$current] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return $result;
    }

    /**
     * Alle Berechtigungen, die (direkt oder indirekt) von der gegebenen
     * abhängen; diese müssen beim Entziehen mit entzogen werden.
     *
     * @return list<string>
     */
    public static function dependentsOf(string $permission): array
    {
        $reverse = [];
        foreach (self::dependencies() as $key => $requires) {
            foreach ($requires as $req) {
                $reverse[$req][] = $key;
            }
        }

        $result = [];
        $stack = $reverse[$permission] ?? [];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (in_array($current, $result, true)) {
                continue;
            }
            $result[] = $current;
            foreach ($reverse[$current] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return $result;
    }

    /**
     * Standard-Berechtigungen je Rolle (admin hat implizit alle Rechte).
     * Lehrkräfte können ohne Zusatzrechte ihren Unterricht vollständig führen.
     *
     * @return list<string>
     */
    public static function defaultsForRole(string $role): array
    {
        return match ($role) {
            'teacher' => [
                self::KLAUSUREN_ERSTELLEN,
                self::ARCHIV_DURCHSUCHEN,
                self::KLAUSUREN_IMPORTIEREN,
                self::KURSE_SEHEN,
                self::KURSE_BEARBEITEN,
                self::BENUTZER_SEHEN,
                self::BENUTZER_ERSTELLEN,
                self::BENUTZER_BEARBEITEN,
                self::BENUTZER_IMPORTIEREN,
                self::BENUTZER_PASSWORT_ZURUECKSETZEN,
                self::ZUGANGSKARTEN_DRUCKEN,
                self::NACHTEILSAUSGLEICH_BEARBEITEN,
                self::RAEUME_VERWALTEN,
            ],
            default => [],
        };
    }
}
