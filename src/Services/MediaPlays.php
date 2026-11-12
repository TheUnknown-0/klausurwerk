<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Audio- und Video-Material: Abspielregel je Datei und Zähler je Teilnahme.
 *
 * - 'teacher': nur Lehrkräfte (Pult, Vorschau) bekommen die Datei; Schüler:innen sehen nur einen Hinweis.
 * - 'free': Schüler:innen spielen beliebig oft ab.
 * - 'limited': jeder Start fragt den Server (start()), der zählt und einen Einmal-Schlüssel ausgibt;
 *   die Datei gibt es nur mit diesem Schlüssel, einmal und innerhalb von TOKEN_TTL Sekunden
 *   (redeem()). Der Browser lädt sie dann als Ganzes und spielt sie aus dem Speicher ab.
 *   Das hält Neugierige ab; wer die Datei im Browser abfängt, könnte sie behalten (wie bei allem
 *   Material). Im Safe Exam Browser geht das nicht.
 */
final class MediaPlays
{
    public const POLICIES = ['teacher', 'free', 'limited'];
    public const POLICY_LABELS = [
        'teacher' => 'nur Lehrkraft (Pult)',
        'free' => 'Schüler:innen, beliebig oft',
        'limited' => 'Schüler:innen, begrenzt',
    ];
    public const MAX_LIMIT = 9;
    public const TOKEN_TTL = 600;

    public function __construct(private readonly Database $db)
    {
    }

    public static function isMedia(string $mime): bool
    {
        return str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/');
    }

    public static function cleanPolicy(mixed $policy): string
    {
        return is_string($policy) && in_array($policy, self::POLICIES, true) ? $policy : 'teacher';
    }

    public static function cleanLimit(mixed $limit): int
    {
        return max(1, min(self::MAX_LIMIT, (int) $limit));
    }

    /** @return array<int, int> material_id => Anzahl Starts */
    public function countsFor(int $participationId): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT material_id, plays FROM material_plays WHERE participation_id = ?', [$participationId]) as $row) {
            $out[(int) $row['material_id']] = (int) $row['plays'];
        }

        return $out;
    }

    /**
     * Ein Start durch eine Teilnahme. Bei 'limited' zählt er und liefert einen Einmal-Schlüssel.
     *
     * @param array<string, mixed> $material Zeile aus exam_materials
     * @return array{plays: int, limit: ?int, token: ?string}
     * @throws \DomainException wenn nicht (mehr) abspielbar
     */
    public function start(int $participationId, array $material): array
    {
        $policy = self::cleanPolicy($material['play_policy'] ?? 'teacher');
        if ($policy === 'teacher') {
            throw new \DomainException('Diese Aufnahme spielt die Lehrkraft für alle ab.');
        }
        $materialId = (int) $material['id'];
        if ($policy === 'free') {
            $this->db->run(
                'INSERT INTO material_plays (participation_id, material_id, plays, last_played_at) VALUES (?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE plays = LEAST(plays + 1, 65000), last_played_at = NOW()',
                [$participationId, $materialId],
            );

            return ['plays' => $this->countsFor($participationId)[$materialId] ?? 1, 'limit' => null, 'token' => null];
        }

        $limit = self::cleanLimit($material['play_limit'] ?? 2);
        $token = bin2hex(random_bytes(24));

        return $this->db->transaction(function () use ($participationId, $materialId, $limit, $token): array {
            $this->db->run(
                'INSERT IGNORE INTO material_plays (participation_id, material_id, plays) VALUES (?, ?, 0)',
                [$participationId, $materialId],
            );
            $plays = (int) $this->db->fetchValue(
                'SELECT plays FROM material_plays WHERE participation_id = ? AND material_id = ? FOR UPDATE',
                [$participationId, $materialId],
            );
            if ($plays >= $limit) {
                throw new \DomainException('Du hast diese Aufnahme schon ' . $limit . '-mal abgespielt.');
            }
            $this->db->run(
                'UPDATE material_plays SET plays = plays + 1, token_hash = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL ' . self::TOKEN_TTL . ' SECOND), last_played_at = NOW()
                 WHERE participation_id = ? AND material_id = ?',
                [hash('sha256', $token), $participationId, $materialId],
            );

            return ['plays' => $plays + 1, 'limit' => $limit, 'token' => $token];
        });
    }

    /** Verbraucht den Einmal-Schlüssel; true, wenn er gültig war. */
    public function redeem(int $participationId, int $materialId, string $token): bool
    {
        if ($token === '' || strlen($token) > 100) {
            return false;
        }
        $changed = $this->db->run(
            'UPDATE material_plays SET token_hash = NULL, token_expires_at = NULL
             WHERE participation_id = ? AND material_id = ? AND token_hash = ? AND token_expires_at >= NOW()',
            [$participationId, $materialId, hash('sha256', $token)],
        );

        return $changed->rowCount() === 1;
    }
}
