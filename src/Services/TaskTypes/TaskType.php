<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

/**
 * Ein Antworttyp (tasks.answer_type). Jede Klasse src/Services/TaskTypes/*Type.php,
 * die dieses Interface implementiert, wird automatisch registriert
 * (siehe TaskTypeRegistry). Formate je Typ: docs/DATENFORMATE.md.
 *
 * Fehlerhafte Eingaben werfen \InvalidArgumentException mit einer deutschen,
 * für Menschen lesbaren Meldung.
 */
interface TaskType
{
    /** Schlüssel in tasks.answer_type, z. B. 'choice'. */
    public function name(): string;

    /** Anzeigename, z. B. 'Multiple Choice'. */
    public function label(): string;

    /** Gruppe im Typ-Menü: 'Programmieren', 'Text & Auswahl', 'Diagramme', 'Mikrowelt'. */
    public function group(): string;

    /** @return array<string, mixed> */
    public function defaultConfig(): array;

    /** @return array<string, mixed> Inhalt für task_solutions.solution */
    public function defaultSolution(): array;

    /**
     * Konfiguration der Lehrkraft prüfen und normalisieren.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function normalizeConfig(array $config): array;

    /**
     * Lösung prüfen und normalisieren (gegen die bereits normalisierte Konfiguration).
     *
     * @param array<string, mixed> $solution
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function normalizeSolution(array $solution, array $config): array;

    /**
     * Was die Schüleransicht bekommt. Darf mischen (deterministisch aus $seed),
     * enthält NIE Lösungsdaten (die liegen ohnehin nicht in config).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function studentConfig(array $config, int $seed): array;

    /**
     * Antwort von Schüler:innen prüfen und normalisieren (Autosave).
     * Unbekannte Felder verwerfen, Größen begrenzen.
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function normalizeContent(array $content, array $config): array;

    /** @param array<string, mixed> $content */
    public function isAnswered(array $content): bool;

    /**
     * Automatische Vorbewertung. null = nur manuell bewertbar
     * (oder asynchron über den Runner, wie bei Code-Tests).
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $solution
     * @param array<string, mixed> $content
     * @return array{ratio: float, details: array<string, mixed>}|null ratio 0..1
     */
    public function autoCheck(array $config, array $solution, array $content): ?array;

    /**
     * Textdarstellung der Antwort (PDF, Suche, Export).
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $config
     */
    public function toText(array $content, array $config): string;
}
