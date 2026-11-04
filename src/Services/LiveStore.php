<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Flüchtiger Speicher der Live-Wand: Live-Zustand der Klausurseite und Bildschirm-Standbilder.
 *
 * Datenschutz: alles liegt nur im Arbeitsspeicher (tmpfs, Umgebung LIVE_DIR), nie in der
 * Datenbank, und je Teilnahme immer nur das jeweils letzte. Das Klausurverzeichnis wird beim
 * Ende der Klausur gelöscht (clearExam). Dateinamen entstehen ausschließlich aus int-IDs.
 *
 * Layout: <dir>/e<ExamId>/p<ParticipationId>.live.json | .screen.jpg | .screen.json | .rate-<bucket>
 *         <dir>/e<ExamId>/focus.json
 */
final class LiveStore
{
    /** Einträge älter als das gelten als veraltet. */
    public const STALE_MS = 120000;
    /** Dauer des Fokus, den das Pult wiederholt setzen muss. */
    public const FOCUS_MS = 30000;
    public const SCREEN_INTERVAL_MS = 5000;
    public const FOCUS_INTERVAL_MS = 1000;
    public const DEFAULT_DIR = '/run/klausurwerk-live';

    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $this->resolveDir($dir ?? ((string) (getenv('LIVE_DIR') ?: '')));
    }

    public function dir(): string
    {
        return $this->dir;
    }

    // ---------- Live-Zustand ----------

    /** @param array<string, mixed> $state */
    public function putState(int $examId, int $participationId, array $state, ?int $nowMs = null): void
    {
        $state['received_ms'] = $nowMs ?? ExamClock::nowMs();
        $this->writeAtomic($this->file($examId, "p{$participationId}.live.json"), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Letzter Zustand oder null (nie gesendet, veraltet).
     *
     * @return array<string, mixed>|null
     */
    public function getState(int $examId, int $participationId, ?int $nowMs = null): ?array
    {
        $state = $this->readJson($this->file($examId, "p{$participationId}.live.json", false));
        if ($state === null || !isset($state['received_ms'])) {
            return null;
        }
        $now = $nowMs ?? ExamClock::nowMs();

        return $now - (int) $state['received_ms'] > self::STALE_MS ? null : $state;
    }

    // ---------- Bildschirm ----------

    /** Speichert das letzte Standbild (Rohbytes eines JPEG). */
    public function putScreen(int $examId, int $participationId, string $jpeg, int $width, int $height, string $surface, ?int $nowMs = null): void
    {
        $this->writeAtomic($this->file($examId, "p{$participationId}.screen.jpg"), $jpeg);
        $this->writeAtomic($this->file($examId, "p{$participationId}.screen.json"), json_encode([
            'ts_ms' => $nowMs ?? ExamClock::nowMs(),
            'width' => $width,
            'height' => $height,
            'surface' => $surface,
        ]));
    }

    /**
     * Metadaten des letzten Standbilds oder null (keins, veraltet).
     *
     * @return array{ts_ms: int, width: int, height: int, surface: string}|null
     */
    public function getScreenMeta(int $examId, int $participationId, ?int $nowMs = null): ?array
    {
        $meta = $this->readJson($this->file($examId, "p{$participationId}.screen.json", false));
        if ($meta === null || !isset($meta['ts_ms'])) {
            return null;
        }
        if (($nowMs ?? ExamClock::nowMs()) - (int) $meta['ts_ms'] > self::STALE_MS) {
            return null;
        }

        return [
            'ts_ms' => (int) $meta['ts_ms'],
            'width' => (int) ($meta['width'] ?? 0),
            'height' => (int) ($meta['height'] ?? 0),
            'surface' => (string) ($meta['surface'] ?? 'unknown'),
        ];
    }

    /** Rohbytes des letzten Standbilds oder null (keins, veraltet). */
    public function getScreenImage(int $examId, int $participationId, ?int $nowMs = null): ?string
    {
        if ($this->getScreenMeta($examId, $participationId, $nowMs) === null) {
            return null;
        }
        $path = $this->file($examId, "p{$participationId}.screen.jpg", false);
        $raw = is_file($path) ? @file_get_contents($path) : false;

        return $raw === false || $raw === '' ? null : $raw;
    }

    // ---------- Fokus ----------

    /** Setzt den Fokus (30 s) auf eine Teilnahme; null hebt ihn auf. */
    public function setFocus(int $examId, ?int $participationId, ?int $nowMs = null): void
    {
        if ($participationId === null) {
            @unlink($this->file($examId, 'focus.json', false));

            return;
        }
        $this->writeAtomic($this->file($examId, 'focus.json'), json_encode([
            'participation_id' => $participationId,
            'until_ms' => ($nowMs ?? ExamClock::nowMs()) + self::FOCUS_MS,
        ]));
    }

    public function focusedParticipation(int $examId, ?int $nowMs = null): ?int
    {
        $focus = $this->readJson($this->file($examId, 'focus.json', false));
        if ($focus === null || !isset($focus['participation_id'], $focus['until_ms'])) {
            return null;
        }

        return (int) $focus['until_ms'] > ($nowMs ?? ExamClock::nowMs()) ? (int) $focus['participation_id'] : null;
    }

    public function isFocused(int $examId, int $participationId, ?int $nowMs = null): bool
    {
        return $this->focusedParticipation($examId, $nowMs) === $participationId;
    }

    /** Gewünschter Abstand der Bilder für diese Person in Millisekunden. */
    public function screenIntervalMs(int $examId, int $participationId, ?int $nowMs = null): int
    {
        return $this->isFocused($examId, $participationId, $nowMs) ? self::FOCUS_INTERVAL_MS : self::SCREEN_INTERVAL_MS;
    }

    // ---------- Ratenbegrenzung ----------

    /**
     * Erlaubt höchstens $max Anfragen je Sekunde und Teilnahme und Eimer ("live", "screen").
     * Zählt nur erlaubte Anfragen. Fällt die Sperre aus, wird die Anfrage durchgelassen.
     */
    public function allow(int $examId, int $participationId, string $bucket, int $max = 2, ?int $nowMs = null): bool
    {
        $bucket = preg_replace('/[^a-z]/', '', $bucket) ?: 'x';
        $path = $this->file($examId, "p{$participationId}.rate-{$bucket}");
        $now = $nowMs ?? ExamClock::nowMs();
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return true;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return true;
            }
            $times = array_filter(
                array_map('intval', explode(',', (string) stream_get_contents($fh))),
                static fn (int $t): bool => $t > $now - 1000 && $t <= $now,
            );
            if (count($times) >= $max) {
                return false;
            }
            $times[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, implode(',', $times));

            return true;
        } finally {
            fclose($fh);
        }
    }

    // ---------- Aufräumen ----------

    /** Löscht alles zur Klausur (Klausurende, Löschen, Archivieren). */
    public function clearExam(int $examId): void
    {
        $dir = $this->examDir($examId);
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                @unlink($dir . '/' . $name);
            }
        }
        @rmdir($dir);
    }

    // ---------- intern ----------

    private function examDir(int $examId): string
    {
        return $this->dir . '/e' . $examId;
    }

    /** Pfad einer Datei der Klausur; legt bei $create das Verzeichnis an. */
    private function file(int $examId, string $name, bool $create = true): string
    {
        $dir = $this->examDir($examId);
        if ($create && !is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir . '/' . $name;
    }

    private function writeAtomic(string $path, string|false $data): void
    {
        if ($data === false) {
            return;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $data) === false) {
            return;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** Konfiguriertes Verzeichnis, sonst Rückfall auf das Temp-Verzeichnis. */
    private function resolveDir(string $configured): string
    {
        $candidates = [$configured !== '' ? $configured : self::DEFAULT_DIR, sys_get_temp_dir() . '/klausurwerk-live'];
        foreach ($candidates as $dir) {
            $dir = rtrim($dir, '/\\');
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }

        return rtrim($candidates[1], '/\\');
    }
}
