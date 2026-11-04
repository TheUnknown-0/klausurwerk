<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Uhr einer Klausur: reine Berechnung ohne Datenbankzugriff.
 *
 * Formel (ARCHITECTURE.md):
 *   Ende = Start + Dauer x (1 + Nachteilsausgleich/100) + Zusatzminuten
 *          + Gesamtpausen (exams.paused_seconds + laufende Pause)
 *          + Einzelpausen (participations.paused_seconds + laufende Pause)
 *
 * Alle Zeiten sind Serverzeit in Millisekunden seit Epoche. Datenbankwerte
 * (DATETIME(3), Zeitzone der PHP-Anwendung) werden über toMs()/toDb() gewandelt.
 */
final class ExamClock
{
    /** Zeitstempel der Datenbank (Y-m-d H:i:s[.v]) in Millisekunden; null bei leer/ungültig. */
    public static function toMs(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        try {
            $date = new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return null;
        }

        return (int) $date->format('U') * 1000 + (int) $date->format('v');
    }

    /** Millisekunden seit Epoche als DATETIME(3)-Wert in der Zeitzone der Anwendung. */
    public static function toDb(int $ms): string
    {
        $seconds = intdiv($ms, 1000);
        $millis = $ms - $seconds * 1000;
        if ($millis < 0) {
            $seconds -= 1;
            $millis += 1000;
        }
        $date = (new \DateTimeImmutable('@' . $seconds))->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return $date->format('Y-m-d H:i:s') . '.' . str_pad((string) $millis, 3, '0', STR_PAD_LEFT);
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * Uhr für eine Person.
     *
     * @param array<string, mixed> $exam          Zeile aus exams (started_at, paused_at, paused_seconds, duration_minutes)
     * @param array<string, mixed> $participation Zeile aus participations (extra_minutes, accommodation_percent, paused_at, paused_seconds)
     * @return array{
     *   started: bool, ends_at_ms: ?int, remaining_ms: ?int, paused: bool, time_up: bool, unlimited: bool,
     *   total_ms: ?int, extra_minutes: int, accommodation_percent: int
     * }
     */
    public static function forParticipation(array $exam, array $participation, int $nowMs): array
    {
        $extra = (int) ($participation['extra_minutes'] ?? 0);
        $accommodation = max(0, (int) ($participation['accommodation_percent'] ?? 0));
        $examPausedAt = self::toMs($exam['paused_at'] ?? null);
        $personPausedAt = self::toMs($participation['paused_at'] ?? null);
        $paused = $examPausedAt !== null || $personPausedAt !== null;

        $result = [
            'started' => false,
            'ends_at_ms' => null,
            'remaining_ms' => null,
            'paused' => $paused,
            'time_up' => false,
            'unlimited' => false,
            'total_ms' => null,
            'extra_minutes' => $extra,
            'accommodation_percent' => $accommodation,
        ];

        $startMs = self::toMs($exam['started_at'] ?? null);
        if ($startMs === null) {
            return $result;
        }
        $result['started'] = true;

        $duration = $exam['duration_minutes'] ?? null;
        if ($duration === null || $duration === '') {
            $result['unlimited'] = true;

            return $result;
        }

        $total = self::totalMs((int) $duration, $accommodation, $extra);
        $pauses = self::pauseMs($exam, $examPausedAt, $nowMs) + self::pauseMs($participation, $personPausedAt, $nowMs);
        $ends = $startMs + $total + $pauses;

        $result['total_ms'] = $total;
        $result['ends_at_ms'] = $ends;
        $result['remaining_ms'] = max(0, $ends - $nowMs);
        $result['time_up'] = $nowMs >= $ends;

        return $result;
    }

    /**
     * Gesamtdauer einer Person in Millisekunden, nie unter 0.
     * Zusatzminuten dürfen negativ sein (Zeit zurücknehmen).
     */
    public static function totalMs(int $durationMinutes, int $accommodationPercent, int $extraMinutes): int
    {
        $base = (int) round($durationMinutes * 60000 * (100 + max(0, $accommodationPercent)) / 100);

        return max(0, $base + $extraMinutes * 60000);
    }

    /**
     * Ende der Grundzeit ohne Nachteilsausgleich und Zusatzzeit (für das Klausur-Signal).
     *
     * @param array<string, mixed> $exam
     */
    public static function baseEndsAtMs(array $exam, int $nowMs): ?int
    {
        $startMs = self::toMs($exam['started_at'] ?? null);
        $duration = $exam['duration_minutes'] ?? null;
        if ($startMs === null || $duration === null || $duration === '') {
            return null;
        }

        return $startMs + (int) $duration * 60000 + self::pauseMs($exam, self::toMs($exam['paused_at'] ?? null), $nowMs);
    }

    /** Abgeschlossene plus laufende Pause einer Zeile in Millisekunden. */
    private static function pauseMs(array $row, ?int $pausedAtMs, int $nowMs): int
    {
        $closed = max(0, (int) ($row['paused_seconds'] ?? 0)) * 1000;
        $running = $pausedAtMs !== null ? max(0, $nowMs - $pausedAtMs) : 0;

        return $closed + $running;
    }
}
